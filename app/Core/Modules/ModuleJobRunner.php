<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Database;
use App\Services\AppSettingsService;
use App\Services\Logger;
use PDO;
use PDOException;
use Throwable;

final class ModuleJobRunner
{
    private const ACTIVE_STATUSES = ['pending', 'running', 'retry', 'paused'];

    public function __construct(private readonly ?ModuleRegistry $registry = null)
    {
    }

    /** @return array<string,mixed> */
    public function processDue(int $limit = 2, ?float $deadline = null): array
    {
        return $this->processSelected($limit, $deadline, null);
    }

    /** @return array<string,mixed> */
    public function processExact(int $jobId, ?float $deadline = null): array
    {
        return $this->processSelected(1, $deadline, $jobId);
    }

    /** @return array<string,mixed> */
    private function processSelected(int $limit, ?float $deadline, ?int $jobId): array
    {
        $registry = $this->registry ?? new ModuleRegistry();
        $pdo = Database::connectionFresh();
        $this->recoverExpiredLeases($pdo);
        $reconciled = $jobId === null ? $this->reconcilePendingEvents(
            max(1, (new AppSettingsService())->int('module.jobs.event_reconcile_limit', 50)),
            $registry
        ) : 0;
        $processed = 0;
        $ignored = 0;
        $errors = 0;
        $leaseLost = 0;
        $details = [];

        for ($index = 0; $index < max(1, $limit); $index++) {
            if ($deadline !== null && microtime(true) >= $deadline - 2) {
                break;
            }

            $job = $this->claim($pdo, $registry, $jobId);
            if ($job === null) {
                break;
            }
            $moduleId = (string) $job['module_id'];
            $token = (string) $job['lock_owner'];
            $generation = (int) $job['lease_generation'];

            try {
                $provider = $registry->provider($moduleId);
                if ($provider === null || !in_array((string) $job['job_type'], $provider->jobTypes(), true)) {
                    throw new \RuntimeException('Handler de trabajo modular no disponible.');
                }

                $job['payload'] = json_decode((string) ($job['payload_json'] ?? '{}'), true) ?: [];
                $job['checkpoint'] = json_decode((string) ($job['checkpoint_json'] ?? '{}'), true) ?: [];
                $job['heartbeat'] = fn (): bool => $this->heartbeat((int) $job['id'], $token, $generation);
                $this->heartbeat((int) $job['id'], $token, $generation);
                $result = $provider->processJob($job);
                $resultStatus = (string) ($result['status'] ?? 'completed');

                if (in_array($resultStatus, ['pending', 'retry', 'delayed', 'partial'], true)) {
                    if (!$this->persistContinuation($job, $result, $token, $generation)) {
                        $leaseLost++;
                        $details[] = $this->leaseLostDetail($job);
                        continue;
                    }
                    $details[] = ['module' => $moduleId, 'job_type' => $job['job_type'], 'status' => $resultStatus];
                    continue;
                }

                if (!$this->complete($pdo, $job, $result, $token, $generation)) {
                    $leaseLost++;
                    $details[] = $this->leaseLostDetail($job);
                    continue;
                }
                if ($resultStatus === 'ignored_unsupported') {
                    $ignored++;
                } else {
                    $processed++;
                }
                $details[] = ['module' => $moduleId, 'job_type' => $job['job_type'], 'status' => $resultStatus];
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors++;
                if (!$this->fail($job, $token, $generation)) {
                    $leaseLost++;
                    $details[] = $this->leaseLostDetail($job);
                }
                Logger::write('error', 'Trabajo modular no completado.', [
                    'module' => $moduleId,
                    'job_type' => (string) ($job['job_type'] ?? 'unknown'),
                    'error_class' => $error::class,
                    'lease_generation' => $generation,
                ]);
            }
        }

        return [
            'processed' => $processed,
            'ignored' => $ignored,
            'errors' => $errors,
            'lease_lost' => $leaseLost,
            'events_reconciled' => $reconciled,
            'details' => $details,
        ];
    }

    public function enqueue(string $moduleId, string $jobType, ?int $accountId, array $payload, int $priority = 100): int
    {
        $registry = $this->registry ?? new ModuleRegistry();
        $provider = $registry->provider($moduleId);
        if ($provider === null
            || !$registry->isEnabled($moduleId)
            || !in_array($jobType, $provider->jobTypes(), true)
            || !(new ModuleJobCapabilityGate())->allows($moduleId, $jobType, $payload)) {
            return 0;
        }

        $dedupeKey = $this->dedupeKey($moduleId, $jobType, $accountId, $payload);
        $pdo = Database::connection();
        $lockName = 'erp_module_enqueue_' . substr($dedupeKey, 0, 32);
        $lock = $pdo->prepare('SELECT GET_LOCK(?,2)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new \RuntimeException('No fue posible reservar la cola modular.');
        }
        try {
            $existingId = $this->existingJobId($pdo, $dedupeKey);
            if ($existingId > 0) {
                return $existingId;
            }
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO system_module_jobs
                     (module_id,job_type,meli_account_id,dedupe_key,active_dedupe_key,status,stage,priority,
                      payload_json,checkpoint_json,next_run_at,progress_total,created_at,updated_at)
                     VALUES (?,?,?,?,?,'pending','queued',?,?,?,UTC_TIMESTAMP(),1,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
                );
                $stmt->execute([
                    $moduleId,
                    $jobType,
                    $accountId,
                    $dedupeKey,
                    $dedupeKey,
                    $priority,
                    $this->json($payload),
                    '{}',
                ]);
                return (int) $pdo->lastInsertId();
            } catch (PDOException $error) {
                if ((string) ($error->errorInfo[1] ?? '') !== '1062') {
                    throw $error;
                }
                $existingId = $this->existingJobId($pdo, $dedupeKey);
                if ($existingId < 1) {
                    throw $error;
                }
                return $existingId;
            }
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable) {
            }
        }
    }

    public function enqueueScoped(string $moduleId, string $jobType, int $companyId, int $accountId, array $payload, int $priority = 100): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM meli_accounts WHERE id=? AND company_id=?');
        $stmt->execute([$accountId, $companyId]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new \App\Core\HttpException(404, 'No se encontró la cuenta solicitada.');
        }
        return $this->enqueue($moduleId, $jobType, $accountId, $payload, $priority);
    }

    public function pause(int $jobId): bool
    {
        return $this->transition($jobId, ['pending', 'retry'], 'paused', 'paused_by_user');
    }

    public function resume(int $jobId): bool
    {
        return $this->transition($jobId, ['paused', 'failed'], 'pending', 'queued', true);
    }

    public function cancel(int $jobId): bool
    {
        return $this->transition($jobId, ['pending', 'retry', 'paused'], 'cancelled', 'cancelled');
    }

    public function retry(int $jobId): bool
    {
        return $this->transition($jobId, ['failed'], 'pending', 'queued', true);
    }

    public function pauseScoped(int $jobId, int $companyId, int $accountId): bool
    {
        return $this->transitionScoped($jobId, $companyId, $accountId, ['pending', 'retry'], 'paused', 'paused_by_user');
    }

    public function resumeScoped(int $jobId, int $companyId, int $accountId): bool
    {
        return $this->transitionScoped($jobId, $companyId, $accountId, ['paused', 'failed'], 'pending', 'queued', true);
    }

    public function cancelScoped(int $jobId, int $companyId, int $accountId): bool
    {
        return $this->transitionScoped($jobId, $companyId, $accountId, ['pending', 'retry', 'paused'], 'cancelled', 'cancelled');
    }

    public function retryScoped(int $jobId, int $companyId, int $accountId): bool
    {
        return $this->transitionScoped($jobId, $companyId, $accountId, ['failed'], 'pending', 'queued', true);
    }

    public function heartbeat(int $jobId, string $owner, int $generation): bool
    {
        $seconds = max(30, min(600, (new AppSettingsService())->int('module.jobs.lease_seconds', 120)));
        $stmt = Database::connection()->prepare(
            "UPDATE system_module_jobs
             SET lease_heartbeat_at=UTC_TIMESTAMP(),
                 lock_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),
                 updated_at=UTC_TIMESTAMP()
             WHERE id=? AND status='running' AND lock_owner=? AND lease_generation=?"
        );
        $stmt->execute([$seconds, $jobId, $owner, $generation]);
        return $stmt->rowCount() === 1;
    }

    public function reconcilePendingEvents(int $limit = 50, ?ModuleRegistry $registry = null): int
    {
        $registry ??= $this->registry ?? new ModuleRegistry();
        $limit = max(1, min(500, $limit));
        try {
            $stmt = Database::connection()->query(
                "SELECT e.id,e.module_id,e.source_event_id,e.meli_account_id,e.topic,e.resource_type,e.remote_resource_id
                 FROM system_module_events e
                 WHERE e.status='pending'
                   AND NOT EXISTS (
                     SELECT 1 FROM system_module_jobs j
                     WHERE j.module_id=e.module_id
                       AND j.status IN ('pending','running','retry','paused')
                       AND JSON_UNQUOTE(JSON_EXTRACT(j.payload_json,'$.source_event_id'))=CAST(e.source_event_id AS CHAR)
                   )
                 ORDER BY e.id ASC LIMIT {$limit}"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $event) {
            $provider = $registry->provider((string) $event['module_id']);
            if ($provider === null || !$registry->isEnabled($provider->id())) {
                continue;
            }
            $jobTypes = $provider->jobTypes();
            $jobType = in_array('event_sync', $jobTypes, true) ? 'event_sync' : ($jobTypes[0] ?? '');
            if ($jobType === '') {
                continue;
            }
            try {
                $this->enqueue(
                    $provider->id(),
                    $jobType,
                    isset($event['meli_account_id']) ? (int) $event['meli_account_id'] : null,
                    [
                        'source_event_id' => (int) $event['source_event_id'],
                        'topic' => (string) $event['topic'],
                        'resource_type' => $event['resource_type'],
                        'resource_id' => $event['remote_resource_id'],
                    ],
                    40
                );
                $count++;
            } catch (Throwable) {
                // El evento durable permanece pendiente para el siguiente ciclo.
            }
        }
        return $count;
    }

    /** @return array<string,mixed>|null */
    private function claim(PDO $pdo, ModuleRegistry $registry, ?int $jobId = null): ?array
    {
        $pdo->beginTransaction();
        try {
            $reservationGuard = \App\Services\ManualCampaignReservationGuard::sql('module_jobs', 'system_module_jobs.id');
            $stmt = $pdo->prepare(
                "SELECT * FROM system_module_jobs
                 WHERE status IN ('pending','retry') AND next_run_at<=UTC_TIMESTAMP()
                   AND (? IS NULL OR id=?)" . $reservationGuard . "
                 ORDER BY (priority-LEAST(TIMESTAMPDIFF(MINUTE,next_run_at,UTC_TIMESTAMP()),60)) ASC,
                          COALESCE(last_success_at,'1970-01-01') ASC,id ASC
                 LIMIT 1 FOR UPDATE"
            );
            $stmt->execute([$jobId, $jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($job)) {
                $pdo->commit();
                return null;
            }
            if (!$registry->isEnabled((string) $job['module_id'])) {
                $pause = $pdo->prepare(
                    "UPDATE system_module_jobs SET status='paused',stage='module_disabled',
                     active_dedupe_key=dedupe_key,safe_error_message='Módulo deshabilitado.',updated_at=UTC_TIMESTAMP()
                     WHERE id=?"
                );
                $pause->execute([(int) $job['id']]);
                $pdo->commit();
                // En el carril normal puede buscarse otro módulo habilitado.
                // Una campaña dirigida debe detener este recurso exacto; caer
                // al selector genérico procesaría un job distinto al congelado.
                return $jobId === null ? $this->claim($pdo, $registry) : null;
            }
            $token = bin2hex(random_bytes(16));
            $generation = (int) ($job['lease_generation'] ?? 0) + 1;
            $seconds = max(30, min(600, (new AppSettingsService())->int('module.jobs.lease_seconds', 120)));
            $claim = $pdo->prepare(
                "UPDATE system_module_jobs
                 SET status='running',stage=COALESCE(stage,'starting'),active_dedupe_key=dedupe_key,
                     lock_owner=?,lease_generation=?,lease_heartbeat_at=UTC_TIMESTAMP(),
                     lock_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),attempts=attempts+1,
                     started_at=COALESCE(started_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP()
                 WHERE id=? AND status IN ('pending','retry')"
            );
            $claim->execute([$token, $generation, $seconds, (int) $job['id']]);
            if ($claim->rowCount() !== 1) {
                $pdo->rollBack();
                return null;
            }
            $pdo->commit();
            $job['lock_owner'] = $token;
            $job['lease_generation'] = $generation;
            return $job;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $result */
    private function persistContinuation(array $job, array $result, string $token, int $generation): bool
    {
        $resultStatus = (string) ($result['status'] ?? 'pending');
        $delay = max(5, min(3600, (int) ($result['delay_seconds'] ?? 30)));
        $nextStatus = $resultStatus === 'retry' ? 'retry' : 'pending';
        $stmt = Database::connection()->prepare(
            "UPDATE system_module_jobs
             SET status=?,stage=?,active_dedupe_key=dedupe_key,progress_current=?,progress_total=?,
                 checkpoint_json=?,result_json=?,next_run_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),
                 consecutive_failures=?,safe_error_message=?,lock_owner=NULL,lock_expires_at=NULL,
                 lease_heartbeat_at=NULL,updated_at=UTC_TIMESTAMP()
             WHERE id=? AND lock_owner=? AND lease_generation=? AND status='running'"
        );
        $stmt->execute([
            $nextStatus,
            (string) ($result['stage'] ?? 'waiting'),
            max(0, (int) ($result['progress_current'] ?? $job['progress_current'] ?? 0)),
            max(1, (int) ($result['progress_total'] ?? $job['progress_total'] ?? 1)),
            $this->json($result['checkpoint'] ?? $job['checkpoint']),
            $this->json($result),
            $delay,
            $resultStatus === 'retry' ? ((int) ($job['consecutive_failures'] ?? 0) + 1) : 0,
            isset($result['safe_message']) ? mb_substr((string) $result['safe_message'], 0, 500) : null,
            (int) $job['id'],
            $token,
            $generation,
        ]);
        return $stmt->rowCount() === 1;
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $result */
    private function complete(PDO $pdo, array $job, array $result, string $token, int $generation): bool
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "UPDATE system_module_jobs
                 SET status='completed',stage='completed',active_dedupe_key=NULL,
                     progress_current=GREATEST(progress_total,progress_current),consecutive_failures=0,
                     result_json=?,safe_error_message=NULL,finished_at=UTC_TIMESTAMP(),last_success_at=UTC_TIMESTAMP(),
                     lock_owner=NULL,lock_expires_at=NULL,lease_heartbeat_at=NULL,updated_at=UTC_TIMESTAMP()
                 WHERE id=? AND lock_owner=? AND lease_generation=? AND status='running'"
            );
            $stmt->execute([$this->json($result), (int) $job['id'], $token, $generation]);
            if ($stmt->rowCount() !== 1) {
                $pdo->rollBack();
                return false;
            }
            $payload = json_decode((string) ($job['payload_json'] ?? '{}'), true) ?: [];
            $sourceEventId = (int) ($payload['source_event_id'] ?? 0);
            if ($sourceEventId > 0) {
                $eventStatus = (string) ($result['status'] ?? '') === 'ignored_unsupported' ? 'ignored' : 'processed';
                $event = $pdo->prepare(
                    "UPDATE system_module_events SET status=?,processed_at=UTC_TIMESTAMP()
                     WHERE module_id=? AND source_event_id=? AND status='pending'"
                );
                $event->execute([$eventStatus, (string) $job['module_id'], $sourceEventId]);
            }
            $pdo->commit();
            return true;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $job */
    private function fail(array $job, string $token, int $generation): bool
    {
        $failures = (int) ($job['consecutive_failures'] ?? 0) + 1;
        $maximum = max(1, (new AppSettingsService())->int('module.jobs.max_consecutive_failures', 3));
        $status = $failures >= $maximum ? 'failed' : 'retry';
        $delay = min(60, 5 * $failures);
        try {
            $stmt = Database::connection()->prepare(
                "UPDATE system_module_jobs
                 SET status=?,stage='error',active_dedupe_key=?,
                     next_run_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? MINUTE),consecutive_failures=?,
                     safe_error_message=?,lock_owner=NULL,lock_expires_at=NULL,lease_heartbeat_at=NULL,
                     updated_at=UTC_TIMESTAMP()
                 WHERE id=? AND lock_owner=? AND lease_generation=? AND status='running'"
            );
            $stmt->execute([
                $status,
                $status === 'failed' ? null : (string) ($job['dedupe_key'] ?? ''),
                $delay,
                $failures,
                'No fue posible completar esta etapa. Revise la capacidad y el diagnóstico del módulo.',
                (int) $job['id'],
                $token,
                $generation,
            ]);
            return $stmt->rowCount() === 1;
        } catch (Throwable) {
            return false;
        }
    }

    private function transition(int $jobId, array $from, string $to, string $stage, bool $clearError = false): bool
    {
        if ($jobId < 1 || $from === []) {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($from), '?'));
        $activeExpression = in_array($to, self::ACTIVE_STATUSES, true) ? 'dedupe_key' : 'NULL';
        $sql = "UPDATE system_module_jobs SET status=?,stage=?,active_dedupe_key={$activeExpression},
                next_run_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()";
        if ($clearError) {
            $sql .= ',consecutive_failures=0,safe_error_message=NULL';
        }
        if (in_array($to, ['cancelled', 'completed'], true)) {
            $sql .= ',finished_at=UTC_TIMESTAMP(),lock_owner=NULL,lock_expires_at=NULL,lease_heartbeat_at=NULL';
        }
        $sql .= ' WHERE id=? AND status IN (' . $placeholders . ')';
        try {
            $stmt = Database::connection()->prepare($sql);
            $stmt->execute(array_merge([$to, $stage, $jobId], $from));
            return $stmt->rowCount() === 1;
        } catch (PDOException $error) {
            if ((string) ($error->errorInfo[1] ?? '') === '1062') {
                return false;
            }
            throw $error;
        }
    }

    /** @param list<string> $from */
    private function transitionScoped(
        int $jobId,
        int $companyId,
        int $accountId,
        array $from,
        string $to,
        string $stage,
        bool $clearError = false
    ): bool {
        if ($jobId < 1 || $companyId < 1 || $accountId < 1 || $from === []) {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($from), '?'));
        $activeExpression = in_array($to, self::ACTIVE_STATUSES, true) ? 'j.dedupe_key' : 'NULL';
        $sql = "UPDATE system_module_jobs j
                JOIN meli_accounts a ON a.id=j.meli_account_id AND a.company_id=?
                SET j.status=?,j.stage=?,j.active_dedupe_key={$activeExpression},
                    j.next_run_at=UTC_TIMESTAMP(),j.updated_at=UTC_TIMESTAMP()";
        if ($clearError) {
            $sql .= ',j.consecutive_failures=0,j.safe_error_message=NULL';
        }
        if (in_array($to, ['cancelled', 'completed'], true)) {
            $sql .= ',j.finished_at=UTC_TIMESTAMP(),j.lock_owner=NULL,j.lock_expires_at=NULL,j.lease_heartbeat_at=NULL';
        }
        $sql .= ' WHERE j.id=? AND j.meli_account_id=? AND j.status IN (' . $placeholders . ')';
        try {
            $stmt = Database::connection()->prepare($sql);
            $stmt->execute(array_merge([$companyId, $to, $stage, $jobId, $accountId], $from));
            return $stmt->rowCount() === 1;
        } catch (PDOException $error) {
            if ((string) ($error->errorInfo[1] ?? '') === '1062') {
                return false;
            }
            throw $error;
        }
    }

    private function recoverExpiredLeases(PDO $pdo): void
    {
        try {
            $pdo->exec(
                "UPDATE system_module_jobs
                 SET status='retry',stage='lease_recovered',active_dedupe_key=dedupe_key,
                     lease_generation=lease_generation+1,next_run_at=UTC_TIMESTAMP(),
                     lock_owner=NULL,lock_expires_at=NULL,lease_heartbeat_at=NULL,
                     safe_error_message='La ejecución anterior se interrumpió; el trabajo se reanudará.',
                     updated_at=UTC_TIMESTAMP()
                 WHERE status='running' AND lock_expires_at IS NOT NULL AND lock_expires_at<UTC_TIMESTAMP()"
            );
        } catch (Throwable) {
        }
    }

    private function existingJobId(PDO $pdo, string $dedupeKey): int
    {
        $stmt = $pdo->prepare(
            "SELECT id FROM system_module_jobs
             WHERE active_dedupe_key=? AND status IN ('pending','running','retry','paused')
             ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute([$dedupeKey]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function dedupeKey(string $moduleId, string $jobType, ?int $accountId, array $payload): string
    {
        $resource = trim((string) ($payload['resource_id'] ?? ''));
        $scope = $resource !== '' ? $resource : 'account';
        return hash('sha256', $moduleId . '|' . $jobType . '|' . ($accountId ?? 0) . '|' . $scope);
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function leaseLostDetail(array $job): array
    {
        Logger::write('warning', 'El trabajo modular perdió su lease y no modificó el estado.', [
            'module' => (string) ($job['module_id'] ?? 'unknown'),
            'job_type' => (string) ($job['job_type'] ?? 'unknown'),
            'lease_generation' => (int) ($job['lease_generation'] ?? 0),
        ]);
        return [
            'module' => (string) ($job['module_id'] ?? 'unknown'),
            'job_type' => (string) ($job['job_type'] ?? 'unknown'),
            'status' => 'lease_lost',
        ];
    }

    private function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
