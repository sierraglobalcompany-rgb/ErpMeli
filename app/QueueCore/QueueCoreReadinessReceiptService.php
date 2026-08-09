<?php

declare(strict_types=1);

namespace App\QueueCore;

use App\Core\Env;
use App\Services\EmergencyControlService;
use PDO;

final class QueueCoreReadinessReceiptService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string,scalar|null> $metrics */
    public function record(
        int $engineGeneration,
        string $type,
        bool $passed,
        array $metrics,
        int $ttlSeconds,
        ?int $companyId = null,
        ?int $accountId = null,
    ): int {
        if (!in_array($type, ['preflight', 'canary', 'convergence'], true)) {
            throw new \InvalidArgumentException('Queue Core readiness receipt type is invalid.');
        }
        ksort($metrics);
        $json = json_encode($metrics, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $statement = $this->pdo->prepare(
            'INSERT INTO queue_core_readiness_receipts
             (engine_generation,receipt_type,company_id,meli_account_id,status,evidence_hash,metrics_json,expires_at)
             VALUES (?,?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ? SECOND))'
        );
        $statement->execute([
            max(0, $engineGeneration), $type, $companyId, $accountId,
            $passed ? 'pass' : 'fail', hash('sha256', $json), $json,
            max(60, min(86400, $ttlSeconds)),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{ok:bool,reason:string} */
    public function canActivateV4(int $engineGeneration): array
    {
        if (!Env::bool('CRON_V4_ENABLED', false)) {
            return ['ok' => false, 'reason' => 'cron_v4_disabled'];
        }
        if (Env::bool('CRON_V3_ENABLED', false) || Env::bool('CRON_V3_SHADOW_ENABLED', false)) {
            return ['ok' => false, 'reason' => 'v3_runtime_configured'];
        }
        if (Env::bool('ML_WRITE_ENABLED', false)) {
            return ['ok' => false, 'reason' => 'ml_write_enabled'];
        }
        if (!(new EmergencyControlService())->automationStopped()) {
            return ['ok' => false, 'reason' => 'automation_not_stopped'];
        }
        $statement = $this->pdo->prepare(
            'SELECT receipt_type,MAX(id) latest_id
             FROM queue_core_readiness_receipts
             WHERE engine_generation=? AND status="pass" AND expires_at>UTC_TIMESTAMP(3)
             GROUP BY receipt_type'
        );
        $statement->execute([max(0, $engineGeneration)]);
        $found = array_fill_keys(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)), true);
        foreach (['preflight', 'canary', 'convergence'] as $required) {
            if (!isset($found[$required])) {
                return ['ok' => false, 'reason' => $required . '_receipt_missing'];
            }
        }
        return ['ok' => true, 'reason' => 'ready'];
    }
}

