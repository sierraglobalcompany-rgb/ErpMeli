<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
require __DIR__.'/K1dSafeTestDatabase.php';
require __DIR__.'/cap2_health_fixture.php';
foreach(['APP_ENV'=>'test','ML_WRITE_ENABLED'=>'false','DB_HOST'=>'127.0.0.1','DB_PORT'=>'33079','DB_USER'=>'root','DB_PASS'=>'','DB_NAME'=>'erp_meli_k1d_test_calls_history_'.bin2hex(random_bytes(4))]as $k=>$v)putenv($k.'='.$v);
$h=K1dSafeTestDatabase::createFromEnvironment();
final class CallsHistoryMeasurePdo extends PDO {
    public string $lastPrepared='';
    public function prepare(string $query,array $options=[]):PDOStatement|false {$this->lastPrepared=$query;return parent::prepare($query,$options);}
}
try {
    $pdo=$h->pdo();cap2_health_create_schema($pdo);cap2_health_seed($pdo);
    $path='/billing/integration/group/ML/order/details';
    $pdo->prepare("INSERT INTO api_request_logs(scope_kind,company_id,meli_account_id,http_status,reached_remote,created_at,endpoint_path,request_id) VALUES('account',1,11,429,1,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 73 HOUR),?,?)")->execute([$path,str_repeat('a',24)]);
    $r=new App\Services\ApiRhythmPolicyService();$d=$r->billing429BackoffDiagnostic();
    k1b_assert(($d['status']??'')==='UNKNOWN'&&($d['increase_evidence_status']??'')==='UNKNOWN'&&!$d['backoff_active'],'aged73h_without_new_evidence_must_remain_unknown:'.json_encode($d));
    for($i=0;$i<60;$i++) {
        $id=bin2hex(random_bytes(20));
        $pdo->prepare("INSERT INTO api_request_logs(scope_kind,company_id,meli_account_id,http_status,reached_remote,created_at,endpoint_path,request_id) VALUES('account',1,11,200,1,UTC_TIMESTAMP(),?,?)")->execute([$path,$id]);
        $pdo->prepare("INSERT INTO api_remote_permits(company_id,meli_account_id,endpoint_key,permit_token,http_status,completed_at,dispatched_at) VALUES(1,11,'billing_orders',?,200,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$id]);
    }
    $d=$r->billing429BackoffDiagnostic();
    k1b_assert($d['status']==='UNKNOWN'&&$d['increase_evidence_status']==='OK'&&$d['known_successes_after_last_unknown']===60,'new60_authorize_increase_without_rewriting_history');
    // Test-only indexes mirror applicable schema301 keys; no runtime DDL.
    $pdo->exec('ALTER TABLE api_request_logs ADD KEY idx_api_request_endpoint_time(meli_account_id,endpoint_path,created_at),ADD KEY idx_api_request_remote_time(reached_remote,created_at)');
    $pdo->exec('ALTER TABLE api_remote_permits ADD UNIQUE KEY uq_api_remote_permit_token(permit_token),ADD KEY idx_api_remote_permit_scope(company_id,meli_account_id,endpoint_key)');
    $log=$pdo->prepare("INSERT INTO api_request_logs(scope_kind,company_id,meli_account_id,http_status,reached_remote,created_at,endpoint_path,request_id) VALUES('account',1,11,200,1,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 100 HOUR),?,?)");
    $permit=$pdo->prepare("INSERT INTO api_remote_permits(company_id,meli_account_id,endpoint_key,permit_token,http_status,completed_at,dispatched_at) VALUES(1,11,'billing_orders',?,200,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 100 HOUR),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 100 HOUR))");
    $pdo->beginTransaction();for($i=0;$i<10000;$i++){$id=bin2hex(random_bytes(20));$log->execute([$path,$id]);$permit->execute([$id]);}$pdo->commit();
    $meter=new CallsHistoryMeasurePdo('mysql:host=127.0.0.1;port=33079;dbname='.getenv('DB_NAME').';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $before=memory_get_usage();$start=hrtime(true);
    $history=(new ReflectionMethod(App\Services\ApiRhythmPolicyService::class,'billingHistoricalEvidence'))->invoke($r,$meter,true,true);
    $ms=(hrtime(true)-$start)/1e6;$bytes=memory_get_usage()-$before;$sql=$meter->lastPrepared;
    $explain=$meter->prepare('EXPLAIN '.$sql);$explain->execute([$path,'billing_orders']);$plan=$explain->fetchAll(PDO::FETCH_ASSOC);
    k1b_assert((int)$history['unknown_rows']===1,'historical_known_pairs_do_not_create_unknown');
    echo 'HISTORY_MEASURE='.json_encode(['retained_physical_rows'=>20121,'returned_rows'=>1,'elapsed_ms'=>$ms,'php_memory_delta_bytes'=>$bytes,'explain'=>$plan])."\n";
    echo "CALLS_BILLING_HISTORY_MYSQL_OK\n";
}finally{$h->cleanup();}
