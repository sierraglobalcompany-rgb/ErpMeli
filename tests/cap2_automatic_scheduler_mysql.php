<?php

declare(strict_types=1);

// Stage doubles isolate external/large subsystems only. This exercises the
// real scheduler, capacity service/policy, SQL lease and shared cycle budget;
// it is not claimed as final physical-wire proof.
namespace App\QueueV4Clean {
    final class Cap2AutomaticStageFixture {
        public static int $calls = 0;
        public static ?int $workerArgument = null;
        public static ?\PDO $interleavingPdo = null;
        public static string $leaseBoundaryAction = 'none';
        public static int $leaseBoundaryActions = 0;
        public static function consume(int $wanted = 1): int {
            $count = min($wanted, QueueV4CleanCycleBudget::remaining());
            for ($i = 0; $i < $count; $i++) {
                $id = bin2hex(random_bytes(20));
                \App\Services\ApiExecutionMetadataContext::run(['source'=>'queue_v4_clean','transport_request_id'=>$id], static function () use ($id): void {
                    QueueV4CleanCycleBudget::claim($id);
                    QueueV4CleanCycleBudget::enteringTransport($id);
                });
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
        public static int $acquired = 0;
        public static int $released = 0;
        public function __construct(\PDO $pdo) {}
        public function acquire(string $drainer, string $owner, int $lease): \App\Work\DrainAuthorityToken {
            self::$acquired++;
            $fixture = \App\QueueV4Clean\Cap2AutomaticStageFixture::class;
            if ($fixture::$leaseBoundaryAction === 'reduce') {
                $stmt = $fixture::$interleavingPdo->prepare(
                    "UPDATE app_settings SET setting_value='3' WHERE setting_key='automation.max_api_calls_per_cycle'"
                );
                $stmt->execute();
                $fixture::$leaseBoundaryActions++;
            } elseif ($fixture::$leaseBoundaryAction === 'break_capacity_read') {
                $fixture::$interleavingPdo->exec('DROP TABLE app_settings');
                $fixture::$leaseBoundaryActions++;
            }
            return new \App\Work\DrainAuthorityToken($drainer, $owner, 1, $lease, 'cap2-fixture');
        }
        public function release(\App\Work\DrainAuthorityToken $token): void { self::$released++; }
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
    use App\Work\Adapters\QueueCoreDrainAuthority;

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
        $before = $policy->snapshot('automation');
        $policy->save('automation', 50, 100, $before['revision']);
        AppSettingsService::clearCache();
        $loadedBeforeReduction = (new AutomationCallBudgetService())->resolve(50);
        k1b_assert($loadedBeforeReduction['max_calls'] === 50, 'fixture_loaded_fifty_before_concurrent_reduction');
        Cap2AutomaticStageFixture::$interleavingPdo = new PDO(
            'mysql:host=127.0.0.1;port=33079;dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
            'root',
            '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
        Cap2AutomaticStageFixture::$leaseBoundaryAction = 'reduce';
        Cap2AutomaticStageFixture::$leaseBoundaryActions = 0;
        Cap2AutomaticStageFixture::$calls = 0;
        Cap2AutomaticStageFixture::$workerArgument = null;

        $result = (new QueueV4CleanScheduler($pdo))->run(50, 45);
        k1b_assert(Cap2AutomaticStageFixture::$leaseBoundaryActions === 1,
            'second_connection_reduces_current_at_drain_lease_boundary');
        k1b_assert($result['status'] === 'completed', 'scheduler_runs_after_concurrent_reduction');
        k1b_assert(($result['requested_max_calls'] ?? null) === 50, 'scheduler_preserves_original_requested_capacity');
        k1b_assert($result['configured_max_calls'] === 3 && $result['ceiling'] === 100,
            'scheduler_rereads_final_erp_capacity');
        k1b_assert($result['max_calls'] === 3, 'concurrent_erp_reduction_wins_before_cycle_begin');
        k1b_assert($result['physical_http_calls'] === 3 && Cap2AutomaticStageFixture::$calls === 3,
            'oauth_audit_and_worker_share_final_three_call_budget');
        k1b_assert(Cap2AutomaticStageFixture::$workerArgument === 1, 'worker_receives_only_remaining_call');
        k1b_assert(QueueV4CleanCycleBudget::snapshot()['limit'] === 0, 'scheduler_clears_outer_budget');

        $beforeReadFailure = $policy->snapshot('automation');
        $policy->save('automation', 50, 100, $beforeReadFailure['revision']);
        AppSettingsService::clearCache();
        Cap2AutomaticStageFixture::$leaseBoundaryAction = 'break_capacity_read';
        $readFailed = false;
        try {
            (new QueueV4CleanScheduler($pdo))->run(50, 45);
        } catch (PDOException) {
            $readFailed = true;
        }
        k1b_assert($readFailed, 'fresh_capacity_read_failure_propagates');
        k1b_assert(QueueCoreDrainAuthority::$acquired === 2 && QueueCoreDrainAuthority::$released === 2,
            'fresh_capacity_read_failure_releases_drain_authority');
        k1b_assert($pdo->query("SELECT owner_ref FROM queue_v4_clean_leases WHERE lease_key='scheduler'")->fetchColumn() === null,
            'fresh_capacity_read_failure_releases_scheduler_lease');
        k1b_assert(QueueV4CleanCycleBudget::snapshot()['limit'] === 0,
            'fresh_capacity_read_failure_clears_cycle_budget_context');

        echo "STATUS=PASS CAP2_AUTOMATIC_SCHEDULER_MYSQL\nSTAGE_DOUBLES=NOT_FINAL_PHYSICAL_PROOF\nREAL_MELI_HTTP=0\nREAL_EMAIL_SENT=0\n";
    } finally {
        QueueV4CleanCycleBudget::clear();
        AppSettingsService::clearCache();
        $harness->cleanup();
    }
}
