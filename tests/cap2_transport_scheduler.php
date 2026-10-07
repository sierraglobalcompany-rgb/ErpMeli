<?php
declare(strict_types=1);
// Dedicated orchestration proof: real scheduler/SQL leases/policy and budget,
// isolated stage outcomes. Physical wire/fences are proven separately in cap2_transport_mysql.
namespace App\QueueV4Clean {
    final class Cap2TransportStages {
        public static string $stop='';public static int $repairs=0;public static bool $cross=true;public static ?string $oauthStop=null;
        public static bool $drainBusy=false;public static bool $oauthBlocked=false;public static bool $salesBlocked=false;
        public static function consume():void {
            $id=bin2hex(random_bytes(20));
            \App\Services\ApiExecutionMetadataContext::run(['source'=>'queue_v4_clean','transport_request_id'=>$id],static function()use($id):void{QueueV4CleanCycleBudget::claim($id);if(self::$cross)QueueV4CleanCycleBudget::enteringTransport($id);});
        }
    }
    final class QueueV4CleanOAuthOperationRepository {public function __construct(\PDO $pdo){}}
    final class QueueV4CleanOAuthSupervisor {public function __construct(\PDO $pdo,QueueV4CleanOAuthOperationRepository $r){}public function run(string $owner):array{if(Cap2TransportStages::$oauthBlocked)return ['claimed'=>0,'status'=>'oauth_control_plane_blocked','abort_scheduler'=>true];if(Cap2TransportStages::$oauthStop!==null){QueueV4CleanCycleBudget::stop(Cap2TransportStages::$oauthStop);return ['claimed'=>0,'status'=>'waiting','abort_scheduler'=>true];}return ['claimed'=>0];}}
    final class QueueV4CleanUncertainReadRecoveryService {public function __construct(\PDO $pdo){}public function recoverOne():array{return ['recovered'=>0];}}
    final class QueueV4CleanSalesAuditStage {public function run(float $deadline):array{return ['claimed'=>0,'abort_scheduler'=>Cap2TransportStages::$salesBlocked];}}
    final class QueueV4CleanProducer {public function __construct(\PDO $pdo,QueueV4CleanRepository $r){}public function produce():array{return ['produced'=>0];}}
    final class QueueV4CleanMaintenanceService {public function run(int $limit):array{return ['materialized'=>0];}}
    final class QueueV4CleanWorker {
        public const DEFAULT_MAX_CALLS=1;
        public function __construct(\PDO $pdo,QueueV4CleanRepository $r){}
        public function run(string $launcher,int $maxCalls,int $runtime):array{Cap2TransportStages::consume();return ['claimed'=>1,'stop_reason'=>Cap2TransportStages::$stop];}
    }
}
namespace App\Services {
    final class EmergencyControlService {public function automationStopped():bool{return false;}}
    final class SalesAuditExactRepairService {public function processDue(int $limit):array{\App\QueueV4Clean\Cap2TransportStages::$repairs++;\App\QueueV4Clean\Cap2TransportStages::consume();return ['jobs'=>1];}}
}
namespace App\Work\Adapters {
    final class QueueCoreDrainAuthority {
        public function __construct(\PDO $pdo){}
        public function acquire(string $drainer,string $owner,int $lease):?\App\Work\DrainAuthorityToken{if(\App\QueueV4Clean\Cap2TransportStages::$drainBusy)return null;return new \App\Work\DrainAuthorityToken($drainer,$owner,1,$lease,'cap2');}
        public function release(\App\Work\DrainAuthorityToken $token):void{}
    }
}
namespace {
    require __DIR__.'/k1b_bootstrap.php';require __DIR__.'/K1dSafeTestDatabase.php';
    foreach(['APP_ENV'=>'test','ML_WRITE_ENABLED'=>'false','DB_HOST'=>'127.0.0.1','DB_PORT'=>getenv('DB_PORT')?:'33079','DB_USER'=>'root','DB_PASS'=>(string)getenv('DB_PASS'),'DB_NAME'=>'erp_meli_k1d_test_cap2_scheduler_stop_'.bin2hex(random_bytes(4))]as $k=>$v)putenv($k.'='.$v);
    $h=K1dSafeTestDatabase::createFromEnvironment();
    try {
        $pdo=$h->pdo();
        $journal=file_get_contents(__DIR__.'/../database/migrations/115_resumable_execution_journal_2_21_3.sql');
        preg_match_all('/CREATE TABLE IF NOT EXISTS system_execution_(?:runs|attempts)\s*\(.*?ENGINE=InnoDB.*?;/s',$journal,$tables);
        foreach($tables[0] as $table){$pdo->exec($table);}
        $pdo->exec('CREATE TABLE schema_migrations(version VARCHAR(255))');
        $pdo->exec("INSERT INTO schema_migrations VALUES('303_outer_cron_http_receipt.sql')");
        $pdo->exec('CREATE TABLE system_cold_archives(dataset_key VARCHAR(80) NOT NULL) ENGINE=InnoDB');
        $pdo->exec(file_get_contents(__DIR__.'/../database/migrations/303_outer_cron_http_receipt.sql'));
        $pdo->exec('CREATE TABLE app_settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT,is_encrypted TINYINT NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL DEFAULT "general") ENGINE=InnoDB');
        $pdo->exec("INSERT INTO app_settings(setting_key,setting_value) VALUES('automation.max_api_calls_per_cycle','5'),('automation.max_api_calls_ceiling','55')");
        $pdo->exec('CREATE TABLE queue_v4_clean_control(control_key VARCHAR(32) PRIMARY KEY,engine_state VARCHAR(20),scheduler_enabled TINYINT,last_scheduler_at DATETIME(3)) ENGINE=InnoDB');
        $pdo->exec("INSERT INTO queue_v4_clean_control VALUES('primary','ACTIVE',1,NULL)");
        $pdo->exec('CREATE TABLE queue_v4_clean_leases(lease_key VARCHAR(32) PRIMARY KEY,owner_ref VARCHAR(96),acquired_at DATETIME(3),heartbeat_at DATETIME(3),expires_at DATETIME(3)) ENGINE=InnoDB');
        $pdo->exec("INSERT INTO queue_v4_clean_leases(lease_key) VALUES('scheduler')");
        foreach(['remote_result_uncertain','remote_429_global_pause','no_claimable_job']as $stop) {
            App\QueueV4Clean\Cap2TransportStages::$stop=$stop;App\QueueV4Clean\Cap2TransportStages::$repairs=0;
            $result=(new App\QueueV4Clean\QueueV4CleanScheduler($pdo))->run(5,45);
            $protected=$stop!=='no_claimable_job';
            k1b_assert(App\QueueV4Clean\Cap2TransportStages::$repairs===($protected?0:1),'scheduler_does_not_enter_repair_after_'.$stop);
            k1b_assert($result['http_budget']['used']===($protected?1:2),'shared_budget_retained_at_protected_stop');
            $receipt=App\QueueV4Clean\OuterCronHttpReceipt::read($pdo,$result['cron_cycle_id']);
            k1b_assert($receipt['max_calls_source']==='CLI_MAX_CALLS_OVERRIDE','explicit scheduler source');
        }
        App\QueueV4Clean\Cap2TransportStages::$stop='no_claimable_job';
        $result=(new App\QueueV4Clean\QueueV4CleanScheduler($pdo))->run(null,45);
        k1b_assert(App\QueueV4Clean\OuterCronHttpReceipt::read($pdo,$result['cron_cycle_id'])['max_calls_source']==='ERP_SETTINGS','active scheduler source survives lease revalidation');
        foreach(['remote_429_global_pause','remote_result_uncertain']as $stop){
            App\QueueV4Clean\Cap2TransportStages::$oauthStop=$stop;
            $result=(new App\QueueV4Clean\QueueV4CleanScheduler($pdo))->run(5,45);
            $receipt=App\QueueV4Clean\OuterCronHttpReceipt::read($pdo,$result['cron_cycle_id']);
            k1b_assert($result['status']==='waiting'&&$receipt['protected_stop_reason']===$stop,'actual scheduler captures OAuth budget stop before clear');
        }
        App\QueueV4Clean\Cap2TransportStages::$oauthStop=null;
        foreach(['drainBusy'=>'busy_drainer','oauthBlocked'=>'oauth_control_plane_blocked','salesBlocked'=>'sales_audit_invariant_blocked']as $flag=>$expected){
            App\QueueV4Clean\Cap2TransportStages::${$flag}=true;
            $result=(new App\QueueV4Clean\QueueV4CleanScheduler($pdo))->run(null,45);
            $receipt=App\QueueV4Clean\OuterCronHttpReceipt::read($pdo,$result['cron_cycle_id']);
            k1b_assert($receipt['terminal_status']===$expected&&$receipt['physical_http_total']===0,'early-return receipt '.$expected);
            App\QueueV4Clean\Cap2TransportStages::${$flag}=false;
        }
        App\QueueV4Clean\Cap2TransportStages::$cross=false;App\QueueV4Clean\Cap2TransportStages::$stop='remote_result_uncertain';
        $result=(new App\QueueV4Clean\QueueV4CleanScheduler($pdo))->run(5,45);
        k1b_assert($result['physical_http_calls']===null&&($result['physical_http_calls_certainty']??'')==='UNKNOWN'&&($result['charged_calls']??null)===1,'scheduler_never_labels_unresolved_charge_as_certified_http');
        echo "CAP2_TRANSPORT_SCHEDULER_OK\n";
    } finally {$h->cleanup();}
}
