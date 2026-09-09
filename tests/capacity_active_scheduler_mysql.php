<?php
declare(strict_types=1);

// Only transport-bearing stage dependencies are replaced. The production
// scheduler, physical CycleBudget, capacity policy and MySQL leases run intact.
namespace App\QueueV4Clean {
    final class SchedulerCapacityFixture {
        public static array $events = [];
        public static int $transports = 0;
        public static ?int $workerArgument = null;
        public static function transport(string $stage, int $wanted = 1): int {
            $before = QueueV4CleanCycleBudget::snapshot();
            $count = min($wanted, QueueV4CleanCycleBudget::remaining());
            for ($i = 0; $i < $count; $i++) {
                $attemptId = bin2hex(random_bytes(20));
                \App\Services\ApiExecutionMetadataContext::run([
                    'source' => 'scheduler_capacity_fixture',
                    'transport_request_id' => $attemptId,
                    'scheduler_stage' => $stage,
                ], static function () use ($attemptId): void {
                    QueueV4CleanCycleBudget::claim($attemptId);
                    QueueV4CleanCycleBudget::enteringTransport($attemptId);
                });
                self::$transports++;
            }
            self::$events[] = ['stage'=>$stage,'before'=>$before,'calls'=>$count,'after'=>QueueV4CleanCycleBudget::snapshot()];
            return $count;
        }
    }
    final class QueueV4CleanOAuthOperationRepository { public function __construct(\PDO $pdo) {} }
    final class QueueV4CleanOAuthSupervisor {
        public function __construct(\PDO $pdo, QueueV4CleanOAuthOperationRepository $repository) {}
        public function run(string $owner): array { return ['claimed'=>SchedulerCapacityFixture::transport('oauth')]; }
    }
    final class QueueV4CleanUncertainReadRecoveryService {
        public function __construct(\PDO $pdo) {}
        public function recoverOne(): array { return ['recovered'=>0]; }
    }
    final class QueueV4CleanSalesAuditStage {
        public function run(float $deadline): array { return ['claimed'=>SchedulerCapacityFixture::transport('audit')]; }
    }
    final class QueueV4CleanProducer {
        public function __construct(\PDO $pdo, QueueV4CleanRepository $repository) {}
        public function produce(): array { return ['produced'=>0]; }
    }
    final class QueueV4CleanMaintenanceService {
        public function run(int $limit): array { return ['materialized'=>0]; }
    }
    final class QueueV4CleanWorker {
        public const DEFAULT_MAX_CALLS = 1;
        public function __construct(\PDO $pdo, QueueV4CleanRepository $repository) {}
        public function run(string $launcher, int $maxCalls, int $runtime): array {
            SchedulerCapacityFixture::$workerArgument = $maxCalls;
            \k1b_assert($maxCalls === QueueV4CleanCycleBudget::remaining(), 'scheduler_passes_remaining_not_original_limit');
            \k1b_assert($runtime >= 5 && $runtime <= 45, 'scheduler_worker_deadline_remains_bounded');
            return ['claimed'=>SchedulerCapacityFixture::transport('worker', max(1, $maxCalls - 1))];
        }
    }
}
namespace App\Services {
    final class EmergencyControlService { public function automationStopped(): bool { return false; } }
    final class SalesAuditExactRepairService {
        public function processDue(int $limit): array {
            \k1b_assert($limit === 1, 'repair_resource_batch_unchanged');
            return ['jobs'=>\App\QueueV4Clean\SchedulerCapacityFixture::transport('repair')];
        }
    }
}
namespace App\Work\Adapters {
    final class QueueCoreDrainAuthority {
        public static int $acquired = 0;
        public static int $released = 0;
        public function __construct(\PDO $pdo) {}
        public function acquire(string $drainer, string $owner, int $lease): \App\Work\DrainAuthorityToken {
            self::$acquired++;
            return new \App\Work\DrainAuthorityToken($drainer,$owner,1,$lease,'fixture');
        }
        public function release(\App\Work\DrainAuthorityToken $token): void { self::$released++; }
    }
}
namespace {
    require __DIR__ . '/k1b_bootstrap.php';
    require __DIR__ . '/K1dSafeTestDatabase.php';
    use App\QueueV4Clean\SchedulerCapacityFixture;
    use App\QueueV4Clean\QueueV4CleanScheduler;
    use App\QueueV4Clean\QueueV4CleanCycleBudget;
    use App\Services\CapacityPolicyService;
    use App\Work\Adapters\QueueCoreDrainAuthority;

    putenv('APP_ENV=test');
    putenv('ML_WRITE_ENABLED=false');
    putenv('DB_HOST=127.0.0.1');
    putenv('DB_PORT=' . (getenv('DB_PORT') ?: '33079'));
    putenv('DB_USER=root');
    putenv('DB_PASS=');
    putenv('DB_NAME=erp_meli_k1d_test_active_capacity_' . bin2hex(random_bytes(4)));
    $harness = K1dSafeTestDatabase::createFromEnvironment();
    try {
        $pdo = $harness->pdo();
        $pdo->exec('CREATE TABLE app_settings (setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NULL,is_encrypted TINYINT NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL DEFAULT "general") ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE queue_v4_clean_control (control_key VARCHAR(32) PRIMARY KEY,engine_state VARCHAR(20),scheduler_enabled TINYINT,last_scheduler_at DATETIME(3)) ENGINE=InnoDB');
        $pdo->exec("INSERT INTO queue_v4_clean_control VALUES ('primary','ACTIVE',1,NULL)");
        $pdo->exec('CREATE TABLE queue_v4_clean_leases (lease_key VARCHAR(32) PRIMARY KEY,owner_ref VARCHAR(96),acquired_at DATETIME(3),heartbeat_at DATETIME(3),expires_at DATETIME(3)) ENGINE=InnoDB');
        $pdo->exec("INSERT INTO queue_v4_clean_leases (lease_key) VALUES ('scheduler')");
        $policy = new CapacityPolicyService($pdo);
        foreach ([1,2,3,15,55,100] as $budget) {
            $before = $policy->snapshot('automation');
            $policy->save('automation',$budget,$budget,$before['revision'],static fn (): array => ['allowed'=>true]);
            SchedulerCapacityFixture::$events = [];
            SchedulerCapacityFixture::$transports = 0;
            SchedulerCapacityFixture::$workerArgument = null;
            $result = (new QueueV4CleanScheduler($pdo))->run(101,45);
            k1b_assert($result['status'] === 'completed', 'active_scheduler_completed_' . $budget);
            k1b_assert($result['max_calls'] === $budget, 'active_scheduler_ceiling_' . $budget);
            k1b_assert($result['physical_http_calls'] === $budget && SchedulerCapacityFixture::$transports === $budget, 'active_scheduler_shared_physical_total_' . $budget);
            k1b_assert(($result['http_budget']['limit'] ?? null) === $budget, 'active_scheduler_final_budget_limit_' . $budget);
            k1b_assert(($result['http_budget']['used'] ?? null) === $budget, 'active_scheduler_final_budget_used_' . $budget);
            k1b_assert(($result['http_budget']['remaining'] ?? null) === 0, 'active_scheduler_final_budget_remaining_' . $budget);
            k1b_assert(($result['http_budget']['physical_http_calls'] ?? null) === $budget, 'active_scheduler_final_budget_physical_' . $budget);
            k1b_assert(($result['http_budget']['physical_http_calls_certainty'] ?? null) === 'CERTIFIED', 'active_scheduler_final_budget_certainty_' . $budget);
            k1b_assert(SchedulerCapacityFixture::$workerArgument === ($budget > 2 ? $budget - 2 : null), 'worker_skipped_when_prior_stages_exhausted_' . $budget);
            $stages = array_column(SchedulerCapacityFixture::$events, 'stage');
            k1b_assert($stages === ($budget <= 2 ? ['oauth','audit'] : ($budget === 3 ? ['oauth','audit','worker'] : ['oauth','audit','worker','repair'])), 'active_scheduler_stage_order_' . $budget);
            k1b_assert(QueueV4CleanCycleBudget::snapshot()['limit'] === 0, 'scheduler_clears_cycle_context_' . $budget);
            k1b_assert($pdo->query("SELECT owner_ref FROM queue_v4_clean_leases WHERE lease_key='scheduler'")->fetchColumn() === null, 'scheduler_releases_sql_lease_' . $budget);
        }
        k1b_assert(QueueCoreDrainAuthority::$acquired === 6 && QueueCoreDrainAuthority::$released === 6, 'global_drain_authority_released_every_cycle');
        echo "STATUS=PASS CAPACITY_ACTIVE_SCHEDULER_MYSQL\nACTUAL_SCHEDULER_CYCLES=6\nLEVELS=1,2,3,15,55,100\nSHARED_STAGES=oauth,audit,worker,repair\nREAL_MELI_HTTP=0\nREAL_EMAIL_SENT=0\n";
    } finally { QueueV4CleanCycleBudget::clear(); $harness->cleanup(); }
}
