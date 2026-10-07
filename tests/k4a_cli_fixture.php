<?php
declare(strict_types=1);

// Test-only bootstrap. No ERP config, database credentials or HTTP transport.
namespace App\Core {
    final class Database {
        public static function useProfile(string $profile): void { $GLOBALS['k4a_profile'] = $profile; }
        public static function connectionFresh(): \PDO { return new class extends \PDO { public function __construct() {} }; }
    }
}
namespace App\Services {
    final class AutomationCallBudgetService {
        public const MIN = 1;
        public const HARD_MAX = 100;
        public function resolve(?int $requested = null): array {
            return ['requested_max_calls' => $requested === null ? 10 : min(10, $requested),
                'max_calls_source' => $requested === null ? 'ERP_SETTINGS' : 'CLI_MAX_CALLS_OVERRIDE'];
        }
    }
}
namespace App\QueueV4Clean {
    final class OuterCronHttpReceipt {
        public static function withCapacitySource(string $source,callable $operation):array {
            if(!in_array($source,['ERP_SETTINGS','SAFE_DEFAULT','CLI_MAX_CALLS_OVERRIDE'],true)){throw new \RuntimeException('invalid source');}
            return $operation();
        }
    }
    final class QueueV4CleanScheduler {
        public function __construct(\PDO $pdo) {}
        public function run(int $calls, int $runtime): array {
            $trace = ['calls'=>$calls, 'runtime'=>$runtime, 'profile'=>$GLOBALS['k4a_profile'] ?? null,
                'deadline_active'=>\App\Services\CronDeadlineContext::active(),
                'deadline_remaining'=>round((\App\Services\CronDeadlineContext::deadline() ?? 0) - microtime(true))];
            file_put_contents((string)getenv('K4A_TRACE'), json_encode($trace));
            if (getenv('K4A_SCENARIO') === 'exception') { throw new \RuntimeException('private-token-must-not-leak'); }
            return ['ok'=>getenv('K4A_SCENARIO') !== 'failed', 'status'=>getenv('K4A_SCENARIO') === 'failed' ? 'blocked' : 'completed',
                'physical_http_calls'=>getenv('K4A_SCENARIO') === 'unknown' ? null : 3,
                'physical_http_calls_certainty'=>getenv('K4A_SCENARIO') === 'unknown' ? 'UNKNOWN' : 'CERTIFIED',
                'worker'=>['completed'=>2,'deferred'=>1],
                'custom_scheduler_field'=>['unicode'=>'válido','nested'=>false]];
        }
    }
    final class QueueV4CleanOAuthStageContext { public static function current(): array { return []; } }
    final class QueueV4CleanSafeDiagnosticService {
        public function capture(\Throwable $error, array $context): array {
            return ['diagnostic_id'=>'TEST-DIAGNOSTIC', 'error_class'=>'runtimeexception',
                'safe_stage'=>'test_stage','file'=>'tests/k4a_cli_fixture.php','line'=>1];
        }
    }
}
namespace {
    $root = dirname(__DIR__);
    foreach (['app/Services/AutomationCliCapacityArgumentParser.php','app/Services/CronExecutionWindow.php','app/Services/CronDeadlineContext.php',
        'app/Work/DrainResult.php','app/Work/Contracts/DrainerContract.php','app/Work/Adapters/QueueV4CurrentDrainer.php'] as $file) {
        require_once $root . '/' . $file;
    }
}
