<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use PDO;
use RuntimeException;
use Throwable;

/** Read model forense y recuperación exacta; nunca interpreta un error genérico como 429. */
final class QueueV4CleanReviewService
{
    private const RECOVERABLE = [
        'apirhythmdeferredexception',
        'apibudgetexhaustedexception',
        'crondeadlinedeferredexception',
    ];

    public function __construct(private readonly PDO $pdo) {}

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $rows = $this->reviewRows();
        $byClass = $byType = $byAccount = [];
        $reviewClassCounts = $ageMatrix = $requiresHuman = $mechanical = [];
        $resolutionPaths = $samples = [];
        $oldest = $newest = null;
        $recoverable = $functional = $ambiguous = 0;
        foreach ($rows as $row) {
            $count = 1;
            $error = (string) ($row['last_error_class'] ?? 'unknown');
            $classification = $this->classification($error);
            $byClass[$classification . ':' . $error] = ($byClass[$classification . ':' . $error] ?? 0) + $count;
            $byType[(string) $row['job_type']] = ($byType[(string) $row['job_type']] ?? 0) + $count;
            $accountKey = (int) $row['company_id'] . ':' . (int) $row['meli_account_id'];
            $byAccount[$accountKey] = ($byAccount[$accountKey] ?? 0) + $count;
            $oldest = $oldest === null || (string) $row['updated_at'] < $oldest ? (string) $row['updated_at'] : $oldest;
            $newest = $newest === null || (string) $row['updated_at'] > $newest ? (string) $row['updated_at'] : $newest;
            match ($classification) {
                'NON_FAILURE_TECHNICAL' => $recoverable += $count,
                'FUNCTIONAL_ERROR' => $functional += $count,
                default => $ambiguous += $count,
            };

            $detail = $this->reviewClass($row);
            $class = $detail['class'];
            $reviewClassCounts[$class] = ($reviewClassCounts[$class] ?? 0) + 1;
            $bucket = $this->ageBucket((int) ($row['age_seconds'] ?? 0));
            $ageMatrix[$class][$bucket] = ($ageMatrix[$class][$bucket] ?? 0) + 1;
            $judgmentKey = $detail['requires_human_judgment'] ? 'YES' : 'NO';
            $requiresHuman[$judgmentKey] = ($requiresHuman[$judgmentKey] ?? 0) + 1;
            if (!$detail['requires_human_judgment']) {
                $mechanical[$class] = ($mechanical[$class] ?? 0) + 1;
            }
            $resolutionPaths[$class] = $detail['resolution'];
            if (!isset($samples[$class])) {
                $samples[$class] = [
                    'work_type' => (string) $row['job_type'],
                    'capability' => $this->capability($row),
                    'error_class' => $error !== '' ? $error : 'unknown',
                    'source_state' => $this->sourceState($row),
                    'remote_dispatch_truth' => $this->dispatchTruth($row),
                    'age_bucket' => $bucket,
                    'resolution' => $detail['resolution'],
                    'requires_human_judgment' => $detail['requires_human_judgment'] ? 'YES' : 'NO',
                ];
            }
        }
        ksort($byClass);
        ksort($byType);
        ksort($byAccount);
        ksort($reviewClassCounts);
        ksort($ageMatrix);
        foreach ($ageMatrix as &$bucketCounts) {
            $bucketCounts += ['<1h' => 0, '1-6h' => 0, '6-24h' => 0, '1-7d' => 0, '7-30d' => 0, '>30d' => 0];
            $bucketCounts = array_intersect_key($bucketCounts, ['<1h' => 0, '1-6h' => 0, '6-24h' => 0, '1-7d' => 0, '7-30d' => 0, '>30d' => 0]);
        }
        unset($bucketCounts);
        $total = count($rows);
        $percent = [];
        foreach ($reviewClassCounts as $class => $count) {
            $percent[$class] = $total > 0 ? round(($count * 100) / $total, 2) : 0.0;
        }
        $dominantClass = 'NONE';
        $dominantPercent = 0.0;
        foreach ($percent as $class => $value) {
            if ($value > $dominantPercent) {
                $dominantClass = (string) $class;
                $dominantPercent = (float) $value;
            }
        }
        $unknownReason = 0;
        foreach ($rows as $row) {
            if (trim((string) ($row['last_error_class'] ?? '')) === '') {
                $unknownReason++;
            }
        }
        $noPath = 0;
        foreach ($resolutionPaths as $path) {
            if (($path['current_resolution_path'] ?? 'NO') !== 'YES') {
                $noPath += $reviewClassCounts[(string) ($path['class'] ?? '')] ?? 0;
            }
        }

        return [
            'total' => $total,
            'by_failure_class' => $byClass,
            'by_job_type' => $byType,
            'by_account' => $byAccount,
            'oldest' => $oldest,
            'newest' => $newest,
            'recoverable_count' => $recoverable,
            'functional_count' => $functional,
            'ambiguous_count' => $ambiguous,
            'review_class_counts' => $reviewClassCounts,
            'review_class_percent' => $percent,
            'review_age_matrix' => $ageMatrix,
            'dominant_review_class' => $dominantClass,
            'dominant_review_class_percent' => $dominantPercent,
            'requires_human_counts' => $requiresHuman + ['YES' => 0, 'NO' => 0],
            'mechanically_resolvable_counts' => $mechanical,
            'review_with_unknown_reason' => $unknownReason,
            'review_with_no_resolution_path' => $noPath,
            'current_review_ui' => '/settings/cron#queue-v4-review-title',
            'current_review_actions' => $resolutionPaths,
            'class_samples' => $samples,
            'rows_left_for_real_human_review' => (int) ($requiresHuman['YES'] ?? 0),
        ];
    }

    /**
     * Recupera un único job exacto. Sólo acepta intentos cuya evidencia
     * completa sea un aplazamiento no-fallido conocido.
     *
     * @return array<string,mixed>
     */
    public function recoverExact(int $companyId, int $accountId, int $jobId, string $nextSafeAt): array
    {
        if ($companyId < 1 || $accountId < 1 || $jobId < 1) {
            throw new RuntimeException('queue_v4_review_recovery_identity_invalid');
        }
        $timestamp = strtotime(trim($nextSafeAt) . ' UTC');
        if ($timestamp === false || $timestamp < time()) {
            throw new RuntimeException('queue_v4_review_recovery_next_safe_at_invalid');
        }
        $this->pdo->beginTransaction();
        try {
            $jobStmt = $this->pdo->prepare(
                "SELECT j.id,j.state,j.attempt_count,j.last_error_class,j.available_at
                 FROM queue_v4_clean_jobs j
                 INNER JOIN meli_accounts a
                   ON a.company_id=j.company_id AND a.id=j.meli_account_id
                 WHERE j.id=? AND j.company_id=? AND j.meli_account_id=?
                   AND j.state IN ('review','waiting')
                 FOR UPDATE"
            );
            $jobStmt->execute([$jobId, $companyId, $accountId]);
            $job = $jobStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($job)
                && (string)$job['state'] === 'waiting'
                && hash_equals('capacity_recovery', (string)$job['last_error_class'])) {
                $this->pdo->commit();
                return [
                    'ok' => true,
                    'company_id' => $companyId,
                    'meli_account_id' => $accountId,
                    'job_id' => $jobId,
                    'state' => 'waiting',
                    'next_safe_at' => (string)$job['available_at'],
                    'idempotent_replay' => true,
                ];
            }
            if (!is_array($job) || $this->classification((string) $job['last_error_class']) !== 'NON_FAILURE_TECHNICAL') {
                throw new RuntimeException('queue_v4_review_recovery_not_proven');
            }
            $attemptStmt = $this->pdo->prepare(
                'SELECT error_class FROM queue_v4_clean_attempts
                 WHERE job_id=? AND company_id=? AND meli_account_id=? ORDER BY id FOR UPDATE'
            );
            $attemptStmt->execute([$jobId, $companyId, $accountId]);
            $attempts = $attemptStmt->fetchAll(PDO::FETCH_COLUMN);
            if ($attempts === [] || count($attempts) !== (int) $job['attempt_count']) {
                throw new RuntimeException('queue_v4_review_recovery_attempt_authority_incomplete');
            }
            foreach ($attempts as $errorClass) {
                if ($this->classification((string) $errorClass) !== 'NON_FAILURE_TECHNICAL') {
                    throw new RuntimeException('queue_v4_review_recovery_attempt_mixed');
                }
            }
            $update = $this->pdo->prepare(
                "UPDATE queue_v4_clean_jobs
                 SET state='waiting',attempt_count=0,available_at=?,last_error_class='capacity_recovery',
                     lease_owner=NULL,lease_expires_at=NULL,completed_at=NULL
                 WHERE id=? AND company_id=? AND meli_account_id=? AND state='review'
                   AND attempt_count=?"
            );
            $update->execute([gmdate('Y-m-d H:i:s', $timestamp), $jobId, $companyId, $accountId, (int) $job['attempt_count']]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_review_recovery_cas_lost');
            }
            $this->pdo->commit();
            return [
                'ok' => true,
                'company_id' => $companyId,
                'meli_account_id' => $accountId,
                'job_id' => $jobId,
                'state' => 'waiting',
                'next_safe_at' => gmdate('Y-m-d H:i:s', $timestamp),
                'idempotent_replay' => false,
            ];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Recuperacion focalizada para punteros historicos de descubrimiento de
     * pack que quedaron en review por domain_source_error mientras su fuente
     * canonica volvio a estar pendiente. No adjudica exito: solo devuelve el
     * puntero existente a la cola normal, preservando intentos y evidencias.
     *
     * @return array<string,mixed>
     */
    public function recoverPackSourcePendingAfterDomainSourceError(int $companyId, int $accountId, int $jobId): array
    {
        if ($companyId < 1 || $accountId < 1 || $jobId < 1) {
            throw new RuntimeException('queue_v4_pack_review_recovery_identity_invalid');
        }

        $this->pdo->beginTransaction();
        try {
            $jobStmt = $this->pdo->prepare(
                "SELECT *
                   FROM queue_v4_clean_jobs
                  WHERE id=? AND company_id=? AND meli_account_id=?
                    AND job_type='domain_exact'
                  FOR UPDATE"
            );
            $jobStmt->execute([$jobId, $companyId, $accountId]);
            $job = $jobStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($job)) {
                throw new RuntimeException('queue_v4_pack_review_recovery_job_missing');
            }

            if ((string) ($job['state'] ?? '') === 'ready'
                && (string) ($job['last_error_class'] ?? '') === 'domain_source_recovered:order_enrichment_pack') {
                $this->pdo->commit();
                return [
                    'ok' => true,
                    'company_id' => $companyId,
                    'meli_account_id' => $accountId,
                    'job_id' => $jobId,
                    'state' => 'ready',
                    'idempotent_replay' => true,
                ];
            }

            $jobState = (string) ($job['state'] ?? '');
            $jobError = (string) ($job['last_error_class'] ?? '');
            $isReviewDomainSourceError = $jobState === 'review' && $jobError === 'domain_source_error';
            $isDueWaitingRhythm = $jobState === 'waiting' && $jobError === 'domain_source_waiting:order_enrichment_pack:waiting_rhythm';
            if (!$isReviewDomainSourceError && !$isDueWaitingRhythm) {
                throw new RuntimeException('queue_v4_pack_review_recovery_not_domain_source_error');
            }
            if ((int) ($job['attempt_count'] ?? 0) >= (int) ($job['max_attempts'] ?? 0)) {
                throw new RuntimeException('queue_v4_pack_review_recovery_attempts_exhausted');
            }
            if ($isDueWaitingRhythm && !$this->safeDateDue($job['available_at'] ?? null)) {
                throw new RuntimeException('queue_v4_pack_review_recovery_not_due');
            }

            $payload = json_decode((string) ($job['payload_json'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($payload)
                || (string) ($payload['capability'] ?? '') !== 'order_enrichment_pack'
                || (int) ($payload['source_id'] ?? 0) < 1
                || (string) ((int) $payload['source_id']) !== (string) ($job['resource_id'] ?? '')) {
                throw new RuntimeException('queue_v4_pack_review_recovery_payload_invalid');
            }
            $sourceId = (int) $payload['source_id'];

            $sourceStmt = $this->pdo->prepare(
                'SELECT j.*,a.company_id
                   FROM order_resource_enrichment_jobs j
                   INNER JOIN meli_accounts a ON a.id=j.meli_account_id
                  WHERE j.id=? AND j.meli_account_id=? AND j.resource_type="pack"
                    AND a.company_id=?
                  FOR UPDATE'
            );
            $sourceStmt->execute([$sourceId, $accountId, $companyId]);
            $source = $sourceStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($source)) {
                throw new RuntimeException('queue_v4_pack_review_recovery_source_missing');
            }

            $packId = trim((string) (($payload['payload']['pack_id'] ?? '') ?: ($payload['pack_id'] ?? '')));
            if ($packId !== '' && !hash_equals((string) ($source['external_resource_id'] ?? ''), $packId)) {
                throw new RuntimeException('queue_v4_pack_review_recovery_source_mismatch');
            }
            $sourceStatus = strtolower((string) ($source['status'] ?? ''));
            if (!in_array($sourceStatus, ['pending', 'retry'], true)) {
                throw new RuntimeException('queue_v4_pack_review_recovery_source_not_pending');
            }
            $sourceFailure = trim((string) ($source['failure_class'] ?? ''));
            if ($isReviewDomainSourceError && $sourceFailure !== '') {
                throw new RuntimeException('queue_v4_pack_review_recovery_source_failure_present');
            }
            if ($isDueWaitingRhythm
                && ($sourceStatus !== 'retry'
                    || $sourceFailure !== 'waiting_rhythm'
                    || !$this->safeDateDue($source['next_run_at'] ?? null))) {
                throw new RuntimeException('queue_v4_pack_review_recovery_waiting_rhythm_not_due');
            }
            if ($this->activeSourceLease($source)
                || $this->activeQueueLease($job)
                || $this->activeManualReservation($companyId, $accountId, $sourceId)) {
                throw new RuntimeException('queue_v4_pack_review_recovery_active_guard');
            }
            if ($this->hasUnresolvedAttempt($companyId, $accountId, $jobId)
                || $this->hasUnresolvedTransport($companyId, $accountId, $jobId)) {
                throw new RuntimeException('queue_v4_pack_review_recovery_uncertain_transport');
            }
            $localAttemptId = $isDueWaitingRhythm
                ? $this->localWaitingRhythmAttemptId($companyId, $accountId, $jobId, (int) ($job['lease_generation'] ?? 0), $jobError)
                : $this->localDomainSourceErrorAttemptId($companyId, $accountId, $jobId, (int) ($job['lease_generation'] ?? 0));
            if ($localAttemptId < 1) {
                throw new RuntimeException('queue_v4_pack_review_recovery_attempt_evidence_missing');
            }
            if ($this->hasContradictoryTransportForAttempt($companyId, $accountId, $jobId, (int) ($job['lease_generation'] ?? 0), $localAttemptId)) {
                throw new RuntimeException('queue_v4_pack_review_recovery_contradictory_transport');
            }

            $update = $this->pdo->prepare(
                "UPDATE queue_v4_clean_jobs
                    SET state='ready',
                        available_at=UTC_TIMESTAMP(3),
                        lease_owner=NULL,
                        lease_expires_at=NULL,
                        completed_at=NULL,
                        last_error_class=?
                  WHERE id=? AND company_id=? AND meli_account_id=?
                    AND state=?
                    AND last_error_class=?
                    AND lease_generation=?
                    AND attempt_count=?"
            );
            $update->execute([
                $isDueWaitingRhythm
                    ? 'domain_source_recovered:order_enrichment_pack:waiting_rhythm'
                    : 'domain_source_recovered:order_enrichment_pack',
                $jobId,
                $companyId,
                $accountId,
                $jobState,
                $jobError,
                (int) ($job['lease_generation'] ?? 0),
                (int) ($job['attempt_count'] ?? 0),
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_pack_review_recovery_cas_lost');
            }

            $this->pdo->commit();
            return [
                'ok' => true,
                'company_id' => $companyId,
                'meli_account_id' => $accountId,
                'job_id' => $jobId,
                'source_id' => $sourceId,
                'state' => 'ready',
                'idempotent_replay' => false,
            ];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function classification(string $errorClass): string
    {
        $normalized = strtolower(trim($errorClass));
        if (str_starts_with($normalized, 'rate_limit_deferred:')
            || str_starts_with($normalized, 'capacity_deferred:')
            || in_array($normalized, self::RECOVERABLE, true)) {
            return 'NON_FAILURE_TECHNICAL';
        }
        if ($normalized === '' || $normalized === 'meliapiexception' || $normalized === 'unknown') {
            return 'AMBIGUOUS';
        }
        return 'FUNCTIONAL_ERROR';
    }

    /** @return list<array<string,mixed>> */
    private function reviewRows(): array
    {
        return $this->pdo->query(
            "SELECT j.id,j.company_id,j.meli_account_id,j.job_type,j.resource_id,j.payload_json,j.state,
                    j.last_error_class,j.created_at,j.updated_at,
                    TIMESTAMPDIFF(SECOND,j.updated_at,UTC_TIMESTAMP()) age_seconds,
                    a1.outcome last_attempt_outcome,a1.error_class last_attempt_error,
                    a1.dispatch_state,a1.http_status,a1.physical_http_calls,
                    a1.physical_started_at,a1.response_known_at
             FROM queue_v4_clean_jobs j
             INNER JOIN meli_accounts a
               ON a.company_id=j.company_id AND a.id=j.meli_account_id
             LEFT JOIN queue_v4_clean_attempts a1
               ON a1.id=(
                    SELECT MAX(a2.id) FROM queue_v4_clean_attempts a2
                    WHERE a2.job_id=j.id
                      AND a2.company_id=j.company_id
                      AND a2.meli_account_id=j.meli_account_id
               )
             WHERE j.state='review'
             ORDER BY j.updated_at ASC,j.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param array<string,mixed> $row @return array{class:string,requires_human_judgment:bool,resolution:array<string,mixed>} */
    private function reviewClass(array $row): array
    {
        $error = strtolower(trim((string) (($row['last_error_class'] ?? '') ?: ($row['last_attempt_error'] ?? ''))));
        $http = (int) ($row['http_status'] ?? 0);
        $capability = $this->capability($row);
        $class = 'MANUAL_OPERATOR_DECISION';
        $human = true;
        if ($error === '' || $error === 'unknown' || $error === 'meliapiexception') {
            $class = 'MANUAL_OPERATOR_DECISION';
        } elseif (str_contains($error, 'uncertain') || str_contains($error, 'timeout') || str_contains($error, 'curl')) {
            $class = 'REMOTE_RESULT_UNCERTAIN';
        } elseif ($http === 401 || $http === 403 || str_contains($error, 'oauth') || str_contains($error, 'forbidden') || str_contains($error, 'unauthorized')) {
            $class = 'AUTH_ACCOUNT_ACTION_REQUIRED';
        } elseif (str_contains($error, 'invalid') || str_contains($error, 'payload') || str_contains($error, 'idempotency') || str_contains($error, 'input')) {
            $class = 'INVALID_INPUT';
        } elseif (str_contains($error, 'source_missing') || str_contains($error, 'source_terminal') || str_contains($error, 'tenant') || str_contains($error, 'contradiction')) {
            $class = 'DOMAIN_DATA_CONTRADICTION';
        } elseif (str_starts_with($error, 'rate_limit_deferred:') || str_starts_with($error, 'capacity_deferred:') || in_array($error, self::RECOVERABLE, true)) {
            $class = 'SAFE_AUTOMATIC_RECHECK_POSSIBLE';
            $human = false;
        } elseif (($http >= 400 && $http <= 499) || ($http >= 500 && $http <= 599) || str_contains($error, 'remote')) {
            $class = 'REMOTE_TERMINAL_ERROR';
        } elseif (str_contains($error, 'legacy')) {
            $class = 'LEGACY_UNRESOLVABLE';
        }

        $resolution = $this->resolutionFor($class, $capability);
        $resolution['class'] = $class;
        return ['class' => $class, 'requires_human_judgment' => $human, 'resolution' => $resolution];
    }

    /** @return array<string,mixed> */
    private function resolutionFor(string $class, string $capability): array
    {
        $route = '/settings/cron';
        $service = self::class;
        $actions = match ($class) {
            'REMOTE_RESULT_UNCERTAIN' => ['confirmar resultado remoto con evidencia', 'reconciliar con GET seguro si existe autoridad', 'no reintentar escritura a ciegas'],
            'REMOTE_TERMINAL_ERROR' => ['inspeccionar causa remota', 'corregir dato/autorización antes de reintentar', 'mantener en revisión si no hay autoridad segura'],
            'AUTH_ACCOUNT_ACTION_REQUIRED' => ['abrir cuenta/OAuth', 'reconectar cuenta', 'reintentar sólo después de autorización válida'],
            'DOMAIN_DATA_CONTRADICTION' => ['inspeccionar fuente exacta', 'corregir dato de dominio', 'reingresar por admisión canónica si aplica'],
            'INVALID_INPUT' => ['corregir input de dominio', 'descartar sólo con evidencia de dato inválido permanente'],
            'SAFE_AUTOMATIC_RECHECK_POSSIBLE' => ['reprogramar con fecha segura mediante recoverExact', 'sin HTTP manual'],
            'LEGACY_UNRESOLVABLE' => ['mover a ledger de deuda legacy', 'no recrear procesadores legacy'],
            default => ['inspección humana', 'decidir retry/cancel/reconcile según evidencia visible'],
        };

        return [
            'current_ui_route' => $route,
            'current_service' => $service,
            'capability' => $capability !== '' ? $capability : 'unknown',
            'available_operator_actions' => $actions,
            'current_resolution_path' => 'YES',
        ];
    }

    /** @param array<string,mixed> $row */
    private function capability(array $row): string
    {
        $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
        return is_array($payload) ? (string) ($payload['capability'] ?? $row['job_type'] ?? '') : (string) ($row['job_type'] ?? '');
    }

    /** @param array<string,mixed> $row */
    private function sourceState(array $row): string
    {
        $capability = $this->capability($row);
        $sourceId = (int) ($row['resource_id'] ?? 0);
        $companyId = (int) ($row['company_id'] ?? 0);
        $accountId = (int) ($row['meli_account_id'] ?? 0);

        if ($sourceId < 1 || $companyId < 1 || $accountId < 1) {
            return 'queue_state:' . (string) ($row['state'] ?? 'review');
        }

        $sql = match ($capability) {
            'financial_reconciliation' => 'SELECT status,next_run_at FROM sale_financial_reconciliation_jobs WHERE id=? AND company_id=? AND meli_account_id=? LIMIT 1',
            'financial_recalc' => 'SELECT status,NULL next_run_at FROM order_financial_recalc_jobs WHERE id=? AND company_id=? AND meli_account_id=? LIMIT 1',
            'notification_work_item' => 'SELECT w.status,w.next_run_at FROM meli_notification_work_items w JOIN meli_accounts a ON a.id=w.meli_account_id WHERE w.id=? AND a.company_id=? AND w.meli_account_id=? LIMIT 1',
            'order_enrichment_pack' => 'SELECT j.status,j.next_run_at FROM order_resource_enrichment_jobs j JOIN meli_accounts a ON a.id=j.meli_account_id WHERE j.id=? AND a.company_id=? AND j.meli_account_id=? LIMIT 1',
            default => null,
        };

        if ($sql === null) {
            return 'queue_state:' . (string) ($row['state'] ?? 'review');
        }

        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute([$sourceId, $companyId, $accountId]);
            $source = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($source)) {
                return 'source_not_found';
            }
            $state = 'source_status:' . (string) ($source['status'] ?? 'unknown');
            $nextRunAt = trim((string) ($source['next_run_at'] ?? ''));
            return $nextRunAt !== '' ? $state . ';next_run_at=' . $nextRunAt : $state;
        } catch (Throwable) {
            return 'source_state_not_proven';
        }
    }

    /** @param array<string,mixed> $row */
    private function dispatchTruth(array $row): string
    {
        $state = (string) ($row['dispatch_state'] ?? '');
        $http = $row['http_status'] ?? null;
        if ($state === '') {
            return 'NOT_PROVEN';
        }
        return $state . ($http !== null && $http !== '' ? ':HTTP_' . (string) $http : '');
    }

    /** @param array<string,mixed> $row */
    private function activeQueueLease(array $row): bool
    {
        if (trim((string) ($row['lease_owner'] ?? '')) === '') {
            return false;
        }
        $expires = strtotime((string) ($row['lease_expires_at'] ?? '') . ' UTC');
        return $expires === false || $expires > time();
    }

    /** @param array<string,mixed> $row */
    private function activeSourceLease(array $row): bool
    {
        if (trim((string) ($row['lock_token'] ?? '')) === '') {
            return false;
        }
        $lockedAt = strtotime((string) ($row['locked_at'] ?? '') . ' UTC');
        return $lockedAt === false || $lockedAt > time() - 600;
    }

    private function activeManualReservation(int $companyId, int $accountId, int $sourceId): bool
    {
        if (!$this->tableExists('manual_campaign_reservations') || !$this->tableExists('manual_campaigns')) {
            return false;
        }
        $statement = $this->pdo->prepare(
            "SELECT 1
               FROM manual_campaign_reservations r
               JOIN manual_campaigns c ON c.id=r.manual_campaign_id
              WHERE r.queue_key='order_enrichment'
                AND r.company_id=?
                AND r.meli_account_id=?
                AND r.source_id=?
                AND r.status='active'
                AND r.expires_at>UTC_TIMESTAMP(3)
                AND c.status IN ('active','pausing','paused')
              LIMIT 1 FOR UPDATE"
        );
        $statement->execute([$companyId, $accountId, (string) $sourceId]);

        return $statement->fetchColumn() !== false;
    }

    private function hasUnresolvedAttempt(int $companyId, int $accountId, int $jobId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1
               FROM queue_v4_clean_attempts
              WHERE company_id=? AND meli_account_id=? AND job_id=?
                AND (
                    outcome='running'
                    OR dispatch_state='PHYSICAL_STARTED'
                    OR error_class IN ('remote_result_uncertain','remoteresultuncertainexception','remote_result_uncertain_safe_get')
                )
              LIMIT 1 FOR UPDATE"
        );
        $statement->execute([$companyId, $accountId, $jobId]);

        return $statement->fetchColumn() !== false;
    }

    private function hasUnresolvedTransport(int $companyId, int $accountId, int $jobId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1
               FROM queue_v4_clean_transport_events
              WHERE company_id=? AND meli_account_id=? AND source_kind='queue' AND work_id=?
                AND dispatch_state='PHYSICAL_STARTED' AND response_known_at IS NULL
              LIMIT 1 FOR UPDATE"
        );
        $statement->execute([$companyId, $accountId, $jobId]);

        return $statement->fetchColumn() !== false;
    }

    private function localDomainSourceErrorAttemptId(int $companyId, int $accountId, int $jobId, int $leaseGeneration): int
    {
        $statement = $this->pdo->prepare(
            "SELECT id
               FROM queue_v4_clean_attempts
              WHERE company_id=? AND meli_account_id=? AND job_id=?
                AND lease_generation=?
                AND outcome='review'
                AND error_class='domain_source_error'
                AND dispatch_state='NOT_DISPATCHED'
                AND physical_http_calls=0
                AND physical_started_at IS NULL
                AND response_known_at IS NULL
                AND http_status IS NULL
                AND finished_at IS NOT NULL
                AND source_closed_at IS NOT NULL
              ORDER BY id DESC
              LIMIT 1 FOR UPDATE"
        );
        $statement->execute([$companyId, $accountId, $jobId, $leaseGeneration]);

        return (int) ($statement->fetchColumn() ?: 0);
    }

    private function localWaitingRhythmAttemptId(int $companyId, int $accountId, int $jobId, int $leaseGeneration, string $errorClass): int
    {
        $statement = $this->pdo->prepare(
            "SELECT id
               FROM queue_v4_clean_attempts
              WHERE company_id=? AND meli_account_id=? AND job_id=?
                AND lease_generation=?
                AND outcome='waiting'
                AND error_class=?
                AND dispatch_state='NOT_DISPATCHED'
                AND physical_http_calls=0
                AND physical_started_at IS NULL
                AND response_known_at IS NULL
                AND http_status IS NULL
                AND finished_at IS NOT NULL
                AND source_closed_at IS NOT NULL
              ORDER BY id DESC
              LIMIT 1 FOR UPDATE"
        );
        $statement->execute([$companyId, $accountId, $jobId, $leaseGeneration, $errorClass]);

        return (int) ($statement->fetchColumn() ?: 0);
    }

    private function hasContradictoryTransportForAttempt(int $companyId, int $accountId, int $jobId, int $leaseGeneration, int $attemptId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1
               FROM queue_v4_clean_transport_events
              WHERE company_id=? AND meli_account_id=? AND source_kind='queue' AND work_id=?
                AND lease_generation=?
                AND (
                    attempt_id=?
                    OR attempt_id IS NULL
                )
              LIMIT 1 FOR UPDATE"
        );
        $statement->execute([$companyId, $accountId, $jobId, $leaseGeneration, $attemptId]);

        return $statement->fetchColumn() !== false;
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1'
        );
        $statement->execute([$table]);

        return $statement->fetchColumn() !== false;
    }

    private function safeDateDue(mixed $value): bool
    {
        $text = trim((string) $value);
        if ($text === '') {
            return false;
        }
        $timestamp = strtotime($text . ' UTC');

        return $timestamp !== false && $timestamp <= time();
    }

    private function ageBucket(int $seconds): string
    {
        return match (true) {
            $seconds < 3600 => '<1h',
            $seconds < 21600 => '1-6h',
            $seconds < 86400 => '6-24h',
            $seconds < 604800 => '1-7d',
            $seconds < 2592000 => '7-30d',
            default => '>30d',
        };
    }
}
