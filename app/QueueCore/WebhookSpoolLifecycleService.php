<?php

declare(strict_types=1);

namespace App\QueueCore;

use App\Services\WebhookService;
use PDO;
use RuntimeException;
use Throwable;

/** Durable local authority for every accepted webhook spool observation. */
final class WebhookSpoolLifecycleService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $payload */
    public function ensureReceived(string $spoolKey, array $payload): void
    {
        $this->assertKey($spoolKey);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $statement = $this->pdo->prepare(
            'INSERT INTO queue_core_webhook_spool_items
             (spool_key,payload_sha256,payload_json,lifecycle)
             VALUES (?,?,?,"received")
             ON DUPLICATE KEY UPDATE spool_key=VALUES(spool_key)'
        );
        $statement->execute([$spoolKey, hash('sha256', $json), $json]);

        $verify = $this->pdo->prepare(
            'SELECT payload_sha256 FROM queue_core_webhook_spool_items WHERE spool_key=? LIMIT 1'
        );
        $verify->execute([$spoolKey]);
        $stored = (string) ($verify->fetchColumn() ?: '');
        if ($stored === '' || !hash_equals($stored, hash('sha256', $json))) {
            throw new RuntimeException('Webhook spool identity was reused with another payload.');
        }
    }

    /** @return 'materializing'|'busy'|'materialized'|'resolved'|'archived' */
    public function beginMaterialization(string $spoolKey): string
    {
        $this->assertKey($spoolKey);
        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare(
                'SELECT lifecycle,materializing_at FROM queue_core_webhook_spool_items
                 WHERE spool_key=? FOR UPDATE'
            );
            $select->execute([$spoolKey]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new RuntimeException('Webhook spool lifecycle is unavailable.');
            }
            $state = (string) $row['lifecycle'];
            if (in_array($state, ['materialized', 'resolved', 'archived'], true)) {
                $this->pdo->commit();
                return $state;
            }
            $stale = $state === 'materializing'
                && strtotime((string) ($row['materializing_at'] ?? '') . ' UTC') <= time() - 600;
            if ($state === 'materializing' && !$stale) {
                $this->pdo->commit();
                return 'busy';
            }
            $update = $this->pdo->prepare(
                'UPDATE queue_core_webhook_spool_items
                 SET lifecycle="materializing",materializing_at=UTC_TIMESTAMP(3),
                     materialization_attempts=materialization_attempts+1,last_error_class=NULL
                 WHERE spool_key=? AND lifecycle=?'
            );
            $update->execute([$spoolKey, $state]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Webhook spool materialization fence changed.');
            }
            $this->pdo->commit();
            return 'materializing';
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function markMaterialized(
        string $spoolKey,
        int $companyId,
        int $accountId,
        int $triggerId,
        int $generation
    ): void {
        if ($companyId < 1 || $accountId < 1 || $triggerId < 1 || $generation < 1) {
            throw new RuntimeException('Webhook spool materialization scope is invalid.');
        }
        $statement = $this->pdo->prepare(
            'UPDATE queue_core_webhook_spool_items
             SET lifecycle="materialized",company_id=?,meli_account_id=?,trigger_id=?,
                 observation_generation=?,materialized_at=UTC_TIMESTAMP(3),last_error_class=NULL
             WHERE spool_key=? AND lifecycle="materializing"'
        );
        $statement->execute([$companyId, $accountId, $triggerId, $generation, $spoolKey]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Webhook spool materialized fence changed.');
        }
    }

    public function returnToReceived(string $spoolKey, string $errorClass): void
    {
        $safe = self::safeClass($errorClass);
        $statement = $this->pdo->prepare(
            'UPDATE queue_core_webhook_spool_items
             SET lifecycle="received",materializing_at=NULL,last_error_class=?
             WHERE spool_key=? AND lifecycle="materializing"'
        );
        $statement->execute([$safe, $spoolKey]);
    }

    public function markArchived(string $spoolKey, ?string $reason = null): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE queue_core_webhook_spool_items
             SET lifecycle="archived",archived_at=UTC_TIMESTAMP(3),last_error_class=?
             WHERE spool_key=? AND lifecycle IN ("received","materializing","materialized")'
        );
        $statement->execute([$reason !== null ? self::safeClass($reason) : null, $spoolKey]);
        if ($statement->rowCount() === 1) {
            return true;
        }
        $check = $this->pdo->prepare(
            'SELECT lifecycle FROM queue_core_webhook_spool_items WHERE spool_key=? LIMIT 1'
        );
        $check->execute([$spoolKey]);
        return (string) ($check->fetchColumn() ?: '') === 'archived';
    }

    public function resolveForTrigger(
        int $triggerId,
        int $companyId,
        int $accountId,
        int $processedGeneration
    ): int {
        $statement = $this->pdo->prepare(
            'UPDATE queue_core_webhook_spool_items
             SET lifecycle="resolved",resolved_at=UTC_TIMESTAMP(3),last_error_class=NULL
             WHERE trigger_id=? AND company_id=? AND meli_account_id=?
               AND observation_generation<=? AND lifecycle="materialized"'
        );
        $statement->execute([$triggerId, $companyId, $accountId, $processedGeneration]);
        return $statement->rowCount();
    }

    public function unresolvedCount(): int
    {
        return max(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM queue_core_webhook_spool_items
             WHERE lifecycle IN ("received","materializing","materialized")'
        )->fetchColumn());
    }

    /** @return array{inspected:int,replayed:int,errors:int,remaining:int} */
    public function replayUnresolvedToLegacy(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $statement = $this->pdo->query(
            'SELECT spool_key,payload_json FROM queue_core_webhook_spool_items
             WHERE lifecycle IN ("received","materializing","materialized")
             ORDER BY id ASC LIMIT ' . $limit
        );
        $inspected = $replayed = $errors = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $inspected++;
            $payload = json_decode((string) $row['payload_json'], true);
            if (!is_array($payload)) {
                $errors++;
                continue;
            }
            try {
                $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $result = (new WebhookService())->receiveResult($raw, 'queue_core_rollback', false);
                if (!empty($result['accepted'])
                    || (!empty($result['terminal']) && !empty($result['quarantined']))) {
                    if ($this->markArchived((string) $row['spool_key'], 'rollback_replayed')) {
                        $replayed++;
                        continue;
                    }
                }
            } catch (Throwable) {
            }
            $errors++;
        }
        return [
            'inspected' => $inspected,
            'replayed' => $replayed,
            'errors' => $errors,
            'remaining' => $this->unresolvedCount(),
        ];
    }

    private function assertKey(string $spoolKey): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $spoolKey) !== 1) {
            throw new RuntimeException('Webhook spool key is invalid.');
        }
    }

    private static function safeClass(string $value): string
    {
        $safe = strtolower((string) preg_replace('/[^a-z0-9_.-]+/i', '_', $value));
        return substr(trim($safe, '_'), 0, 100) ?: 'unknown_failure';
    }
}
