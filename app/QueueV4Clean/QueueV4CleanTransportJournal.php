<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use PDO;
use RuntimeException;

/** Immutable physical-HTTP authority shared by all Queue V4 stages. */
final class QueueV4CleanTransportJournal
{
    /** Process-local authority: retained only between preparation and curl_exec. */
    private static array $prepared = [];
    private static array $preparing = [];

    /** Mark before the first SQL write; a missing INSERT acknowledgement is not a rollback. */
    public static function preparationBegan(string $requestId): void
    {
        self::$preparing[$requestId] = true;
    }
    public static function started(
        PDO $pdo,
        int $companyId,
        int $accountId,
        string $source,
        int $workId,
        ?int $attemptId,
        int $generation,
        string $requestId,
        string $method,
        string $endpointKey,
    ): void {
        if ($companyId < 1 || $accountId < 1 || $workId < 1 || $generation < 1
            || !in_array($source, ['queue', 'oauth', 'sales_audit', 'sales_repair'], true)
            || $requestId === '' || !in_array($method, ['GET', 'POST'], true)
            || $endpointKey === '') {
            throw new RuntimeException('queue_v4_transport_journal_context_invalid');
        }
        $statement = $pdo->prepare(
            'INSERT INTO queue_v4_clean_transport_events
             (company_id,meli_account_id,source_kind,work_id,attempt_id,lease_generation,
              request_id,method,endpoint_key,physical_started_at)
             VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(3))'
        );
        $statement->execute([
            $companyId, $accountId, $source, $workId, $attemptId, $generation,
            $requestId, $method, $endpointKey,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('queue_v4_transport_journal_start_failed');
        }
        self::$prepared[$requestId] = [
            'id' => (int) $pdo->lastInsertId(), 'company' => $companyId, 'account' => $accountId,
            'source' => $source, 'work' => $workId, 'attempt' => $attemptId, 'generation' => $generation,
            'meta' => \App\Services\ApiExecutionMetadataContext::current(),
        ];
    }

    /** Once execution is entered, even a missing response is never refundable. */
    public static function enteringCurl(string $requestId): void
    {
        unset(self::$prepared[$requestId], self::$preparing[$requestId]);
    }

    /** A successful rollback proves that this provisional insert never became durable. */
    public static function preparationRolledBack(string $requestId): void
    {
        unset(self::$prepared[$requestId], self::$preparing[$requestId]);
    }

    /** A lost COMMIT acknowledgement is not proof of zero; certify cancellation or stop uncertain. */
    public static function preparationFailed(array $meta): void
    {
        $request = (string) ($meta['transport_request_id'] ?? '');
        $began = isset(self::$preparing[$request]);
        unset(self::$preparing[$request]);
        if (!isset(self::$prepared[$request])) {
            if ($began) { throw new \App\Services\RemoteResultUncertainException($request); }
            QueueV4CleanCycleBudget::releaseBeforeTransport();
            return;
        }
        try { $cancelled = self::cancelBeforeCurl(\App\Core\Database::connectionFresh(), $meta); }
        catch (\Throwable) { $cancelled = false; }
        if (!$cancelled) { throw new \App\Services\RemoteResultUncertainException($request); }
    }

    /** Undo only this process's provisional marker; genuine physical events stay immutable. */
    public static function cancelBeforeCurl(PDO $pdo, array $meta): bool
    {
        $request = (string) ($meta['transport_request_id'] ?? '');
        $p = self::$prepared[$request] ?? null;
        unset(self::$prepared[$request], self::$preparing[$request]); // exactly one certification attempt, including failure
        if ($p === null || $p['meta'] !== $meta || $pdo->inTransaction()) { return false; }
        $pdo->beginTransaction();
        try {
            $identity = [$p['work'], $p['company'], $p['account']];
            if ($p['source'] === 'queue') {
                $stmt = $pdo->prepare("UPDATE queue_v4_clean_attempts a
                    INNER JOIN queue_v4_clean_jobs j ON j.id=a.job_id AND j.company_id=a.company_id AND j.meli_account_id=a.meli_account_id
                    SET a.dispatch_state='NOT_DISPATCHED',a.physical_http_calls=0,a.physical_started_at=NULL,
                        a.error_class='cancelled_before_remote'
                    WHERE a.job_id=? AND a.company_id=? AND a.meli_account_id=? AND a.id=?
                      AND a.lease_owner=? AND a.lease_generation=? AND a.outcome='running'
                      AND a.dispatch_state='PHYSICAL_STARTED' AND a.response_known_at IS NULL
                      AND j.state='running' AND j.lease_owner=a.lease_owner AND j.lease_generation=a.lease_generation");
                $stmt->execute([...$identity,$p['attempt'],(string)($meta['queue_v4_lease_owner']??''),$p['generation']]);
            } elseif ($p['source'] === 'oauth') {
                $stmt=$pdo->prepare("UPDATE oauth_refresh_operations
                    SET remote_dispatch_state='NOT_DISPATCHED',remote_dispatched_at=NULL,
                        remote_attempt_count=remote_attempt_count-1,last_error_class='cancelled_before_remote'
                    WHERE id=? AND company_id=? AND meli_account_id=? AND lease_owner=? AND lease_generation=?
                      AND state='RUNNING' AND remote_dispatch_state='MAY_HAVE_DISPATCHED'
                      AND response_known_at IS NULL AND last_request_id=? AND remote_attempt_count>0");
                $stmt->execute([...$identity,(string)($meta['oauth_lease_owner']??''),$p['generation'],$request]);
            } elseif ($p['source'] === 'sales_audit') {
                $stmt=$pdo->prepare("UPDATE sync_sales_audit_jobs
                    SET remote_dispatch_state='NOT_DISPATCHED',remote_dispatched_at=NULL,last_error_class='cancelled_before_remote'
                    WHERE id=? AND company_id=? AND meli_account_id=? AND locked_by=? AND lease_generation=?
                      AND status='running' AND remote_dispatch_state='PHYSICAL_STARTED'
                      AND response_known_at IS NULL AND last_request_id=?");
                $stmt->execute([...$identity,(string)($meta['sales_audit_lease_owner']??''),$p['generation'],$request]);
            } else {
                $stmt=$pdo->prepare("UPDATE sync_sales_repair_job_items i
                    INNER JOIN sync_sales_repair_jobs j ON j.id=i.sync_sales_repair_job_id
                    SET i.error_message=?
                    WHERE j.id=? AND j.company_id=? AND j.meli_account_id=? AND i.id=?
                      AND j.status='running' AND i.status='running' AND j.source_kind='exact'
                      AND j.lock_owner=? AND j.lease_generation=?");
                $stmt->execute(['cancelled_before_remote:' . $request,...$identity,$p['attempt'],(string)($meta['sales_repair_lease_owner']??''),$p['generation']]);
            }
            if ($stmt->rowCount() !== 1) { throw new RuntimeException('queue_v4_cancel_owner_lost'); }
            $event=$pdo->prepare("DELETE FROM queue_v4_clean_transport_events
                WHERE id=? AND company_id=? AND meli_account_id=? AND source_kind=? AND work_id=?
                  AND attempt_id <=> ? AND lease_generation=? AND request_id=?
                  AND dispatch_state='PHYSICAL_STARTED' AND response_known_at IS NULL AND http_status IS NULL");
            $event->execute([$p['id'],$p['company'],$p['account'],$p['source'],$p['work'],$p['attempt'],$p['generation'],$request]);
            if ($event->rowCount() !== 1) { throw new RuntimeException('queue_v4_cancel_event_lost'); }
            $pdo->commit();
            QueueV4CleanCycleBudget::releaseBeforeTransport();
            return true;
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $error;
        }
    }

    public static function responseKnown(
        PDO $pdo,
        int $companyId,
        int $accountId,
        string $source,
        string $requestId,
        int $status,
    ): void {
        $statement = $pdo->prepare(
            "UPDATE queue_v4_clean_transport_events
             SET dispatch_state='RESPONSE_KNOWN',response_known_at=UTC_TIMESTAMP(3),http_status=?
             WHERE company_id=? AND meli_account_id=? AND source_kind=? AND request_id=?
               AND dispatch_state='PHYSICAL_STARTED'"
        );
        $statement->execute([
            max(0, min(999, $status)), $companyId, $accountId, $source, $requestId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('queue_v4_transport_journal_response_failed');
        }
    }
}
