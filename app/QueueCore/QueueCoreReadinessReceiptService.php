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
        $safety = (new EmergencyControlService())->status();
        if (($safety['automation'] ?? '') !== 'stopped') {
            return ['ok' => false, 'reason' => 'automation_not_stopped'];
        }
        if (($safety['api'] ?? '') !== 'enabled') {
            return ['ok' => false, 'reason' => 'api_reads_not_enabled'];
        }
        $flags = new QueueCoreFeatureFlagService($this->pdo);
        foreach (['fresh_producer', 'webhook_producer', 'pack_shipment_followups'] as $feature) {
            if (!$flags->enabled($feature)) {
                return ['ok' => false, 'reason' => $feature . '_disabled'];
            }
        }
        $statement = $this->pdo->prepare(
            'SELECT receipt_type,MAX(id) latest_id
             FROM queue_core_readiness_receipts
             WHERE engine_generation=? AND status="pass" AND expires_at>UTC_TIMESTAMP(3)
               AND receipt_type="preflight"
             GROUP BY receipt_type'
        );
        $statement->execute([max(0, $engineGeneration)]);
        $found = array_fill_keys(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)), true);
        if (!isset($found['preflight'])) {
            return ['ok' => false, 'reason' => 'preflight_receipt_missing'];
        }
        $accounts = array_map('intval', $this->pdo->query(
            'SELECT id FROM meli_accounts WHERE status IN ("conectado","connected") ORDER BY id'
        )->fetchAll(PDO::FETCH_COLUMN));
        if ($accounts === []) {
            return ['ok' => false, 'reason' => 'no_connected_accounts'];
        }
        $perAccount = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT r.meli_account_id)
             FROM queue_core_readiness_receipts r
             JOIN meli_accounts a ON a.id=r.meli_account_id
               AND a.status IN ("conectado","connected")
             WHERE r.engine_generation=? AND r.receipt_type=? AND r.status="pass"
               AND r.expires_at>UTC_TIMESTAMP(3)'
        );
        foreach (['canary', 'convergence'] as $type) {
            $perAccount->execute([max(0, $engineGeneration), $type]);
            if ((int) $perAccount->fetchColumn() !== count($accounts)) {
                return ['ok' => false, 'reason' => $type . '_account_receipts_missing'];
            }
        }
        return ['ok' => true, 'reason' => 'ready'];
    }
}
