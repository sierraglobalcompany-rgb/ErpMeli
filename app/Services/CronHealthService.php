<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class CronHealthService
{
    private const AUTOMATIC_SOURCES = ['scheduled_cli', 'notification_cli'];
    // Los contadores completos viven en columnas y el detalle por tarea en
    // system_cron_run_steps. Este JSON solo conserva un resumen operativo;
    // limitarlo a 2 KiB evita que un ciclo por minuto vuelva a convertir la
    // salud de Cron en una de las tablas técnicas más grandes.
    private const MAX_PAYLOAD_BYTES = 2048;

    /** @return array{id:int,run_token:string,source:string} */
    public function begin(
        string $jobName,
        string $source = 'scheduled_cli',
        ?int $expectedIntervalMinutes = null
    ): array {
        $source = $this->normalizeSource($source);
        $runToken = $this->newRunToken();
        $interval = max(1, min(1440, $expectedIntervalMinutes ?? $this->expectedInterval($jobName)));
        $pdo = Database::connectionFresh();

        if ($this->supportsBoundedSchema()) {
            $deadlineSeconds = $this->settings()->int('cron.max_runtime_seconds', 40);
            $stmt = $pdo->prepare(
                'INSERT INTO cron_health_checks
                 (job_name,execution_source,run_token,status,result_state,started_at,heartbeat_at,
                  current_step,deadline_at,end_reason,lock_name,expected_interval_minutes)
                 VALUES (:job,:source,:token,"running","running",UTC_TIMESTAMP(),UTC_TIMESTAMP(),
                         "bootstrap",DATE_ADD(UTC_TIMESTAMP(),INTERVAL :deadline SECOND),NULL,:lock_name,:interval)'
            );
            $stmt->execute([
                'job' => $jobName,
                'source' => $source,
                'token' => $runToken,
                'deadline' => max(5, min(120, $deadlineSeconds)),
                'lock_name' => $jobName,
                'interval' => $interval,
            ]);
        } elseif ($this->supportsVerificationSchema()) {
            $stmt = $pdo->prepare(
                'INSERT INTO cron_health_checks
                 (job_name,execution_source,run_token,status,result_state,started_at,heartbeat_at,expected_interval_minutes)
                 VALUES (:job,:source,:token,"running","running",UTC_TIMESTAMP(),UTC_TIMESTAMP(),:interval)'
            );
            $stmt->execute([
                'job' => $jobName,
                'source' => $source,
                'token' => $runToken,
                'interval' => $interval,
            ]);
        } else {
            $pdo->prepare(
                'INSERT INTO cron_health_checks (job_name,status,started_at) VALUES (:job,"running",UTC_TIMESTAMP())'
            )->execute(['job' => $jobName]);
        }

        $id = (int) $pdo->lastInsertId();
        if ($id > 0 && $this->supportsReleaseIntegritySchema()) {
            (new ReleaseIntegrityService())->stampRun($id, $jobName);
        }

        return [
            'id' => $id,
            'run_token' => $runToken,
            'source' => $source,
        ];
    }

    public function start(string $jobName): int
    {
        return $this->begin(
            $jobName,
            PHP_SAPI === 'cli' ? 'scheduled_cli' : 'manual_web'
        )['id'];
    }

    /** @param array<string,mixed> $payload */
    public function heartbeat(int $id, array $payload = []): void
    {
        if ($id <= 0 || !$this->supportsVerificationSchema()) {
            return;
        }
        try {
            $json = $payload === [] ? '' : $this->encodedPayload($payload);
            if ($this->supportsBoundedSchema()) {
                $stmt = Database::connectionFresh()->prepare(
                    'UPDATE cron_health_checks
                     SET heartbeat_at=UTC_TIMESTAMP(),current_step=:step,
                         payload_json=CASE WHEN :payload="" THEN payload_json ELSE :payload_write END
                     WHERE id=:id AND status="running"'
                );
                $stmt->execute([
                    'step' => mb_substr((string) ($payload['current_step'] ?? 'working'), 0, 100),
                    'payload' => $json,
                    'payload_write' => $json !== '' ? $json : null,
                    'id' => $id,
                ]);
            } else {
                $stmt = Database::connectionFresh()->prepare(
                    'UPDATE cron_health_checks
                     SET heartbeat_at=UTC_TIMESTAMP(),
                         payload_json=CASE WHEN :payload="" THEN payload_json ELSE :payload_write END
                     WHERE id=:id AND status="running"'
                );
                $stmt->execute([
                    'payload' => $json,
                    'payload_write' => $json !== '' ? $json : null,
                    'id' => $id,
                ]);
            }
        } catch (Throwable) {
            // La observabilidad no puede detener el trabajo principal.
        }
    }

    /** @param array<string,mixed> $summary */
    public function finish(
        int $id,
        string $status,
        array $summary = [],
        ?string $message = null,
        ?int $exitCode = null
    ): void {
        if ($id <= 0) {
            return;
        }
        $safeStatus = in_array($status, ['success', 'partial'], true) ? 'success' : 'error';
        $resultState = $status === 'partial'
            ? 'partial'
            : ($safeStatus === 'error'
                ? 'error'
                : ($this->isEmptySummary($summary) ? 'empty' : 'success'));
        $pdo = Database::connectionFresh();

        if (!$this->supportsVerificationSchema()) {
            $pdo->prepare(
                'UPDATE cron_health_checks
                 SET status=:status,finished_at=UTC_TIMESTAMP(),
                     duration_ms=TIMESTAMPDIFF(MICROSECOND,started_at,UTC_TIMESTAMP()) DIV 1000,
                     processed_chunks=:processed,completed_chunks=:completed,partial_chunks=:partial,
                     error_chunks=:errors,orders_count=:orders,message=:message,payload_json=:payload
                 WHERE id=:id'
            )->execute($this->legacyFinishParams($id, $safeStatus, $summary, $message));
            return;
        }

        $row = $this->findById($id) ?? [
            'expected_interval_minutes' => $this->expectedInterval('process_sync_queue'),
            'job_name' => 'process_sync_queue',
            'execution_source' => 'legacy',
            'automatic_streak' => 0,
            'started_at' => gmdate('Y-m-d H:i:s'),
            'status' => 'running',
            'result_state' => 'running',
        ];
        $interval = max(1, (int) ($row['expected_interval_minutes'] ?? $this->expectedInterval((string) ($row['job_name'] ?? ''))));
        $source = (string) ($row['execution_source'] ?? 'legacy');
        $streak = in_array($source, self::AUTOMATIC_SOURCES, true) && $safeStatus === 'success'
            ? $this->nextAutomaticStreak($row, $interval)
            : 0;
        $observedInterval = $this->observedIntervalSeconds($row);
        $observedSet = (new SchemaInspectorService())->hasColumn('cron_health_checks', 'observed_interval_seconds')
            ? ',observed_interval_seconds=:observed_interval'
            : '';

        $boundedSet = $this->supportsBoundedSchema()
            ? ',current_step="finished",end_reason=:end_reason'
            : '';
        $stmt = $pdo->prepare(
            'UPDATE cron_health_checks
             SET status=:status,result_state=:result_state,exit_code=:exit_code,
                 heartbeat_at=UTC_TIMESTAMP(),finished_at=UTC_TIMESTAMP(),
                 duration_ms=TIMESTAMPDIFF(MICROSECOND,started_at,UTC_TIMESTAMP()) DIV 1000,
                 processed_chunks=:processed,completed_chunks=:completed,partial_chunks=:partial,
                 error_chunks=:errors,orders_count=:orders,message=:message,payload_json=:payload,
                 automatic_streak=:streak' . $observedSet . $boundedSet . ',
                 next_expected_at=CASE
                    WHEN execution_source IN ("scheduled_cli","notification_cli")
                    THEN DATE_ADD(
                        UTC_TIMESTAMP(),
                        INTERVAL :next_interval_seconds SECOND
                    )
                    ELSE NULL
                 END
             WHERE id=:id'
        );
        $params = [
            'status' => $safeStatus,
            'result_state' => $resultState,
            'exit_code' => $exitCode ?? ($safeStatus === 'success' ? 0 : 1),
            'processed' => (int) ($summary['processed_chunks'] ?? $summary['processed'] ?? 0),
            'completed' => (int) ($summary['completed_chunks'] ?? 0),
            'partial' => (int) ($summary['partial_chunks'] ?? 0),
            'errors' => (int) ($summary['error_chunks'] ?? $summary['errors'] ?? 0),
            'orders' => (int) ($summary['orders_count'] ?? $summary['orders'] ?? 0),
            'message' => $message ? mb_substr(Logger::redactString($message), 0, 500) : null,
            'payload' => $this->encodedPayload($summary),
            'streak' => $streak,
            'next_interval_seconds' => max(10, $observedInterval ?? ($interval * 60)),
            'id' => $id,
        ];
        if ($this->supportsBoundedSchema()) {
            $params['end_reason'] = mb_substr(
                (string) ($summary['end_reason'] ?? ($resultState === 'empty' ? 'queue_empty' : $resultState)),
                0,
                80
            );
        }
        if ($observedSet !== '') {
            $params['observed_interval'] = $observedInterval;
        }
        $stmt->execute($params);
    }

    public function markInterrupted(string $jobName, int $staleSeconds = 180): int
    {
        if (!$this->supportsVerificationSchema()) {
            return 0;
        }
        $seconds = max(60, min(3600, $staleSeconds));
        $extra = $this->supportsBoundedSchema()
            ? ',current_step=COALESCE(current_step,"unknown"),end_reason="stale_heartbeat"'
            : '';
        try {
            $stmt = Database::connectionFresh()->prepare(
                'UPDATE cron_health_checks
                 SET status="error",result_state="interrupted",finished_at=UTC_TIMESTAMP(),
                     exit_code=1,message="La ejecución anterior dejó de enviar heartbeat."' . $extra . '
                 WHERE job_name=:job AND status="running"
                   AND COALESCE(heartbeat_at,started_at)<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $seconds . ' SECOND)'
            );
            $stmt->execute(['job' => $jobName]);
            return $stmt->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }

    public function latest(string $jobName = 'process_sync_queue'): ?array
    {
        return $this->latestWhere($jobName, '');
    }

    public function latestAutomatic(string $jobName = 'process_sync_queue'): ?array
    {
        if (!$this->supportsVerificationSchema()) {
            return null;
        }
        if ($this->supportsReleaseIntegritySchema()) {
            $identity = (new ReleaseIntegrityService())->identity($jobName);
            if ($identity['build_id'] === '') {
                return null;
            }
            return $this->latestWhere(
                $jobName,
                ' AND execution_source IN ("scheduled_cli","notification_cli")
                  AND release_build_id=:release_build',
                ['release_build' => $identity['build_id']]
            );
        }
        return $this->latestWhere(
            $jobName,
            ' AND execution_source IN ("scheduled_cli","notification_cli")'
        );
    }

    /** @return array<string,mixed>|null */
    public function latestAutomaticAnyBuild(string $jobName = 'process_sync_queue'): ?array
    {
        if (!$this->supportsVerificationSchema()) {
            return null;
        }
        return $this->latestWhere(
            $jobName,
            ' AND execution_source IN ("scheduled_cli","notification_cli")'
        );
    }

    public function latestManual(string $jobName = 'process_sync_queue'): ?array
    {
        if (!$this->supportsVerificationSchema()) {
            return null;
        }
        return $this->latestWhere(
            $jobName,
            ' AND execution_source IN ("manual_web","manual_cli","hostinger_test")'
        );
    }

    /** @return array<string,mixed> */
    public function status(string $jobName = 'process_sync_queue'): array
    {
        if (!$this->supportsVerificationSchema()) {
            return $this->legacyStatus($jobName);
        }

        $automatic = $this->latestAutomatic($jobName);
        $manual = $this->latestManual($jobName);
        if (!$automatic) {
            return [
                'state' => 'missing',
                'label' => 'No llegó una invocación automática',
                'message' => $manual
                    ? 'Existe una comprobación manual, pero no demuestra que Hostinger haya iniciado el lanzador.'
                    : 'Todavía no hay señales del lanzador automático.',
                'latest' => null,
                'latest_automatic' => null,
                'latest_manual' => $manual,
                'runtime' => $this->runtimeFrom($manual),
                'runtime_matches_web' => null,
                'automatic_streak' => 0,
                'required_streak' => $this->requiredStreak(),
                'next_expected_at' => null,
                'is_empty' => false,
            ];
        }

        $runtime = $this->runtimeFrom($automatic);
        $runtimeMatchesWeb = is_array($runtime)
            ? $this->samePhpBranch((string) ($runtime['php_version'] ?? ''), PHP_VERSION)
            : null;
        $referenceAt = (string) ($automatic['heartbeat_at'] ?: $automatic['finished_at'] ?: $automatic['started_at']);
        $ageSeconds = $this->ageSeconds($referenceAt);
        $medianObserved = (new ManualCampaignReservationTtlService())->observedIntervalSeconds();
        $observedSeconds = $medianObserved > 0
            ? $medianObserved
            : max(0, (int) ($automatic['observed_interval_seconds'] ?? 0));
        $interval = max(1, (int) ($automatic['expected_interval_minutes'] ?? $this->expectedInterval($jobName)));
        $staleSeconds = max(
            $this->settings()->int('cron.running_stale_seconds', 180),
            (($observedSeconds > 0 ? $observedSeconds : $interval * 60) * 2) + 60
        );
        $streak = (int) ($automatic['automatic_streak'] ?? 0);
        $required = $this->requiredStreak();
        $resultState = (string) ($automatic['result_state'] ?? $automatic['status']);

        if ($automatic['status'] === 'running') {
            if ($ageSeconds <= $staleSeconds) {
                [$state, $label, $message] = ['running', 'Ejecutándose', 'El cron automático está trabajando en este momento.'];
            } else {
                [$state, $label, $message] = ['interrupted', 'Ejecución interrumpida', 'La ejecución quedó sin heartbeat y necesita revisión.'];
            }
        } elseif ($automatic['status'] === 'error' || $resultState === 'error') {
            [$state, $label, $message] = [
                'error',
                'Con error',
                (string) ($automatic['message'] ?: 'La última ejecución automática terminó con error.'),
            ];
        } elseif ($ageSeconds > $staleSeconds) {
            [$state, $label, $message] = ['stale', 'Ejecución atrasada', 'No se recibió la siguiente señal automática dentro del intervalo esperado.'];
        } elseif ($streak >= $required) {
            [$state, $label, $message] = [
                'ok',
                $observedSeconds > 90
                    ? 'Automatización operativa, pero lenta'
                    : 'Automatización verificada',
                $observedSeconds > 90
                    ? 'El lanzador funciona, pero Hostinger está entregando ciclos más lentos de lo recomendado.'
                    : ($resultState === 'empty'
                    ? 'El cron automático terminó correctamente; no había trabajo pendiente.'
                    : 'El cron automático completó ejecuciones CLI consecutivas correctamente.'),
            ];
        } else {
            [$state, $label, $message] = [
                'pending_verification',
                'Esperando confirmación',
                'Se recibió una ejecución CLI correcta. Falta otra señal automática consecutiva para verificar la programación.',
            ];
        }

        return [
            'state' => $state,
            'label' => $label,
            'message' => $message,
            'latest' => $automatic,
            'latest_automatic' => $automatic,
            'latest_manual' => $manual,
            'runtime' => $runtime,
            'runtime_matches_web' => $runtimeMatchesWeb,
            'automatic_streak' => $streak,
            'required_streak' => $required,
            'next_expected_at' => $observedSeconds > 0
                ? gmdate(
                    'Y-m-d H:i:s',
                    (strtotime((string) $automatic['started_at'] . ' UTC') ?: time()) + $observedSeconds
                )
                : ($automatic['next_expected_at'] ?? null),
            'observed_interval_seconds' => $observedSeconds > 0 ? $observedSeconds : null,
            'is_empty' => $resultState === 'empty',
        ];
    }

    public function recommendedCommand(): string
    {
        $root = AppPaths::installationRoot();
        if (AppPaths::managed() && is_file($root . '/launcher/cron.php')) {
            return 'php ' . $root . '/launcher/cron.php process_sync_queue.php';
        }
        return 'php ' . $root . '/jobs/process_sync_queue.php';
    }

    public function recommendedNotificationCommand(): string
    {
        return $this->recommendedCommand();
    }

    public function recommendedProbeCommand(): string
    {
        $root = AppPaths::installationRoot();
        if (AppPaths::managed() && is_file($root . '/launcher/cron.php')) {
            return 'php ' . $root . '/launcher/cron.php cron_probe.php';
        }
        return 'php ' . $root . '/jobs/cron_probe.php';
    }

    public function recommendedManualCommand(): string
    {
        return $this->recommendedCommand();
    }

    public function recommendedCampaignCommand(): string
    {
        return $this->recommendedCommand();
    }

    public function recommendedManualEngineProbeCommand(): string
    {
        // Compatibilidad temporal: ya no existe un segundo motor ni una
        // certificación recurrente. Toda ejecución usa el lanzador principal.
        return $this->recommendedCommand();
    }

    /** @return array<string,mixed>|null */
    private function latestWhere(string $jobName, string $where, array $params = []): ?array
    {
        try {
            $stmt = Database::connection()->prepare(
                'SELECT * FROM cron_health_checks WHERE job_name=:job' . $where . ' ORDER BY started_at DESC,id DESC LIMIT 1'
            );
            $stmt->execute(array_merge(['job' => $jobName], $params));
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function legacyStatus(string $jobName): array
    {
        $latest = $this->latest($jobName);
        if (!$latest) {
            return [
                'state' => 'missing',
                'label' => 'Esperando primera ejecución',
                'message' => 'Todavía no hay registros del cron.',
                'latest' => null,
                'latest_automatic' => null,
                'latest_manual' => null,
                'runtime' => null,
                'runtime_matches_web' => null,
                'automatic_streak' => 0,
                'required_streak' => 2,
                'next_expected_at' => null,
                'is_empty' => false,
            ];
        }
        $runtime = $this->runtimeFrom($latest);
        return [
            'state' => 'pending_verification',
            'label' => 'Verificación avanzada pendiente',
            'message' => 'Aplique la migración 071 para separar pruebas manuales y ejecuciones automáticas.',
            'latest' => $latest,
            'latest_automatic' => null,
            'latest_manual' => null,
            'runtime' => $runtime,
            'runtime_matches_web' => is_array($runtime)
                ? $this->samePhpBranch((string) ($runtime['php_version'] ?? ''), PHP_VERSION)
                : null,
            'automatic_streak' => 0,
            'required_streak' => 2,
            'next_expected_at' => null,
            'is_empty' => false,
        ];
    }

    /** @param array<string,mixed> $row */
    private function nextAutomaticStreak(array $row, int $interval): int
    {
        try {
            $stmt = Database::connectionFresh()->prepare(
                'SELECT automatic_streak,started_at,status,result_state,observed_interval_seconds
                 FROM cron_health_checks
                 WHERE job_name=:job
                   AND execution_source IN ("scheduled_cli","notification_cli")
                   AND id<>:id
                 ORDER BY started_at DESC,id DESC LIMIT 1'
            );
            $stmt->execute(['job' => (string) $row['job_name'], 'id' => (int) $row['id']]);
            $previous = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$previous || (string) $previous['status'] !== 'success') {
                return 1;
            }
            $previousAt = (new DateTimeImmutable((string) $previous['started_at'], new DateTimeZone('UTC')))->getTimestamp();
            $currentAt = (new DateTimeImmutable((string) $row['started_at'], new DateTimeZone('UTC')))->getTimestamp();
            $gap = max(0, $currentAt - $previousAt);
            if ($gap < 10 || $gap > 86400) {
                return 1;
            }
            $previousObserved = max(0, (int) ($previous['observed_interval_seconds'] ?? 0));
            if ($previousObserved > 0) {
                $tolerance = max(15, (int) ceil($previousObserved * 0.25));
                if (abs($gap - $previousObserved) > $tolerance) {
                    return 1;
                }
            }
            return min(100, max(0, (int) $previous['automatic_streak']) + 1);
        } catch (Throwable) {
            return 1;
        }
    }

    /** @param array<string,mixed> $row */
    private function observedIntervalSeconds(array $row): ?int
    {
        if (!in_array((string) ($row['execution_source'] ?? ''), self::AUTOMATIC_SOURCES, true)) {
            return null;
        }
        try {
            $stmt = Database::connectionFresh()->prepare(
                'SELECT started_at
                 FROM cron_health_checks
                 WHERE job_name=:job
                   AND execution_source IN ("scheduled_cli","notification_cli")
                   AND id<>:id
                 ORDER BY started_at DESC,id DESC LIMIT 1'
            );
            $stmt->execute(['job' => (string) $row['job_name'], 'id' => (int) $row['id']]);
            $previous = $stmt->fetchColumn();
            if (!$previous) {
                return null;
            }
            $previousAt = (new DateTimeImmutable((string) $previous, new DateTimeZone('UTC')))->getTimestamp();
            $currentAt = (new DateTimeImmutable((string) $row['started_at'], new DateTimeZone('UTC')))->getTimestamp();
            return max(1, min(86400, $currentAt - $previousAt));
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed>|null */
    private function findById(int $id): ?array
    {
        $stmt = Database::connectionFresh()->prepare('SELECT * FROM cron_health_checks WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function expectedInterval(string $jobName): int
    {
        return max(1, $this->settings()->int('cron.main_interval_minutes', 1));
    }

    private function requiredStreak(): int
    {
        return max(2, min(5, $this->settings()->int('cron.verification_required_streak', 2)));
    }

    private function settings(): AppSettingsService
    {
        return new AppSettingsService();
    }

    private function supportsVerificationSchema(): bool
    {
        return (new SchemaInspectorService())->hasColumn('cron_health_checks', 'execution_source')
            && (new SchemaInspectorService())->hasColumn('cron_health_checks', 'run_token');
    }

    private function supportsBoundedSchema(): bool
    {
        return $this->supportsVerificationSchema()
            && (new SchemaInspectorService())->hasColumn('cron_health_checks', 'current_step')
            && (new SchemaInspectorService())->hasColumn('cron_health_checks', 'end_reason');
    }

    private function supportsReleaseIntegritySchema(): bool
    {
        $schema = new SchemaInspectorService();
        return $schema->hasColumn('cron_health_checks', 'release_version')
            && $schema->hasColumn('cron_health_checks', 'release_build_id')
            && $schema->hasColumn('cron_health_checks', 'component_checksum');
    }

    private function normalizeSource(string $source): string
    {
        return in_array($source, [
            'scheduled_cli',
            'notification_cli',
            'manual_web',
            'manual_cli',
            'hostinger_test',
            'legacy',
        ], true) ? $source : 'legacy';
    }

    private function newRunToken(): string
    {
        return 'CRON-' . gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(6)), 0, 8);
    }

    /** @param array<string,mixed> $summary */
    private function isEmptySummary(array $summary): bool
    {
        foreach ([
            'selected',
            'started',
            'inspected',
            'deferred',
            'not_started',
            'attempted_remote_calls',
            'blocked_remote_calls',
            'processed',
            'processed_chunks',
            'orders',
            'orders_count',
            'notification_events_processed',
            'financial_recalc_processed',
            'catalog_description_processed',
            'recurring_enqueued',
        ] as $key) {
            if ((int) ($summary[$key] ?? 0) > 0) {
                return false;
            }
        }
        foreach (($summary['coordinator']['steps'] ?? []) as $step) {
            if (is_array($step)) {
                foreach (['selected', 'started', 'inspected', 'deferred', 'not_started', 'attempted_remote_calls', 'blocked_remote_calls', 'processed'] as $key) {
                    if ((int) ($step[$key] ?? 0) > 0) {
                        return false;
                    }
                }
            }
        }
        foreach (($summary['work_availability'] ?? []) as $availability) {
            if (is_array($availability) && (int) ($availability['work_count'] ?? 0) > 0) {
                return false;
            }
        }
        $endReason = trim((string) ($summary['end_reason'] ?? ''));
        if ($endReason !== '' && !in_array($endReason, ['queue_empty', 'no_due_work'], true)) {
            return false;
        }
        return true;
    }

    /** @return array<string,mixed>|null */
    private function runtimeFrom(?array $row): ?array
    {
        if (!$row) {
            return null;
        }
        $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
        return is_array($payload) && is_array($payload['_runtime'] ?? null) ? $payload['_runtime'] : null;
    }

    private function ageSeconds(string $utcDate): int
    {
        try {
            return max(0, time() - (new DateTimeImmutable($utcDate, new DateTimeZone('UTC')))->getTimestamp());
        } catch (Throwable) {
            return PHP_INT_MAX;
        }
    }

    /** @param array<string,mixed> $summary
     *  @return array<string,mixed>
     */
    private function withTimePayload(array $summary): array
    {
        $summary = $this->compactPayload($summary);
        $utc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $local = $utc->setTimezone(new DateTimeZone(DateTimePresenter::timezone()));
        $summary['_time'] = [
            'utc' => $utc->format('Y-m-d H:i:s'),
            'local' => $local->format('Y-m-d H:i:s'),
            'timezone' => DateTimePresenter::timezone(),
            'php_timezone' => date_default_timezone_get(),
        ];
        $summary['_runtime'] = [
            'php_version' => PHP_VERSION,
            'php_version_id' => PHP_VERSION_ID,
            'sapi' => PHP_SAPI,
        ];
        $encoded = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($encoded) && strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            $summary = $this->topLevelCounters($summary);
            $summary['_truncated'] = true;
        }
        return $summary;
    }

    /** @param array<string,mixed> $payload */
    private function encodedPayload(array $payload): string
    {
        $summary = $this->withTimePayload($payload);
        $json = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return '{}';
        }
        if (strlen($json) <= self::MAX_PAYLOAD_BYTES) {
            return $json;
        }
        $summary = $this->topLevelCounters($summary);
        $summary['_truncated'] = true;
        $summary['_payload_limit_bytes'] = self::MAX_PAYLOAD_BYTES;
        $summary['_payload_original_sha256'] = hash('sha256', $json);
        $json = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json) && strlen($json) <= self::MAX_PAYLOAD_BYTES) {
            return $json;
        }
        return json_encode([
            '_truncated' => true,
            '_payload_limit_bytes' => self::MAX_PAYLOAD_BYTES,
            '_payload_original_sha256' => hash('sha256', is_string($json) ? $json : ''),
            '_runtime' => [
                'php_version' => PHP_VERSION,
                'php_version_id' => PHP_VERSION_ID,
                'sapi' => PHP_SAPI,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function compactPayload(array $payload, int $depth = 0): array
    {
        if ($depth >= 4) {
            return ['_truncated' => true];
        }
        $result = [];
        $count = 0;
        foreach ($payload as $key => $value) {
            if (++$count > 40) {
                $result['_truncated'] = true;
                break;
            }
            $name = (string) $key;
            if (preg_match(
                '/token|secret|password|authorization|cookie|raw|payload|sql|path|binary|ini/i',
                $name
            )) {
                continue;
            }
            if (is_array($value)) {
                $result[$name] = $name === 'coordinator'
                    ? $this->compactCoordinator($value)
                    : $this->compactPayload($value, $depth + 1);
            } elseif (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $result[$name] = $value;
            } elseif (is_string($value)) {
                $result[$name] = mb_substr(Logger::redactString($value), 0, 500);
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $coordinator @return array<string,mixed> */
    private function compactCoordinator(array $coordinator): array
    {
        $steps = is_array($coordinator['steps'] ?? null) ? $coordinator['steps'] : [];
        if ($steps === [] && isset($coordinator['step_count'])) {
            return [
                'budget_ms' => max(0, (int) ($coordinator['budget_ms'] ?? 0)),
                'used_ms' => max(0, (int) ($coordinator['used_ms'] ?? 0)),
                'remaining_ms' => max(0, (int) ($coordinator['remaining_ms'] ?? 0)),
                'step_count' => max(0, (int) $coordinator['step_count']),
                'processed' => max(0, (int) ($coordinator['processed'] ?? 0)),
                'errors' => max(0, (int) ($coordinator['errors'] ?? 0)),
                'last_steps' => is_array($coordinator['last_steps'] ?? null) ? $coordinator['last_steps'] : [],
                'detail_table' => 'system_cron_run_steps',
            ];
        }
        $processed = 0;
        $errors = 0;
        $last = [];
        foreach ($steps as $name => $step) {
            if (!is_array($step)) {
                continue;
            }
            $processed += max(0, (int) ($step['processed'] ?? 0));
            $errors += max(0, (int) ($step['errors'] ?? 0));
            $last[(string) $name] = [
                'status' => (string) ($step['status'] ?? ''),
                'processed' => max(0, (int) ($step['processed'] ?? 0)),
                'errors' => max(0, (int) ($step['errors'] ?? 0)),
                'duration_ms' => max(0, (int) ($step['duration_ms'] ?? 0)),
                'stop_reason' => mb_substr((string) ($step['stop_reason'] ?? ''), 0, 80),
            ];
            if (count($last) > 5) {
                array_shift($last);
            }
        }
        return [
            'budget_ms' => max(0, (int) ($coordinator['budget_ms'] ?? 0)),
            'used_ms' => max(0, (int) ($coordinator['used_ms'] ?? 0)),
            'remaining_ms' => max(0, (int) ($coordinator['remaining_ms'] ?? 0)),
            'step_count' => count($steps),
            'processed' => $processed,
            'errors' => $errors,
            'last_steps' => $last,
            'detail_table' => 'system_cron_run_steps',
        ];
    }

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    private function topLevelCounters(array $summary): array
    {
        $result = [];
        foreach ($summary as $key => $value) {
            if (
                is_bool($value)
                || is_int($value)
                || is_float($value)
                || $value === null
                || in_array($key, ['_time', '_runtime'], true)
            ) {
                $result[$key] = $value;
            } elseif (is_string($value) && strlen($value) <= 500) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $summary
     *  @return array<string,mixed>
     */
    private function legacyFinishParams(
        int $id,
        string $status,
        array $summary,
        ?string $message
    ): array {
        return [
            'status' => $status,
            'processed' => (int) ($summary['processed_chunks'] ?? $summary['processed'] ?? 0),
            'completed' => (int) ($summary['completed_chunks'] ?? 0),
            'partial' => (int) ($summary['partial_chunks'] ?? 0),
            'errors' => (int) ($summary['error_chunks'] ?? $summary['errors'] ?? 0),
            'orders' => (int) ($summary['orders_count'] ?? $summary['orders'] ?? 0),
            'message' => $message ? mb_substr(Logger::redactString($message), 0, 500) : null,
            'payload' => $this->encodedPayload($summary),
            'id' => $id,
        ];
    }

    private function samePhpBranch(string $left, string $right): bool
    {
        if (!preg_match('/^(\d+)\.(\d+)/', $left, $leftParts)
            || !preg_match('/^(\d+)\.(\d+)/', $right, $rightParts)) {
            return false;
        }
        return $leftParts[1] === $rightParts[1] && $leftParts[2] === $rightParts[2];
    }
}
