<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
require __DIR__.'/K1dSafeTestDatabase.php';
require __DIR__.'/calls_transport_wire_fixture.php';
use App\QueueV4Clean\QueueV4CleanCycleBudget as B;
use App\Services\ApiExecutionMetadataContext as M;
use App\Services\Cap2DomainsWire as W;
use App\Services\ApiRhythmPolicyService as R;
$root='D:/Codex/tmp/erp-meli/calls-20260906/transport';
foreach(['APP_ENV'=>'test','ML_WRITE_ENABLED'=>'false','DB_HOST'=>'127.0.0.1','DB_PORT'=>'33079','DB_USER'=>'root','DB_PASS'=>'','DB_NAME'=>'erp_meli_k1d_test_calls_transport_'.bin2hex(random_bytes(4)),'APP_KEY'=>'calls-disposable-test-only','PRIVATE_STORAGE_PATH'=>$root.'/private','MELI_API_BASE'=>'https://calls-wire.invalid'] as $k=>$v)putenv($k.'='.$v);
define('ERP_INSTALLATION_ROOT',$root.'/install-'.bin2hex(random_bytes(4)));mkdir(ERP_INSTALLATION_ROOT,0777,true);
$h=K1dSafeTestDatabase::createFromEnvironment();$fail=[];
function calls_check(bool $ok,string $label):void {global $fail;if(!$ok){$fail[]=$label;echo 'EXPECTED_RED='.$label."\n";}}
final class CallsPauseFailurePdo extends PDO {
    public bool $failPause=false;
    public function beginTransaction():bool {if($this->failPause){$this->failPause=false;throw new RuntimeException('calls_pause_transaction_unavailable');}return parent::beginTransaction();}
}
try {
    $pdo=$h->pdo();(new App\Services\Migrator($pdo,__DIR__.'/../database/migrations'))->run(301);
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9001,'Calls transport',1)");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9011,9001,'Calls transport',99011,'conectado')");
    $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at) VALUES(9011,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))')->execute([App\Core\Crypto::encrypt('test-access'),App\Core\Crypto::encrypt('test-refresh')]);
    $pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE',readiness_state='CERTIFIED' WHERE control_key='primary'");$pdo->exec("UPDATE queue_engine_control SET active_engine='v4'");
    // Supplemental technical profile retry proof, not a business-source escape:
    // authorized private capability, one outer limit2, real client/Curl/permit/log.
    $pdo->exec("UPDATE app_settings SET setting_value='0' WHERE setting_key IN ('api.guard.jitter_min_ms','api.guard.jitter_max_ms')");App\Services\AppSettingsService::clearCache();
    W::$responses['/users/me']=[500,['message'=>'temporary']];$retryHeaders=[];
    W::$onWire=static function()use(&$retryHeaders):void {$retryHeaders[]=App\Services\CallsWireOptions::$headers;W::$responses['/users/me']=[200,['id'=>99011]];};
    B::start(2,'manual',microtime(true)+45);$retryError=null;
    try {M::run(['source'=>'web','company_id'=>9001,'account_id'=>9011,'transport_request_id'=>str_repeat('f',40)],static fn()=>M::withTechnicalOperation('oauth_profile',static fn()=>(new App\Services\MeliApiClient(9011))->get('/users/me')));}catch(Throwable $caught){$retryError=$caught;}
    W::$onWire=null;$retryCalls=array_values(array_filter(W::$calls,static fn(array $call):bool=>$call['path']==='/users/me'));
    calls_check($retryError===null&&count($retryCalls)===2&&B::snapshot()['used']===2,'authorized_profile_two_internal_retries_share_outer2:'.($retryError?->getMessage()??'none'));
    $retryIds=array_column(array_column($retryCalls,'meta'),'transport_request_id');
    calls_check(count($retryIds)===2&&count(array_unique($retryIds))===2,'retry_loop_generates_new_identity_inside_loop');
    foreach($retryIds as $i=>$id) {
        calls_check(strlen($id)===40&&$id!==str_repeat('f',40)&&in_array('X-Request-Id: '.$id,$retryHeaders[$i],true),'retry_identity_overrides_metadata_and_header');
        foreach(['api_remote_permits'=>'permit_token','api_request_logs'=>'request_id'] as $table=>$column){$q=$pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column}=? AND company_id=9001 AND meli_account_id=9011");$q->execute([$id]);calls_check((int)$q->fetchColumn()===1,'retry_exact_identity_'.$table);}
    }
    B::clear();usleep(1100000);
    // Deadline after the real SQL fence cancels once; actual wire loss never refunds.
    $cancelKey=bin2hex(random_bytes(20));
    $pdo->prepare("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,lease_owner,lease_generation,lease_expires_at) VALUES(9001,9011,'order_exact','8101',?,'{}','running','calls',1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 MINUTE))")->execute([$cancelKey]);$cancelJob=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO queue_v4_clean_attempts(job_id,run_id,company_id,meli_account_id,lease_owner,lease_generation) VALUES(?,9998,9001,9011,'calls',1)")->execute([$cancelJob]);$cancelAttempt=(int)$pdo->lastInsertId();
    $cancelMeta=['source'=>'queue_v4_clean','company_id'=>9001,'account_id'=>9011,'queue_v4_job_id'=>$cancelJob,'queue_v4_attempt_id'=>$cancelAttempt,'queue_v4_lease_owner'=>'calls','queue_v4_lease_generation'=>1];
    B::start(100,'automatic',microtime(true)+45);$cancelId='';$cancelError=null;$before=count(W::$calls);
    App\Services\CallsWireOptions::$onFinalOptions=static function()use(&$cancelId):void {$cancelId=(string)M::current()['transport_request_id'];App\Services\CallsWireOptions::$clockOffset=46;};
    try {M::run($cancelMeta,static fn()=>(new App\Services\MeliApiClient(9011))->get('/orders/8101'));}catch(Throwable $caught){$cancelError=$caught;}
    finally {App\Services\CallsWireOptions::$onFinalOptions=null;App\Services\CallsWireOptions::$clockOffset=0;}
    calls_check($cancelError instanceof App\Services\CronDeadlineDeferredException&&count(W::$calls)===$before&&B::snapshot()['used']===0,'post_fence_deadline_certifies_zero_once');
    calls_check(B::snapshot()['physical_http_calls']===0&&B::snapshot()['physical_http_calls_certainty']==='CERTIFIED','real_cancel_certifies_physical_zero');
    $q=$pdo->prepare('SELECT COUNT(*) FROM queue_v4_clean_transport_events WHERE request_id=?');$q->execute([$cancelId]);calls_check((int)$q->fetchColumn()===0,'cancel_removes_only_own_provisional_event');
    W::$responses['/orders/8101']=[200,['id'=>8101]];W::$onWire=static function():void {throw new RuntimeException('physical_response_lost');};$lost=null;
    try {M::run($cancelMeta,static fn()=>(new App\Services\MeliApiClient(9011))->get('/orders/8101'));}catch(Throwable $caught){$lost=$caught;}
    W::$onWire=null;
    calls_check($lost instanceof App\Services\RemoteResultUncertainException&&B::snapshot()['used']===1&&B::snapshot()['stopped_reason']==='remote_result_uncertain','sent_loss_consumes_and_stops_outer');
    calls_check(B::snapshot()['physical_http_calls']===1&&B::snapshot()['physical_http_calls_certainty']==='CERTIFIED','real_sent_loss_certifies_physical_one_not_response');
    $lostId=(string)(W::$calls[array_key_last(W::$calls)]['meta']['transport_request_id']??'');
    $q=$pdo->prepare('SELECT COUNT(*) FROM api_request_logs WHERE request_id=? AND company_id=9001 AND meli_account_id=9011');$q->execute([$lostId]);calls_check((int)$q->fetchColumn()===1,'uncertain_attempt_retains_same_log_identity');
    B::clear();$pdo->exec("UPDATE api_remote_permits SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE status='dispatched'");usleep(1100000);
    $ids=[];
    foreach([200,429] as $status) {
        B::start(100,'automatic',microtime(true)+45);
        $key=bin2hex(random_bytes(20));
        $pdo->prepare("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,lease_owner,lease_generation,lease_expires_at) VALUES(9001,9011,'order_exact','8101',?,'{}','running','calls',1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 MINUTE))")->execute([$key]);$job=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO queue_v4_clean_attempts(job_id,run_id,company_id,meli_account_id,lease_owner,lease_generation) VALUES(?,9998,9001,9011,'calls',1)")->execute([$job]);$attempt=(int)$pdo->lastInsertId();
        $meta=['source'=>'queue_v4_clean','company_id'=>9001,'account_id'=>9011,'queue_v4_job_id'=>$job,'queue_v4_attempt_id'=>$attempt,'queue_v4_lease_owner'=>'calls','queue_v4_lease_generation'=>1,'transport_request_id'=>str_repeat('f',40)];
        W::$responses['/orders/8101']=[$status,['id'=>8101,'message'=>'test']];$error=null;$before=count(W::$calls);
        try {M::run($meta,static fn()=>(new App\Services\MeliApiClient(9011))->get('/orders/8101'));}catch(Throwable $caught){$error=$caught;}
        $physical=count(W::$calls)-$before;calls_check($physical===1,'real_client_enters_wire_once_'.$status.':'.($error?->getMessage()??'none'));
        if($physical===1) {
            $id=(string)W::$calls[array_key_last(W::$calls)]['meta']['transport_request_id'];$ids[]=$id;
            calls_check(preg_match('/^[a-f0-9]{40}$/D',$id)===1&&$id!==str_repeat('f',40),'fresh_server_owned40hex_'.$status);
            calls_check(in_array('X-Request-Id: '.$id,App\Services\CallsWireOptions::$headers,true),'header_same_id');
            foreach(['api_remote_permits'=>'permit_token','api_request_logs'=>'request_id','queue_v4_clean_transport_events'=>'request_id'] as $table=>$column) {
                $q=$pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column}=? AND company_id=9001 AND meli_account_id=9011");$q->execute([$id]);calls_check((int)$q->fetchColumn()===1,'identity_correlates_'.$table.'_'.$status);
            }
        }
        calls_check(B::snapshot()['used']===1,'physical_counter_consumed_'.$status);
        if($status===429)calls_check(B::snapshot()['remaining']===99&&B::snapshot()['stopped_reason']==='remote_429_global_pause','first429_stops_outer99');
        B::clear();usleep(1100000);
    }
    calls_check(count($ids)===2&&count(array_unique($ids))===2,'two_physical_attempts_two_identities');
    $pdo->exec("DELETE FROM api_rhythm_penalties");$pdo->exec("UPDATE api_rhythm_states SET next_allowed_at=NULL,block_pause_until=NULL,calls_in_block=0 WHERE scope_key='global'");
    $path='/billing/integration/group/ML/order/details';$rhythm=new R();
    $key=bin2hex(random_bytes(20));
    $pdo->prepare("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,lease_owner,lease_generation,lease_expires_at) VALUES(9001,9011,'domain_exact','8101',?,'{}','running','calls',1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 MINUTE))")->execute([$key]);$job=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO queue_v4_clean_attempts(job_id,run_id,company_id,meli_account_id,lease_owner,lease_generation) VALUES(?,9998,9001,9011,'calls',1)")->execute([$job]);$attempt=(int)$pdo->lastInsertId();
    $meta=['source'=>'queue_v4_clean_domain_exact','company_id'=>9001,'account_id'=>9011,'queue_v4_job_id'=>$job,'queue_v4_attempt_id'=>$attempt,'queue_v4_lease_owner'=>'calls','queue_v4_lease_generation'=>1];
    W::$responses[$path]=[429,['message'=>'known Billing rate limit']];B::start(100,'automatic',microtime(true)+45);
    try {M::run($meta,static fn()=>(new App\Services\MeliApiClient(9011))->get($path,['order_ids'=>'8101']));}catch(App\Services\ApiRhythmDeferredException){}
    calls_check(B::snapshot()['used']===1&&B::snapshot()['stopped_reason']==='remote_429_global_pause','known_billing429_consumes_and_stops');B::clear();
    $known429=$rhythm->billing429BackoffDiagnostic();
    calls_check(($known429['status']??'')==='OK'&&$known429['streak']===1&&strtotime((string)$known429['backoff_until'].' UTC')<=time()+1805,'known429_does_not_create_transient_partial_max_backoff:'.json_encode($known429));
    // Fault after the exact status CAS, before pause transaction: the permit
    // remains dispatched, the 429 remains known, and no longer pause is lost.
    $faultId=bin2hex(random_bytes(20));$faultOwner=bin2hex(random_bytes(16));
    $generation=(int)$pdo->query("SELECT generation FROM api_rhythm_states WHERE scope_key='global'")->fetchColumn();
    $pdo->prepare("INSERT INTO api_request_logs(company_id,meli_account_id,request_id,method,endpoint_path,http_status,reached_remote,created_at) VALUES(9001,9011,?,'GET',?,429,1,UTC_TIMESTAMP())")->execute([$faultId,$path]);
    $pdo->prepare("INSERT INTO api_remote_permits(permit_token,owner_token,generation,company_id,meli_account_id,endpoint_key,job_type,method,status,requested_interval_ms,effective_interval_ms,created_at,dispatched_at,expires_at) VALUES(?,?,?,9001,9011,'billing_orders','test','GET','dispatched',1000,1000,UTC_TIMESTAMP(),UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 MINUTE))")->execute([$faultId,$faultOwner,$generation]);
    $pdo->exec("UPDATE api_rhythm_states SET block_pause_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 24 HOUR) WHERE scope_key='global'");
    $longPause=$pdo->query("SELECT block_pause_until FROM api_rhythm_states WHERE scope_key='global'")->fetchColumn();
    $faultPdo=new CallsPauseFailurePdo('mysql:host=127.0.0.1;port=33079;dbname='.getenv('DB_NAME').';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $connection=new ReflectionProperty(App\Core\Database::class,'connection');$original=$connection->getValue();$connection->setValue(null,$faultPdo);$faultPdo->failPause=true;$faultCaught=false;
    try {(new ReflectionMethod(R::class,'completeRateLimitedKnownResult'))->invoke($rhythm,['enabled'=>true,'permit_token'=>$faultId,'owner_token'=>$faultOwner,'generation'=>$generation,'endpoint_key'=>'billing_orders'],null);}catch(RuntimeException $error){$faultCaught=$error->getMessage()==='calls_pause_transaction_unavailable';}
    finally {$connection->setValue(null,$original);}
    $q=$pdo->prepare('SELECT status,http_status FROM api_remote_permits WHERE permit_token=? AND owner_token=? AND generation=?');$q->execute([$faultId,$faultOwner,$generation]);$faultRow=$q->fetch(PDO::FETCH_ASSOC);
    calls_check($faultCaught&&$faultRow['status']==='dispatched'&&(int)$faultRow['http_status']===429,'pause_failure_retains_exact_known_dispatched_permit');
    calls_check($pdo->query("SELECT block_pause_until FROM api_rhythm_states WHERE scope_key='global'")->fetchColumn()===$longPause,'pause_failure_preserves_longer_persisted_pause');
    $faultDenied=false;try{$rhythm->reserve(9011,'GET',$path,['transport_request_id'=>bin2hex(random_bytes(20))]);}catch(App\Services\ApiRhythmDeferredException){$faultDenied=true;}
    calls_check($faultDenied,'pause_failure_cannot_authorize_another_dispatch');
    // Historical matrix is independent of the preceding real physical proof.
    $pdo->prepare('DELETE FROM api_request_logs WHERE endpoint_path=?')->execute([$path]);
    $pdo->exec("DELETE FROM api_remote_permits WHERE endpoint_key='billing_orders'");
    $pdo->exec("DELETE FROM api_rhythm_penalties");$pdo->exec("UPDATE api_rhythm_states SET next_allowed_at=NULL,block_pause_until=NULL,calls_in_block=0 WHERE scope_key='global'");
    $pdo->prepare("INSERT INTO api_request_logs(company_id,meli_account_id,request_id,method,endpoint_path,http_status,reached_remote,source_work_id,created_at) VALUES(9001,9011,?,'GET',?,429,1,'42',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR))")->execute([str_repeat('a',24),$path]);
    $pdo->prepare("INSERT INTO api_remote_permits(permit_token,owner_token,generation,work_key,company_id,meli_account_id,endpoint_key,job_type,method,status,http_status,requested_interval_ms,effective_interval_ms,created_at,dispatched_at,completed_at,expires_at) VALUES(?, ?,1,'42',9001,9011,'billing_orders','test','GET','completed',429,1000,1000,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR),UTC_TIMESTAMP())")->execute([str_repeat('b',40),str_repeat('c',32)]);
    $scope='endpoint:shared:'.hash('sha256','billing_orders');
    $pdo->prepare("INSERT INTO api_rhythm_penalties(scope_key,reduced_limit_per_minute,blocked_until,reduced_until,reason) VALUES(?,1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 24 HOUR),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 24 HOUR),'http_429')")->execute([$scope]);
    $d1=$rhythm->billing429BackoffDiagnostic();usleep(20000);$d2=$rhythm->billing429BackoffDiagnostic();
    calls_check(($d1['status']??'')==='UNKNOWN'&&($d1['unique_physical_events']??null)===null,'legacy_correlation_unknown_not_zero');
    calls_check($d1['backoff_until']===$d2['backoff_until'],'unknown_deadline_stable_on_reads');
    $until=strtotime((string)($d1['backoff_until']??'').' UTC');calls_check($until>=time()+86395,'unknown_preserves_long_persisted_pause');
    $block=new ReflectionMethod($rhythm,'billingBlock');$blocked=$block->invoke($rhythm,$pdo,'billing_orders');
    calls_check(strtotime((string)($blocked['next_safe_at']??'').' UTC')>=time()+86395,'admission_does_not_shorten_persisted_pause');
    $pdo->prepare("INSERT INTO api_request_logs(company_id,meli_account_id,request_id,method,endpoint_path,http_status,reached_remote,retry_after_seconds,created_at) VALUES(9001,9011,?,'GET',?,429,1,172800,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 13 HOUR))")->execute([str_repeat('e',24),$path]);$olderRetry=(int)$pdo->lastInsertId();
    $retryState=$rhythm->billing429BackoffDiagnostic();$retryDeadline=strtotime((string)$retryState['backoff_until'].' UTC');
    calls_check(abs($retryDeadline-(time()+35*3600))<=5,'retry_after_uses_each_event_absolute_deadline_not_latest_event_shift');
    $pdo->prepare('DELETE FROM api_request_logs WHERE id=? AND company_id=9001 AND meli_account_id=9011')->execute([$olderRetry]);
    $pdo->prepare("UPDATE api_request_logs SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 13 HOUR) WHERE endpoint_path=?")->execute([$path]);
    $pdo->exec("UPDATE api_remote_permits SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 13 HOUR),dispatched_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 13 HOUR),completed_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 13 HOUR) WHERE endpoint_key='billing_orders'");
    $pdo->prepare("UPDATE api_rhythm_penalties SET blocked_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR),reduced_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE scope_key=?")->execute([$scope]);
    $expired=$rhythm->billing429BackoffDiagnostic();calls_check(($expired['status']??'')==='UNKNOWN'&&!$expired['backoff_active'],'expiry_resumes_without_rewriting_unknown_history');
    calls_check($block->invoke($rhythm,$pdo,'billing_orders')===null,'expired_unknown_does_not_block_forever');
    calls_check(($expired['increase_evidence_status']??'')==='UNKNOWN','expired_unknown_still_blocks_increase_without_evidence');
    $pdo->prepare("UPDATE api_request_logs SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 73 HOUR) WHERE endpoint_path=?")->execute([$path]);
    $pdo->exec("UPDATE api_remote_permits SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 73 HOUR),dispatched_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 73 HOUR),completed_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 73 HOUR) WHERE endpoint_key='billing_orders'");
    $aged=$rhythm->billing429BackoffDiagnostic();
    calls_check(($aged['status']??'')==='UNKNOWN'&&($aged['increase_evidence_status']??'')==='UNKNOWN'&&!$aged['backoff_active'],'aged73h_history_remains_unknown_without_new_evidence');
    for($i=1;$i<=60;$i++) {
        $identity=bin2hex(random_bytes(20));
        $pdo->prepare("INSERT INTO api_request_logs(company_id,meli_account_id,request_id,method,endpoint_path,http_status,reached_remote,created_at) VALUES(9001,9011,?,'GET',?,200,1,UTC_TIMESTAMP())")->execute([$identity,$path]);
        $pdo->prepare("INSERT INTO api_remote_permits(permit_token,owner_token,generation,company_id,meli_account_id,endpoint_key,job_type,method,status,http_status,requested_interval_ms,effective_interval_ms,created_at,dispatched_at,completed_at,expires_at) VALUES(?,?,1,9001,9011,'billing_orders','test','GET','completed',200,1000,1000,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$identity,str_repeat('d',32)]);
        if($i===59)calls_check(($rhythm->billing429BackoffDiagnostic()['increase_evidence_status']??'')==='UNKNOWN','insufficient_new_correlated_successes_still_block_increase');
    }
    $new=$rhythm->billing429BackoffDiagnostic();
    calls_check(($new['status']??'')==='UNKNOWN'&&($new['increase_evidence_status']??'')==='OK'&&($new['known_successes_after_last_unknown']??0)===60,'configured_known_evidence_reopens_increase_without_rewriting_history');
    $pdo->exec('RENAME TABLE api_request_logs TO calls_hidden_logs');
    $unavailable=$rhythm->billing429BackoffDiagnostic();calls_check(($unavailable['status']??'')==='ERROR'&&($unavailable['unique_physical_events']??null)===null,'authority_failure_not_zero');
    $denied=false;try{$rhythm->reserve(9011,'GET',$path,['transport_request_id'=>bin2hex(random_bytes(20))]);}catch(Throwable){$denied=true;}
    calls_check($denied,'authority_failure_denies_reservation');$pdo->exec('RENAME TABLE calls_hidden_logs TO api_request_logs');
    k1b_assert($fail===[],'calls_transport_mysql_failures:'.implode(',',$fail));echo "CALLS_TRANSPORT_MYSQL_OK\n";
} finally {B::clear();$h->cleanup();}
