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

    /** @return array{created:int,accounts:int,historical_used:bool} */
    public function produce(int $windowSeconds = 300): array
    {
        $control = $this->repository->control();
        if ((string) $control['engine_state'] !== 'ACTIVE') {
            return ['created' => 0, 'accounts' => 0, 'historical_used' => false];
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
        return ['created' => $created, 'accounts' => count($accounts), 'historical_used' => false];
    }
}
