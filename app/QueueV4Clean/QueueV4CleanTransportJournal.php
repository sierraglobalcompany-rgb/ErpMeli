<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use PDO;
use RuntimeException;

/** Immutable physical-HTTP authority shared by all Queue V4 stages. */
final class QueueV4CleanTransportJournal
{
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
