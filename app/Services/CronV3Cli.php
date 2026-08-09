<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use App\QueueCore\QueueEngineControlService;
use App\QueueCore\QueueEngineRuntimePermit;
use Throwable;

final class CronV3Cli
{
    /** @param list<string> $argv */
    public static function run(string $lane, array $argv): int
    {
        if (PHP_SAPI !== 'cli' || !in_array($lane, ['local', 'remote'], true)) {
            return 2;
        }

        if (in_array('--doctor', $argv, true)) {
            Database::useProfile('diagnostic');
            $doctor = (new CronV3DoctorService())->snapshot($lane);
            if (in_array('--json', $argv, true)) {
                self::write($doctor, true);
            } else {
                echo CronV3DoctorService::textReport($doctor);
            }
            return !empty($doctor['ok']) ? 0 : 2;
        }

        $runtimeDefault = $lane === 'local' ? 45 : 35;
        $maxDefault = $lane === 'local' ? 50 : 12;
        $runtime = self::integerArg($argv, 'runtime', $runtimeDefault, 5, 55);
        $maxItems = self::integerArg($argv, $lane === 'local' ? 'max-items' : 'max-http', $maxDefault, 1, $lane === 'local' ? 50 : 30);
        $delay = $lane === 'remote' ? self::integerArg($argv, 'delay', 10, 0, 30) : 0;
        $safeCloseSeconds = min(10, max(1, $runtime - 1));
        CronDeadlineContext::start(
            $runtime,
            $runtime - $safeCloseSeconds,
            self::integerEnv('CRON_V3_API_TIMEOUT', 8, 2, 20),
            self::integerEnv('CRON_V3_API_CONNECT_TIMEOUT', 3, 1, 20),
        );

        $lock = null;
        $engineControl = null;
        $enginePermit = null;
        try {
            $shadow = in_array('--shadow', $argv, true);
            $activeEnabled = self::envBool('CRON_V3_ENABLED', false);
            $shadowEnabled = self::envBool('CRON_V3_SHADOW_ENABLED', false);
            if ((!$shadow && !$activeEnabled) || ($shadow && !$shadowEnabled)) {
                self::write([
                    'ok' => true,
                    'mode' => $shadow ? 'shadow' : 'active',
                    'lane' => $lane,
                    'status' => 'disabled',
                    'required_env' => $shadow ? 'CRON_V3_SHADOW_ENABLED=true' : 'CRON_V3_ENABLED=true',
                    'http_calls' => 0,
                ]);
                return 0;
            }

            // Autoridad física fail-closed. Debe ejecutarse antes del lock,
            // delay, conexión DB, cutover, productores, importadores o claims.
            // El JSON de salida es el receipt; no crea heartbeat ni trabajo útil.
            if ((new EmergencyControlService())->automationStopped()) {
                self::write([
                    'ok' => true,
                    'mode' => $shadow ? 'shadow' : 'active',
                    'lane' => $lane,
                    'status' => 'SKIPPED_AUTOMATION_STOPPED',
                    'reason' => 'automation_stop_marker_present',
                    'http_calls' => 0,
                    'source_mutations' => 0,
                ]);
                return 0;
            }

            if (function_exists('job_try_lock')) {
                $lock = \job_try_lock('cron_v3_' . $lane);
                if ($lock === null) {
                    self::write(['ok' => true, 'lane' => $lane, 'status' => 'already_running', 'http_calls' => 0]);
                    return 0;
                }
            }
            if ($delay > 0) {
                sleep($delay);
            }
            if (!CronDeadlineContext::canAcceptWork()) {
                self::write([
                    'ok' => true,
                    'lane' => $lane,
                    'status' => 'deadline_exhausted',
                    'http_calls' => 0,
                ]);
                return 0;
            }
            Database::useProfile('cli');
            $pdo = Database::connection();
            $engineControl = new QueueEngineControlService($pdo);
            $engineRuntime = $engineControl->acquireRuntime('v3', $lane);
            if (empty($engineRuntime['ok'])
                || !(($engineRuntime['permit'] ?? null) instanceof QueueEngineRuntimePermit)) {
                self::write([
                    'ok' => true,
                    'mode' => $shadow ? 'shadow' : 'active',
                    'lane' => $lane,
                    'status' => 'engine_inactive',
                        'reason' => $engineRuntime['reason'],
                        'active_engine' => $engineRuntime['active_engine'],
                        'generation' => $engineRuntime['generation'],
                    'http_calls' => 0,
                    'source_mutations' => 0,
                ]);
                return 0;
            }
            $enginePermit = $engineRuntime['permit'];
            $kernel = CronV3::boot($pdo);
            if (CronDeadlineContext::remainingSeconds() <= $safeCloseSeconds) {
                self::write([
                    'ok' => true,
                    'lane' => $lane,
                    'status' => 'deadline_exhausted',
                    'http_calls' => 0,
                ]);
                return 0;
            }
            if (!$engineControl->stillCurrent($enginePermit)) {
                self::write([
                    'ok' => true,
                    'lane' => $lane,
                    'status' => 'engine_generation_changed',
                    'http_calls' => 0,
                ]);
                return 0;
            }
            $certifiedCutover = null;
            $operationalCutover = null;
            if (!$shadow) {
                if ((new CronV3OperationalModeService(Database::connection()))->enabled()) {
                    $operationalCutover = (new CronV3OperationalCutoverService(Database::connection()))
                        ->applyIfSafe('cron_v3_' . $lane);
                    if (empty($operationalCutover['ok'])) {
                        self::write([
                            'ok' => false,
                            'lane' => $lane,
                            'status' => 'operational_cutover_blocked',
                            'reason' => (string) ($operationalCutover['reason'] ?? 'blocked'),
                            'blocking' => array_values((array) ($operationalCutover['blocking'] ?? [])),
                            'http_calls' => 0,
                            'operational_cutover' => $operationalCutover,
                        ]);
                        return 2;
                    }
                } else {
                    $certifiedCutover = (new CronV3CertifiedCutoverService(Database::connection()))
                        ->applyIfSafe('cron_v3_' . $lane);
                }
            }
            $legacyImport = $lane === 'local' && !$shadow
                ? (new CronV3LegacyImportCoordinator(Database::connection()))->importMany(50, 20, 4)
                : null;
            $maintenanceProducer = $lane === 'local' && !$shadow
                ? (new CronV3MaintenanceProducer(Database::connection()))->enqueueDue()
                : null;
            if (CronDeadlineContext::remainingSeconds() <= $safeCloseSeconds) {
                self::write([
                    'ok' => true,
                    'lane' => $lane,
                    'status' => 'deadline_exhausted',
                    'http_calls' => 0,
                    'certified_cutover' => $certifiedCutover,
                    'operational_cutover' => $operationalCutover,
                    'legacy_import' => $legacyImport,
                    'maintenance_producer' => $maintenanceProducer,
                ]);
                return 0;
            }
            $remainingRuntime = max(1, min(
                $runtime,
                (int) floor(CronDeadlineContext::remainingSeconds()),
            ));
            $fallbackRateLimit = self::integerEnv('CRON_V3_RATE_LIMIT', 10, 1, 300);
            $ratePolicy = new CronV3RatePolicyService();
            $result = $kernel->runner($ratePolicy->currentLimit($fallbackRateLimit))
                ->run($lane, $remainingRuntime, $maxItems, $shadow);
            $result['rate_policy'] = $ratePolicy->current($fallbackRateLimit);
            if ($certifiedCutover !== null) {
                $result['certified_cutover'] = $certifiedCutover;
            }
            if ($operationalCutover !== null) {
                $result['operational_cutover'] = $operationalCutover;
            }
            if ($legacyImport !== null) {
                $result['legacy_import'] = $legacyImport;
            }
            if ($maintenanceProducer !== null) {
                $result['maintenance_producer'] = $maintenanceProducer;
            }
            self::write($result);
            return !empty($result['ok']) ? 0 : 2;
        } catch (Throwable) {
            self::write([
                'ok' => false,
                'lane' => $lane,
                'status' => 'safe_failure',
                'reason' => 'cron_v3_runtime_unavailable',
                'http_calls' => 0,
            ]);
            return 2;
        } finally {
            if ($engineControl instanceof QueueEngineControlService) {
                $engineControl->releaseRuntime(
                    $enginePermit instanceof QueueEngineRuntimePermit ? $enginePermit : null
                );
            }
            CronDeadlineContext::clear();
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @param list<string> $argv */
    private static function integerArg(array $argv, string $name, int $default, int $min, int $max): int
    {
        foreach ($argv as $argument) {
            $prefix = '--' . $name . '=';
            if (str_starts_with($argument, $prefix)) {
                $value = substr($argument, strlen($prefix));
                return ctype_digit($value) ? max($min, min($max, (int) $value)) : $default;
            }
        }
        return $default;
    }

    private static function envBool(string $name, bool $default): bool
    {
        return Env::bool($name, $default);
    }

    private static function integerEnv(string $name, int $default, int $min, int $max): int
    {
        $value = Env::get($name);
        return $value !== null && ctype_digit($value) ? max($min, min($max, (int) $value)) : $default;
    }

    /** @param array<string,mixed> $payload */
    private static function write(array $payload, bool $pretty = false): void
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        echo json_encode($payload, $flags) . PHP_EOL;
    }
}
