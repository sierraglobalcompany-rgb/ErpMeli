<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use App\Core\Database;
use App\Services\ApiExecutionMetadataContext;
use App\Services\CronDeadlineContext;
use App\Services\MeliTransportSourcePolicy;
use PDO;
use RuntimeException;

/** Durable physical boundary for Queue V4 commercial GETs and sales-audit GETs. */
final class QueueV4CleanDispatchFence
{
    public static function immediatelyBeforeCurl(string $method, string $path): void
    {
        $meta = ApiExecutionMetadataContext::current();
        $source = (string) ($meta['source'] ?? '');
        if (!MeliTransportSourcePolicy::requiresQueueV4ReadFence($source)) {
            return;
        }
        $method = strtoupper($method);
        $path = '/' . ltrim($path, '/');
        if ($method !== 'GET' || !self::allowedPath($path)) {
            throw new RuntimeException('queue_v4_clean_dispatch_endpoint_invalid');
        }
        CronDeadlineContext::assertCanStartRemote(2.0);
        QueueV4CleanCycleBudget::claim();
        try {
            if ($source === MeliTransportSourcePolicy::QUEUE_V4_SALES_AUDIT) {
                self::startSalesAudit($meta);
            } else {
                self::startQueueAttempt($meta, $method, $path);
            }
        } catch (\Throwable $error) {
            QueueV4CleanCycleBudget::releaseBeforeTransport();
            throw $error;
        }
    }

    public static function responseKnown(int $status): void
    {
        $meta = ApiExecutionMetadataContext::current();
        $source = (string) ($meta['source'] ?? '');
        if (!MeliTransportSourcePolicy::requiresQueueV4ReadFence($source)) {
            return;
        }
        $pdo = Database::connectionFresh();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
        if ($source === MeliTransportSourcePolicy::QUEUE_V4_SALES_AUDIT) {
            $stmt = $pdo->prepare(
                "UPDATE sync_sales_audit_jobs
                 SET remote_dispatch_state='RESPONSE_KNOWN',response_known_at=UTC_TIMESTAMP(3),last_http_status=?
                 WHERE id=? AND company_id=? AND meli_account_id=? AND status='running'
                   AND locked_by=? AND lease_generation=? AND remote_dispatch_state='PHYSICAL_STARTED'
                   AND last_request_id=?"
            );
            $stmt->execute([
                self::status($status), (int) ($meta['sales_audit_job_id'] ?? 0),
                (int) ($meta['company_id'] ?? 0), (int) ($meta['account_id'] ?? 0),
                (string) ($meta['sales_audit_lease_owner'] ?? ''),
                (int) ($meta['sales_audit_lease_generation'] ?? 0),
                (string) ($meta['transport_request_id'] ?? ''),
            ]);
        } else {
            $stmt = $pdo->prepare(
                "UPDATE queue_v4_clean_attempts a
                 INNER JOIN queue_v4_clean_jobs j
                   ON j.id=a.job_id AND j.company_id=a.company_id AND j.meli_account_id=a.meli_account_id
                 SET a.dispatch_state='RESPONSE_KNOWN',a.response_known_at=UTC_TIMESTAMP(3),a.http_status=?
                 WHERE a.id=? AND a.job_id=? AND a.company_id=? AND a.meli_account_id=?
                   AND a.lease_owner=? AND a.outcome='running' AND a.dispatch_state='PHYSICAL_STARTED'
                   AND j.state='running' AND j.lease_owner=?"
            );
            $stmt->execute([
                self::status($status), (int) ($meta['queue_v4_attempt_id'] ?? 0),
                (int) ($meta['queue_v4_job_id'] ?? 0), (int) ($meta['company_id'] ?? 0),
                (int) ($meta['account_id'] ?? 0), (string) ($meta['queue_v4_lease_owner'] ?? ''),
                (string) ($meta['queue_v4_lease_owner'] ?? ''),
            ]);
        }
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('queue_v4_clean_response_fence_lost');
        }
        QueueV4CleanTransportJournal::responseKnown(
            $pdo,
            (int) ($meta['company_id'] ?? 0),
            (int) ($meta['account_id'] ?? 0),
            $source === MeliTransportSourcePolicy::QUEUE_V4_SALES_AUDIT ? 'sales_audit' : 'queue',
            (string) ($meta['transport_request_id'] ?? ''),
            $status,
        );
        if ($ownsTransaction) {
            $pdo->commit();
        }
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{dispatch_state:string,http_status:?int} */
    public static function state(array $meta): array
    {
        $source = (string) ($meta['source'] ?? '');
        if ($source === MeliTransportSourcePolicy::QUEUE_V4_SALES_AUDIT) {
            $stmt = Database::connectionFresh()->prepare(
                'SELECT remote_dispatch_state,last_http_status FROM sync_sales_audit_jobs
                 WHERE id=? AND company_id=? AND meli_account_id=? AND locked_by=? AND lease_generation=? LIMIT 1'
            );
            $stmt->execute([
                (int) ($meta['sales_audit_job_id'] ?? 0), (int) ($meta['company_id'] ?? 0),
                (int) ($meta['account_id'] ?? 0), (string) ($meta['sales_audit_lease_owner'] ?? ''),
                (int) ($meta['sales_audit_lease_generation'] ?? 0),
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return [
                'dispatch_state' => (string) ($row['remote_dispatch_state'] ?? 'UNKNOWN'),
                'http_status' => isset($row['last_http_status']) ? (int) $row['last_http_status'] : null,
            ];
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT a.dispatch_state,a.http_status FROM queue_v4_clean_attempts a
             INNER JOIN queue_v4_clean_jobs j
               ON j.id=a.job_id AND j.company_id=a.company_id AND j.meli_account_id=a.meli_account_id
             WHERE a.id=? AND a.job_id=? AND a.company_id=? AND a.meli_account_id=?
               AND a.lease_owner=? AND j.lease_owner=? LIMIT 1'
        );
        $stmt->execute([
            (int) ($meta['queue_v4_attempt_id'] ?? 0), (int) ($meta['queue_v4_job_id'] ?? 0),
            (int) ($meta['company_id'] ?? 0), (int) ($meta['account_id'] ?? 0),
            (string) ($meta['queue_v4_lease_owner'] ?? ''),
            (string) ($meta['queue_v4_lease_owner'] ?? ''),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'dispatch_state' => (string) ($row['dispatch_state'] ?? 'UNKNOWN'),
            'http_status' => isset($row['http_status']) ? (int) $row['http_status'] : null,
        ];
    }

    /** @param array<string,mixed> $meta */
    private static function startQueueAttempt(array $meta, string $method, string $path): void
    {
        $pdo = Database::connectionFresh();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
        $stmt = $pdo->prepare(
            "UPDATE queue_v4_clean_attempts a
             INNER JOIN queue_v4_clean_jobs j
               ON j.id=a.job_id AND j.company_id=a.company_id AND j.meli_account_id=a.meli_account_id
             SET a.dispatch_state='PHYSICAL_STARTED',a.transport_method=?,a.endpoint_key=?,
                 a.physical_http_calls=1,a.physical_started_at=UTC_TIMESTAMP(3)
             WHERE a.id=? AND a.job_id=? AND a.company_id=? AND a.meli_account_id=?
               AND a.lease_owner=? AND a.outcome='running' AND a.dispatch_state='NOT_DISPATCHED'
               AND j.state='running' AND j.lease_owner=? AND j.lease_expires_at>UTC_TIMESTAMP(3)"
        );
        $owner = (string) ($meta['queue_v4_lease_owner'] ?? '');
        $stmt->execute([
            $method, self::endpointKey($path), (int) ($meta['queue_v4_attempt_id'] ?? 0),
            (int) ($meta['queue_v4_job_id'] ?? 0), (int) ($meta['company_id'] ?? 0),
            (int) ($meta['account_id'] ?? 0), $owner, $owner,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('queue_v4_clean_dispatch_fence_lost');
        }
        QueueV4CleanTransportJournal::started(
            $pdo,
            (int) ($meta['company_id'] ?? 0),
            (int) ($meta['account_id'] ?? 0),
            'queue',
            (int) ($meta['queue_v4_job_id'] ?? 0),
            (int) ($meta['queue_v4_attempt_id'] ?? 0),
            (int) ($meta['queue_v4_lease_generation'] ?? 0),
            (string) ($meta['transport_request_id'] ?? ''),
            $method,
            self::endpointKey($path),
        );
        if ($ownsTransaction) {
            $pdo->commit();
        }
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $meta */
    private static function startSalesAudit(array $meta): void
    {
        $pdo = Database::connectionFresh();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
        $stmt = $pdo->prepare(
            "UPDATE sync_sales_audit_jobs
             SET remote_dispatch_state='PHYSICAL_STARTED',remote_dispatched_at=UTC_TIMESTAMP(3),last_request_id=?
             WHERE id=? AND company_id=? AND meli_account_id=? AND status='running'
               AND locked_by=? AND lease_generation=? AND lock_expires_at>UTC_TIMESTAMP()
               AND remote_dispatch_state='NOT_DISPATCHED'"
        );
        $stmt->execute([
            (string) ($meta['transport_request_id'] ?? ''), (int) ($meta['sales_audit_job_id'] ?? 0),
            (int) ($meta['company_id'] ?? 0), (int) ($meta['account_id'] ?? 0),
            (string) ($meta['sales_audit_lease_owner'] ?? ''),
            (int) ($meta['sales_audit_lease_generation'] ?? 0),
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('queue_v4_clean_sales_dispatch_fence_lost');
        }
        QueueV4CleanTransportJournal::started(
            $pdo,
            (int) ($meta['company_id'] ?? 0),
            (int) ($meta['account_id'] ?? 0),
            'sales_audit',
            (int) ($meta['sales_audit_job_id'] ?? 0),
            null,
            (int) ($meta['sales_audit_lease_generation'] ?? 0),
            (string) ($meta['transport_request_id'] ?? ''),
            'GET',
            'orders_search',
        );
        if ($ownsTransaction) {
            $pdo->commit();
        }
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private static function allowedPath(string $path): bool
    {
        return hash_equals('/orders/search', $path) || preg_match('#^/orders/[0-9]+$#D', $path) === 1;
    }

    private static function endpointKey(string $path): string
    {
        return hash_equals('/orders/search', $path) ? 'orders_search' : 'order_exact';
    }

    private static function status(int $status): int
    {
        return max(0, min(999, $status));
    }
}
