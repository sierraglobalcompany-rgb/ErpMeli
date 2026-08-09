<?php

declare(strict_types=1);

namespace App\QueueCore;

use PDO;

/** Only the already-certified order_exact path is admitted from notifications. */
final class NotificationOrderHistoricalSource implements HistoricalBacklogSource
{
    public function key(): string
    {
        return 'notification_orders';
    }

    public function requiredCapability(): string
    {
        return 'order_exact';
    }

    public function highWater(PDO $pdo, int $companyId, int $accountId): int
    {
        $statement = $pdo->prepare(
            'SELECT COALESCE(MAX(w.id),0)
             FROM meli_notification_work_items w
             JOIN meli_accounts a ON a.id=w.meli_account_id AND a.company_id=?
             WHERE w.meli_account_id=?
               AND w.status IN ("pending","retry","error","running","paused")'
        );
        $statement->execute([$companyId, $accountId]);
        return max(0, (int) $statement->fetchColumn());
    }

    public function scan(PDO $pdo, int $companyId, int $accountId, int $afterId, int $highWaterId, int $limit): array
    {
        $statement = $pdo->prepare(
            'SELECT w.id source_id,w.meli_account_id,w.resource_type,w.remote_resource_id,
                    w.latest_event_id,w.status source_state,w.next_run_at,w.last_result,
                    w.completed_at,w.updated_at
             FROM meli_notification_work_items w
             JOIN meli_accounts a ON a.id=w.meli_account_id AND a.company_id=?
             WHERE w.meli_account_id=? AND w.id>? AND w.id<=?
               AND w.status IN ("pending","retry","error","running","paused")
             ORDER BY w.id ASC LIMIT ' . max(1, min(200, $limit))
        );
        $statement->execute([$companyId, $accountId, max(0, $afterId), max(0, $highWaterId)]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function classify(array $row, LegacyWorkClassifier $classifier): array
    {
        $sourceId = max(0, (int) ($row['source_id'] ?? 0));
        $accountId = max(0, (int) ($row['meli_account_id'] ?? 0));
        $remoteId = trim((string) ($row['remote_resource_id'] ?? ''));
        $resourceType = trim((string) ($row['resource_type'] ?? ''));
        $sourceState = trim((string) ($row['source_state'] ?? ''));
        $latestEventId = max(0, (int) ($row['latest_event_id'] ?? 0));
        $sourceVersion = hash('sha256', implode('|', [
            $sourceId,
            $latestEventId,
            $resourceType,
            $remoteId,
            (string) ($row['updated_at'] ?? ''),
        ]));
        $inputVersion = hash('sha256', implode('|', ['notification_order', $latestEventId, $remoteId]));
        $snapshot = [
            'status' => $sourceState,
            'next_run_at' => $row['next_run_at'] ?? null,
            'last_result' => $row['last_result'] ?? null,
            'completed_at' => $row['completed_at'] ?? null,
            'latest_event_id' => $latestEventId,
        ];
        $evidence = hash('sha256', self::json([
            'source_id' => $sourceId,
            'account_id' => $accountId,
            'resource_type' => $resourceType,
            'remote_id' => $remoteId,
            'state' => $sourceState,
            'source_version' => $sourceVersion,
        ]));

        $base = [
            'job' => null,
            'reason' => null,
            'source_id' => $sourceId,
            'source_state' => $sourceState,
            'source_version' => $sourceVersion,
            'source_generation' => $latestEventId,
            'input_version' => $inputVersion,
            'snapshot' => $snapshot,
            'evidence_sha256' => $evidence,
        ];
        if ($sourceId < 1 || $accountId < 1) {
            return array_replace($base, ['reason' => 'invalid_source_scope']);
        }
        if ($resourceType !== 'order') {
            return array_replace($base, ['reason' => 'unsupported_notification_resource']);
        }
        if (!ctype_digit($remoteId)) {
            return array_replace($base, ['reason' => 'invalid_order_identity']);
        }
        if (!in_array($sourceState, ['pending', 'retry'], true)) {
            return array_replace($base, ['reason' => match ($sourceState) {
                'error' => 'legacy_error_requires_review',
                'running' => 'legacy_running_requires_review',
                'paused' => 'legacy_paused_by_operator',
                default => 'legacy_state_not_importable',
            }]);
        }
        $availableAt = trim((string) ($row['next_run_at'] ?? ''));
        return array_replace($base, [
            'job' => $classifier->notificationOrder(
                $accountId,
                $remoteId,
                $sourceId,
                $latestEventId,
                $inputVersion,
                $availableAt !== '' ? $availableAt : null,
            ),
        ]);
    }

    public function close(PDO $pdo, array $receipt): bool
    {
        $snapshot = self::decode((string) ($receipt['source_snapshot_json'] ?? ''));
        $statement = $pdo->prepare(
            'UPDATE meli_notification_work_items w
             JOIN meli_accounts a ON a.id=w.meli_account_id AND a.company_id=?
             SET w.status="complete",w.last_result="queue_core_completed",
                 w.completed_at=UTC_TIMESTAMP(3),w.next_run_at=UTC_TIMESTAMP(3)
             WHERE w.id=? AND w.meli_account_id=? AND w.latest_event_id=?
               AND w.status=?'
        );
        $statement->execute([
            (int) $receipt['company_id'],
            (int) $receipt['source_id'],
            (int) $receipt['meli_account_id'],
            (int) ($snapshot['latest_event_id'] ?? -1),
            (string) ($snapshot['status'] ?? ''),
        ]);
        return $statement->rowCount() === 1;
    }

    public function restore(PDO $pdo, array $receipt): bool
    {
        $snapshot = self::decode((string) ($receipt['source_snapshot_json'] ?? ''));
        $statement = $pdo->prepare(
            'UPDATE meli_notification_work_items w
             JOIN meli_accounts a ON a.id=w.meli_account_id AND a.company_id=?
             SET w.status=?,w.next_run_at=?,w.last_result=?,w.completed_at=?
             WHERE w.id=? AND w.meli_account_id=? AND w.latest_event_id=?
               AND w.status="complete" AND w.last_result="queue_core_completed"'
        );
        $statement->execute([
            (int) $receipt['company_id'],
            (string) ($snapshot['status'] ?? 'pending'),
            $snapshot['next_run_at'] ?? null,
            $snapshot['last_result'] ?? null,
            $snapshot['completed_at'] ?? null,
            (int) $receipt['source_id'],
            (int) $receipt['meli_account_id'],
            (int) ($snapshot['latest_event_id'] ?? -1),
        ]);
        return $statement->rowCount() === 1;
    }

    /** @param array<string,mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private static function decode(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
