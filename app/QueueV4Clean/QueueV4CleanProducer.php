<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use PDO;
use RuntimeException;
use Throwable;

final class QueueV4CleanProducer
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly QueueV4CleanRepository $repository,
    ) {
    }

    /** @return array{created:int,accounts:int,historical_used:bool,inventory_refresh_created:int} */
    public function produce(int $windowSeconds = 300): array
    {
        $control = $this->repository->control();
        if ((string) $control['engine_state'] !== 'ACTIVE') {
            return ['created' => 0, 'accounts' => 0, 'historical_used' => false, 'inventory_refresh_created' => 0];
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
        $created = 0;
        $now = time();
        $windowSeconds = max(60, min(900, $windowSeconds));
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
                    $from = max($now - $windowSeconds, $from);
                }
                $to = $now;
                $key = gmdate('YmdHis', $from) . '-' . gmdate('YmdHis', $to);
                $jobId = $this->repository->enqueue(
                    $companyId,
                    $accountId,
                    'fresh_orders_discovery',
                    null,
                    'fresh:' . $key,
                    ['from' => gmdate(DATE_ATOM, $from), 'to' => gmdate(DATE_ATOM, $to), 'offset' => 0, 'limit' => 20],
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
        return [
            'created' => $created + $inventoryRefreshCreated,
            'accounts' => count($accounts),
            'historical_used' => false,
            'inventory_refresh_created' => $inventoryRefreshCreated,
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
                $order = $this->inventoryOrderCandidate($companyId, $accountId, $cursor);
                if ($order === null && $cursor > 0) {
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
                        ['order_id' => $externalId, 'source' => 'inventory_lifecycle_refresh'],
                        3,
                    );
                    $lastOrderId = (int) $order['id'];
                    $created++;
                }
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
}
