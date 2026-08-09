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
        $preflight = (new QueueCorePreflightService($this->pdo))->check(false);
        if (empty($preflight['ok'])) {
            return ['ok' => false, 'reason' => 'current_preflight_failed'];
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
        $backupPath=trim((string)Env::get('QUEUE_CORE_APPROVED_BACKUP_PATH',''));
        $backupSha=trim((string)Env::get('QUEUE_CORE_APPROVED_BACKUP_SHA256',''));
        // La certificación ya ligó el dump a los conteos vivos previos. En el CAS se
        // vuelve a abrir y verificar el mismo artefacto/hash sin rechazar crecimiento
        // legítimo producido por el canario de solo lectura.
        if(!(new QueueCoreReleaseEvidenceService($this->pdo))->verifyBackup($backupPath,$backupSha,true)['ok']){
            return ['ok'=>false,'reason'=>'backup_artifact_unavailable'];
        }
        $manifest = (new \App\Services\QueueCoreDeploymentGateService($this->pdo))->runtimeManifestCheck();
        if (!$manifest['ok']) {
            return ['ok' => false, 'reason' => 'runtime_manifest_mismatch'];
        }
        $blockers = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM queue_core_jobs
             WHERE queue_domain='operational'
               AND (state IN ('review','dead') OR dispatch_state='DISPATCHED_RESULT_UNCERTAIN')"
        )->fetchColumn();
        if ($blockers > 0) {
            return ['ok' => false, 'reason' => 'go_live_work_blocked'];
        }
        if ((new QueueCoreHealthService($this->pdo))->snapshot()['health'] === 'RED') {
            return ['ok' => false, 'reason' => 'health_red'];
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
        $manifestPath = dirname(__DIR__, 2) . '/resources/runtime-manifest.json';
        $manifestHash = is_file($manifestPath) ? hash_file('sha256', $manifestPath) : false;
        $profile = $this->runtimeProfile();
        // El checkpoint es salida mutable del propio canario y no puede formar
        // parte de la identidad de readiness: avanzarlo invalidaría el recibo
        // que está intentando producir. Se liga el contrato de selección y
        // sus overrides operatorios; la ventana/captura exacta queda ligada en
        // los recibos canary/convergence.
        $bootstrapStatement = $this->pdo->query(
            "SELECT setting_key,setting_value FROM app_settings
             WHERE setting_key LIKE 'queue_core.fresh_orders.bootstrap_from.%'
             ORDER BY setting_key"
        );
        $bootstrapAuthorities = [
            'contract' => 'checkpoint_then_local_overlap_then_certified_legacy_then_explicit:v1',
            'operator_overrides' => $bootstrapStatement->fetchAll(PDO::FETCH_ASSOC),
        ];
        $context = [
            'engine_generation' => max(0, $engineGeneration),
            'accounts' => $accounts,
            'features' => $flags,
            'bootstrap_authorities' => $bootstrapAuthorities,
            'runtime' => [
                'cron_v4' => Env::bool('CRON_V4_ENABLED', false),
                'cron_v3' => Env::bool('CRON_V3_ENABLED', false),
                'cron_v3_shadow' => Env::bool('CRON_V3_SHADOW_ENABLED', false),
                'ml_write' => Env::bool('ML_WRITE_ENABLED', false),
                'profile' => $profile,
            ],
            'release_manifest_hash' => is_string($manifestHash) ? $manifestHash : 'missing',
            'approved_backup_sha256' => strtolower(trim((string) Env::get('QUEUE_CORE_APPROVED_BACKUP_SHA256', ''))),
            'approved_backup_path_hash' => hash('sha256',str_replace('\\','/',trim((string)Env::get('QUEUE_CORE_APPROVED_BACKUP_PATH','')))),
            'capability_registry_hash' => (new QueueCapabilityRegistry())->authorityHash(),
            'safety' => [
                'api' => (string) ($safety['api'] ?? 'unknown'),
                // Automation STOP is revalidated in the activation CAS. It is
                // a precondition, not part of the post-cutover health identity.
                'automation_required_at_activation' => 'stopped',
            ],
        ];
        return hash('sha256', json_encode($context, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return array{cadence_seconds:int,runtime_seconds:int,safe_close_seconds:int,max_remote_jobs:int,safe_http_per_minute:float} */
    public function runtimeProfile(): array
    {
        $defaults = [
            'cadence_seconds' => 60,
            'runtime_seconds' => 45,
            'safe_close_seconds' => 10,
            'max_remote_jobs' => 3,
            'safe_http_per_minute' => 3.0,
        ];
        $statement = $this->pdo->query(
            "SELECT setting_key,setting_value FROM app_settings
             WHERE setting_key IN (
               'queue_core.v4.cadence_seconds','queue_core.v4.runtime_seconds',
               'queue_core.v4.safe_close_seconds','queue_core.v4.max_remote_jobs',
               'queue_core.v4.safe_http_per_minute'
             )"
        );
        $values = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values[(string) $row['setting_key']] = (string) $row['setting_value'];
        }
        return [
            'cadence_seconds' => max(1, (int) ($values['queue_core.v4.cadence_seconds'] ?? $defaults['cadence_seconds'])),
            'runtime_seconds' => max(5, (int) ($values['queue_core.v4.runtime_seconds'] ?? $defaults['runtime_seconds'])),
            'safe_close_seconds' => max(1, (int) ($values['queue_core.v4.safe_close_seconds'] ?? $defaults['safe_close_seconds'])),
            'max_remote_jobs' => max(1, (int) ($values['queue_core.v4.max_remote_jobs'] ?? $defaults['max_remote_jobs'])),
            'safe_http_per_minute' => max(0.1, (float) ($values['queue_core.v4.safe_http_per_minute'] ?? $defaults['safe_http_per_minute'])),
        ];
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
                    COALESCE(t.refresh_version,0) refresh_version,t.expires_at,
                    (t.access_token_encrypted IS NOT NULL AND t.access_token_encrypted<>"") access_present,
                    (t.refresh_token_encrypted IS NOT NULL AND t.refresh_token_encrypted<>"") refresh_present
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
                'expires_at' => $includeIdentity ? (string) ($row['expires_at'] ?? '') : '',
                'access_present' => $includeIdentity ? (int) ($row['access_present'] ?? 0) : 0,
                'refresh_present' => $includeIdentity ? (int) ($row['refresh_present'] ?? 0) : 0,
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
