<?php

declare(strict_types=1);

// Stage doubles isolate external/large subsystems only. This exercises the
// real scheduler, capacity service/policy, SQL lease and shared cycle budget;
// it is not claimed as final physical-wire proof.
namespace App\QueueV4Clean {
    final class Cap2AutomaticStageFixture {
        public static int $calls = 0;
        public static ?int $workerArgument = null;
        public static function consume(int $wanted = 1): int {
            $count = min($wanted, QueueV4CleanCycleBudget::remaining());
            for ($i = 0; $i < $count; $i++) {
                QueueV4CleanCycleBudget::claim();
                self::$calls++;
            }
            return $count;
        }
    }
    final class QueueV4CleanOAuthOperationRepository { public function __construct(\PDO $pdo) {} }
    final class QueueV4CleanOAuthSupervisor {
        public function __construct(\PDO $pdo, QueueV4CleanOAuthOperationRepository $repository) {}
        public function run(string $owner): array { return ['claimed' => Cap2AutomaticStageFixture::consume()]; }
    }
    final class QueueV4CleanUncertainReadRecoveryService {
        public function __construct(\PDO $pdo) {}
        public function recoverOne(): array { return ['recovered' => 0]; }
    }
    final class QueueV4CleanSalesAuditStage {
        public function run(float $deadline): array { return ['claimed' => Cap2AutomaticStageFixture::consume()]; }
    }
    final class QueueV4CleanProducer {
        public function __construct(\PDO $pdo, QueueV4CleanRepository $repository) {}
        public function produce(): array { return ['produced' => 0]; }
    }
    final class QueueV4CleanMaintenanceService {
        public function run(int $limit): array { return ['materialized' => 0]; }
    }
    final class QueueV4CleanWorker {
        public const DEFAULT_MAX_CALLS = 1;
        public function __construct(\PDO $pdo, QueueV4CleanRepository $repository) {}
        public function run(string $launcher, int $maxCalls, int $runtime): array {
            self::assertRemaining($maxCalls);
            Cap2AutomaticStageFixture::$workerArgument = $maxCalls;
            return ['claimed' => Cap2AutomaticStageFixture::consume($maxCalls)];
        }
        private static function assertRemaining(int $maxCalls): void {
            \k1b_assert($maxCalls === QueueV4CleanCycleBudget::remaining(), 'scheduler_passes_shared_remaining_to_worker');
        }
    }
}
namespace App\Services {
    final class EmergencyControlService { public function automationStopped(): bool { return false; } }
    final class SalesAuditExactRepairService {
        public function processDue(int $limit): array { return ['jobs' => \App\QueueV4Clean\Cap2AutomaticStageFixture::consume()]; }
    }
}
namespace App\Work\Adapters {
    final class QueueCoreDrainAuthority {
        public function __construct(\PDO $pdo) {}
        public function acquire(string $drainer, string $owner, int $lease): \App\Work\DrainAuthorityToken {
            return new \App\Work\DrainAuthorityToken($drainer, $owner, 1, $lease, 'cap2-fixture');
        }
        public function release(\App\Work\DrainAuthorityToken $token): void {}
    }
}
namespace {
    require __DIR__ . '/k1b_bootstrap.php';
    require __DIR__ . '/K1dSafeTestDatabase.php';

    use App\QueueV4Clean\Cap2AutomaticStageFixture;
    use App\QueueV4Clean\QueueV4CleanCycleBudget;
    use App\QueueV4Clean\QueueV4CleanScheduler;
    use App\Services\AppSettingsService;
    use App\Services\AutomationCallBudgetService;
    use App\Services\CapacityPolicyService;

    putenv('APP_ENV=test');
    putenv('ML_WRITE_ENABLED=false');
    putenv('DB_HOST=127.0.0.1');
    putenv('DB_PORT=33079');
    putenv('DB_USER=root');
    putenv('DB_PASS=');
    putenv('DB_NAME=erp_meli_k1d_test_cap2_scheduler_' . bin2hex(random_bytes(4)));

    $harness = K1dSafeTestDatabase::createFromEnvironment();
    try {
        $pdo = $harness->pdo();
        $pdo->exec('CREATE TABLE app_settings (setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NULL,is_encrypted TINYINT NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL DEFAULT "general") ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE queue_v4_clean_control (control_key VARCHAR(32) PRIMARY KEY,engine_state VARCHAR(20),scheduler_enabled TINYINT,last_scheduler_at DATETIME(3)) ENGINE=InnoDB');
        $pdo->exec("INSERT INTO queue_v4_clean_control VALUES ('primary','ACTIVE',1,NULL)");
        $pdo->exec('CREATE TABLE queue_v4_clean_leases (lease_key VARCHAR(32) PRIMARY KEY,owner_ref VARCHAR(96),acquired_at DATETIME(3),heartbeat_at DATETIME(3),expires_at DATETIME(3)) ENGINE=InnoDB');
        $pdo->exec("INSERT INTO queue_v4_clean_leases (lease_key) VALUES ('scheduler')");

        $policy = new CapacityPolicyService($pdo);
        $allow = static fn (): array => ['allowed' => true, 'message' => ''];
        $before = $policy->snapshot('automation');
        $policy->save('automation', 50, 100, $before['revision'], $allow);
        AppSettingsService::clearCache();
        $loadedBeforeReduction = (new AutomationCallBudgetService())->resolve(50);
        k1b_assert($loadedBeforeReduction['max_calls'] === 50, 'fixture_loaded_fifty_before_concurrent_reduction');

        $beforeReduction = $policy->snapshot('automation');
        $policy->save('automation', 3, 100, $beforeReduction['revision'], $allow);
        AppSettingsService::clearCache();
        Cap2AutomaticStageFixture::$calls = 0;
        Cap2AutomaticStageFixture::$workerArgument = null;

        $result = (new QueueV4CleanScheduler($pdo))->run(50, 45);
        k1b_assert($result['status'] === 'completed', 'scheduler_runs_after_concurrent_reduction');
        k1b_assert(($result['requested_max_calls'] ?? null) === 50, 'scheduler_preserves_original_requested_capacity');
        k1b_assert($result['configured_max_calls'] === 3 && $result['ceiling'] === 100,
            'scheduler_rereads_final_erp_capacity');
        k1b_assert($result['max_calls'] === 3, 'concurrent_erp_reduction_wins_before_cycle_begin');
        k1b_assert($result['physical_http_calls'] === 3 && Cap2AutomaticStageFixture::$calls === 3,
            'oauth_audit_and_worker_share_final_three_call_budget');
        k1b_assert(Cap2AutomaticStageFixture::$workerArgument === 1, 'worker_receives_only_remaining_call');
        k1b_assert(QueueV4CleanCycleBudget::snapshot()['limit'] === 0, 'scheduler_clears_outer_budget');

        echo "STATUS=PASS CAP2_AUTOMATIC_SCHEDULER_MYSQL\nSTAGE_DOUBLES=NOT_FINAL_PHYSICAL_PROOF\nREAL_MELI_HTTP=0\nREAL_EMAIL_SENT=0\n";
    } finally {
        QueueV4CleanCycleBudget::clear();
        AppSettingsService::clearCache();
        $harness->cleanup();
    }
}
