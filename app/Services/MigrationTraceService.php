<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Env;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Throwable;

final class MigrationTraceService
{
    private const MAX_SAFE_MESSAGE = 700;
    private const MAX_CONTEXT_BYTES = 8000;
    private const MAX_FALLBACK_BYTES = 5242880;

    private readonly string $diagnosticId;
    private readonly string $fileVersion;
    private readonly string $installedVersion;

    public function __construct(private readonly PDO $pdo, ?string $diagnosticId = null)
    {
        $this->diagnosticId = $diagnosticId ?? self::newDiagnosticId();
        $this->fileVersion = AppVersionService::fileVersion();
        $this->installedVersion = $this->readInstalledVersion();
    }

    public static function newDiagnosticId(): string
    {
        return 'MIG-' . gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
    }

    public function diagnosticId(): string
    {
        return $this->diagnosticId;
    }

    public function event(
        string $stage,
        string $status,
        ?string $migrationKey = null,
        ?string $checksum = null,
        ?Throwable $error = null,
        array $context = [],
        ?int $durationMs = null
    ): void {
        try {
            $errorData = self::errorData($error);
            $context['event_time_erp'] = self::erpTimestamp();
            $context['erp_timezone'] = self::erpTimezone();
            $safeContext = self::sanitizeContext($context);
            $payload = [
                'diagnostic_id' => $this->diagnosticId,
                'migration_key' => $migrationKey,
                'stage' => self::safeToken($stage, 80),
                'status' => self::safeToken($status, 30),
                'duration_ms' => $durationMs,
                'checksum_sha256' => $checksum,
                'file_version' => $this->fileVersion,
                'installed_version' => $this->installedVersion,
                'php_version' => PHP_VERSION,
                'php_sapi' => PHP_SAPI,
                'pdo_driver' => $this->driverName(),
                'sqlstate' => $errorData['sqlstate'],
                'driver_code' => $errorData['driver_code'],
                'exception_class' => $errorData['exception_class'],
                'safe_message' => $errorData['safe_message'],
                'context' => $safeContext,
                'created_at_utc' => gmdate('Y-m-d H:i:s'),
            ];

            try {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO system_update_migration_events
                     (diagnostic_id,migration_key,stage,status,duration_ms,checksum_sha256,file_version,
                      installed_version,php_version,php_sapi,pdo_driver,sql_state,driver_code,
                      exception_class,safe_message,context_json,created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())'
                );
                $stmt->execute([
                    $payload['diagnostic_id'],
                    $payload['migration_key'],
                    $payload['stage'],
                    $payload['status'],
                    $payload['duration_ms'],
                    $payload['checksum_sha256'],
                    $payload['file_version'],
                    $payload['installed_version'],
                    $payload['php_version'],
                    $payload['php_sapi'],
                    $payload['pdo_driver'],
                    $payload['sqlstate'],
                    $payload['driver_code'],
                    $payload['exception_class'],
                    $payload['safe_message'],
                    self::encodeContext($safeContext),
                ]);
                if (in_array($stage, ['run_completed', 'run_failed'], true)) {
                    $this->cleanup();
                }
                return;
            } catch (Throwable) {
                $this->writeFallback($payload);
            }
        } catch (Throwable $traceError) {
            // Incluso un error al sanitizar o construir la traza debe ser secundario.
            $this->writeFallback([
                'diagnostic_id' => $this->diagnosticId,
                'migration_key' => $migrationKey,
                'stage' => 'trace_build_failed',
                'status' => 'warning',
                'file_version' => $this->fileVersion,
                'installed_version' => $this->installedVersion,
                'php_version' => PHP_VERSION,
                'php_sapi' => PHP_SAPI,
                'pdo_driver' => $this->driverName(),
                'safe_message' => self::safeMessage($traceError),
                'created_at_utc' => gmdate('Y-m-d H:i:s'),
            ]);
        }
    }

    public static function safeMessage(Throwable|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $message = $value instanceof Throwable ? $value->getMessage() : $value;
        $message = preg_replace('/(?i)(access[_-]?token|refresh[_-]?token|client[_-]?secret|authorization|password|app[_-]?key)\s*[:=]\s*[^\s,;]+/', '$1=[REDACTED]', $message) ?? $message;
        $message = preg_replace('/(?i)(token=)[a-z0-9._~-]+/', '$1[REDACTED]', $message) ?? $message;
        $message = preg_replace('/[A-Za-z]:\\\\(?:[^\\\\\r\n]+\\\\)*[^\\\\\r\n]*/', '[RUTA_PRIVADA]', $message) ?? $message;
        $message = preg_replace('#/(?:home|var|srv|www)/[^\s:]+#', '[RUTA_PRIVADA]', $message) ?? $message;
        return mb_substr(trim($message), 0, self::MAX_SAFE_MESSAGE);
    }

    public static function errorData(?Throwable $error): array
    {
        if ($error === null) {
            return [
                'sqlstate' => null,
                'driver_code' => null,
                'exception_class' => null,
                'safe_message' => null,
            ];
        }
        $sqlState = null;
        $driverCode = null;
        if ($error instanceof PDOException) {
            $info = $error->errorInfo ?? [];
            $sqlState = isset($info[0]) ? (string) $info[0] : null;
            $driverCode = isset($info[1]) ? (string) $info[1] : null;
        }
        if ($sqlState === null && preg_match('/SQLSTATE\[([A-Z0-9]+)\]/', $error->getMessage(), $match) === 1) {
            $sqlState = $match[1];
        }
        return [
            'sqlstate' => $sqlState,
            'driver_code' => $driverCode,
            'exception_class' => $error::class,
            'safe_message' => self::safeMessage($error),
        ];
    }

    public static function sanitizeContext(array $context): array
    {
        $redacted = Logger::redact($context);
        $safe = [];
        foreach ($redacted as $key => $value) {
            $safeKey = self::safeToken((string) $key, 80);
            if (is_array($value)) {
                $safe[$safeKey] = self::sanitizeContext($value);
            } elseif (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $safe[$safeKey] = $value;
            } else {
                $safe[$safeKey] = self::safeMessage((string) $value);
            }
        }
        return $safe;
    }

    private function driverName(): string
    {
        try {
            return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable) {
            return 'unknown';
        }
    }

    private static function erpTimezone(): string
    {
        $timezone = Env::get('APP_TIMEZONE', 'America/Bogota');
        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : 'America/Bogota';
    }

    private static function erpTimestamp(): string
    {
        try {
            return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone(self::erpTimezone()))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return gmdate('Y-m-d H:i:s');
        }
    }

    private function readInstalledVersion(): string
    {
        try {
            $version = $this->pdo->query('SELECT version FROM app_versions ORDER BY installed_at DESC,id DESC LIMIT 1')->fetchColumn();
            return $version ? (string) $version : 'sin registrar';
        } catch (Throwable) {
            return 'sin registrar';
        }
    }

    private function writeFallback(array $payload): void
    {
        try {
            $directory = AppPaths::storage('logs/migrations');
            if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
                return;
            }
            $file = $directory . '/migration-' . gmdate('Y-m-d') . '.jsonl';
            if (is_file($file) && (int) @filesize($file) >= self::MAX_FALLBACK_BYTES) {
                $file = $directory . '/migration-' . gmdate('Y-m-d-His') . '.jsonl';
            }
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) {
                @file_put_contents($file, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
            $this->cleanupFallbackFiles($directory, $this->settingInt('update.migration_debug_retention_days', 30, 1, 365));
        } catch (Throwable) {
            // El debug nunca puede sustituir ni ocultar el error original.
        }
    }

    private static function encodeContext(array $context): ?string
    {
        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return null;
        }
        return mb_substr($encoded, 0, self::MAX_CONTEXT_BYTES);
    }

    private static function safeToken(string $value, int $limit): string
    {
        $value = preg_replace('/[^a-zA-Z0-9_.:-]+/', '_', trim($value)) ?? '';
        return mb_substr($value === '' ? 'unknown' : $value, 0, $limit);
    }

    private function cleanup(): void
    {
        try {
            $days = $this->settingInt('update.migration_debug_retention_days', 30, 1, 365);
            $maxEvents = $this->settingInt('update.migration_debug_max_events', 2000, 200, 20000);
            $this->pdo->exec(
                'DELETE FROM system_update_migration_events
                 WHERE created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $days . ' DAY)'
            );
            $this->pdo->exec(
                'DELETE FROM system_update_migration_events
                 WHERE id<COALESCE((
                    SELECT cutoff_id FROM (
                        SELECT id cutoff_id FROM system_update_migration_events
                        ORDER BY id DESC LIMIT 1 OFFSET ' . ($maxEvents - 1) . '
                    ) retained_cutoff
                 ),0)'
            );
            $this->cleanupFallbackFiles(
                AppPaths::storage('logs/migrations'),
                $days
            );
        } catch (Throwable) {
            // La retención nunca debe afectar una migración.
        }
    }

    private function settingInt(string $key, int $default, int $minimum, int $maximum): int
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1'
            );
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            if (is_numeric($value)) {
                return max($minimum, min($maximum, (int) $value));
            }
        } catch (Throwable) {
            // Antes de la migración 063 se usan defaults seguros.
        }
        return $default;
    }

    private function cleanupFallbackFiles(string $directory, int $retentionDays): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $cutoff = time() - ($retentionDays * 86400);
        foreach (glob($directory . '/migration-*.jsonl') ?: [] as $file) {
            $modified = @filemtime($file);
            if (is_int($modified) && $modified < $cutoff) {
                @unlink($file);
            }
        }
    }
}
