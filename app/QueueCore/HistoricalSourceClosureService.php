<?php

declare(strict_types=1);

namespace App\QueueCore;

use PDO;
use RuntimeException;
use Throwable;

final class HistoricalSourceClosureService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly HistoricalBacklogSourceRegistry $registry,
    ) {
    }

    /** @return array{inspected:int,closed:int,review:int} */
    public function closeCompleted(int $limit = 50): array
    {
        $limit = max(1, min(50, $limit));
        $rows = $this->pdo->query(
            'SELECT r.* FROM queue_core_historical_receipts r
             JOIN queue_core_jobs j ON j.id=r.queue_job_id
             WHERE r.closure_state="open" AND j.state="completed"
               AND j.dispatch_state="DISPATCHED_RESULT_KNOWN"
             ORDER BY r.id ASC LIMIT ' . $limit
        )->fetchAll(PDO::FETCH_ASSOC);
        $closed = $review = 0;
        foreach ($rows as $row) {
            $this->pdo->beginTransaction();
            try {
                $receipt = $this->lockedReceipt((int) $row['id'], 'open');
                if ($receipt === null) {
                    $this->pdo->rollBack();
                    continue;
                }
                $source = $this->registry->get((string) $receipt['source_key']);
                if (!$source->close($this->pdo, $receipt)) {
                    $this->markReceipt((int) $receipt['id'], 'review', null);
                    $this->recordReview($receipt, 'source_changed_before_close');
                    $review++;
                } else {
                    $this->markReceipt((int) $receipt['id'], 'closed', 'closed_at');
                    $closed++;
                }
                $this->pdo->commit();
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $error;
            }
        }
        return ['inspected' => count($rows), 'closed' => $closed, 'review' => $review];
    }

    /** @return array{inspected:int,restored:int,review:int} */
    public function restoreClosed(int $limit = 50): array
    {
        $limit = max(1, min(50, $limit));
        $rows = $this->pdo->query(
            'SELECT * FROM queue_core_historical_receipts
             WHERE closure_state="closed" ORDER BY id ASC LIMIT ' . $limit
        )->fetchAll(PDO::FETCH_ASSOC);
        $restored = $review = 0;
        foreach ($rows as $row) {
            $this->pdo->beginTransaction();
            try {
                $receipt = $this->lockedReceipt((int) $row['id'], 'closed');
                if ($receipt === null) {
                    $this->pdo->rollBack();
                    continue;
                }
                $source = $this->registry->get((string) $receipt['source_key']);
                if (!$source->restore($this->pdo, $receipt)) {
                    $this->markReceipt((int) $receipt['id'], 'review', null);
                    $this->recordReview($receipt, 'source_changed_before_rollback');
                    $review++;
                } else {
                    $this->markReceipt((int) $receipt['id'], 'rollback_restored', 'restored_at');
                    $restored++;
                }
                $this->pdo->commit();
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $error;
            }
        }
        return ['inspected' => count($rows), 'restored' => $restored, 'review' => $review];
    }

    /** @return array<string,mixed>|null */
    private function lockedReceipt(int $id, string $state): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM queue_core_historical_receipts
             WHERE id=? AND closure_state=? FOR UPDATE'
        );
        $statement->execute([$id, $state]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function markReceipt(int $id, string $state, ?string $timestampColumn): void
    {
        if (!in_array($state, ['closed', 'rollback_restored', 'review'], true)) {
            throw new RuntimeException('Historical receipt state is invalid.');
        }
        $timestampSql = match ($timestampColumn) {
            'closed_at' => ',closed_at=UTC_TIMESTAMP(3)',
            'restored_at' => ',restored_at=UTC_TIMESTAMP(3)',
            default => '',
        };
        $statement = $this->pdo->prepare(
            'UPDATE queue_core_historical_receipts SET closure_state=?' . $timestampSql . ' WHERE id=?'
        );
        $statement->execute([$state, $id]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Historical receipt fencing was lost.');
        }
    }

    /** @param array<string,mixed> $receipt */
    private function recordReview(array $receipt, string $reason): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO queue_core_historical_reviews
             (source_key,company_id,meli_account_id,source_id,source_version,reason_code,source_state,evidence_sha256)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE evidence_sha256=VALUES(evidence_sha256)'
        );
        $statement->execute([
            (string) $receipt['source_key'],
            (int) $receipt['company_id'],
            (int) $receipt['meli_account_id'],
            (int) $receipt['source_id'],
            (string) $receipt['source_version'],
            $reason,
            (string) $receipt['source_state'],
            hash('sha256', implode('|', [
                $receipt['source_key'],
                $receipt['company_id'],
                $receipt['meli_account_id'],
                $receipt['source_id'],
                $receipt['source_version'],
                $reason,
            ])),
        ]);
    }
}
