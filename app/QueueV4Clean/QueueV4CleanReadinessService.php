<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use App\Core\Crypto;
use App\Core\Env;
use App\Services\ApiExecutionMetadataContext;
use App\Services\AppVersionService;
use App\Services\EmergencyControlService;
use App\Services\InstalledVersionMarkerService;
use App\Services\MeliApiClient;
use App\Services\MeliReadClientInterface;
use App\Services\Migrator;
use PDO;
use RuntimeException;
use Throwable;

final class QueueV4CleanReadinessService
{
    private const REQUIRED_MIGRATION = '297_queue_v4_transport_sales_api_health_2_38_5.sql';
    /** @var \Closure(int):MeliReadClientInterface */
    private \Closure $clientFactory;

    /** @param null|callable(int):MeliReadClientInterface $clientFactory */
    public function __construct(
        private readonly PDO $pdo,
        ?callable $clientFactory = null,
    ) {
        $this->clientFactory = $clientFactory !== null
            ? \Closure::fromCallable($clientFactory)
            : static fn (int $accountId): MeliReadClientInterface => new MeliApiClient($accountId);
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $repository = new QueueV4CleanRepository($this->pdo);
        $control = $repository->control();
        $observability = $repository->operationalObservability();
        $review = (new QueueV4CleanReviewService($this->pdo))->summary();
        $oauth = (new QueueV4CleanOAuthOperationRepository($this->pdo))->observability();
        $checks = $this->preconditions();
        $stored = (string) $control['readiness_state'];
        $state = $stored;
        if (!in_array($stored, ['TESTING', 'CERTIFIED'], true)) {
            $state = $checks['ok'] ? 'READY_TO_TEST' : ($stored === 'FAILED' ? 'FAILED' : 'NOT_READY');
        }
        return [
            'ok' => true,
            'state' => $state,
            'accounts_oauth' => count($checks['accounts']),
            'readiness_get_passed' => (int) $control['readiness_passed_accounts'],
            'queue' => $repository->counts(),
            'scheduler' => (int) $control['scheduler_enabled'] === 1 ? 'active' : 'inactive',
            'scheduler_config' => (int) $control['scheduler_enabled'] === 1 ? 'ENABLED' : 'DISABLED',
            'last_scheduler_heartbeat' => $observability['last_scheduler_heartbeat'],
            'physical_cron_observed' => $observability['physical_cron_observed'],
            'engine' => (string) $control['engine_state'],
            'review_forensics' => $review,
            'oauth_control_plane' => $oauth,
            'issues' => $checks['issues'],
            'legacy_state_consulted' => false,
            'read_only' => true,
        ];
    }

    /** @return list<string> */
    public function activationIssues(): array
    {
        return $this->preconditions()['issues'];
    }

    /** @return array<string,mixed> */
    public function certify(int $actorId): array
    {
        if ($actorId < 1) {
            throw new RuntimeException('queue_v4_clean_actor_invalid');
        }
        $locked = (int) $this->pdo->query(
            "SELECT GET_LOCK('erp_meli_queue_v4_clean_readiness',0)"
        )->fetchColumn();
        if ($locked !== 1) {
            throw new RuntimeException('queue_v4_clean_readiness_busy');
        }
        try {
            return $this->certifyLocked($actorId);
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('erp_meli_queue_v4_clean_readiness')");
        }
    }

    /** @return array<string,mixed> */
    private function certifyLocked(int $actorId): array
    {
        $checks = $this->preconditions();
        if (!$checks['ok']) {
            throw new RuntimeException('queue_v4_clean_not_ready:' . implode(',', $checks['issues']));
        }
        $this->pdo->beginTransaction();
        try {
            $control = (new QueueV4CleanRepository($this->pdo))->control(true);
            if ((string) $control['engine_state'] === 'ACTIVE' || (int) $control['scheduler_enabled'] !== 0) {
                throw new RuntimeException('queue_v4_clean_readiness_runtime_active');
            }
            if ((string) $control['readiness_state'] === 'TESTING') {
                $this->pdo->exec(
                    "UPDATE queue_v4_clean_readiness_runs
                     SET state='FAILED',failure_class='interrupted',finished_at=UTC_TIMESTAMP(3)
                     WHERE state='TESTING'"
                );
                $this->pdo->exec(
                    "UPDATE queue_v4_clean_control
                     SET engine_state='STOPPED',readiness_state='FAILED',readiness_passed_accounts=0,
                         readiness_error_class='interrupted',certified_at=NULL
                     WHERE control_key='primary' AND readiness_state='TESTING'"
                );
            }
            $run = $this->pdo->prepare(
                "INSERT INTO queue_v4_clean_readiness_runs(state,started_by) VALUES ('TESTING',?)"
            );
            $run->execute([$actorId]);
            $runId = (int) $this->pdo->lastInsertId();
            $update = $this->pdo->prepare(
                "UPDATE queue_v4_clean_control
                 SET readiness_state='TESTING',readiness_passed_accounts=0,readiness_error_class=NULL,updated_by=?
                 WHERE control_key='primary' AND scheduler_enabled=0 AND engine_state<>'ACTIVE'"
            );
            $update->execute([$actorId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_clean_readiness_cas_lost');
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        $passed = 0;
        $failure = null;
        foreach ($checks['accounts'] as $account) {
            $companyId = (int) $account['company_id'];
            $accountId = (int) $account['meli_account_id'];
            try {
                $client = ($this->clientFactory)($accountId);
                $response = QueueV4CleanTransportContext::runReadiness(
                    $companyId,
                    $accountId,
                    static fn (): array => ApiExecutionMetadataContext::run(
                        [
                            'source' => 'queue_v4_clean_readiness',
                            'job_type' => 'queue_v4_clean_readiness',
                            'company_id' => $companyId,
                            'account_id' => $accountId,
                            'bulk' => false,
                        ],
                        static fn (): array => $client->get('/users/me')
                    )
                );
                if ((string) ($response['id'] ?? '') !== (string) $account['meli_user_id']) {
                    throw new RuntimeException('identity_mismatch');
                }
                $this->recordAccountResult($runId, $companyId, $accountId, 'PASS', null);
                $passed++;
            } catch (Throwable $error) {
                $failure = $this->safeFailureClass($error);
                $this->recordAccountResult($runId, $companyId, $accountId, 'FAIL', $failure);
                break;
            }
        }

        $finalState = $passed === 3 && $failure === null ? 'CERTIFIED' : 'FAILED';
        $this->pdo->beginTransaction();
        try {
            $run = $this->pdo->prepare(
                'UPDATE queue_v4_clean_readiness_runs
                 SET state=?,passed_accounts=?,failure_class=?,finished_at=UTC_TIMESTAMP(3)
                 WHERE id=? AND state=\'TESTING\''
            );
            $run->execute([$finalState, $passed, $failure, $runId]);
            if ($run->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_clean_readiness_run_lost');
            }
            $control = $this->pdo->prepare(
                "UPDATE queue_v4_clean_control
                 SET engine_state=IF(?='CERTIFIED','CERTIFIED','STOPPED'),readiness_state=?,
                     readiness_passed_accounts=?,readiness_error_class=?,
                     certified_at=IF(?='CERTIFIED',UTC_TIMESTAMP(3),NULL),updated_by=?
                 WHERE control_key='primary' AND readiness_state='TESTING' AND scheduler_enabled=0"
            );
            $control->execute([$finalState, $finalState, $passed, $failure, $finalState, $actorId]);
            if ($control->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_clean_readiness_finalize_lost');
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return [
            'ok' => $finalState === 'CERTIFIED',
            'state' => $finalState,
            'oauth_accounts' => 3,
            'readiness_get_passed' => $passed,
            'scheduler_created' => false,
            'engine_activated' => false,
            'queue_jobs_created' => 0,
            'failure_class' => $failure,
        ];
    }

    /** @return array{ok:bool,issues:list<string>,accounts:list<array<string,mixed>>} */
    private function preconditions(): array
    {
        $issues = [];
        $version = AppVersionService::fileVersion();
        if (preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1) {
            $issues[] = 'version_invalid';
        }
        $appVersion = $this->pdo->prepare(
            "SELECT setting_value FROM app_settings WHERE setting_key='app.version' LIMIT 1"
        );
        $appVersion->execute();
        if ((string) $appVersion->fetchColumn() !== $version) {
            $issues[] = 'app_version_invalid';
        }
        $migration = $this->pdo->prepare(
            'SELECT COUNT(*) FROM schema_migrations WHERE version=?'
        );
        $migration->execute([self::REQUIRED_MIGRATION]);
        if ((int) $migration->fetchColumn() !== 1) {
            $issues[] = 'migration_297_missing';
        }
        $pending = (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->pendingCount();
        if ($pending !== 0) {
            $issues[] = 'pending_migrations';
        }
        array_push($issues, ...(new QueueV4CleanDatabaseContract($this->pdo))->issues());
        $marker = (new InstalledVersionMarkerService())->read();
        if (!$marker['valid'] || !hash_equals($version, $marker['version'])) {
            $issues[] = 'marker_version_invalid';
        }
        if (Env::bool('ML_WRITE_ENABLED', false)) {
            $issues[] = 'ml_write_enabled';
        }
        if (Env::bool('CRON_V3_ENABLED', false) || Env::bool('CRON_V3_SHADOW_ENABLED', false)) {
            $issues[] = 'legacy_cron_enabled';
        }
        $safety = new EmergencyControlService();
        if (!$safety->automationStopped()) {
            $issues[] = 'automation_not_stopped';
        }
        $control = (new QueueV4CleanRepository($this->pdo))->control();
        if ((int) $control['scheduler_enabled'] !== 0) {
            $issues[] = 'scheduler_not_stopped';
        }
        if ((string) $control['engine_state'] === 'ACTIVE') {
            $issues[] = 'engine_active';
        }
        $accounts = [];
        $rows = $this->pdo->query(
            'SELECT a.company_id,a.id meli_account_id,a.meli_user_id,
                    t.access_token_encrypted,t.refresh_token_encrypted,t.expires_at
             FROM meli_accounts a
             INNER JOIN meli_tokens t ON t.meli_account_id=a.id
             WHERE a.status IN ("conectado","connected")
             ORDER BY a.company_id,a.id'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            try {
                $valid = (int) $row['company_id'] > 0
                    && (int) $row['meli_account_id'] > 0
                    && trim((string) $row['meli_user_id']) !== ''
                    && trim(Crypto::decrypt((string) $row['access_token_encrypted'])) !== ''
                    && trim(Crypto::decrypt((string) $row['refresh_token_encrypted'])) !== ''
                    && (strtotime((string) $row['expires_at'] . ' UTC') ?: 0) > time() + 30;
            } catch (Throwable) {
                $valid = false;
            }
            if (!$valid) {
                $issues[] = 'oauth_account_invalid';
                continue;
            }
            $accounts[] = [
                'company_id' => (int) $row['company_id'],
                'meli_account_id' => (int) $row['meli_account_id'],
                'meli_user_id' => (string) $row['meli_user_id'],
            ];
        }
        if (count($accounts) !== 3) {
            $issues[] = 'oauth_accounts_not_exactly_three';
        }
        $issues = array_values(array_unique($issues));
        return ['ok' => $issues === [], 'issues' => $issues, 'accounts' => $accounts];
    }

    private function recordAccountResult(
        int $runId,
        int $companyId,
        int $accountId,
        string $outcome,
        ?string $failure,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO queue_v4_clean_readiness_accounts
             (readiness_run_id,company_id,meli_account_id,outcome,failure_class)
             VALUES (?,?,?,?,?)'
        );
        $statement->execute([$runId, $companyId, $accountId, $outcome, $failure]);
    }

    private function safeFailureClass(Throwable $error): string
    {
        $class = strtolower((new \ReflectionClass($error))->getShortName());
        return substr(preg_replace('/[^a-z0-9_]+/', '_', $class) ?: 'readiness_failed', 0, 100);
    }
}
