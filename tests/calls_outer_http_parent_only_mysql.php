<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
require __DIR__.'/K1dSafeTestDatabase.php';
use App\QueueV4Clean\OuterCronHttpReceipt;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\Services\ApiExecutionMetadataContext;
use App\Services\CronDeadlineContext;
use App\Services\AppSettingsService;

final class ParentScanMeasuredStatement extends PDOStatement
{
    public static array $queries=[];
    protected function __construct() {}
    public function execute(?array $params=null): bool
    {
        self::$queries[]=['sql'=>$this->queryString,'params'=>$params];
        return parent::execute($params);
    }
}

foreach(['APP_ENV'=>'test','ML_WRITE_ENABLED'=>'false','DB_HOST'=>'127.0.0.1','DB_PORT'=>'33338','DB_USER'=>'root','DB_PASS'=>'local-http-receipt-test-only','DB_NAME'=>'erp_meli_k1d_test_http_parent_'.bin2hex(random_bytes(5)), 'CALLS_VERIFY_QA_ROOT'=>dirname(__DIR__).'/storage/codex-http-phase1'] as $k=>$v) { putenv($k.'='.$v); }
$db=K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo=$db->pdo();
    preg_match_all('/CREATE TABLE IF NOT EXISTS system_execution_(?:runs|attempts)\s*\(.*?ENGINE=InnoDB.*?;/s',file_get_contents(dirname(__DIR__).'/database/migrations/115_resumable_execution_journal_2_21_3.sql'),$tables);
    foreach($tables[0] as $table) { $pdo->exec($table); }
    $pdo->exec('CREATE TABLE schema_migrations(version VARCHAR(255))');
    $pdo->exec('CREATE TABLE system_cold_archives(dataset_key VARCHAR(80))');
    $pdo->exec(file_get_contents(dirname(__DIR__).'/database/migrations/303_outer_cron_http_receipt.sql'));
    preg_match('/CREATE TABLE IF NOT EXISTS system_retention_cli_state\s*\(.*?ENGINE=InnoDB.*?;/s',file_get_contents(dirname(__DIR__).'/database/migrations/219_operational_snapshots_health_retention_2_28_39.sql'),$stateTable);
    $pdo->exec($stateTable[0]);
    $pdo->exec('CREATE TABLE app_settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT,is_encrypted TINYINT NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL DEFAULT "general") ENGINE=InnoDB');
    $pdo->exec("INSERT INTO app_settings(setting_key,setting_value) VALUES('retention.incident_days','90')");
    AppSettingsService::clearCache();
    if (($argv[1]??'')==='scan-only') {
        $insert=$pdo->prepare("INSERT INTO system_execution_runs(run_token,component_key,status,finished_at,http_receipt_json) VALUES(?,'queue_v4_outer_http','completed',DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 180 DAY),?)");
        for($i=0;$i<3000;$i++) { $insert->execute([bin2hex(random_bytes(20)),json_encode(['terminal_status'=>'incomplete'],JSON_THROW_ON_ERROR)]); }
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ParentScanMeasuredStatement::class,[]]);
        $step=OuterCronHttpReceipt::retainStep($pdo);
        $select=array_values(array_filter(ParentScanMeasuredStatement::$queries,static fn($q)=>str_starts_with($q['sql'],'SELECT id,run_token,http_receipt_json,status,finished_at FROM system_execution_runs')))[0];
        $analyze=$pdo->prepare('ANALYZE FORMAT=JSON '.$select['sql']); $analyze->execute($select['params']);
        $plan=json_decode((string)$analyze->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
        $examined=0;
        $visit=static function(array $node) use(&$visit,&$examined): void {
            if(($node['table_name']??null)==='system_execution_runs') { $examined=max($examined,(int)($node['r_rows']??0)); }
            foreach($node as $value) { if(is_array($value)) { $visit($value); } }
        };
        $visit($plan);
        echo 'ADVERSARIAL_ACTUAL_SCAN_ROWS='.$examined.PHP_EOL;
        k1b_assert($examined>0 && $examined<=100,'RED actual storage scan must be bounded before JSON');
        k1b_assert($step['deleted']===0 && $step['scanned']===100,'held scan batch bound');
        k1b_assert((int)$pdo->query('SELECT COUNT(*) FROM system_execution_runs')->fetchColumn()===3000,'unknown retained');
        echo "ADVERSARIAL_SCAN_BOUND=PASS\n";
        return;
    }
    $wrap=static function(callable $op,int $limit=55) use($pdo): array {
        $before=(int)$pdo->query('SELECT COUNT(*) FROM system_execution_runs')->fetchColumn();
        $childrenBefore=(int)$pdo->query('SELECT COUNT(*) FROM system_execution_attempts')->fetchColumn();
        $r=OuterCronHttpReceipt::within($pdo,['max_calls'=>$limit,'ceiling'=>55],static function() use($op,$limit): array {
            QueueV4CleanCycleBudget::start($limit,'automatic',microtime(true)+45);
            try { $op(); return ['status'=>'completed']; } finally { QueueV4CleanCycleBudget::clear(); }
        });
        k1b_assert((int)$pdo->query('SELECT COUNT(*) FROM system_execution_runs')->fetchColumn()===$before+1,'P1 exactly one parent');
        k1b_assert((int)$pdo->query('SELECT COUNT(*) FROM system_execution_attempts')->fetchColumn()===$childrenBefore,'P2 RED child ledger must be removed');
        return OuterCronHttpReceipt::read($pdo,$r['cron_cycle_id']);
    };
    $reserve=static function(string $id): void { OuterCronHttpReceipt::reserve($id,'GET','/orders/100',9001,9011,'queue_v4_clean'); };
    $known=static function(string $id,int $status=200) use($reserve): void {
        ApiExecutionMetadataContext::run(['source'=>'queue_v4_clean','transport_request_id'=>$id],static function() use($reserve,$id,$status): void {
            $reserve($id); QueueV4CleanCycleBudget::reserve($id,'queue_v4_clean');
            OuterCronHttpReceipt::boundary($id); QueueV4CleanCycleBudget::enteringTransport($id);
            OuterCronHttpReceipt::enteringWire($id); OuterCronHttpReceipt::result($id,$status,'');
        });
    };
    $r=$wrap(static function() use($reserve): void { $id=bin2hex(random_bytes(20)); $reserve($id); OuterCronHttpReceipt::cancelBeforeTransport($id); });
    k1b_assert($r['blocked_before_transport']===1 && $r['physical_http_total']===0 && $r['pending_physical_request']===null,'P3 preflight local aggregate');
    echo "P1 P2 P3=PASS\n";
    $r=$wrap(static function() use($reserve): void {
        $id=bin2hex(random_bytes(20)); $reserve($id); $reserve($id);
        try { OuterCronHttpReceipt::reserve($id,'POST','/orders/100',9001,9011,'queue_v4_clean'); throw new RuntimeException('mismatch accepted'); }
        catch(RuntimeException $e) { k1b_assert($e->getMessage()==='outer_http_request_identity_reused','P6 exact conflict'); }
        OuterCronHttpReceipt::cancelBeforeTransport($id);
    });
    echo "P5 P6=PASS\n";
    $r=$wrap(static function() use($pdo,$reserve,$known): void {
        for($i=0;$i<100;$i++) {
            $id=bin2hex(random_bytes(20));
            ApiExecutionMetadataContext::run(['source'=>'queue_v4_clean','transport_request_id'=>$id],static function() use($pdo,$reserve,$id): void {
                $reserve($id); QueueV4CleanCycleBudget::reserve($id,'queue_v4_clean'); OuterCronHttpReceipt::boundary($id);
                $durable=json_decode((string)$pdo->query('SELECT http_receipt_json FROM system_execution_runs ORDER BY id DESC LIMIT 1')->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
                k1b_assert($durable['pending_physical_request']['request_id']===$id,'P7 durable pending before final fence');
                k1b_assert(QueueV4CleanCycleBudget::releaseBeforeTransport($id),'refund'); OuterCronHttpReceipt::cancelBeforeTransport($id);
                $durable=json_decode((string)$pdo->query('SELECT http_receipt_json FROM system_execution_runs ORDER BY id DESC LIMIT 1')->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
                k1b_assert($durable['pending_physical_request']===null && $durable['physical_http_known']===0,'P9 exact safe cancellation clears pending');
            });
        }
        for($i=0;$i<55;$i++) { $known(bin2hex(random_bytes(20))); }
    });
    k1b_assert($r['physical_http_total']===55 && count($r['physical_request_ids'])===55 && $r['blocked_before_transport']===100 && $r['released_before_transport']===100,'P10 P11 refunds do not grow rows/known ids');
    echo "P7 P9 P10 P11=PASS parent_rows=1 child_rows=0 refunded=100 known_ids=55\n";
    $r=$wrap(static function() use($reserve,$known): void {
        for($i=0;$i<10;$i++) { $known(bin2hex(random_bytes(20))); }
        $id=bin2hex(random_bytes(20)); $reserve($id);
        try { QueueV4CleanCycleBudget::reserve($id,'queue_v4_clean'); throw new RuntimeException('11 allowed'); }
        catch(App\Services\ApiBudgetExhaustedException) { OuterCronHttpReceipt::cancelBeforeTransport($id,true); }
    },10);
    k1b_assert($r['physical_http_total']===10 && $r['budget_exhausted_before_transport']===1,'P4 P12 exact limit');
    echo "P4 P12=PASS\n";
    foreach([200,429,503] as $status) {
        $r=$wrap(static fn()=> $known(bin2hex(random_bytes(20)),$status));
        k1b_assert($r['physical_http_known']===1 && count($r['physical_request_ids'])===1 && $r['pending_physical_request']===null,'known result');
        k1b_assert($r[$status===200?'http_2xx':($status===429?'http_429':'http_5xx')]===1,'status counter');
        echo 'P'.($status===200?13:($status===429?14:15))."=PASS\n";
    }
    $r=$wrap(static function() use($reserve): void {
        $a=bin2hex(random_bytes(20)); $reserve($a); OuterCronHttpReceipt::boundary($a);
        $b=bin2hex(random_bytes(20)); $reserve($b);
        try { OuterCronHttpReceipt::boundary($b); throw new RuntimeException('overlap allowed'); }
        catch(RuntimeException $e) { k1b_assert($e->getMessage()==='outer_http_pending_request_exists','P19 overlap exact error'); }
        OuterCronHttpReceipt::enteringWire($a); OuterCronHttpReceipt::result($a,0,'synthetic_unknown');
    });
    k1b_assert($r['physical_http_total']===null && $r['terminal_status']==='incomplete' && $r['pending_physical_request']!==null,'unknown held');
    echo "P19=PASS\n";
    $pdo->exec("CREATE TRIGGER parent_result_fault BEFORE UPDATE ON system_execution_runs FOR EACH ROW BEGIN IF JSON_EXTRACT(NEW.http_receipt_json,'$.physical_http_known')>JSON_EXTRACT(OLD.http_receipt_json,'$.physical_http_known') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic_parent_result_fault'; END IF; END");
    $r=$wrap(static fn()=> $known(bin2hex(random_bytes(20))));
    k1b_assert($r['terminal_status']==='incomplete' && $r['pending_physical_request']!==null && $r['physical_http_total']===null,'P17 preserve pending after failed known persistence');
    $pdo->exec('DROP TRIGGER parent_result_fault');
    echo "P17=PASS\n";
    $before=(int)$pdo->query('SELECT COUNT(*) FROM system_execution_runs')->fetchColumn();
    $pdo->exec("CREATE TRIGGER parent_pending_fault BEFORE UPDATE ON system_execution_runs FOR EACH ROW BEGIN IF JSON_EXTRACT(NEW.http_receipt_json,'$.pending_physical_request.request_id') IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic_parent_pending_fault'; END IF; END");
    $committed=false; $wire=false;
    try { $wrap(static function() use($reserve,&$committed,&$wire): void { $id=bin2hex(random_bytes(20)); $reserve($id); OuterCronHttpReceipt::boundary($id); $committed=true; $wire=true; }); }
    catch(PDOException $e) { k1b_assert(str_contains($e->getMessage(),'synthetic_parent_pending_fault'),'P8 injected error'); }
    k1b_assert(!$committed && !$wire,'P8 reject before final fence/wire');
    $pdo->exec('DROP TRIGGER parent_pending_fault');
    echo "P8=PASS\n";
    $r=$wrap(static fn()=> $known(bin2hex(random_bytes(20))));
    k1b_assert(OuterCronHttpReceipt::read($pdo,$r['cycle_id'])===$r,'P18 persisted read');
    echo "P18=PASS\n";
    // Retention: seed >10 eligible parents, plus exact protected conditions.
    $pdo->exec("UPDATE system_execution_runs SET finished_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 180 DAY)");
    $protected=[];
    foreach(['recent_429','other_component','malformed','incomplete','pending','child_present','unknown_count','id_count_mismatch','duplicate_ids','cycle_mismatch'] as $kind) {
        $r=$wrap(static fn()=> $known(bin2hex(random_bytes(20)), $kind==='recent_429'?429:200));
        $id=(int)$pdo->query('SELECT MAX(id) FROM system_execution_runs')->fetchColumn();
        $changes=$r;
        if($kind==='incomplete') { $changes['terminal_status']='incomplete'; }
        if($kind==='pending') { $changes['pending_physical_request']=['request_id'=>str_repeat('b',40)]; }
        if($kind==='unknown_count') { $changes['physical_http_unknown']=1; }
        if($kind==='id_count_mismatch') { $changes['physical_http_known']=2; }
        if($kind==='duplicate_ids') { $changes['physical_request_ids'][]=$changes['physical_request_ids'][0]; $changes['physical_http_known']=2; $changes['physical_http_total']=2; }
        if($kind==='cycle_mismatch') { $changes['cycle_id']=str_repeat('f',40); }
        $pdo->prepare('UPDATE system_execution_runs SET component_key=?,finished_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ? DAY),http_receipt_json=? WHERE id=?')->execute([$kind==='other_component'?'other':'queue_v4_outer_http',$kind==='recent_429'?2:180,$kind==='malformed'?'invalid':json_encode($changes,JSON_THROW_ON_ERROR),$id]);
        if($kind==='child_present') { $pdo->prepare("INSERT INTO system_execution_attempts(system_execution_run_id,queue_key,source_id,operation_key,idempotency_key) VALUES(?,'other','fixture','fixture',?)")->execute([$id,hash('sha256',(string)$id)]); }
        $protected[$id]=$pdo->query('SELECT http_receipt_json FROM system_execution_runs WHERE id='.$id)->fetchColumn();
    }
    for($i=0;$i<15;$i++) { $r=$wrap(static function(): void {}); $pdo->prepare('UPDATE system_execution_runs SET finished_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 180 DAY) WHERE run_token=?')->execute([$r['cycle_id']]); }
    QueueV4CleanCycleBudget::start(10);
    try {
        $beforeBudget=QueueV4CleanCycleBudget::snapshot();
        $rowsBefore=(int)$pdo->query('SELECT COUNT(*) FROM system_execution_runs')->fetchColumn();
        $skip=CronDeadlineContext::within(microtime(true)+0.001,static fn()=> OuterCronHttpReceipt::retainStep($pdo));
        k1b_assert($skip['deleted']===0 && $skip['deferred'] && (int)$pdo->query('SELECT COUNT(*) FROM system_execution_runs')->fetchColumn()===$rowsBefore,'P24 near deadline zero mutation');
        $step=OuterCronHttpReceipt::retainStep($pdo);
        k1b_assert($step['deleted']>0 && $step['deleted']<=10,'P23 bounded mixed batch');
        foreach($protected as $id=>$bytes) { k1b_assert($pdo->query('SELECT http_receipt_json FROM system_execution_runs WHERE id='.$id)->fetchColumn()===$bytes,'P21 P22 protected receipt unchanged'); }
        k1b_assert(QueueV4CleanCycleBudget::snapshot()===$beforeBudget,'P25 zero budget use');
    } finally { QueueV4CleanCycleBudget::clear(); }
    // Capacity proof in an isolated eligible cohort, not a fabricated promise
    // that held/corrupt evidence must be deleted to maintain throughput.
    $pdo->exec('DELETE FROM system_execution_attempts');
    $pdo->exec('DELETE FROM system_execution_runs');
    for($i=0;$i<100;$i++) { $r=$wrap(static function(): void {}); $pdo->prepare('UPDATE system_execution_runs SET finished_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 180 DAY) WHERE run_token=?')->execute([$r['cycle_id']]); }
    for($i=0;$i<10;$i++) { k1b_assert(OuterCronHttpReceipt::retainStep($pdo)['deleted']===10,'P23 service rate on eligible parents'); }
    k1b_assert((int)$pdo->query('SELECT COUNT(*) FROM system_execution_runs')->fetchColumn()===0,'P23 all eligible drained');
    // Adversarial RED: held prefix must not monopolize every invocation.
    for($i=0;$i<10;$i++) {
        $r=$wrap(static function(): void {});
        $r['cycle_id']=str_repeat('f',40);
        $pdo->prepare('UPDATE system_execution_runs SET finished_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 180 DAY),http_receipt_json=? WHERE id=(SELECT id FROM (SELECT MAX(id) id FROM system_execution_runs) latest)')->execute([json_encode($r,JSON_THROW_ON_ERROR)]);
    }
    for($i=0;$i<10;$i++) { $r=$wrap(static function(): void {}); $pdo->prepare('UPDATE system_execution_runs SET finished_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 180 DAY) WHERE run_token=?')->execute([$r['cycle_id']]); }
    $deleted=0;
    for($i=0;$i<4;$i++) { $deleted+=OuterCronHttpReceipt::retainStep($pdo)['deleted']; }
    k1b_assert($deleted===10,'RED held prefix must not starve eligible receipts');
    k1b_assert((int)$pdo->query('SELECT COUNT(*) FROM system_execution_runs')->fetchColumn()===10,'held evidence preserved');
    echo "ADVERSARIAL_HELD_PREFIX_PROGRESS=PASS\n";
    echo "P21 P22 P23 P24 P25=PASS service=10 ingress=1 margin=9 ttl_days=90\n";
    foreach(['app/Services/ColdArchiveService.php','app/Services/RetentionPolicyService.php','app/Services/TechnicalRetentionCliService.php','database/migrations/303_outer_cron_http_receipt.sql'] as $path) {
        $bytes=file_get_contents(dirname(__DIR__).'/'.$path);
        k1b_assert(!str_contains($bytes,'outer_http_attempts') && !str_contains($bytes,'outer_http_runs'),'P26 cold removal '.$path);
    }
    foreach(['app/Services/CurlMeliHttpTransport.php','app/Services/MeliApiClient.php','app/QueueV4Clean/QueueV4CleanScheduler.php'] as $path) {
        $bytes=file_get_contents(dirname(__DIR__).'/'.$path);
        k1b_assert(!str_contains($bytes,'curl_multi') && !str_contains($bytes,'Fiber') && !str_contains($bytes,'proc_open'),'P20 synchronous certified path '.$path);
    }
    $transport=file_get_contents(dirname(__DIR__).'/app/Services/CurlMeliHttpTransport.php');
    k1b_assert(substr_count($transport,'$raw = curl_exec($ch);')===1,'P20 one synchronous wire entry');
    $maintenance=file_get_contents(dirname(__DIR__).'/app/QueueV4Clean/QueueV4CleanMaintenanceService.php');
    k1b_assert(str_contains($maintenance,'OuterCronHttpReceipt::retainStep(Database::connection())'),'P23 every maintenance lane');
    echo "P20=PASS synchronous_transport_no_parallel_path\n";
    echo "P26=PASS\nPARENT_ONLY_TARGETED=PASS REAL_MELI_HTTP=0 REAL_OAUTH=0\n";
} finally { $db->cleanup(); }
