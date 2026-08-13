<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use PDO;
use RuntimeException;
use Throwable;

/** Requeues at most one historical read-only uncertain attempt per CLI cycle. */
final class QueueV4CleanUncertainReadRecoveryService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array{eligible:int,recovered:int,blocked:int} */
    public function recoverOne(): array
    {
        $this->pdo->beginTransaction();
        try {
            $row = $this->pdo->query(
                "SELECT j.id job_id,j.company_id,j.meli_account_id,j.job_type,j.resource_id,j.payload_json,
                        j.attempt_count,a.id attempt_id,a.dispatch_state,a.error_class
                 FROM queue_v4_clean_jobs j
                 INNER JOIN queue_v4_clean_attempts a
                   ON a.id=(SELECT MAX(latest.id) FROM queue_v4_clean_attempts latest
                            WHERE latest.job_id=j.id AND latest.company_id=j.company_id
                              AND latest.meli_account_id=j.meli_account_id)
                 LEFT JOIN queue_v4_clean_recovery_events e
                   ON e.job_id=j.id AND e.attempt_id=a.id
                  AND e.company_id=j.company_id AND e.meli_account_id=j.meli_account_id
                 WHERE j.state='review' AND j.job_type IN ('fresh_orders_discovery','order_exact')
                   AND j.last_error_class IN ('remoteresultuncertainexception','remote_result_uncertain')
                   AND a.outcome='review' AND e.id IS NULL
                 ORDER BY j.id LIMIT 1 FOR UPDATE"
            )->fetchAll(PDO::FETCH_ASSOC);
            if ($row === []) {
                $this->pdo->commit();
                return ['eligible' => 0, 'recovered' => 0, 'blocked' => 0];
            }
            $job = $row[0];
            try {
                $this->assertReadOnlyPayload($job);
                $companyId = (int) $job['company_id'];
                $accountId = (int) $job['meli_account_id'];
                $tenant = $this->pdo->prepare('SELECT 1 FROM meli_accounts WHERE company_id=? AND id=? LIMIT 1');
                $tenant->execute([$companyId, $accountId]);
                if ($tenant->fetchColumn() === false) {
                    throw new RuntimeException('queue_v4_clean_uncertain_recovery_tenant_mismatch');
                }
            } catch (RuntimeException) {
                // Malformed historical evidence remains untouched for review,
                // but it must not abort OAuth, sales or ordinary queue work.
                $this->pdo->commit();
                return ['eligible' => 1, 'recovered' => 0, 'blocked' => 1];
            }
            $event = $this->pdo->prepare(
                'INSERT INTO queue_v4_clean_recovery_events
                 (job_id,attempt_id,company_id,meli_account_id,recovery_class,observed_dispatch_state)
                 VALUES (?,?,?,?,?,?)'
            );
            $event->execute([
                (int) $job['job_id'], (int) $job['attempt_id'], $companyId, $accountId,
                'historical_idempotent_get', (string) $job['dispatch_state'],
            ]);
            $update = $this->pdo->prepare(
                "UPDATE queue_v4_clean_jobs
                 SET state='waiting',available_at=UTC_TIMESTAMP(3),attempt_count=GREATEST(attempt_count-1,0),
                     last_error_class='remote_result_uncertain_safe_get_recovered',completed_at=NULL
                 WHERE id=? AND company_id=? AND meli_account_id=? AND state='review'
                   AND last_error_class IN ('remoteresultuncertainexception','remote_result_uncertain')"
            );
            $update->execute([(int) $job['job_id'], $companyId, $accountId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_clean_uncertain_recovery_cas_lost');
            }
            $this->pdo->commit();
            return ['eligible' => 1, 'recovered' => 1, 'blocked' => 0];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $row */
    private function assertReadOnlyPayload(array $row): void
    {
        $payload = json_decode((string) ($row['payload_json'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
        if ((string) $row['job_type'] === 'order_exact') {
            $id = trim((string) ($payload['order_id'] ?? $row['resource_id'] ?? ''));
            if ($id === '' || !ctype_digit($id)) {
                throw new RuntimeException('queue_v4_clean_uncertain_recovery_payload_invalid');
            }
            return;
        }
        foreach (['from', 'to'] as $key) {
            if (trim((string) ($payload[$key] ?? '')) === '') {
                throw new RuntimeException('queue_v4_clean_uncertain_recovery_payload_invalid');
            }
        }
    }
}
