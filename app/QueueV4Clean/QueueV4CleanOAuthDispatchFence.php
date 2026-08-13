<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use App\Core\Database;
use App\Core\Env;
use App\Services\ApiExecutionMetadataContext;
use App\Services\CronDeadlineContext;
use App\Services\EmergencyControlService;
use App\Services\MeliEmergencyStopService;
use PDO;
use RuntimeException;

final class QueueV4CleanOAuthDispatchFence
{
    public static function immediatelyBeforeCurl(string $method, string $path): void
    {
        $metadata = ApiExecutionMetadataContext::current();
        if ((string) ($metadata['source'] ?? '') !== 'queue_v4_clean_oauth') {
            return;
        }
        if (strtoupper($method) !== 'POST' || !hash_equals('/oauth/token', '/' . ltrim($path, '/'))) {
            throw new RuntimeException('queue_v4_clean_oauth_fence_endpoint_invalid');
        }
        CronDeadlineContext::assertCanStartRemote(2.0);
        if ((new EmergencyControlService())->automationStopped() || Env::bool('ML_WRITE_ENABLED', false)) {
            throw new RuntimeException('queue_v4_clean_oauth_fence_runtime_stopped');
        }
        (new MeliEmergencyStopService())->assertAllowed();

        $operationId = (int) ($metadata['oauth_operation_id'] ?? 0);
        $companyId = (int) ($metadata['company_id'] ?? 0);
        $accountId = (int) ($metadata['account_id'] ?? 0);
        $version = (int) ($metadata['expected_refresh_version'] ?? -1);
        $operationOwner = (string) ($metadata['oauth_lease_owner'] ?? '');
        $operationGeneration = (int) ($metadata['oauth_lease_generation'] ?? 0);
        $schedulerOwner = (string) ($metadata['scheduler_lease_owner'] ?? '');
        $requestId = (string) ($metadata['transport_request_id'] ?? '');
        if ($operationId < 1 || $companyId < 1 || $accountId < 1 || $version < 0
            || $operationOwner === '' || $operationGeneration < 1 || $schedulerOwner === '' || $requestId === '') {
            throw new RuntimeException('queue_v4_clean_oauth_fence_context_invalid');
        }

        QueueV4CleanCycleBudget::claim();
        try {
            $pdo = Database::connectionFresh();
            $scheduler = $pdo->prepare(
                "UPDATE queue_v4_clean_leases SET heartbeat_at=UTC_TIMESTAMP(3),
                        expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 60 SECOND)
                 WHERE lease_key='scheduler' AND owner_ref=? AND expires_at>UTC_TIMESTAMP(3)"
            );
            $scheduler->execute([$schedulerOwner]);
            if ($scheduler->rowCount() !== 1) {
                $stillOwned = $pdo->prepare(
                    "SELECT 1 FROM queue_v4_clean_leases
                     WHERE lease_key='scheduler' AND owner_ref=? AND expires_at>UTC_TIMESTAMP(3) LIMIT 1"
                );
                $stillOwned->execute([$schedulerOwner]);
                if ($stillOwned->fetchColumn() === false) {
                    throw new RuntimeException('queue_v4_clean_oauth_scheduler_lease_lost');
                }
            }
            $dispatch = $pdo->prepare(
                "UPDATE oauth_refresh_operations o
                 INNER JOIN meli_accounts a ON a.company_id=o.company_id AND a.id=o.meli_account_id
                 INNER JOIN meli_tokens t ON t.meli_account_id=a.id
                 SET o.remote_dispatch_state='MAY_HAVE_DISPATCHED',o.remote_dispatched_at=UTC_TIMESTAMP(3),
                     o.remote_attempt_count=o.remote_attempt_count+1,o.last_request_id=?
                 WHERE o.id=? AND o.company_id=? AND o.meli_account_id=?
                   AND o.expected_refresh_version=? AND o.expected_meli_user_id=a.meli_user_id
                   AND t.refresh_version=o.expected_refresh_version
                   AND o.state='RUNNING' AND o.remote_dispatch_state='NOT_DISPATCHED'
                   AND o.lease_owner=? AND o.lease_generation=? AND o.lease_expires_at>UTC_TIMESTAMP(3)"
            );
            $dispatch->execute([
                $requestId, $operationId, $companyId, $accountId, $version,
                $operationOwner, $operationGeneration,
            ]);
            if ($dispatch->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_clean_oauth_dispatch_fence_lost');
            }
        } catch (\Throwable $error) {
            QueueV4CleanCycleBudget::releaseBeforeTransport();
            throw $error;
        }
    }

    public static function responseKnown(int $status): void
    {
        $metadata = ApiExecutionMetadataContext::current();
        if ((string) ($metadata['source'] ?? '') !== 'queue_v4_clean_oauth') {
            return;
        }
        $statement = Database::connectionFresh()->prepare(
            "UPDATE oauth_refresh_operations
             SET remote_dispatch_state='RESPONSE_KNOWN',response_known_at=UTC_TIMESTAMP(3),last_http_status=?
             WHERE id=? AND company_id=? AND meli_account_id=? AND state='RUNNING'
               AND lease_owner=? AND lease_generation=?
               AND remote_dispatch_state='MAY_HAVE_DISPATCHED' AND last_request_id=?"
        );
        $statement->execute([
            max(0, min(999, $status)),
            (int) ($metadata['oauth_operation_id'] ?? 0),
            (int) ($metadata['company_id'] ?? 0),
            (int) ($metadata['account_id'] ?? 0),
            (string) ($metadata['oauth_lease_owner'] ?? ''),
            (int) ($metadata['oauth_lease_generation'] ?? 0),
            (string) ($metadata['transport_request_id'] ?? ''),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('queue_v4_clean_oauth_response_fence_lost');
        }
    }

    /** @return array{dispatch_state:string,http_status:?int} */
    public static function state(array $operation): array
    {
        $statement = Database::connectionFresh()->prepare(
            'SELECT remote_dispatch_state,last_http_status FROM oauth_refresh_operations
             WHERE id=? AND company_id=? AND meli_account_id=? LIMIT 1'
        );
        $statement->execute([(int) $operation['id'], (int) $operation['company_id'], (int) $operation['meli_account_id']]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return [
            'dispatch_state' => (string) ($row['remote_dispatch_state'] ?? 'UNKNOWN'),
            'http_status' => isset($row['last_http_status']) ? (int) $row['last_http_status'] : null,
        ];
    }
}
