<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use App\QueueCore\QueueCoreCanaryService;
use App\QueueCore\QueueCoreConvergenceService;
use App\QueueCore\QueueCoreFeatureFlagService;
use App\QueueCore\QueueCorePreflightService;
use App\QueueCore\QueueCoreReadinessOperationLock;
use App\QueueCore\QueueCoreReadinessReceiptService;
use App\QueueCore\QueueCoreReleaseEvidenceService;
use App\QueueCore\QueueEngineControlService;
use Closure;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Orquestador administrativo acotado para certificar readiness V4 sin crear
 * scheduler ni activar Queue Engine. Cada POST avanza como máximo una etapa
 * remota (una cuenta) y toda falla posterior al armado vuelve a fail-closed.
 */
final class V4ReadinessBootstrapService
{
    public const CONFIRMATION_PHRASE = 'PREPARAR_Y_CERTIFICAR_V4_SIN_SCHEDULER';
    public const REQUIRED_VERSION = '2.36.9';
    public const LAST_MIGRATION = '293_queue_core_runtime_profile_defaults_b2_1.sql';
    public const LOCK_NAME = 'erp_meli_v4_readiness_bootstrap_2366';
    public const SCHEDULER_AUTHORITY_KEY = 'queue_core.v4.scheduler_authority';
    public const CERTIFIED_RECEIPT_KEY = 'queue_core.v4.readiness_certified_receipt';

    /** @var array<string,bool> */
    private const FLAGS_DISABLED = [
        'fresh_producer' => false,
        'webhook_producer' => false,
        'pack_shipment_followups' => false,
        'remote_financial' => false,
        'historical_importer' => false,
    ];

    /** @var array<string,bool> */
    private const FLAGS_READY = [
        'fresh_producer' => true,
        'webhook_producer' => true,
        'pack_shipment_followups' => true,
        'remote_financial' => false,
        'historical_importer' => false,
    ];

    public function __construct(
        private readonly ?Closure $pdoFactory = null,
        private readonly ?string $configPath = null,
        private readonly ?Closure $canaryRunner = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        try {
            $pdo = $this->connection();
            return $this->snapshotWithPdo($pdo);
        } catch (Throwable $error) {
            return [
                'ok' => false,
                'state' => 'blocked',
                'reason' => $this->safeReason($error),
                'scheduler_created' => false,
                'engine_activated' => false,
            ];
        }
    }

    /** @return array<string,mixed> */
    public function advance(int $actorUserId): array
    {
        $this->assertRequestAuthority($actorUserId);
        $pdo = $this->connection();
        $locked = false;
        try {
            $lock = $pdo->prepare('SELECT GET_LOCK(?,0)');
            $lock->execute([self::LOCK_NAME]);
            if ((int) $lock->fetchColumn() !== 1) {
                throw new RuntimeException('v4_bootstrap_lock_busy');
            }
            $locked = true;

            $snapshot = $this->snapshotWithPdo($pdo);
            $state = (string) ($snapshot['state'] ?? 'blocked');
            if ($state === 'recovery_required') {
                $recovery = $this->rollbackAuthorities(
                    $pdo,
                    $actorUserId,
                    'partial_arm_recovery_2369',
                );
                return [
                    'ok' => $recovery['state'] === 'rolled_back',
                    'state' => 'recovered_fail_closed',
                    'message' => 'El armado parcial fue restaurado a fail-closed. Vuelva a pulsar para iniciar una preparación nueva.',
                    'requires_next_request' => true,
                    'next_single_action' => 'USER_PRESS_PREPARE_AND_CERTIFY_V4_AGAIN',
                    'scheduler_created' => false,
                    'engine_activated' => false,
                ];
            }
            if ($state === 'ready_to_arm') {
                return $this->armStableAuthorities($pdo, $actorUserId);
            }
            if ($state === 'ready_for_context') {
                return $this->enterReadiness($pdo, $actorUserId);
            }
            if ($state === 'preparing') {
                return $this->advancePreparing($pdo, $actorUserId, $snapshot);
            }
            if ($state === 'certified') {
                return [
                    'ok' => true,
                    'state' => 'certified',
                    'message' => 'Readiness V4 ya está certificado. No se repitió ninguna prueba.',
                    'next_single_action' => 'REVIEW_BEFORE_CREATING_HOSTINGER_SCHEDULER',
                    'scheduler_created' => false,
                    'engine_activated' => false,
                ];
            }
            throw new RuntimeException('v4_bootstrap_state_not_actionable:' . (string) ($snapshot['reason'] ?? $state));
        } catch (Throwable $error) {
            $rollback = $this->rollbackIfArmed($pdo, $actorUserId, $error);
            throw new RuntimeException(
                $this->safeReason($error) . ($rollback['attempted'] ? ':' . $rollback['state'] : ''),
                0,
                $error,
            );
        } finally {
            if ($locked) {
                try {
                    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $release->execute([self::LOCK_NAME]);
                } catch (Throwable) {
                }
            }
        }
    }

    /** @return array<string,mixed> */
    public function rollback(int $actorUserId, string $confirmation): array
    {
        if ($actorUserId < 1 || !hash_equals(self::CONFIRMATION_PHRASE, trim($confirmation))) {
            throw new RuntimeException('v4_bootstrap_confirmation_invalid');
        }
        $pdo = $this->connection();
        $result = $this->rollbackAuthorities($pdo, $actorUserId, 'operator_requested');
        return [
            'ok' => $result['state'] === 'rolled_back',
            'state' => $result['state'],
            'message' => 'Readiness V4 fue devuelto a estado fail-closed.',
            'next_single_action' => 'REVIEW_ROLLBACK_RECEIPT',
            'scheduler_created' => false,
            'engine_activated' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function armStableAuthorities(PDO $pdo, int $actorUserId): array
    {
        $pre = $this->preconditions($pdo, false);
        if (!$pre['ok']) {
            throw new RuntimeException('v4_bootstrap_preconditions:' . (string) $pre['reason']);
        }
        $this->upsertSetting($pdo, self::SCHEDULER_AUTHORITY_KEY, json_encode([
            'status' => 'absent',
            'authority' => 'permanent_admin_explicit_confirmation',
            'actor_user_id' => $actorUserId,
            'confirmed_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $flags = new QueueCoreFeatureFlagService($pdo);
        $flags->compareAndSwapReadinessFlags(self::FLAGS_DISABLED, self::FLAGS_READY, 1);
        (new EmergencyControlService())->startApiWithoutCanary(
            'v4_readiness_bootstrap_2369',
            'Lecturas habilitadas para readiness V4 acotado; automatización permanece detenida.',
        );
        (new CronV3SetupAssistantService($pdo, $this->configPath()))
            ->prepareV4ReadinessConfig($actorUserId);

        return [
            'ok' => true,
            'state' => 'environment_armed',
            'message' => 'Configuración estable preparada. Vuelva a pulsar para crear el contexto generation 1.',
            'requires_next_request' => true,
            'next_single_action' => 'USER_PRESS_PREPARE_AND_CERTIFY_V4_AGAIN',
            'scheduler_created' => false,
            'engine_activated' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function enterReadiness(PDO $pdo, int $actorUserId): array
    {
        $pre = $this->preconditions($pdo, true);
        if (!$pre['ok']) {
            throw new RuntimeException('v4_bootstrap_preconditions:' . (string) $pre['reason']);
        }
        $engineService = new QueueEngineControlService($pdo);
        $transition = $engineService->compareAndSwapReadiness(
            'preparing',
            (int) $pre['engine']['generation'],
            'admin:' . $actorUserId . ':v4_readiness_bootstrap_2369',
        );
        if (empty($transition['ok']) || (int) ($transition['generation'] ?? -1) !== 1) {
            throw new RuntimeException('v4_bootstrap_generation_transition_failed');
        }
        $generation = 1;
        $contextHash = (string) ($transition['readiness_context_hash'] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/', $contextHash) !== 1) {
            throw new RuntimeException('v4_bootstrap_context_missing');
        }

        return QueueCoreReadinessOperationLock::with($pdo, function () use ($pdo, $generation, $contextHash): array {
            $preflight = (new QueueCorePreflightService($pdo))->check(true);
            if (empty($preflight['ok'])) {
                throw new RuntimeException('v4_bootstrap_preflight_failed');
            }
            $receipts = new QueueCoreReadinessReceiptService($pdo);
            $receipts->record($generation, 'preflight', true, [
                'issue_count' => 0,
                'missing_index_count' => 0,
                'missing_table_count' => 0,
                'account_count' => count((array) ($preflight['accounts'] ?? [])),
                'schema' => 293,
            ], 3600);

            $release = new QueueCoreReleaseEvidenceService($pdo);
            $backup = $release->certifyBackup(
                $generation,
                $contextHash,
                trim((string) Env::get('QUEUE_CORE_APPROVED_BACKUP_PATH', '')),
                trim((string) Env::get('QUEUE_CORE_APPROVED_BACKUP_SHA256', '')),
                3600,
            );
            if (empty($backup['ok'])) {
                throw new RuntimeException('v4_bootstrap_backup_evidence_failed');
            }
            $manifest = $release->certifyManifest($generation, $contextHash, 3600);
            if (empty($manifest['ok'])) {
                throw new RuntimeException('v4_bootstrap_manifest_evidence_failed');
            }

            return [
                'ok' => true,
                'state' => 'preparing',
                'message' => 'Contexto generation 1 y evidencia base certificados. Siguiente clic: canario de una cuenta.',
                'generation' => $generation,
                'readiness_context_hash' => $contextHash,
                'next_single_action' => 'USER_PRESS_PREPARE_AND_CERTIFY_V4_AGAIN',
                'scheduler_created' => false,
                'engine_activated' => false,
            ];
        });
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function advancePreparing(PDO $pdo, int $actorUserId, array $snapshot): array
    {
        $generation = (int) ($snapshot['engine']['generation'] ?? -1);
        $contextHash = (string) ($snapshot['engine']['readiness_context_hash'] ?? '');
        if ($generation !== 1 || preg_match('/^[a-f0-9]{64}$/', $contextHash) !== 1) {
            throw new RuntimeException('v4_bootstrap_readiness_authority_invalid');
        }
        $currentHash = (new QueueCoreReadinessReceiptService($pdo))->currentContextHash($generation);
        if (!hash_equals($contextHash, $currentHash)) {
            throw new RuntimeException('v4_bootstrap_context_drift');
        }

        $accounts = $this->connectedAccounts($pdo);
        foreach ($accounts as $account) {
            if (!$this->latestReceiptPassed($pdo, $generation, $contextHash, 'canary', $account)) {
                $result = $this->runCanary($pdo, (int) $account['meli_account_id']);
                if (empty($result['ok'])) {
                    throw new RuntimeException('v4_bootstrap_canary_failed:' . (string) ($result['reason'] ?? 'unknown'));
                }
                return [
                    'ok' => true,
                    'state' => 'preparing',
                    'stage' => 'canary',
                    'account' => $this->safeAccount($account),
                    'remote_http_calls' => (int) ($result['physical_http_calls'] ?? 0),
                    'message' => 'Canario Queue Core aprobado para una cuenta. No se activó el engine.',
                    'next_single_action' => 'USER_PRESS_PREPARE_AND_CERTIFY_V4_AGAIN',
                    'scheduler_created' => false,
                    'engine_activated' => false,
                ];
            }
        }

        foreach ($accounts as $account) {
            if (!$this->latestReceiptPassed($pdo, $generation, $contextHash, 'convergence', $account)) {
                return $this->recordConvergence($pdo, $generation, $account);
            }
        }

        return $this->certifyFinal($pdo, $actorUserId, $generation, $contextHash);
    }

    /** @param array<string,int> $account @return array<string,mixed> */
    private function recordConvergence(PDO $pdo, int $generation, array $account): array
    {
        return QueueCoreReadinessOperationLock::with($pdo, function () use ($pdo, $generation, $account): array {
            $scope = $pdo->prepare(
                'SELECT MIN(window_from) from_utc,MAX(window_to) to_utc
                 FROM queue_core_readiness_captures
                 WHERE engine_generation=? AND company_id=? AND meli_account_id=? AND complete=1'
            );
            $scope->execute([$generation, $account['company_id'], $account['meli_account_id']]);
            $window = $scope->fetch(PDO::FETCH_ASSOC);
            if (!is_array($window) || empty($window['from_utc']) || empty($window['to_utc'])) {
                throw new RuntimeException('v4_bootstrap_convergence_window_missing');
            }
            $service = new QueueCoreConvergenceService($pdo);
            $result = $service->compare(
                (int) $account['company_id'],
                (int) $account['meli_account_id'],
                (string) $window['from_utc'],
                (string) $window['to_utc'],
                500,
            );
            (new QueueCoreReadinessReceiptService($pdo))->record(
                $generation,
                'convergence',
                !empty($result['ok']),
                [
                    'remote_count' => (int) ($result['remote_identity_count'] ?? 0),
                    'local_count' => (int) ($result['local_identity_count'] ?? 0),
                    'unresolved_count' => (int) ($result['unresolved_exact_count'] ?? 0),
                    'page_count' => (int) ($result['authoritative_page_count'] ?? 0),
                    'empty_window' => !empty($result['authoritative_empty_window']) ? 1 : 0,
                ],
                3600,
                (int) $account['company_id'],
                (int) $account['meli_account_id'],
            );
            if (empty($result['ok'])) {
                throw new RuntimeException('v4_bootstrap_convergence_failed');
            }
            return [
                'ok' => true,
                'state' => 'preparing',
                'stage' => 'convergence',
                'account' => $this->safeAccount($account),
                'remote_http_calls' => 0,
                'message' => 'Convergencia fresh aprobada para una cuenta.',
                'next_single_action' => 'USER_PRESS_PREPARE_AND_CERTIFY_V4_AGAIN',
                'scheduler_created' => false,
                'engine_activated' => false,
            ];
        });
    }

    /** @return array<string,mixed> */
    private function certifyFinal(PDO $pdo, int $actorUserId, int $generation, string $contextHash): array
    {
        return QueueCoreReadinessOperationLock::with($pdo, function () use (
            $pdo, $actorUserId, $generation, $contextHash
        ): array {
            $readiness = new QueueCoreReadinessReceiptService($pdo);
            $release = new QueueCoreReleaseEvidenceService($pdo);
            $profile = $readiness->runtimeProfile();
            $calculation = $release->measuredCapacity(60, $profile);
            $capacity = $release->certifyCapacity($generation, $contextHash, $calculation, [
                'cadence_seconds' => $profile['cadence_seconds'],
                'runtime_seconds' => $profile['runtime_seconds'],
                'safe_close_seconds' => $profile['safe_close_seconds'],
                'max_remote_jobs' => $profile['max_remote_jobs'],
            ], 3600);
            if (empty($capacity['ok'])) {
                throw new RuntimeException('v4_bootstrap_capacity_failed');
            }
            $activation = $readiness->canActivateV4($generation);
            if (empty($activation['ok'])) {
                throw new RuntimeException('v4_bootstrap_activation_gate:' . (string) ($activation['reason'] ?? 'unknown'));
            }
            $receipt = [
                'operation' => 'v4_readiness_bootstrap_2369',
                'result' => 'PASS',
                'generation' => $generation,
                'readiness_context_hash' => $contextHash,
                'can_activate_v4' => true,
                'scheduler' => 'absent',
                'engine' => 'disabled',
                'actor_user_id' => $actorUserId,
                'certified_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
            $json = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $this->upsertSetting($pdo, self::CERTIFIED_RECEIPT_KEY, $json);

            return [
                'ok' => true,
                'state' => 'certified',
                'message' => 'Readiness V4 certificado. Scheduler ausente y Queue Engine continúa disabled.',
                'receipt' => $receipt + ['receipt_sha256' => hash('sha256', $json)],
                'next_single_action' => 'REVIEW_BEFORE_CREATING_HOSTINGER_SCHEDULER',
                'scheduler_created' => false,
                'engine_activated' => false,
            ];
        });
    }

    /** @return array<string,mixed> */
    private function snapshotWithPdo(PDO $pdo): array
    {
        $engine = (new QueueEngineControlService($pdo))->snapshot();
        $flags = (new QueueCoreFeatureFlagService($pdo))->snapshot();
        $pre = $this->preconditions($pdo, Env::bool('CRON_V4_ENABLED', false));
        $contextStable = false;
        if ($engine['readiness_mode'] === 'preparing' && $engine['readiness_context_hash'] !== '') {
            $calculated = (new QueueCoreReadinessReceiptService($pdo))->currentContextHash($engine['generation']);
            $contextStable = hash_equals($engine['readiness_context_hash'], $calculated);
        }
        $certified = $this->certifiedReceipt($pdo, $engine);
        $certificationGate = null;
        if ($certified !== null && $contextStable
            && $engine['active_engine'] === 'disabled'
            && $engine['readiness_mode'] === 'preparing') {
            try {
                $certificationGate = (new QueueCoreReadinessReceiptService($pdo))
                    ->canActivateV4((int) $engine['generation']);
            } catch (Throwable $error) {
                $certificationGate = [
                    'ok' => false,
                    'issues' => ['certified_authority_check_failed:' . $this->safeReason($error)],
                ];
            }
        }

        $state = 'blocked';
        $reason = (string) ($pre['reason'] ?? 'preconditions_failed');
        if ($certified !== null && $contextStable && !empty($certificationGate['ok'])) {
            $state = 'certified';
            $reason = 'ready';
        } elseif ($certified !== null) {
            $state = 'blocked';
            $reason = 'certified_authority_not_current';
        } elseif ($engine['active_engine'] === 'disabled' && $engine['readiness_mode'] === 'preparing') {
            $state = $contextStable ? 'preparing' : 'blocked';
            $reason = $contextStable ? 'readiness_in_progress' : 'readiness_context_changed';
        } elseif (self::isRecoverablePartialArm($engine, $flags, $pre)) {
            $state = 'recovery_required';
            $reason = 'partial_arm_requires_fail_closed_recovery';
        } elseif ($engine['active_engine'] === 'disabled' && $engine['readiness_mode'] === 'idle'
            && $engine['generation'] === 0 && $pre['base_ok']) {
            if ($pre['runtime_ready'] && self::flagsMatch($flags, self::FLAGS_READY)
                && $pre['scheduler_absent_recorded']) {
                $state = 'ready_for_context';
                $reason = 'stable_authorities_ready';
            } elseif ($pre['runtime_fail_closed'] && self::flagsMatch($flags, self::FLAGS_DISABLED)) {
                $state = 'ready_to_arm';
                $reason = 'preconditions_pass';
            }
        }

        return [
            'ok' => in_array(
                $state,
                ['recovery_required', 'ready_to_arm', 'ready_for_context', 'preparing', 'certified'],
                true,
            ),
            'state' => $state,
            'reason' => $reason,
            'engine' => $engine,
            'feature_flags' => $flags,
            'preconditions' => $pre,
            'receipt_inventory' => $this->receiptInventory($pdo, $engine),
            'certified_receipt' => $certified,
            'certification_gate' => $certificationGate,
            'context_hash_stable' => $contextStable,
            'scheduler_created' => false,
            'engine_activated' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function preconditions(PDO $pdo, bool $expectRuntimeReady): array
    {
        $issues = [];
        $fileVersion = trim((string) @file_get_contents(dirname(__DIR__, 2) . '/VERSION'));
        $appVersion = (string) $pdo->query(
            "SELECT setting_value FROM app_settings WHERE setting_key='app.version' LIMIT 1"
        )->fetchColumn();
        if ($fileVersion !== self::REQUIRED_VERSION || $appVersion !== self::REQUIRED_VERSION) {
            $issues[] = 'version_not_2369';
        }
        $schemaAuthority = $pdo->query(
            "SELECT COUNT(*) AS total,
                    COALESCE(MAX(CAST(SUBSTRING_INDEX(version,'_',1) AS UNSIGNED)),0) AS max_version,
                    SUM(version='" . self::LAST_MIGRATION . "') AS migration_293
             FROM schema_migrations"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $schemaCount = (int) ($schemaAuthority['total'] ?? 0);
        $schemaMax = (int) ($schemaAuthority['max_version'] ?? 0);
        $migration293Count = (int) ($schemaAuthority['migration_293'] ?? 0);
        if ($schemaCount !== 293 || $schemaMax !== 293 || $migration293Count !== 1) {
            $issues[] = 'schema_authority_invalid';
        }
        $v3 = (int) $pdo->query(
            "SELECT COUNT(*) FROM cron_v3_queue_ownership WHERE owner_engine='v3' OR enabled=1"
        )->fetchColumn();
        if ($v3 !== 0) {
            $issues[] = 'v3_ownership_active';
        }
        $retirement = $pdo->query(
            "SELECT setting_key,setting_value FROM app_settings
             WHERE setting_key IN ('cron_v3.operational_phase','cron_v3.certified_cutover.phase')
             ORDER BY setting_key"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        if (($retirement['cron_v3.operational_phase'] ?? '') !== 'retired_for_v4'
            || ($retirement['cron_v3.certified_cutover.phase'] ?? '') !== 'retired_for_v4') {
            $issues[] = 'v3_retirement_evidence_missing';
        }
        $historical = (new QueueCoreFeatureFlagService($pdo))->enabled('historical_importer');
        if ($historical) {
            $issues[] = 'historical_importer_enabled';
        }
        $featureRows = $pdo->query(
            'SELECT feature_key,generation FROM queue_core_feature_flags ORDER BY feature_key'
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $featureGenerations = [];
        foreach ($featureRows as $feature => $generation) {
            $featureGenerations[(string) $feature] = (int) $generation;
        }
        ksort($featureGenerations, SORT_STRING);
        $expectedFeatureGenerations = $expectRuntimeReady
            ? [
                'fresh_producer' => 1,
                'webhook_producer' => 1,
                'pack_shipment_followups' => 1,
                'remote_financial' => 0,
                'historical_importer' => 0,
            ]
            : array_fill_keys(QueueCoreFeatureFlagService::READINESS_FEATURES, 0);
        foreach ($expectedFeatureGenerations as $feature => $generation) {
            if (!array_key_exists($feature, $featureRows) || (int) $featureRows[$feature] !== $generation) {
                $issues[] = 'feature_generation_invalid:' . $feature;
            }
        }
        $leases = (int) $pdo->query(
            'SELECT COUNT(*) FROM queue_core_execution_leases WHERE expires_at IS NOT NULL AND expires_at>UTC_TIMESTAMP(3)'
        )->fetchColumn();
        if ($leases !== 0) {
            $issues[] = 'active_execution_lease';
        }
        $uncertain = (int) $pdo->query(
            "SELECT COUNT(*) FROM queue_core_jobs WHERE dispatch_state='DISPATCHED_RESULT_UNCERTAIN'"
        )->fetchColumn();
        if ($uncertain !== 0) {
            $issues[] = 'uncertain_execution_present';
        }
        $activeRuns = (int) $pdo->query(
            "SELECT COUNT(*) FROM queue_core_runs WHERE status='running'"
        )->fetchColumn();
        if ($activeRuns !== 0) {
            $issues[] = 'active_queue_run_present';
        }
        $engine = (new QueueEngineControlService($pdo))->snapshot();
        if ($engine['active_engine'] !== 'disabled') {
            $issues[] = 'engine_not_disabled';
        }
        $safety = (new EmergencyControlService())->status();
        if (($safety['automation'] ?? '') !== 'stopped') {
            $issues[] = 'automation_not_stopped';
        }
        if (Env::bool('ML_WRITE_ENABLED', false)) {
            $issues[] = 'ml_write_enabled';
        }
        $preflight = (new QueueCorePreflightService($pdo))->check(true);
        if (empty($preflight['ok'])) {
            $issues[] = 'queue_core_preflight_failed:' . (string) (($preflight['issues'][0] ?? 'unknown'));
        }
        if (count((array) ($preflight['accounts'] ?? [])) !== 3
            || in_array('account_access_token_expired', (array) ($preflight['issues'] ?? []), true)
            || in_array('account_access_token_near_expiry', (array) ($preflight['issues'] ?? []), true)) {
            $issues[] = 'oauth_accounts_not_current_3_of_3';
        }
        $scheduler = $this->schedulerAuthority($pdo);
        $runtimeFailClosed = !Env::bool('CRON_V4_ENABLED', false)
            && !Env::bool('CRON_V3_ENABLED', false)
            && !Env::bool('CRON_V3_SHADOW_ENABLED', false)
            && ($safety['api'] ?? '') === 'stopped';
        $runtimeReady = Env::bool('CRON_V4_ENABLED', false)
            && !Env::bool('CRON_V3_ENABLED', false)
            && !Env::bool('CRON_V3_SHADOW_ENABLED', false)
            && ($safety['api'] ?? '') === 'enabled';
        if ($expectRuntimeReady && !$runtimeReady) {
            $issues[] = 'readiness_runtime_not_stable';
        }

        $baseIssues = array_values(array_filter($issues, static fn (string $issue): bool =>
            $issue !== 'readiness_runtime_not_stable'
        ));
        return [
            'ok' => $issues === [],
            'base_ok' => $baseIssues === [],
            'reason' => $issues[0] ?? 'ready',
            'issues' => array_values(array_unique($issues)),
            'file_version' => $fileVersion,
            'app_version' => $appVersion,
            'schema' => $schemaMax,
            'schema_count' => $schemaCount,
            'migration_293_count' => $migration293Count,
            'pending_migrations' => max(0, $schemaMax - 293),
            'v3_active_ownership' => $v3,
            'v3_retired' => !in_array('v3_retirement_evidence_missing', $issues, true),
            'active_leases' => $leases,
            'uncertain_executions' => $uncertain,
            'active_runs' => $activeRuns,
            'historical_importer' => $historical,
            'feature_generations' => $featureGenerations,
            'engine' => $engine,
            'api' => (string) ($safety['api'] ?? 'unknown'),
            'automation' => (string) ($safety['automation'] ?? 'unknown'),
            'ml_write_enabled' => Env::bool('ML_WRITE_ENABLED', false),
            'cron_v4_enabled' => Env::bool('CRON_V4_ENABLED', false),
            'cron_v3_enabled' => Env::bool('CRON_V3_ENABLED', false),
            'cron_v3_shadow_enabled' => Env::bool('CRON_V3_SHADOW_ENABLED', false),
            'oauth_current_accounts' => count((array) ($preflight['accounts'] ?? [])),
            'queue_core_preflight_ok' => !empty($preflight['ok']),
            'scheduler_absent_recorded' => $scheduler['status'] === 'absent',
            'runtime_fail_closed' => $runtimeFailClosed,
            'runtime_ready' => $runtimeReady,
        ];
    }

    /** @return array{status:string,authority:string} */
    private function schedulerAuthority(PDO $pdo): array
    {
        $statement = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1');
        $statement->execute([self::SCHEDULER_AUTHORITY_KEY]);
        $decoded = json_decode((string) ($statement->fetchColumn() ?: ''), true);
        return [
            'status' => is_array($decoded) ? (string) ($decoded['status'] ?? 'unknown') : 'unknown',
            'authority' => is_array($decoded) ? (string) ($decoded['authority'] ?? 'unknown') : 'unknown',
        ];
    }

    /** @return list<array{company_id:int,meli_account_id:int}> */
    private function connectedAccounts(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT company_id,id meli_account_id FROM meli_accounts
             WHERE status IN ('conectado','connected') ORDER BY company_id,id"
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $row): array => [
            'company_id' => (int) $row['company_id'],
            'meli_account_id' => (int) $row['meli_account_id'],
        ], $rows);
    }

    /** @param array{company_id:int,meli_account_id:int} $account */
    private function latestReceiptPassed(
        PDO $pdo,
        int $generation,
        string $contextHash,
        string $type,
        array $account,
    ): bool {
        $statement = $pdo->prepare(
            'SELECT status,context_hash,expires_at FROM queue_core_readiness_receipts
             WHERE engine_generation=? AND receipt_type=? AND company_id=? AND meli_account_id=?
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([
            $generation, $type, $account['company_id'], $account['meli_account_id'],
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row)
            && (string) $row['status'] === 'pass'
            && hash_equals($contextHash, (string) $row['context_hash'])
            && (strtotime((string) $row['expires_at'] . ' UTC') ?: 0) > time();
    }

    /** @return array<string,mixed> */
    private function runCanary(PDO $pdo, int $accountId): array
    {
        if ($this->canaryRunner instanceof Closure) {
            return (array) ($this->canaryRunner)($pdo, $accountId);
        }
        return (new QueueCoreCanaryService($pdo))->run($accountId, 3, 2, 20);
    }

    /** @param array<string,mixed> $engine @return array<string,mixed>|null */
    private function certifiedReceipt(PDO $pdo, array $engine): ?array
    {
        $statement = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1');
        $statement->execute([self::CERTIFIED_RECEIPT_KEY]);
        $value = $statement->fetchColumn();
        $receipt = is_string($value) ? json_decode($value, true) : null;
        if (!is_array($receipt)
            || (int) ($receipt['generation'] ?? -1) !== (int) ($engine['generation'] ?? -2)
            || !hash_equals(
                (string) ($receipt['readiness_context_hash'] ?? ''),
                (string) ($engine['readiness_context_hash'] ?? ''),
            )) {
            return null;
        }
        return $receipt;
    }

    /** @param array<string,mixed> $engine @return array<string,mixed> */
    private function receiptInventory(PDO $pdo, array $engine): array
    {
        $generation = max(0, (int) ($engine['generation'] ?? 0));
        $context = (string) ($engine['readiness_context_hash'] ?? '');
        $required = ['preflight', 'canary:3', 'convergence:3', 'backup', 'capacity', 'manifest'];
        if ($context === '') {
            return ['required' => $required, 'present' => [], 'missing' => $required];
        }
        $present = [];
        $readiness = $pdo->prepare(
            "SELECT receipt_type,COUNT(DISTINCT CONCAT(company_id,':',meli_account_id)) scopes
             FROM queue_core_readiness_receipts
             WHERE engine_generation=? AND context_hash=? AND status='pass' AND expires_at>UTC_TIMESTAMP(3)
             GROUP BY receipt_type"
        );
        $readiness->execute([$generation, $context]);
        foreach ($readiness->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $type = (string) $row['receipt_type'];
            $present[] = $type === 'preflight' ? 'preflight' : $type . ':' . (int) $row['scopes'];
        }
        $release = $pdo->prepare(
            "SELECT evidence_type FROM queue_core_release_evidence
             WHERE engine_generation=? AND readiness_context_hash=? AND status='pass' AND expires_at>UTC_TIMESTAMP(3)
             GROUP BY evidence_type"
        );
        $release->execute([$generation, $context]);
        $present = array_merge($present, array_map('strval', $release->fetchAll(PDO::FETCH_COLUMN)));
        $present = array_values(array_unique($present));
        sort($present, SORT_STRING);
        return [
            'required' => $required,
            'present' => $present,
            'missing' => array_values(array_diff($required, $present)),
        ];
    }

    /** @return array{attempted:bool,state:string} */
    private function rollbackIfArmed(PDO $pdo, int $actorUserId, Throwable $error): array
    {
        try {
            $engine = (new QueueEngineControlService($pdo))->snapshot();
            $flags = (new QueueCoreFeatureFlagService($pdo))->snapshot();
            $armed = $engine['readiness_mode'] === 'preparing'
                || self::flagsMatch($flags, self::FLAGS_READY)
                || Env::bool('CRON_V4_ENABLED', false)
                || (new EmergencyControlService())->status()['api'] === 'enabled';
            if (!$armed) {
                return ['attempted' => false, 'state' => 'not_armed'];
            }
            $result = $this->rollbackAuthorities($pdo, $actorUserId, $this->safeReason($error));
            return ['attempted' => true, 'state' => $result['state']];
        } catch (Throwable) {
            return ['attempted' => true, 'state' => 'rollback_blocked'];
        }
    }

    /** @return array{state:string} */
    private function rollbackAuthorities(PDO $pdo, int $actorUserId, string $reason): array
    {
        $errors = [];
        $attempt = static function (string $authority, Closure $operation) use (&$errors): mixed {
            try {
                return $operation();
            } catch (Throwable $error) {
                $safe = preg_replace('/[^a-zA-Z0-9_.:-]+/', '_', $error->getMessage()) ?: 'failed';
                $errors[] = $authority . ':' . substr($safe, 0, 120);
                return null;
            }
        };

        // Estas dos autoridades cortan ejecución remota aun cuando un CAS DB
        // esté contendido. Ningún fallo posterior puede impedir intentarlas.
        $attempt('api', fn (): null => (new EmergencyControlService())->stopApi(
            'v4_readiness_rollback_2369',
            'Rollback fail-closed: ' . mb_substr($reason, 0, 120),
        ));
        $attempt('config', fn (): array => (new CronV3SetupAssistantService($pdo, $this->configPath()))
            ->restoreV4FailClosedConfig($actorUserId));

        $engine = $attempt('engine', function () use ($pdo, $actorUserId): array {
            $service = new QueueEngineControlService($pdo);
            $current = $service->snapshot();
            if ($current['active_engine'] !== 'disabled') {
                throw new RuntimeException('v4_bootstrap_rollback_engine_not_disabled');
            }
            if ($current['readiness_mode'] !== 'preparing') {
                return $current;
            }
            $transition = $service->compareAndSwapReadiness(
                'idle',
                (int) $current['generation'],
                'admin:' . $actorUserId . ':v4_readiness_rollback_2369',
            );
            if (empty($transition['ok'])) {
                throw new RuntimeException('v4_bootstrap_rollback_engine_cas_failed');
            }
            return $transition;
        });
        $generation = is_array($engine) ? max(0, (int) ($engine['generation'] ?? 0)) : 0;

        $attempt('features', function () use ($pdo, $generation): null {
            $flags = new QueueCoreFeatureFlagService($pdo);
            $current = $flags->snapshot();
            if (self::flagsMatch($current, self::FLAGS_READY)) {
                $flags->compareAndSwapReadinessFlags(self::FLAGS_READY, self::FLAGS_DISABLED, $generation);
            } elseif (!self::flagsMatch($current, self::FLAGS_DISABLED)) {
                throw new RuntimeException('v4_bootstrap_rollback_feature_authority_unknown');
            }
            return null;
        });

        $attempt('scheduler_authority', fn (): null => $this->upsertSetting(
            $pdo,
            self::SCHEDULER_AUTHORITY_KEY,
            json_encode([
                'status' => 'absent',
                'authority' => 'rollback_preserved_absence',
                'actor_user_id' => $actorUserId,
                'rolled_back_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));

        if ($errors !== []) {
            throw new RuntimeException('v4_bootstrap_rollback_incomplete:' . implode(',', array_unique($errors)));
        }
        return ['state' => 'rolled_back'];
    }

    private function assertRequestAuthority(int $actorUserId): void
    {
        if ($actorUserId < 1) {
            throw new RuntimeException('v4_bootstrap_admin_required');
        }
    }

    /** @param array<string,bool> $actual @param array<string,bool> $expected */
    private static function flagsMatch(array $actual, array $expected): bool
    {
        if (count($actual) !== count($expected)) {
            return false;
        }
        $actualCanonical = $actual;
        $expectedCanonical = $expected;
        ksort($actualCanonical, SORT_STRING);
        ksort($expectedCanonical, SORT_STRING);
        return $actualCanonical === $expectedCanonical;
    }

    /**
     * Reconoce únicamente la postimagen parcial observada cuando config.env ya
     * habilitó readiness, pero el resto del armado volvió a fail-closed. La
     * decisión usa autoridades estructuradas exactas y nunca textos de issues.
     * Este estado nunca continúa hacia Queue Engine: sólo autoriza compensación.
     *
     * @param array<string,mixed> $engine
     * @param array<string,bool> $flags
     * @param array<string,mixed> $pre
     */
    private static function isRecoverablePartialArm(array $engine, array $flags, array $pre): bool
    {
        $expectedGenerations = array_fill_keys(QueueCoreFeatureFlagService::READINESS_FEATURES, 0);
        ksort($expectedGenerations, SORT_STRING);
        $actualGenerations = (array) ($pre['feature_generations'] ?? []);
        ksort($actualGenerations, SORT_STRING);

        if (($engine['active_engine'] ?? '') !== 'disabled'
            || ($engine['readiness_mode'] ?? '') !== 'idle'
            || (int) ($engine['generation'] ?? -1) !== 0
            || (string) ($engine['readiness_context_hash'] ?? '') !== ''
            || !self::flagsMatch($flags, self::FLAGS_DISABLED)
            || $actualGenerations !== $expectedGenerations
            || ($pre['file_version'] ?? '') !== self::REQUIRED_VERSION
            || ($pre['app_version'] ?? '') !== self::REQUIRED_VERSION
            || (int) ($pre['schema'] ?? -1) !== 293
            || (int) ($pre['schema_count'] ?? -1) !== 293
            || (int) ($pre['migration_293_count'] ?? -1) !== 1
            || (int) ($pre['v3_active_ownership'] ?? -1) !== 0
            || empty($pre['v3_retired'])
            || (int) ($pre['active_leases'] ?? -1) !== 0
            || (int) ($pre['uncertain_executions'] ?? -1) !== 0
            || (int) ($pre['active_runs'] ?? -1) !== 0
            || !empty($pre['historical_importer'])
            || empty($pre['queue_core_preflight_ok'])
            || (int) ($pre['oauth_current_accounts'] ?? -1) !== 3
            || empty($pre['scheduler_absent_recorded'])
            || empty($pre['cron_v4_enabled'])
            || !empty($pre['cron_v3_enabled'])
            || !empty($pre['cron_v3_shadow_enabled'])
            || !empty($pre['ml_write_enabled'])
            || ($pre['api'] ?? '') !== 'stopped'
            || ($pre['automation'] ?? '') !== 'stopped') {
            return false;
        }
        return true;
    }

    private function upsertSetting(PDO $pdo, string $key, string $value): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group)
             VALUES (?,?,0,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
             is_encrypted=0,setting_group=VALUES(setting_group)'
        );
        $statement->execute([$key, $value, 'queue_core']);
        if ($statement->rowCount() < 1 && $this->settingValue($pdo, $key) !== $value) {
            throw new RuntimeException('v4_bootstrap_setting_write_failed');
        }
        AppSettingsService::clearCache($key);
    }

    private function settingValue(PDO $pdo, string $key): string
    {
        $statement = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1');
        $statement->execute([$key]);
        return (string) ($statement->fetchColumn() ?: '');
    }

    /** @param array{company_id:int,meli_account_id:int} $account @return array<string,int> */
    private function safeAccount(array $account): array
    {
        return [
            'company_id' => (int) $account['company_id'],
            'meli_account_id' => (int) $account['meli_account_id'],
        ];
    }

    private function connection(): PDO
    {
        if ($this->pdoFactory instanceof Closure) {
            $pdo = ($this->pdoFactory)();
            if (!$pdo instanceof PDO) {
                throw new RuntimeException('v4_bootstrap_connection_factory_invalid');
            }
            return $pdo;
        }
        Database::useProfile('web');
        return Database::connectionFresh();
    }

    private function configPath(): string
    {
        return $this->configPath ?? dirname(__DIR__, 2) . '/shared/config.env';
    }

    private function safeReason(Throwable $error): string
    {
        $reason = trim($error->getMessage());
        if (str_starts_with($reason, 'v4_bootstrap_')) {
            return preg_replace('/[^a-z0-9_:.-]/i', '_', $reason) ?: 'v4_bootstrap_failed';
        }
        return 'v4_bootstrap_failed';
    }
}
