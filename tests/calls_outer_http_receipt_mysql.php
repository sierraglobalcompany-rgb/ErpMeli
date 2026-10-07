<?php
declare(strict_types=1);

require_once __DIR__ . '/k1b_bootstrap.php';
require_once __DIR__ . '/K1dSafeTestDatabase.php';

use App\QueueV4Clean\OuterCronHttpReceipt;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\Services\ApiExecutionMetadataContext;

if (($argv[1]??'')==='crash-child') {
    $pdo=K1dSafeTestDatabase::connectExistingFromEnvironment()->pdo();
    OuterCronHttpReceipt::within($pdo,['max_calls'=>10,'ceiling'=>55],static function () use ($pdo,$argv): array {
        $id=str_repeat('a',40);
        OuterCronHttpReceipt::reserve($id,'GET','/orders/100',9001,9011,'queue_v4_clean');
        if (($argv[2]??'')==='after') { OuterCronHttpReceipt::boundary($id); OuterCronHttpReceipt::enteringWire($id); }
        if (($argv[2]??'')==='known') { OuterCronHttpReceipt::boundary($id); OuterCronHttpReceipt::enteringWire($id); OuterCronHttpReceipt::result($id,200,''); }
        echo $pdo->query("SELECT run_token FROM system_execution_runs WHERE component_key='queue_v4_outer_http' ORDER BY id DESC LIMIT 1")->fetchColumn();
        exit(73); // Actual process exit: no finally/receipt completion runs.
    });
    exit(74);
}

putenv('APP_ENV=test');
putenv('ML_WRITE_ENABLED=false');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=' . (getenv('DB_PORT') ?: '33338'));
putenv('DB_USER=root');
putenv('DB_PASS=' . (getenv('DB_PASS') ?: 'local-http-receipt-test-only'));
putenv('DB_NAME=erp_meli_k1d_test_outer_http_' . bin2hex(random_bytes(5)));
putenv('CALLS_VERIFY_QA_ROOT=' . dirname(__DIR__) . '/storage/codex-http-phase1');
$database = K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo = $database->pdo();
    // Exact journal schema from migration 115, without unrelated business migrations.
    $sql = file_get_contents(dirname(__DIR__) . '/database/migrations/115_resumable_execution_journal_2_21_3.sql');
    preg_match_all('/CREATE TABLE IF NOT EXISTS system_execution_(?:runs|attempts)\s*\(.*?ENGINE=InnoDB.*?;/s', $sql, $tables);
    k1b_assert(count($tables[0]) === 2, 'journal fixture schema');
    foreach ($tables[0] as $table) { $pdo->exec($table); }
    $pdo->exec('CREATE TABLE schema_migrations (version VARCHAR(255) NOT NULL)');
    $pdo->exec("INSERT INTO schema_migrations VALUES ('303_outer_cron_http_receipt.sql')");
    $migration = dirname(__DIR__) . '/database/migrations/303_outer_cron_http_receipt.sql';
    $pdo->exec('CREATE TABLE system_cold_archives (dataset_key VARCHAR(80) NOT NULL) ENGINE=InnoDB');
    if (is_file($migration)) { $pdo->exec(file_get_contents($migration)); }
    $capacity = ['max_calls'=>10, 'configured_max_calls'=>10, 'ceiling'=>55, 'max_calls_source'=>'erp_setting'];
    k1b_assert(class_exists(OuterCronHttpReceipt::class), 'RED: durable outer Cron receipt authority missing');
    $result = OuterCronHttpReceipt::within($pdo, $capacity, static fn (): array => ['ok'=>true,'status'=>'completed']);
    $receipt = OuterCronHttpReceipt::read($pdo, $result['cron_cycle_id']);
    k1b_assert($receipt['physical_http_total'] === 0, 'T1 zero HTTP');
    k1b_assert($receipt['configured_http_limit'] === 10 && $receipt['configured_http_ceiling'] === 55, 'T1 capacity');
    k1b_assert($receipt['terminal_status'] === 'completed' && $receipt['ended_at'] !== null, 'T1 durable terminal');
    echo "T1 zero_http_receipt=PASS\n";
    foreach (['remote_429_global_pause','remote_result_uncertain','budget_exhausted'] as $stop) {
        $result=OuterCronHttpReceipt::within($pdo,$capacity,static function () use ($stop): array {
            QueueV4CleanCycleBudget::start(10);
            if ($stop==='budget_exhausted') {
                for ($i=0;$i<10;$i++) {
                    $id=bin2hex(random_bytes(20));
                    ApiExecutionMetadataContext::run(['source'=>'test','transport_request_id'=>$id],static function () use ($id): void {
                        QueueV4CleanCycleBudget::reserve($id,'test');
                        QueueV4CleanCycleBudget::enteringTransport($id);
                    });
                }
            } else { QueueV4CleanCycleBudget::stop($stop); }
            QueueV4CleanCycleBudget::clear();
            return ['status'=>'completed'];
        });
        k1b_assert(OuterCronHttpReceipt::read($pdo,$result['cron_cycle_id'])['protected_stop_reason']===$stop,'RED shared budget stop before clear: '.$stop);
    }
    echo "SHARED_BUDGET_STOP_BEFORE_CLEAR=PASS\n";
    $result = OuterCronHttpReceipt::within($pdo, $capacity, static function (): array {
        foreach (['/oauth/token','/orders/100','/billing/integration/periods/key/group/ML/order/details'] as $i=>$endpoint) {
            $requestId = str_pad(dechex($i+1),40,'0',STR_PAD_LEFT);
            OuterCronHttpReceipt::reserve($requestId,'GET',$endpoint,9001,9011,'queue_v4_worker');
            OuterCronHttpReceipt::boundary($requestId);
            OuterCronHttpReceipt::enteringWire($requestId);
            OuterCronHttpReceipt::result($requestId,200,'');
        }
        return ['ok'=>true,'status'=>'completed'];
    });
    $receipt = OuterCronHttpReceipt::read($pdo,$result['cron_cycle_id']);
    k1b_assert($receipt['physical_http_total']===3 && $receipt['physical_http_known']===3, 'T2 exactly three');
    k1b_assert($receipt['http_oauth']===1 && $receipt['http_orders_exact']===1 && $receipt['http_billing']===1, 'T2 endpoint distribution');
    echo "T2 three_http_receipt=PASS\n";
    $physical=static function (int $sequence,string $path,int $status=200): void {
        $id=str_pad(dechex($sequence),40,'0',STR_PAD_LEFT);
        OuterCronHttpReceipt::reserve($id,'GET',$path,9001,9011,'queue_v4_clean');
        ApiExecutionMetadataContext::run(['source'=>'queue_v4_clean','transport_request_id'=>$id],static function () use ($id,$status): void {
            try { QueueV4CleanCycleBudget::reserve($id,'queue_v4_clean'); }
            catch (App\Services\ApiBudgetExhaustedException $failure) { OuterCronHttpReceipt::cancelBeforeTransport($id,true); throw $failure; }
            OuterCronHttpReceipt::boundary($id);
            QueueV4CleanCycleBudget::enteringTransport($id);
            OuterCronHttpReceipt::enteringWire($id);
            OuterCronHttpReceipt::result($id,$status,'');
        });
    };
    $cycle=static function (callable $callback) use ($pdo,$capacity): array {
        $result=OuterCronHttpReceipt::within($pdo,$capacity,static function () use ($callback): array {
            QueueV4CleanCycleBudget::start(10,'automatic',microtime(true)+45);
            try { return $callback(); } finally { QueueV4CleanCycleBudget::clear(); }
        });
        return OuterCronHttpReceipt::read($pdo,$result['cron_cycle_id']);
    };
    $receipt=$cycle(static function () use ($physical): array {
        $physical(1,'/oauth/token');
        for ($i=2;$i<=6;$i++) { $physical($i,'/orders/'.(100+$i)); }
        $physical(7,'/billing/integration/group/ML/order/details');
        $physical(8,'/billing/integration/group/ML/order/details');
        return ['status'=>'completed'];
    });
    k1b_assert($receipt['physical_http_total']===8 && $receipt['http_oauth']===1 && $receipt['http_orders_exact']===5 && $receipt['http_billing']===2,'T3 shared budget');
    echo "T3 shared_oauth_orders_billing=PASS\n";
    $receipt=$cycle(static function () use ($physical): array {
        for ($i=1;$i<=10;$i++) { $physical($i,'/orders/'.(100+$i)); }
        try { $physical(11,'/orders/111'); throw new RuntimeException('eleventh allowed'); }
        catch (App\Services\ApiBudgetExhaustedException) { return ['status'=>'budget_exhausted']; }
    });
    k1b_assert($receipt['physical_http_total']===10 && $receipt['budget_exhausted_before_transport']===1 && $receipt['blocked_before_transport']===1,'T4 eleventh denied');
    echo "T4 eleventh_blocked=PASS\n";
    $receipt=$cycle(static function (): array {
        $id=str_repeat('b',40);
        OuterCronHttpReceipt::reserve($id,'GET','/orders/100',9001,9011,'queue_v4_clean');
        ApiExecutionMetadataContext::run(['source'=>'queue_v4_clean','transport_request_id'=>$id],static function () use ($id): void {
            QueueV4CleanCycleBudget::reserve($id,'queue_v4_clean');
            k1b_assert(QueueV4CleanCycleBudget::releaseBeforeTransport($id),'release owned reservation');
            OuterCronHttpReceipt::cancelBeforeTransport($id);
            OuterCronHttpReceipt::cancelBeforeTransport($id); // Transport + client compensation observes one cancellation.
        });
        return ['status'=>'completed'];
    });
    k1b_assert($receipt['physical_http_total']===0 && $receipt['released_before_transport']===1,'T5 cancelled reserve');
    echo "T5 cancellation=PASS\n";
    foreach (['before','after','known'] as $mode) {
        $pipes=[];
        $process=proc_open([PHP_BINARY,__FILE__,'crash-child',$mode],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        k1b_assert(is_resource($process),'child created');
        $token=trim(stream_get_contents($pipes[1])); $error=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($process);
        k1b_assert($exit===73 && preg_match('/^[a-f0-9]{40}$/D',$token)===1,'child crash code: '.$error);
        $receipt=OuterCronHttpReceipt::read($pdo,$token);
        k1b_assert($receipt['terminal_status']==='incomplete' && $receipt['ended_at']===null,'crash cannot fabricate terminal');
        if ($mode==='known') { k1b_assert($receipt['physical_http_known']===1 && $receipt['physical_http_unknown']===0,'Crash C known durable'); }
        else { k1b_assert($receipt['physical_http_total']===null && $receipt['physical_http_unknown']===1,'crash uncertainty retained'); }
        echo ($mode==='before'?'T6':($mode==='after'?'T7':'T12'))." actual_process_crash_".$mode."=PASS\n";
    }
    foreach ([429,503,206] as $status) {
        $receipt=$cycle(static function () use ($physical,$status): array { $physical(1,'/billing/integration/group/ML/order/details',$status); return ['status'=>$status===429?'remote_429_global_pause':'completed']; });
        k1b_assert($receipt['physical_http_total']===1,'known non-200 consumes one');
        if ($status===429) { k1b_assert($receipt['http_429']===1 && $receipt['protected_stop_reason']==='remote_429_global_pause','429 stop'); }
        if ($status===503) { k1b_assert($receipt['http_5xx']===1,'known 5xx'); }
        if ($status===206) { k1b_assert($receipt['http_206']===1 && $receipt['http_2xx']===1,'206 is 2xx subset'); }
        echo ($status===429?'T8':($status===503?'T9':'T206'))." known_status_".$status."=PASS\n";
    }
    $receipt=$cycle(static function () use ($physical): array { $physical(1,'/orders/100'); return ['status'=>'completed']; });
    $pdo->exec('CREATE TABLE duplicated_observers (request_id CHAR(40),observer_name VARCHAR(20))');
    $pdo->exec("INSERT INTO duplicated_observers VALUES('0000000000000000000000000000000000000001','log'),('0000000000000000000000000000000000000001','permit'),('0000000000000000000000000000000000000001','capture'),('0000000000000000000000000000000000000001','journal')");
    k1b_assert(OuterCronHttpReceipt::read($pdo,$receipt['cycle_id'])['physical_http_total']===1,'T10 observers do not multiply physical identity');
    echo "T10 no_double_count=PASS\n";
    $pdo->exec("CREATE TRIGGER deny_receipt_result BEFORE UPDATE ON system_execution_attempts FOR EACH ROW BEGIN IF NEW.http_state='known_result' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic_result_persistence_failure'; END IF; END");
    $receipt=$cycle(static function () use ($physical): array { $physical(2,'/orders/101'); return ['status'=>'completed']; });
    k1b_assert($receipt['terminal_status']==='incomplete' && $receipt['physical_http_total']===null && $receipt['physical_http_unknown']===1,'result persistence fault remains uncertain');
    $pdo->exec('DROP TRIGGER deny_receipt_result');
    echo "RESULT_PERSISTENCE_FAILURE=PASS\n";
    $pdo->exec("CREATE TRIGGER deny_receipt_close BEFORE UPDATE ON system_execution_runs FOR EACH ROW BEGIN IF NEW.finished_at IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic_close_persistence_failure'; END IF; END");
    try { $cycle(static function () use ($physical): array { $physical(3,'/orders/102'); return ['status'=>'completed']; }); throw new RuntimeException('close failure ignored'); }
    catch (PDOException $failure) { k1b_assert(str_contains($failure->getMessage(),'synthetic_close_persistence_failure'),'exact injected failure'); }
    $token=(string)$pdo->query('SELECT run_token FROM system_execution_runs ORDER BY id DESC LIMIT 1')->fetchColumn();
    $receipt=OuterCronHttpReceipt::read($pdo,$token);
    k1b_assert($receipt['terminal_status']==='incomplete' && $receipt['ended_at']===null && $receipt['physical_http_known']===1 && $receipt['physical_http_total']===null,'failed final closure cannot fabricate success');
    $pdo->exec('DROP TRIGGER deny_receipt_close');
    echo "FINAL_RECEIPT_CLOSE_FAILURE=PASS\n";
    $pdo->exec('CREATE TABLE app_settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT,is_encrypted TINYINT NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL DEFAULT "general") ENGINE=InnoDB');
    $pdo->exec("INSERT INTO app_settings(setting_key,setting_value) VALUES('automation.max_api_calls_per_cycle','10'),('automation.max_api_calls_ceiling','55')");
    $pdo->exec('CREATE TABLE queue_v4_clean_control(control_key VARCHAR(32) PRIMARY KEY,engine_state VARCHAR(20),scheduler_enabled TINYINT,last_scheduler_at DATETIME(3)) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO queue_v4_clean_control VALUES('primary','INACTIVE',0,NULL)");
    $result=(new App\QueueV4Clean\QueueV4CleanScheduler($pdo))->run(10,45);
    k1b_assert(isset($result['cron_cycle_id']), 'RED: real scheduler stopped return lacks durable outer identity');
    $receipt=OuterCronHttpReceipt::read($pdo,$result['cron_cycle_id']);
    k1b_assert($receipt['terminal_status']==='stopped' && $receipt['physical_http_total']===0,'T11 real stopped scheduler');
    echo "T11 scheduler_stopped=PASS\n";
    $result=(new App\QueueV4Clean\QueueV4CleanScheduler($pdo))->run(null,45);
    $receipt=OuterCronHttpReceipt::read($pdo,$result['cron_cycle_id']);
    k1b_assert($receipt['max_calls_source']==='ERP_SETTINGS','scheduler ERP source retained');
    $result=OuterCronHttpReceipt::withCapacitySource('ERP_SETTINGS',static fn (): array => (new App\QueueV4Clean\QueueV4CleanScheduler($pdo))->run(10,45));
    k1b_assert(OuterCronHttpReceipt::read($pdo,$result['cron_cycle_id'])['max_calls_source']==='ERP_SETTINGS','CLI resolved ERP source handoff');
    $result=(new App\QueueV4Clean\QueueV4CleanScheduler($pdo))->run(10,45);
    k1b_assert(OuterCronHttpReceipt::read($pdo,$result['cron_cycle_id'])['max_calls_source']==='CLI_MAX_CALLS_OVERRIDE','explicit override retained');
    echo "CAPACITY_SOURCE_ERP_AND_CLI=PASS\n";
    $pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE',scheduler_enabled=1");
    $pdo->exec('CREATE TABLE queue_v4_clean_leases(lease_key VARCHAR(32) PRIMARY KEY,owner_ref VARCHAR(96),acquired_at DATETIME(3),heartbeat_at DATETIME(3),expires_at DATETIME(3)) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO queue_v4_clean_leases VALUES('scheduler','other-owner',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 5 MINUTE))");
    $result=(new App\QueueV4Clean\QueueV4CleanScheduler($pdo))->run(10,45);
    $receipt=OuterCronHttpReceipt::read($pdo,$result['cron_cycle_id']);
    k1b_assert($receipt['terminal_status']==='busy' && $receipt['physical_http_total']===0,'T11 busy natural lease');
    echo "T11 scheduler_busy=PASS\n";
} finally { $database->cleanup(); }
