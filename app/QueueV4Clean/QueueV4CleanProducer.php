<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use App\Services\CronAdmissionService;
use App\Services\SyncSettingsService;
use PDO;
use RuntimeException;
use Throwable;

final class QueueV4CleanProducer
{
    private const PACK_DISCOVERY_OUTSTANDING_TARGET = 2;
    private const PACK_DISCOVERY_ROUTE_NONE = 0;
    private const PACK_DISCOVERY_ROUTE_EXISTING = 1;
    private const PACK_DISCOVERY_ROUTE_COVERAGE = 2;
    private const PACK_DISCOVERY_FAIRNESS_KEY = 'pack_discovery_fairness';

    public function __construct(
        private readonly PDO $pdo,
        private readonly QueueV4CleanRepository $repository,
    ) {
    }

    /** @return array{created:int,accounts:int,historical_used:bool,inventory_refresh_created:int,pack_discovery_created:int,due_waiting_woken:int} */
    public function produce(int $windowSeconds = 300): array
    {
        $control = $this->repository->control();
        if ((string) $control['engine_state'] !== 'ACTIVE') {
            return ['created' => 0, 'accounts' => 0, 'historical_used' => false, 'inventory_refresh_created' => 0, 'pack_discovery_created' => 0, 'due_waiting_woken' => 0];
        }
        $accounts = $this->pdo->query(
            'SELECT a.company_id,a.id meli_account_id
             FROM queue_v4_clean_readiness_accounts ra
             INNER JOIN queue_v4_clean_readiness_runs rr
               ON rr.id=ra.readiness_run_id AND rr.state="CERTIFIED"
             INNER JOIN meli_accounts a
               ON a.company_id=ra.company_id AND a.id=ra.meli_account_id
             INNER JOIN meli_tokens t ON t.meli_account_id=a.id
             WHERE ra.readiness_run_id=(
                 SELECT MAX(id) FROM queue_v4_clean_readiness_runs WHERE state="CERTIFIED"
             ) AND ra.outcome="PASS" AND a.status IN ("conectado","connected")
             ORDER BY a.company_id,a.id'
        )->fetchAll(PDO::FETCH_ASSOC);
        if (count($accounts) !== 3) {
            throw new RuntimeException('queue_v4_clean_certified_account_set_invalid');
        }
        // Upgrades may inherit an already ACTIVE 2.37.2 engine and therefore
        // never call activate() again. Capture the cutover floors for all
        // certified tenants before any producer branch can schedule work.
        $this->ensurePendingProjectionAuthority($accounts);
        // Finance due-waiting wake-up is owned by QueueV4CleanWorker::run()
        // so the natural scheduler receipt can prove runtime execution.
        $dueWaitingWoken = 0;
        $created = 0;
        $now = time();
        $windowSeconds = max(60, min(900, $windowSeconds));
        $pageLimit = (new SyncSettingsService())->pageLimit();
        foreach ($accounts as $account) {
            $companyId = (int) $account['company_id'];
            $accountId = (int) $account['meli_account_id'];
            $this->pdo->beginTransaction();
            try {
                $select = $this->pdo->prepare(
                    "SELECT watermark_at,next_due_at FROM queue_v4_clean_checkpoints
                     WHERE producer_key='fresh_orders' AND company_id=? AND meli_account_id=? FOR UPDATE"
                );
                $select->execute([$companyId, $accountId]);
                $checkpoint = $select->fetch(PDO::FETCH_ASSOC);
                if (!is_array($checkpoint)) {
                    $insert = $this->pdo->prepare(
                        "INSERT INTO queue_v4_clean_checkpoints
                         (producer_key,company_id,meli_account_id,watermark_at,next_due_at)
                         VALUES ('fresh_orders',?,?,?,UTC_TIMESTAMP(3))"
                    );
                    $insert->execute([$companyId, $accountId, gmdate('Y-m-d H:i:s', $now - $windowSeconds)]);
                    $from = $now - $windowSeconds;
                } else {
                    $due = strtotime((string) $checkpoint['next_due_at'] . ' UTC') ?: 0;
                    if ($due > $now) {
                        $this->pdo->commit();
                        continue;
                    }
                    $from = strtotime((string) $checkpoint['watermark_at'] . ' UTC') ?: ($now - $windowSeconds);
                }
                // A frontier remains open while any of its pagination or
                // exact-order work is operational. Do not mint a new remote
                // discovery round while the tenant still has FIFO work from
                // the previous one (or another bounded local source).
                if ($this->repository->hasOutstandingFreshFrontierWork($companyId, $accountId)) {
                    $this->pdo->commit();
                    continue;
                }
                $from = max(0, min($now, $from));
                if ($from >= $now) {
                    $defer = $this->pdo->prepare(
                        "UPDATE queue_v4_clean_checkpoints
                         SET next_due_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 60 SECOND)
                         WHERE producer_key='fresh_orders' AND company_id=? AND meli_account_id=?"
                    );
                    $defer->execute([$companyId, $accountId]);
                    $this->pdo->commit();
                    continue;
                }
                // Catch up in bounded contiguous windows. Never clamp the
                // lower frontier to "now-window", which would lose coverage
                // after a prolonged backlog or outage.
                $to = min($now, $from + $windowSeconds);
                $key = gmdate('YmdHis', $from) . '-' . gmdate('YmdHis', $to);
                $jobId = $this->repository->enqueue(
                    $companyId,
                    $accountId,
                    'fresh_orders_discovery',
                    null,
                    'fresh:' . $key,
                    ['from' => gmdate(DATE_ATOM, $from), 'to' => gmdate(DATE_ATOM, $to), 'offset' => 0, 'limit' => $pageLimit],
                    3,
                );
                $update = $this->pdo->prepare(
                    "UPDATE queue_v4_clean_checkpoints
                     SET next_due_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 60 SECOND),last_job_id=?
                     WHERE producer_key='fresh_orders' AND company_id=? AND meli_account_id=?"
                );
                $update->execute([$jobId, $companyId, $accountId]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('queue_v4_clean_checkpoint_lost');
                }
                $this->pdo->commit();
                $created++;
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $error;
            }
        }
        $inventoryRefreshCreated = $this->scheduleInventoryRefresh($accounts, $now);
        $packDiscoveryCreated = $this->schedulePackExactDiscovery($accounts);
        return [
            'created' => $created + $inventoryRefreshCreated + $packDiscoveryCreated,
            'accounts' => count($accounts),
            'historical_used' => false,
            'inventory_refresh_created' => $inventoryRefreshCreated,
            'pack_discovery_created' => $packDiscoveryCreated,
            'due_waiting_woken' => $dueWaitingWoken,
        ];
    }

    /**
     * Keep a small bounded number of unresolved exact pack discovery jobs
     * eligible per natural scheduler cycle. order_resource_enrichment_jobs
     * remains source-domain state; Queue V4 remains the only automatic drainer.
     *
     * @param list<array<string,mixed>> $accounts
     */
    private function schedulePackExactDiscovery(array $accounts): int
    {
        if ($accounts === []) {
            return 0;
        }
        $sourceTenantClauses = [];
        $packTenantClauses = [];
        $params = [];
        foreach ($accounts as $account) {
            $sourceTenantClauses[] = '(a.company_id=? AND j.meli_account_id=?)';
            $packTenantClauses[] = '(a.company_id=? AND p.meli_account_id=?)';
            $params[] = (int) $account['company_id'];
            $params[] = (int) $account['meli_account_id'];
        }

        $created = 0;
        $this->pdo->beginTransaction();
        try {
            $occupancy = new PackDiscoveryOccupancyPolicy($this->pdo);
            $occupancy->lockAdmissionAuthority();
            $lastSuccessfulRoute = $this->lockPackDiscoveryFairnessCursor();
            while ($created < self::PACK_DISCOVERY_OUTSTANDING_TARGET) {
                if ($occupancy->outstandingForAccounts($accounts) >= self::PACK_DISCOVERY_OUTSTANDING_TARGET) {
                    break;
                }

                $this->pdo->exec('SAVEPOINT r0_pack_discovery_pair');
                $preferredRoute = $lastSuccessfulRoute === self::PACK_DISCOVERY_ROUTE_EXISTING
                    ? self::PACK_DISCOVERY_ROUTE_COVERAGE
                    : self::PACK_DISCOVERY_ROUTE_EXISTING;
                $route = $preferredRoute;
                $row = $this->packDiscoveryCandidateForRoute(
                    $route,
                    $sourceTenantClauses,
                    $packTenantClauses,
                    $params,
                );
                if (!is_array($row)) {
                    $route = $preferredRoute === self::PACK_DISCOVERY_ROUTE_EXISTING
                        ? self::PACK_DISCOVERY_ROUTE_COVERAGE
                        : self::PACK_DISCOVERY_ROUTE_EXISTING;
                    $row = $this->packDiscoveryCandidateForRoute(
                        $route,
                        $sourceTenantClauses,
                        $packTenantClauses,
                        $params,
                    );
                    if (!is_array($row)) {
                        $this->pdo->exec('RELEASE SAVEPOINT r0_pack_discovery_pair');
                        break;
                    }
                }

                $receipt = (new CronAdmissionService($this->pdo))->submit(
                    'order_enrichment_pack',
                    (int) $row['company_id'],
                    (int) $row['meli_account_id'],
                    (int) $row['id'],
                    'source:' . (int) $row['id'],
                    ['pack_id' => (string) $row['external_resource_id']],
                );
                if (!empty($receipt['accepted']) && empty($receipt['deduplicated'])) {
                    $this->recordPackDiscoverySuccessfulRoute($route);
                    $lastSuccessfulRoute = $route;
                    $created++;
                    $this->pdo->exec('RELEASE SAVEPOINT r0_pack_discovery_pair');
                    continue;
                }
                $this->pdo->exec('ROLLBACK TO SAVEPOINT r0_pack_discovery_pair');
                $this->pdo->exec('RELEASE SAVEPOINT r0_pack_discovery_pair');
                break;
            }
            $this->pdo->commit();

            return $created;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function lockPackDiscoveryFairnessCursor(): int
    {
        // This producer-specific checkpoint stores a route enum in last_job_id,
        // not a Queue V4 job id. Persisting the last successful route keeps the
        // next preference stable across scheduler processes without new schema.
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO queue_v4_clean_checkpoints
             (producer_key,company_id,meli_account_id,watermark_at,next_due_at,last_job_id)
             VALUES (?,0,0,NULL,UTC_TIMESTAMP(3),?)'
        );
        $insert->execute([self::PACK_DISCOVERY_FAIRNESS_KEY, self::PACK_DISCOVERY_ROUTE_NONE]);

        $select = $this->pdo->prepare(
            'SELECT COALESCE(last_job_id,0)
               FROM queue_v4_clean_checkpoints
              WHERE producer_key=? AND company_id=0 AND meli_account_id=0
              FOR UPDATE'
        );
        $select->execute([self::PACK_DISCOVERY_FAIRNESS_KEY]);
        $route = (int) $select->fetchColumn();
        if (!in_array($route, [
            self::PACK_DISCOVERY_ROUTE_NONE,
            self::PACK_DISCOVERY_ROUTE_EXISTING,
            self::PACK_DISCOVERY_ROUTE_COVERAGE,
        ], true)) {
            throw new RuntimeException('queue_v4_clean_pack_discovery_fairness_cursor_invalid');
        }

        return $route;
    }

    /**
     * @param list<string> $sourceTenantClauses
     * @param list<string> $packTenantClauses
     * @param list<int> $params
     * @return array{id:int,company_id:int,meli_account_id:int,external_resource_id:string}|null
     */
    private function packDiscoveryCandidateForRoute(
        int $route,
        array $sourceTenantClauses,
        array $packTenantClauses,
        array $params,
    ): ?array {
        return match ($route) {
            self::PACK_DISCOVERY_ROUTE_EXISTING => $this->packExactDiscoveryCandidate($sourceTenantClauses, $params),
            self::PACK_DISCOVERY_ROUTE_COVERAGE => $this->createPackExactDiscoverySourceCoverage($packTenantClauses, $params),
            default => throw new RuntimeException('queue_v4_clean_pack_discovery_route_invalid'),
        };
    }

    private function recordPackDiscoverySuccessfulRoute(int $route): void
    {
        if (!in_array($route, [self::PACK_DISCOVERY_ROUTE_EXISTING, self::PACK_DISCOVERY_ROUTE_COVERAGE], true)) {
            throw new RuntimeException('queue_v4_clean_pack_discovery_route_invalid');
        }
        $update = $this->pdo->prepare(
            'UPDATE queue_v4_clean_checkpoints
                SET last_job_id=?
              WHERE producer_key=? AND company_id=0 AND meli_account_id=0'
        );
        $update->execute([$route, self::PACK_DISCOVERY_FAIRNESS_KEY]);
    }

    /**
     * @param list<string> $tenantClauses SQL fragments scoped to certified accounts using alias j.
     * @param list<int> $params Bound company/account pairs for $tenantClauses.
     */
    private function countOutstandingPackExactDiscovery(array $tenantClauses, array $params): int
    {
        if ($tenantClauses === []) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM queue_v4_clean_jobs q
             JOIN order_resource_enrichment_jobs j
               ON j.id=CAST(q.resource_id AS UNSIGNED)
              AND j.meli_account_id=q.meli_account_id
             JOIN meli_accounts a ON a.id=j.meli_account_id
             WHERE q.job_type="domain_exact"
               AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,"$.capability"))="order_enrichment_pack"
               AND (q.state IN ("ready","running")
                    OR (q.state="waiting" AND q.available_at<=UTC_TIMESTAMP()))
               AND (' . implode(' OR ', $tenantClauses) . ')'
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param list<string> $tenantClauses SQL fragments already scoped to certified accounts using aliases a/j.
     * @param list<int> $params Bound company/account pairs for $tenantClauses.
     * @return array{id:int,company_id:int,meli_account_id:int,external_resource_id:string}|null
     */
    private function packExactDiscoveryCandidate(array $tenantClauses, array $params): ?array
    {
        if ($tenantClauses === []) {
            return null;
        }

        $candidate = $this->pdo->prepare(
            'SELECT j.id,a.company_id,j.meli_account_id,j.external_resource_id
             FROM order_resource_enrichment_jobs j
             JOIN meli_accounts a ON a.id=j.meli_account_id
             JOIN meli_packs p
               ON p.meli_account_id=j.meli_account_id
              AND p.external_pack_id=j.external_resource_id
             WHERE j.resource_type="pack"
               AND j.status IN ("pending","retry")
               AND j.next_run_at<=UTC_TIMESTAMP()
               AND (j.locked_at IS NULL OR j.locked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))
               AND (' . implode(' OR ', $tenantClauses) . ')
               AND COALESCE(j.failure_class,"") NOT IN ("remote_result_uncertain","remote_result_uncertain_safe_get")
               AND (p.expected_orders_count IS NULL
                    OR p.expected_orders_count=0
                    OR p.expected_orders_json IS NULL
                    OR p.expected_orders_json=""
                    OR p.expected_orders_json="[]")
               AND NOT EXISTS (
                 SELECT 1 FROM queue_v4_clean_jobs q
                 WHERE q.company_id=a.company_id
                   AND q.meli_account_id=j.meli_account_id
                   AND q.job_type="domain_exact"
                   AND q.resource_id=CAST(j.id AS CHAR)
                   AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,"$.capability"))="order_enrichment_pack"
                   AND q.state IN ("ready","running","waiting","review","completed")
               )
             ORDER BY j.next_run_at ASC,j.id ASC
             LIMIT 1 FOR UPDATE'
        );
        $candidate->execute($params);
        $row = $candidate->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? [
            'id' => (int) $row['id'],
            'company_id' => (int) $row['company_id'],
            'meli_account_id' => (int) $row['meli_account_id'],
            'external_resource_id' => (string) $row['external_resource_id'],
        ] : null;
    }

    /**
     * Creates one source and returns that exact source for admission. The
     * caller owns the surrounding transaction/savepoint so a denied admission
     * rolls back this coverage together with its pointer.
     *
     * @param list<string> $tenantClauses SQL fragments already scoped to certified accounts using aliases a/p.
     * @param list<int> $params Bound company/account pairs for $tenantClauses.
     * @return array{id:int,company_id:int,meli_account_id:int,external_resource_id:string}|null
     */
    private function createPackExactDiscoverySourceCoverage(array $tenantClauses, array $params): ?array
    {
        if ($tenantClauses === []) {
            return null;
        }

        $candidate = $this->pdo->prepare(
            'SELECT p.id pack_id,a.company_id,p.meli_account_id,p.external_pack_id,MIN(o.id) meli_order_id
             FROM meli_packs p
             JOIN meli_accounts a ON a.id=p.meli_account_id
             JOIN meli_pack_orders po ON po.meli_pack_id=p.id
             JOIN meli_orders o
               ON o.id=po.meli_order_id
              AND o.meli_account_id=p.meli_account_id
              AND o.external_pack_id=p.external_pack_id
             WHERE (' . implode(' OR ', $tenantClauses) . ')
               AND p.external_pack_id IS NOT NULL
               AND p.external_pack_id<>""
               AND (p.expected_orders_count IS NULL
                    OR p.expected_orders_count=0
                    OR p.expected_orders_json IS NULL
                    OR p.expected_orders_json=""
                    OR p.expected_orders_json="[]")
               AND NOT EXISTS (
                 SELECT 1 FROM order_resource_enrichment_jobs existing
                 WHERE existing.meli_account_id=p.meli_account_id
                   AND existing.resource_type="pack"
                   AND existing.external_resource_id=p.external_pack_id
                   AND existing.status IN ("pending","retry","running","complete")
               )
             GROUP BY p.id,a.company_id,p.meli_account_id,p.external_pack_id
             ORDER BY p.id ASC
             LIMIT 1 FOR UPDATE'
        );
        $candidate->execute($params);
        $row = $candidate->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO order_resource_enrichment_jobs
             (meli_account_id,meli_order_id,resource_type,external_resource_id,status,priority,next_run_at)
             VALUES (:account,:order_id,"pack",:external_id,"pending",10,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                priority=LEAST(priority,VALUES(priority)),
                status=IF(status IN ("complete","running") OR failure_class IN ("remote_result_uncertain","remote_result_uncertain_safe_get"),status,"pending"),
                next_run_at=IF(status IN ("complete","running") OR failure_class IN ("remote_result_uncertain","remote_result_uncertain_safe_get"),next_run_at,UTC_TIMESTAMP()),
                last_error_message=IF(status IN ("complete","running") OR failure_class IN ("remote_result_uncertain","remote_result_uncertain_safe_get"),last_error_message,NULL),
                last_error_diagnostic_id=IF(status IN ("complete","running") OR failure_class IN ("remote_result_uncertain","remote_result_uncertain_safe_get"),last_error_diagnostic_id,NULL),
                last_error_code=IF(status IN ("complete","running") OR failure_class IN ("remote_result_uncertain","remote_result_uncertain_safe_get"),last_error_code,NULL),
                failure_class=IF(status IN ("complete","running") OR failure_class IN ("remote_result_uncertain","remote_result_uncertain_safe_get"),failure_class,NULL),
                reached_remote=IF(status IN ("complete","running") OR failure_class IN ("remote_result_uncertain","remote_result_uncertain_safe_get"),reached_remote,NULL),
                updated_at=UTC_TIMESTAMP(),
                id=LAST_INSERT_ID(id)'
        );
        $insert->execute([
            'account' => (int) $row['meli_account_id'],
            'order_id' => (int) $row['meli_order_id'],
            'external_id' => (string) $row['external_pack_id'],
        ]);
        $sourceId = (int) $this->pdo->lastInsertId();
        if ($sourceId < 1) {
            return null;
        }
        $this->pdo->prepare(
            'INSERT IGNORE INTO order_resource_enrichment_job_orders
             (order_resource_enrichment_job_id,meli_order_id)
             VALUES (?,?)'
        )->execute([$sourceId, (int) $row['meli_order_id']]);

        return [
            'id' => $sourceId,
            'company_id' => (int) $row['company_id'],
            'meli_account_id' => (int) $row['meli_account_id'],
            'external_resource_id' => (string) $row['external_pack_id'],
        ];
    }

    /**
     * Bounded lifecycle refresh for orders that already consumed inventory.
     * It reuses the existing documented GET /orders/{id} job and never makes
     * transport calls itself. One order per tenant is scheduled at most every
     * 15 minutes, in round-robin local-id order.
     *
     * @param list<array<string,mixed>> $accounts
     */
    private function scheduleInventoryRefresh(array $accounts, int $now): int
    {
        $created = 0;
        foreach ($accounts as $account) {
            $companyId = (int) $account['company_id'];
            $accountId = (int) $account['meli_account_id'];
            $this->pdo->beginTransaction();
            try {
                $select = $this->pdo->prepare(
                    "SELECT last_job_id,next_due_at FROM queue_v4_clean_checkpoints
                     WHERE producer_key='inventory_order_refresh'
                       AND company_id=? AND meli_account_id=? FOR UPDATE"
                );
                $select->execute([$companyId, $accountId]);
                $checkpoint = $select->fetch(PDO::FETCH_ASSOC);
                if (!is_array($checkpoint)) {
                    $insert = $this->pdo->prepare(
                        "INSERT INTO queue_v4_clean_checkpoints
                         (producer_key,company_id,meli_account_id,next_due_at)
                         VALUES ('inventory_order_refresh',?,?,UTC_TIMESTAMP(3))"
                    );
                    $insert->execute([$companyId, $accountId]);
                    $cursor = 0;
                } else {
                    $due = strtotime((string) $checkpoint['next_due_at'] . ' UTC') ?: 0;
                    if ($due > $now) {
                        $this->pdo->commit();
                        continue;
                    }
                    $cursor = max(0, (int) ($checkpoint['last_job_id'] ?? 0));
                }
                $turn = $this->inventoryRefreshTurn($companyId, $accountId);
                $order = $turn === 0
                    ? $this->inventoryOrderCandidate($companyId, $accountId, $cursor)
                    : $this->pendingProjectionCandidate($companyId, $accountId);
                $source = $turn === 0 ? 'inventory_lifecycle_refresh' : 'inventory_pending_projection';
                if ($order === null && $turn === 0) {
                    $order = $this->pendingProjectionCandidate($companyId, $accountId);
                    $source = 'inventory_pending_projection';
                } elseif ($order === null) {
                    $order = $this->inventoryOrderCandidate($companyId, $accountId, $cursor);
                    $source = 'inventory_lifecycle_refresh';
                }
                if ($order === null && $cursor > 0 && $source === 'inventory_lifecycle_refresh') {
                    $order = $this->inventoryOrderCandidate($companyId, $accountId, 0);
                }
                $lastOrderId = $cursor;
                if ($order !== null) {
                    $externalId = (string) $order['external_order_id'];
                    $this->repository->enqueue(
                        $companyId,
                        $accountId,
                        'order_exact',
                        $externalId,
                        'order:' . $externalId,
                        ['order_id' => $externalId, 'source' => $source],
                        3,
                    );
                    if ($source === 'inventory_lifecycle_refresh') {
                        $lastOrderId = (int) $order['id'];
                    }
                    $created++;
                }
                $this->setInventoryRefreshTurn($companyId, $accountId, 1 - $turn);
                $update = $this->pdo->prepare(
                    "UPDATE queue_v4_clean_checkpoints
                     SET last_job_id=?,next_due_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 15 MINUTE)
                     WHERE producer_key='inventory_order_refresh'
                       AND company_id=? AND meli_account_id=?"
                );
                $update->execute([$lastOrderId, $companyId, $accountId]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('queue_v4_clean_inventory_refresh_checkpoint_lost');
                }
                $this->pdo->commit();
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $error;
            }
        }
        return $created;
    }

    /** @param list<array<string,mixed>> $accounts */
    private function ensurePendingProjectionAuthority(array $accounts): void
    {
        $this->pdo->beginTransaction();
        try {
            foreach ($accounts as $account) {
                $companyId = (int) $account['company_id'];
                $accountId = (int) $account['meli_account_id'];
                $select = $this->pdo->prepare(
                    'SELECT producer_key,last_job_id FROM queue_v4_clean_checkpoints
                     WHERE producer_key IN ("inventory_pending_floor","inventory_pending_cursor")
                       AND company_id=? AND meli_account_id=? ORDER BY producer_key FOR UPDATE'
                );
                $select->execute([$companyId, $accountId]);
                $rows = $select->fetchAll(PDO::FETCH_KEY_PAIR);
                if (isset($rows['inventory_pending_cursor']) !== isset($rows['inventory_pending_floor'])) {
                    throw new RuntimeException('queue_v4_clean_inventory_pending_authority_incomplete');
                }
                if (isset($rows['inventory_pending_floor'], $rows['inventory_pending_cursor'])) {
                    continue;
                }
                $maximum = $this->pdo->prepare(
                    'SELECT COALESCE(MAX(o.id),0) FROM meli_orders o
                     JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=?
                     WHERE o.meli_account_id=?'
                );
                $maximum->execute([$companyId, $accountId]);
                $floor = (int) $maximum->fetchColumn();
                $insert = $this->pdo->prepare(
                    'INSERT INTO queue_v4_clean_checkpoints
                     (producer_key,company_id,meli_account_id,last_job_id,next_due_at)
                     VALUES (?,?,?,?,UTC_TIMESTAMP(3))'
                );
                $insert->execute(['inventory_pending_floor', $companyId, $accountId, $floor]);
                $insert->execute(['inventory_pending_cursor', $companyId, $accountId, $floor]);
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{id:int,external_order_id:string}|null */
    private function inventoryOrderCandidate(int $companyId, int $accountId, int $afterId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT o.id,o.external_order_id
             FROM meli_orders o
             INNER JOIN meli_accounts a
               ON a.id=o.meli_account_id AND a.company_id=?
             INNER JOIN inventory_movements issue
               ON issue.company_id=a.company_id
              AND issue.meli_account_id=o.meli_account_id
              AND issue.reference_type="meli_order"
              AND issue.reference_id=o.external_order_id
              AND issue.movement_type="sale_issue"
             LEFT JOIN inventory_movements reversal
               ON reversal.company_id=issue.company_id
              AND reversal.meli_account_id=issue.meli_account_id
              AND reversal.reversal_of_movement_id=issue.id
              AND reversal.movement_type="sale_reversal"
             WHERE o.meli_account_id=? AND o.id>? AND reversal.id IS NULL
             ORDER BY o.id ASC LIMIT 1'
        );
        $statement->execute([$companyId, $accountId, $afterId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !ctype_digit((string) $row['external_order_id'])) {
            return null;
        }
        return ['id' => (int) $row['id'], 'external_order_id' => (string) $row['external_order_id']];
    }

    private function inventoryRefreshTurn(int $companyId, int $accountId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COALESCE(last_job_id,0) FROM queue_v4_clean_checkpoints
             WHERE producer_key='inventory_refresh_turn' AND company_id=? AND meli_account_id=? FOR UPDATE"
        );
        $statement->execute([$companyId, $accountId]);
        $value = $statement->fetchColumn();
        if ($value === false) {
            $this->pdo->prepare(
                "INSERT INTO queue_v4_clean_checkpoints
                 (producer_key,company_id,meli_account_id,last_job_id,next_due_at)
                 VALUES ('inventory_refresh_turn',?,?,0,UTC_TIMESTAMP(3))"
            )->execute([$companyId, $accountId]);
            return 0;
        }
        return ((int) $value) % 2;
    }

    private function setInventoryRefreshTurn(int $companyId, int $accountId, int $turn): void
    {
        $this->pdo->prepare(
            "UPDATE queue_v4_clean_checkpoints SET last_job_id=?
             WHERE producer_key='inventory_refresh_turn' AND company_id=? AND meli_account_id=?"
        )->execute([$turn, $companyId, $accountId]);
    }

    /** @return array{id:int,external_order_id:string}|null */
    private function pendingProjectionCandidate(int $companyId, int $accountId): ?array
    {
        $floorKey = 'inventory_pending_floor';
        $cursorKey = 'inventory_pending_cursor';
        $checkpoint = $this->pdo->prepare(
            'SELECT producer_key,last_job_id FROM queue_v4_clean_checkpoints
             WHERE producer_key IN (?,?) AND company_id=? AND meli_account_id=?
             ORDER BY producer_key FOR UPDATE'
        );
        $checkpoint->execute([$floorKey, $cursorKey, $companyId, $accountId]);
        $values = [];
        foreach ($checkpoint->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values[(string) $row['producer_key']] = (int) ($row['last_job_id'] ?? 0);
        }
        if (!isset($values[$floorKey], $values[$cursorKey])) {
            throw new RuntimeException('queue_v4_clean_inventory_pending_authority_missing');
        }
        $floor = $values[$floorKey];
        $cursor = max($floor, $values[$cursorKey]);
        $statement = $this->pdo->prepare(
            'SELECT o.id,o.external_order_id
             FROM meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=?
             WHERE o.meli_account_id=? AND o.id>? AND LOWER(o.status)="paid"
               AND NOT EXISTS (
                 SELECT 1 FROM inventory_movements captured
                 WHERE captured.company_id=a.company_id
                   AND captured.meli_account_id=o.meli_account_id
                   AND captured.reference_type="meli_order"
                   AND captured.reference_id=o.external_order_id
                   AND captured.movement_type="sale_issue"
               )
             ORDER BY o.id ASC LIMIT 1'
        );
        $statement->execute([$companyId, $accountId, $cursor]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) && $cursor > $floor) {
            $statement->execute([$companyId, $accountId, $floor]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
        }
        if (!is_array($row) || !ctype_digit((string) $row['external_order_id'])) {
            return null;
        }
        $this->pdo->prepare(
            "UPDATE queue_v4_clean_checkpoints SET last_job_id=?
             WHERE producer_key='inventory_pending_cursor' AND company_id=? AND meli_account_id=?"
        )->execute([(int) $row['id'], $companyId, $accountId]);
        return ['id' => (int) $row['id'], 'external_order_id' => (string) $row['external_order_id']];
    }
}
