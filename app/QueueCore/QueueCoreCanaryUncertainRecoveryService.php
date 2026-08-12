<?php

declare(strict_types=1);

namespace App\QueueCore;

use PDO;
use Throwable;

/**
 * Recovers only a canary-v4 GET whose physical response remained unknown.
 *
 * The remote call is never represented as successful. The immutable attempt
 * and dispatch journal remain uncertain; only the current job projection is
 * returned to a retryable state because repeating a GET cannot duplicate a
 * remote mutation. Any non-GET, persistence, foreign launcher or mixed
 * authority blocks the whole recovery transaction.
 */
final class QueueCoreCanaryUncertainRecoveryService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function inspect(): array
    {
        $jobs = $this->uncertainJobs(false);
        return $this->inspection($jobs, false);
    }

    /** @return array<string,mixed> */
    public function recover(int $actorUserId): array
    {
        if ($actorUserId < 1) {
            throw new \RuntimeException('canary_uncertain_recovery_admin_required');
        }
        $this->pdo->beginTransaction();
        try {
            $jobs = $this->uncertainJobs(true);
            $inspection = $this->inspection($jobs, true);
            if ($jobs === []) {
                $this->pdo->commit();
                return array_merge($inspection, [
                    'ok' => false,
                    'state' => 'nothing_to_recover',
                    'recovered' => 0,
                    'technical_rows_changed' => 0,
                ]);
            }
            if (empty($inspection['recoverable'])) {
                $this->pdo->rollBack();
                return array_merge($inspection, [
                    'ok' => false,
                    'state' => 'blocked',
                    'recovered' => 0,
                    'technical_rows_changed' => 0,
                ]);
            }

            $recovered = 0;
            foreach ($jobs as $job) {
                $update = $this->pdo->prepare(
                    "UPDATE queue_core_jobs
                     SET state='retry_wait',dispatch_state='NOT_DISPATCHED',
                         available_at=UTC_TIMESTAMP(3),next_attempt_at=UTC_TIMESTAMP(3),
                         completed_at=NULL,last_error_class='canary_get_result_uncertain_requeued',
                         last_http_status=NULL,lease_owner=NULL,lease_expires_at=NULL,
                         lease_heartbeat_at=NULL,lease_generation=lease_generation+1,
                         updated_at=UTC_TIMESTAMP(3)
                     WHERE id=? AND company_id=? AND meli_account_id=?
                       AND queue_domain='operational' AND state='review'
                       AND dispatch_state='DISPATCHED_RESULT_UNCERTAIN'
                       AND last_error_class='remote_result_uncertain'
                       AND lease_owner IS NULL AND attempt_count<max_attempts"
                );
                $update->execute([
                    (int) $job['id'],
                    (int) $job['company_id'],
                    (int) $job['meli_account_id'],
                ]);
                if ($update->rowCount() !== 1) {
                    throw new \RuntimeException('canary_uncertain_recovery_cas_miss');
                }
                $event = $this->pdo->prepare(
                    "INSERT INTO queue_core_events
                     (job_id,company_id,meli_account_id,lane,event_type,event_count,resources_count)
                     VALUES (?,?,?,?, 'recovered',1,0)"
                );
                $event->execute([
                    (int) $job['id'],
                    (int) $job['company_id'],
                    (int) $job['meli_account_id'],
                    (string) $job['lane'],
                ]);
                if ($event->rowCount() !== 1) {
                    throw new \RuntimeException('canary_uncertain_recovery_event_miss');
                }
                $recovered++;
            }
            $receipt = [
                'operation' => 'queue_core_canary_get_uncertain_recovery',
                'actor_user_id' => $actorUserId,
                'job_id' => (int) $jobs[0]['id'],
                'company_id' => (int) $jobs[0]['company_id'],
                'meli_account_id' => (int) $jobs[0]['meli_account_id'],
                'result' => 'recovered_to_retry_wait',
                'recovered_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
            $receiptJson = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $audit = $this->pdo->prepare(
                "INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group)
                 VALUES ('queue_core.v4.canary_uncertain_recovery.last_receipt',?,0,'queue_core')
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=0,
                                         setting_group='queue_core'"
            );
            $audit->execute([$receiptJson]);
            $this->pdo->commit();
            return array_merge($inspection, [
                'ok' => true,
                'state' => 'recovered',
                'recovered' => $recovered,
                'technical_rows_changed' => $recovered * 2 + 1,
                'receipt_sha256' => hash('sha256', $receiptJson),
            ]);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    private function uncertainJobs(bool $forUpdate): array
    {
        $sql = "SELECT id,company_id,meli_account_id,work_type,lane,queue_domain,state,
                       dispatch_state,attempt_count,max_attempts,lease_owner,lease_expires_at,
                       lease_generation,lease_heartbeat_at,next_attempt_at,
                       last_error_class,last_http_status,completed_at
                FROM queue_core_jobs
                WHERE dispatch_state='DISPATCHED_RESULT_UNCERTAIN'
                ORDER BY id";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<array<string,mixed>> $jobs
     * @return array<string,mixed>
     */
    private function inspection(array $jobs, bool $forUpdate): array
    {
        $engine = $this->engineAuthority($forUpdate);
        $eligible = 0;
        $blockers = [];
        foreach ($jobs as $job) {
            $reason = $this->ineligibleReason($job, $engine, $forUpdate);
            if ($reason === null) {
                $eligible++;
                continue;
            }
            $blockers[] = [
                'job_id' => (int) ($job['id'] ?? 0),
                'reason' => $reason,
            ];
        }
        return [
            'ok' => true,
            'total_uncertain' => count($jobs),
            'eligible_canary_get' => $eligible,
            // One administrative request repairs at most one canary account.
            // Multiple uncertain jobs need a new, explicit investigation.
            'recoverable' => count($jobs) === 1 && $eligible === 1,
            'engine_authority' => $engine,
            'blockers' => $blockers,
        ];
    }

    /** @return array{ok:bool,generation:int,readiness_mode:string} */
    private function engineAuthority(bool $forUpdate): array
    {
        $sql = "SELECT active_engine,readiness_mode,generation
                FROM queue_engine_control WHERE control_key='primary'";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $row = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        $mode = (string) ($row['readiness_mode'] ?? '');
        $generation = (int) ($row['generation'] ?? -1);
        return [
            'ok' => is_array($row)
                && (string) ($row['active_engine'] ?? '') === 'disabled'
                && in_array($mode, ['idle', 'preparing'], true)
                && $generation >= 1,
            'generation' => $generation,
            'readiness_mode' => $mode,
        ];
    }

    /** @param array<string,mixed> $job @param array{ok:bool,generation:int,readiness_mode:string} $engine */
    private function ineligibleReason(array $job, array $engine, bool $forUpdate): ?string
    {
        if (empty($engine['ok'])) {
            return 'engine_authority_invalid';
        }
        if ((string) ($job['queue_domain'] ?? '') !== 'operational'
            || (string) ($job['state'] ?? '') !== 'review'
            || (string) ($job['last_error_class'] ?? '') !== 'remote_result_uncertain'
            || ($job['lease_owner'] ?? null) !== null
            || ($job['lease_expires_at'] ?? null) !== null
            || ($job['lease_heartbeat_at'] ?? null) !== null
            || ($job['next_attempt_at'] ?? null) !== null
            || ($job['last_http_status'] ?? null) !== null
            || empty($job['completed_at'])
            || (int) ($job['attempt_count'] ?? 0) >= (int) ($job['max_attempts'] ?? 0)) {
            return 'job_authority_invalid';
        }

        $attemptSql =
            "SELECT id,run_id,job_id,company_id,meli_account_id,lease_owner,lease_generation,
                    launcher,outcome,dispatch_state,physical_http_calls,
                    resources_discovered,resources_persisted,error_class,http_status,
                    physical_http_started_at,response_known_at,source_closed_at,finished_at
             FROM queue_core_attempts
             WHERE job_id=? AND company_id=? AND meli_account_id=?
             ORDER BY id DESC LIMIT 1";
        if ($forUpdate) {
            $attemptSql .= ' FOR UPDATE';
        }
        $attempt = $this->pdo->prepare($attemptSql);
        $attempt->execute([
            (int) $job['id'],
            (int) $job['company_id'],
            (int) $job['meli_account_id'],
        ]);
        $attemptRow = $attempt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($attemptRow)
            || (int) ($attemptRow['job_id'] ?? 0) !== (int) $job['id']
            || (int) ($attemptRow['company_id'] ?? 0) !== (int) $job['company_id']
            || (int) ($attemptRow['meli_account_id'] ?? 0) !== (int) $job['meli_account_id']
            || (int) ($attemptRow['lease_generation'] ?? -1) !== (int) $job['lease_generation']
            || (string) ($attemptRow['lease_owner'] ?? '') === ''
            || (string) ($attemptRow['launcher'] ?? '') !== 'canary_v4'
            || (string) ($attemptRow['outcome'] ?? '') !== 'review'
            || (string) ($attemptRow['dispatch_state'] ?? '') !== 'DISPATCHED_RESULT_UNCERTAIN'
            || (int) ($attemptRow['physical_http_calls'] ?? 0) !== 1
            || (int) ($attemptRow['resources_discovered'] ?? 0) !== 0
            || (int) ($attemptRow['resources_persisted'] ?? 0) !== 0
            || (string) ($attemptRow['error_class'] ?? '') !== 'remote_result_uncertain'
            || ($attemptRow['http_status'] ?? null) !== null
            || empty($attemptRow['physical_http_started_at'])
            || ($attemptRow['response_known_at'] ?? null) !== null
            || ($attemptRow['source_closed_at'] ?? null) !== null
            || empty($attemptRow['finished_at'])) {
            return 'attempt_authority_invalid';
        }

        $journalSql =
            'SELECT id,job_id,attempt_id,company_id,meli_account_id,lease_owner,lease_generation,
                    method,endpoint_key,state,http_status
             FROM queue_core_dispatch_journal
             WHERE attempt_id=? AND job_id=? AND company_id=? AND meli_account_id=?
             ORDER BY id';
        if ($forUpdate) {
            $journalSql .= ' FOR UPDATE';
        }
        $journal = $this->pdo->prepare($journalSql);
        $journal->execute([
            (int) $attemptRow['id'],
            (int) $job['id'],
            (int) $job['company_id'],
            (int) $job['meli_account_id'],
        ]);
        $journalRows = $journal->fetchAll(PDO::FETCH_ASSOC);
        $journalRow = $journalRows[0] ?? [];
        $definition = (new QueueCapabilityRegistry())->definition((string) ($job['work_type'] ?? ''));
        if (count($journalRows) !== 1
            || (int) ($journalRow['job_id'] ?? 0) !== (int) $job['id']
            || (int) ($journalRow['attempt_id'] ?? 0) !== (int) $attemptRow['id']
            || (int) ($journalRow['company_id'] ?? 0) !== (int) $job['company_id']
            || (int) ($journalRow['meli_account_id'] ?? 0) !== (int) $job['meli_account_id']
            || (string) ($journalRow['lease_owner'] ?? '') !== (string) $attemptRow['lease_owner']
            || (int) ($journalRow['lease_generation'] ?? -1) !== (int) $attemptRow['lease_generation']
            || strtoupper((string) ($journalRow['method'] ?? '')) !== 'GET'
            || (string) ($journalRow['state'] ?? '') !== 'in_flight'
            || ($journalRow['http_status'] ?? null) !== null
            || !is_array($definition)
            || (string) ($definition['domain'] ?? '') !== 'operational'
            || !in_array('canary_v4', (array) ($definition['launchers'] ?? []), true)
            || (string) ($definition['method'] ?? '') !== 'GET'
            || (string) ($definition['retry'] ?? '') !== 'safe_read'
            || (int) ($definition['max_remote_calls'] ?? 0) !== 1
            || !in_array((string) ($job['lane'] ?? ''), (array) ($definition['lanes'] ?? []), true)
            || @preg_match(
                (string) ($definition['endpoint_pattern'] ?? ''),
                (string) ($journalRow['endpoint_key'] ?? ''),
            ) !== 1) {
            return 'dispatch_authority_invalid';
        }

        $runSql = 'SELECT engine_generation,launcher,worker_ref,status,jobs_claimed,
                          physical_http_calls,known_responses,resources_persisted,finished_at
                   FROM queue_core_runs WHERE id=?';
        if ($forUpdate) {
            $runSql .= ' FOR UPDATE';
        }
        $run = $this->pdo->prepare($runSql);
        $run->execute([(int) ($attemptRow['run_id'] ?? 0)]);
        $runRow = $run->fetch(PDO::FETCH_ASSOC);
        $expectedRunGeneration = $engine['readiness_mode'] === 'preparing'
            ? $engine['generation']
            : $engine['generation'] - 1;
        if (!is_array($runRow)
            || (string) ($runRow['launcher'] ?? '') !== 'canary_v4'
            || !in_array((string) ($runRow['status'] ?? ''), ['stopped', 'failed'], true)
            || (int) ($runRow['engine_generation'] ?? -1) !== $expectedRunGeneration
            || !hash_equals(
                (string) ($runRow['worker_ref'] ?? ''),
                hash('sha256', (string) ($attemptRow['lease_owner'] ?? '')),
            )
            || (int) ($runRow['jobs_claimed'] ?? 0) < 1
            || (int) ($runRow['physical_http_calls'] ?? 0) < 1
            || (int) ($runRow['known_responses'] ?? 0) >= (int) ($runRow['physical_http_calls'] ?? 0)
            || empty($runRow['finished_at'])) {
            return 'run_authority_invalid';
        }

        $aggregate = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT job_id) jobs_claimed,
                    COALESCE(SUM(physical_http_calls),0) physical_http_calls,
                    COALESCE(SUM(response_known_at IS NOT NULL),0) known_responses,
                    COALESCE(SUM(resources_persisted),0) resources_persisted
             FROM queue_core_attempts
             WHERE run_id=? AND company_id=? AND meli_account_id=?'
        );
        $aggregate->execute([
            (int) $attemptRow['run_id'],
            (int) $job['company_id'],
            (int) $job['meli_account_id'],
        ]);
        $measured = $aggregate->fetch(PDO::FETCH_ASSOC) ?: [];
        $foreignScope = $this->pdo->prepare(
            'SELECT COUNT(*) FROM queue_core_attempts
             WHERE run_id=? AND (company_id<>? OR meli_account_id<>?)'
        );
        $foreignScope->execute([
            (int) $attemptRow['run_id'],
            (int) $job['company_id'],
            (int) $job['meli_account_id'],
        ]);
        if ((int) $foreignScope->fetchColumn() !== 0) {
            return 'run_tenant_scope_invalid';
        }
        foreach (['jobs_claimed', 'physical_http_calls', 'known_responses', 'resources_persisted'] as $field) {
            if ((int) ($runRow[$field] ?? -1) !== (int) ($measured[$field] ?? -2)) {
                return 'run_metrics_invalid';
            }
        }
        return null;
    }
}
