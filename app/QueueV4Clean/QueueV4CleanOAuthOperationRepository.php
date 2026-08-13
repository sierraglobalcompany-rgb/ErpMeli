<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use PDO;
use RuntimeException;
use Throwable;

final class QueueV4CleanOAuthOperationRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function schedule(
        int $companyId,
        int $accountId,
        string $meliUserId,
        int $refreshVersion,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO oauth_refresh_operations
             (company_id,meli_account_id,expected_meli_user_id,expected_refresh_version,state,next_attempt_at)
             VALUES (?,?,?,?,"SCHEDULED",UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'
        );
        $statement->execute([$companyId, $accountId, $meliUserId, $refreshVersion]);
    }

    /** @return array<string,mixed>|null */
    public function claimOne(string $owner): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $row = $this->pdo->query(
                "SELECT * FROM oauth_refresh_operations
                 WHERE state IN ('SCHEDULED','WAITING') AND next_attempt_at<=UTC_TIMESTAMP(3)
                   AND (
                     remote_dispatch_state='NOT_DISPATCHED'
                     OR (remote_dispatch_state='RESPONSE_KNOWN' AND last_http_status=429)
                   )
                 ORDER BY next_attempt_at,id LIMIT 1 FOR UPDATE SKIP LOCKED"
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->pdo->commit();
                return null;
            }
            $companyId = (int) $row['company_id'];
            $accountId = (int) $row['meli_account_id'];
            $generation = (int) $row['lease_generation'] + 1;
            $update = $this->pdo->prepare(
                "UPDATE oauth_refresh_operations
                 SET state='RUNNING',lease_owner=?,lease_generation=?,
                     lease_expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 60 SECOND),
                     remote_dispatch_state='NOT_DISPATCHED',remote_dispatched_at=NULL,
                     response_known_at=NULL,last_http_status=NULL,last_request_id=NULL,
                     last_error_class=NULL,completed_at=NULL
                 WHERE id=? AND company_id=? AND meli_account_id=?
                   AND lease_generation=? AND state IN ('SCHEDULED','WAITING')"
            );
            $update->execute([$owner, $generation, (int) $row['id'], $companyId, $accountId, (int) $row['lease_generation']]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_clean_oauth_operation_claim_lost');
            }
            $this->pdo->commit();
            $row['lease_owner'] = $owner;
            $row['lease_generation'] = $generation;
            $row['remote_dispatch_state'] = 'NOT_DISPATCHED';
            return $row;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function wait(array $operation, string $errorClass, ?string $nextAttemptAt): void
    {
        $this->finishRunning(
            $operation,
            "state='WAITING',next_attempt_at=?,last_error_class=?,lease_owner=NULL,lease_expires_at=NULL",
            [$this->safeDate($nextAttemptAt), $this->safeToken($errorClass)]
        );
    }

    public function complete(array $operation, string $result = 'completed'): void
    {
        $this->finishRunning(
            $operation,
            "state='COMPLETED',last_error_class=?,lease_owner=NULL,lease_expires_at=NULL,completed_at=UTC_TIMESTAMP(3)",
            [$this->safeToken($result)]
        );
    }

    public function waitForDurableRecovery(array $operation): void
    {
        $this->finishRunning(
            $operation,
            "state='WAITING',next_attempt_at=UTC_TIMESTAMP(3),last_error_class='durable_recovery_pending',
             remote_dispatch_state='NOT_DISPATCHED',lease_owner=NULL,lease_expires_at=NULL",
            []
        );
    }

    public function terminal(array $operation, string $state, string $errorClass): void
    {
        if (!in_array($state, ['REMOTE_UNCERTAIN', 'RECONNECT_REQUIRED', 'FAILED'], true)) {
            throw new RuntimeException('queue_v4_clean_oauth_terminal_state_invalid');
        }
        $this->finishRunning(
            $operation,
            "state='" . $state . "',last_error_class=?,lease_owner=NULL,lease_expires_at=NULL,completed_at=UTC_TIMESTAMP(3)",
            [$this->safeToken($errorClass)]
        );
    }

    public function repairStale(): int
    {
        $safe = $this->pdo->exec(
            "UPDATE oauth_refresh_operations
             SET state='WAITING',next_attempt_at=UTC_TIMESTAMP(3),lease_owner=NULL,lease_expires_at=NULL,
                 last_error_class='stale_lease_before_dispatch'
             WHERE state='RUNNING' AND lease_expires_at<UTC_TIMESTAMP(3)
               AND remote_dispatch_state='NOT_DISPATCHED'"
        );
        $uncertain = $this->pdo->exec(
            "UPDATE oauth_refresh_operations
             SET state='REMOTE_UNCERTAIN',lease_owner=NULL,lease_expires_at=NULL,
                 last_error_class='stale_lease_after_dispatch',completed_at=UTC_TIMESTAMP(3)
             WHERE state='RUNNING' AND lease_expires_at<UTC_TIMESTAMP(3)
               AND remote_dispatch_state='MAY_HAVE_DISPATCHED'"
        );
        $knownRateLimit = $this->pdo->exec(
            "UPDATE oauth_refresh_operations
             SET state='WAITING',next_attempt_at=UTC_TIMESTAMP(3),lease_owner=NULL,lease_expires_at=NULL,
                 last_error_class='stale_lease_known_429'
             WHERE state='RUNNING' AND lease_expires_at<UTC_TIMESTAMP(3)
               AND remote_dispatch_state='RESPONSE_KNOWN' AND last_http_status=429"
        );
        $knownUncertain = $this->pdo->exec(
            "UPDATE oauth_refresh_operations
             SET state='REMOTE_UNCERTAIN',lease_owner=NULL,lease_expires_at=NULL,
                 last_error_class='stale_lease_known_response',completed_at=UTC_TIMESTAMP(3)
             WHERE state='RUNNING' AND lease_expires_at<UTC_TIMESTAMP(3)
               AND remote_dispatch_state='RESPONSE_KNOWN' AND (last_http_status IS NULL OR last_http_status<>429)"
        );
        return (int) $safe + (int) $uncertain + (int) $knownRateLimit + (int) $knownUncertain;
    }

    /** @return list<array<string,mixed>> */
    public function observability(): array
    {
        return $this->pdo->query(
            "SELECT ra.company_id,ra.meli_account_id,a.account_name,a.status current_status,
                    t.expires_at,t.refresh_version,
                    CASE
                      WHEN a.status NOT IN ('conectado','connected') OR t.meli_account_id IS NULL
                           OR a.meli_user_id='' OR t.refresh_token_encrypted='' OR t.expires_at IS NULL
                        THEN 'RECONNECT_REQUIRED'
                      ELSE COALESCE(o.state,'IDLE')
                    END automatic_refresh_state,
                    o.next_attempt_at,o.last_http_status,o.last_error_class,
                    (SELECT MAX(done.completed_at) FROM oauth_refresh_operations done
                     WHERE done.company_id=ra.company_id AND done.meli_account_id=ra.meli_account_id
                       AND done.state='COMPLETED') last_completed_refresh_at
             FROM queue_v4_clean_readiness_accounts ra
             INNER JOIN queue_v4_clean_readiness_runs rr
               ON rr.id=ra.readiness_run_id AND rr.state='CERTIFIED'
             INNER JOIN meli_accounts a ON a.company_id=ra.company_id AND a.id=ra.meli_account_id
             LEFT JOIN meli_tokens t ON t.meli_account_id=a.id
             LEFT JOIN oauth_refresh_operations o ON o.id=(
                 SELECT MAX(latest.id) FROM oauth_refresh_operations latest
                 WHERE latest.company_id=ra.company_id AND latest.meli_account_id=ra.meli_account_id
             )
             WHERE ra.readiness_run_id=(SELECT MAX(id) FROM queue_v4_clean_readiness_runs WHERE state='CERTIFIED')
               AND ra.outcome='PASS'
             ORDER BY ra.company_id,ra.meli_account_id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function finishRunning(array $operation, string $set, array $parameters): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE oauth_refresh_operations SET ' . $set . '
             WHERE id=? AND company_id=? AND meli_account_id=? AND state="RUNNING"
               AND lease_owner=? AND lease_generation=?'
        );
        $statement->execute(array_merge($parameters, [
            (int) $operation['id'],
            (int) $operation['company_id'],
            (int) $operation['meli_account_id'],
            (string) $operation['lease_owner'],
            (int) $operation['lease_generation'],
        ]));
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('queue_v4_clean_oauth_operation_finish_lost');
        }
    }

    private function safeDate(?string $value): string
    {
        $timestamp = $value === null ? false : strtotime($value . ' UTC');
        return gmdate('Y-m-d H:i:s', $timestamp === false ? time() + 60 : max(time() + 1, $timestamp));
    }

    private function safeToken(string $value): string
    {
        return substr(preg_replace('/[^a-z0-9_]+/', '_', strtolower($value)) ?: 'oauth_wait', 0, 100);
    }
}
