<?php

declare(strict_types=1);

namespace App\QueueCore;

use App\Core\Env;
use App\Services\EmergencyControlService;
use PDO;
use RuntimeException;

/** Autoridad de evidencia de activacion. Solo el ultimo recibo de cada scope decide. */
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
        if ($type === 'preflight') {
            $companyId = null;
            $accountId = null;
        } elseif ($companyId === null || $companyId < 1 || $accountId === null || $accountId < 1
            || !$this->accountBelongsToCompany($companyId, $accountId)) {
            throw new RuntimeException('Queue Core readiness receipt scope is invalid.');
        }
        $authority = $this->readinessAuthority();
        if ($authority['active_engine'] !== 'disabled'
            || $authority['readiness_mode'] !== 'preparing'
            || $authority['generation'] !== max(0, $engineGeneration)) {
            throw new RuntimeException('Queue Core readiness mode is not current.');
        }
        $contextHash = $this->currentContextHash($engineGeneration);
        if ($authority['readiness_context_hash'] === ''
            || !hash_equals($authority['readiness_context_hash'], $contextHash)) {
            throw new RuntimeException('Queue Core readiness context changed before evidence was recorded.');
        }
        ksort($metrics);
        $json = json_encode($metrics, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $statement = $this->pdo->prepare(
            'INSERT INTO queue_core_readiness_receipts
             (engine_generation,receipt_type,company_id,meli_account_id,status,context_hash,evidence_hash,metrics_json,expires_at)
             VALUES (?,?,?,?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ? SECOND))'
        );
        $statement->execute([
            max(0, $engineGeneration), $type, $companyId, $accountId,
            $passed ? 'pass' : 'fail', $contextHash, hash('sha256', $json), $json,
            max(60, min(86400, $ttlSeconds)),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Queue Core readiness receipt was not persisted.');
        }
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
        $authority = $this->readinessAuthority();
        if ($authority['active_engine'] !== 'disabled'
            || $authority['readiness_mode'] !== 'preparing'
            || $authority['generation'] !== max(0, $engineGeneration)) {
            return ['ok' => false, 'reason' => 'readiness_mode_not_current'];
        }
        $contextHash = $this->currentContextHash($engineGeneration);
        if ($authority['readiness_context_hash'] === ''
            || !hash_equals($authority['readiness_context_hash'], $contextHash)) {
            return ['ok' => false, 'reason' => 'readiness_context_changed'];
        }
        $flags = new QueueCoreFeatureFlagService($this->pdo);
        foreach (['fresh_producer', 'webhook_producer', 'pack_shipment_followups'] as $feature) {
            if (!$flags->enabled($feature)) {
                return ['ok' => false, 'reason' => $feature . '_disabled'];
            }
        }
        if (!$this->latestPassed($engineGeneration, 'preflight', null, null, $contextHash)) {
            return ['ok' => false, 'reason' => 'preflight_receipt_missing'];
        }
        $accounts = $this->connectedAccounts();
        if ($accounts === []) {
            return ['ok' => false, 'reason' => 'no_connected_accounts'];
        }
        foreach ($accounts as $account) {
            foreach (['canary', 'convergence'] as $type) {
                if (!$this->latestPassed(
                    $engineGeneration,
                    $type,
                    $account['company_id'],
                    $account['meli_account_id'],
                    $contextHash,
                )) {
                    return ['ok' => false, 'reason' => $type . '_account_receipts_missing'];
                }
            }
        }
        $releaseEvidence = new QueueCoreReleaseEvidenceService($this->pdo);
        foreach (['backup', 'capacity', 'manifest'] as $type) {
            $evidence = $releaseEvidence->requireLatest(
                $engineGeneration,
                $type,
                $contextHash,
            );
            if (!$evidence['ok']) {
                return ['ok' => false, 'reason' => $evidence['reason']];
            }
        }
        return ['ok' => true, 'reason' => 'ready'];
    }

    public function currentContextHash(int $engineGeneration): string
    {
        $accounts = $this->connectedAccounts(true);
        $flags = $this->pdo->query(
            'SELECT feature_key,enabled,COALESCE(hard_cap,0) hard_cap,generation
             FROM queue_core_feature_flags ORDER BY feature_key'
        )->fetchAll(PDO::FETCH_ASSOC);
        $safety = (new EmergencyControlService())->status();
        $context = [
            'engine_generation' => max(0, $engineGeneration),
            'accounts' => $accounts,
            'features' => $flags,
            'runtime' => [
                'cron_v4' => Env::bool('CRON_V4_ENABLED', false),
                'cron_v3' => Env::bool('CRON_V3_ENABLED', false),
                'cron_v3_shadow' => Env::bool('CRON_V3_SHADOW_ENABLED', false),
                'ml_write' => Env::bool('ML_WRITE_ENABLED', false),
            ],
            'safety' => [
                'api' => (string) ($safety['api'] ?? 'unknown'),
                'automation' => (string) ($safety['automation'] ?? 'unknown'),
            ],
        ];
        return hash('sha256', json_encode($context, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return array{active_engine:string,readiness_mode:string,readiness_context_hash:string,generation:int} */
    private function readinessAuthority(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT active_engine,readiness_mode,readiness_context_hash,generation
             FROM queue_engine_control WHERE control_key="primary" LIMIT 1'
        );
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Queue Core readiness authority is unavailable.');
        }
        return [
            'active_engine' => (string) $row['active_engine'],
            'readiness_mode' => (string) $row['readiness_mode'],
            'readiness_context_hash' => (string) ($row['readiness_context_hash'] ?? ''),
            'generation' => max(0, (int) $row['generation']),
        ];
    }

    private function latestPassed(
        int $generation,
        string $type,
        ?int $companyId,
        ?int $accountId,
        string $contextHash,
    ): bool {
        $statement = $this->pdo->prepare(
            'SELECT status,context_hash,expires_at
             FROM queue_core_readiness_receipts
             WHERE engine_generation=? AND receipt_type=?
               AND company_id <=> ? AND meli_account_id <=> ?
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([$generation, $type, $companyId, $accountId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row)
            && (string) $row['status'] === 'pass'
            && (string) $row['context_hash'] !== ''
            && hash_equals($contextHash, (string) $row['context_hash'])
            && (strtotime((string) $row['expires_at'] . ' UTC') ?: 0) > time();
    }

    /** @return list<array{company_id:int,meli_account_id:int,identity:string,refresh_version:int}> */
    private function connectedAccounts(bool $includeIdentity = false): array
    {
        $statement = $this->pdo->query(
            'SELECT a.company_id,a.id meli_account_id,a.meli_user_id,
                    COALESCE(t.refresh_version,0) refresh_version
             FROM meli_accounts a
             LEFT JOIN meli_tokens t ON t.meli_account_id=a.id
             WHERE a.status IN ("conectado","connected")
             ORDER BY a.company_id,a.id'
        );
        $accounts = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $accounts[] = [
                'company_id' => (int) $row['company_id'],
                'meli_account_id' => (int) $row['meli_account_id'],
                'identity' => $includeIdentity ? hash('sha256', (string) ($row['meli_user_id'] ?? '')) : '',
                'refresh_version' => max(0, (int) $row['refresh_version']),
            ];
        }
        return $accounts;
    }

    private function accountBelongsToCompany(int $companyId, int $accountId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM meli_accounts
             WHERE id=? AND company_id=? AND status IN ("conectado","connected")'
        );
        $statement->execute([$accountId, $companyId]);
        return (int) $statement->fetchColumn() === 1;
    }
}
