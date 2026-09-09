<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
use App\QueueV4Clean\QueueV4CleanTransportContext as C;
use App\Services\ApiExecutionMetadataContext as M;

// Regression: removing the private readiness guard must prevent physical send.
$checks=0;
k1b_assert(method_exists(C::class,'assertBeforeTransport'),'readiness_physical_guard_missing');
M::run(['source'=>'queue_v4_clean_readiness','company_id'=>9001,'account_id'=>9011],static function()use(&$checks):void {
    C::runReadiness(9001,9011,static function():void {
        $denied=false;
        try { C::assertBeforeTransport('GET','/users/me',9011); }
        catch(RuntimeException) { $denied=true; }
        k1b_assert($denied,'unguarded_context_cannot_send');
    });
    C::runReadiness(9001,9011,static function():void {
        C::assertBeforeTransport('GET','/users/me',9011);
        foreach([['GET','/users/me',9012],['GET','/orders/1',9011],['POST','/users/me',9011]] as $case) {
            $denied=false;try {C::assertBeforeTransport(...$case);}catch(RuntimeException){$denied=true;}
            k1b_assert($denied,'wrong_scope_cannot_send');
        }
        M::run(['source'=>'queue_v4_clean_readiness','company_id'=>9002,'account_id'=>9011],static function():void {
            $denied=false;try{C::assertBeforeTransport('GET','/users/me',9011);}catch(RuntimeException){$denied=true;}
            k1b_assert($denied,'cross_company_metadata_cannot_send');
        });
    },static function()use(&$checks):void {$checks++;});
});
k1b_assert($checks===1&&!C::readinessActive(),'guard_once_and_context_cleared');
k1b_assert(method_exists(C::class,'captureReadinessTransport'),'readiness_pretransport_evidence_missing');
M::run(['source'=>'queue_v4_clean_readiness','company_id'=>9001,'account_id'=>9011,'transport_request_id'=>str_repeat('a',40),'transport_meli_account_id'=>9011],static function():void {
    C::runReadiness(9001,9011,static function():void {
        try {C::captureReadinessTransport(static function():void {throw new RuntimeException('local_preparation_failed');});}catch(RuntimeException){}
        k1b_assert(!C::consumePreTransportCancellation(str_repeat('b',40),9001,9011),'wrong_identity_has_no_refund');
        k1b_assert(!C::consumePreTransportCancellation(str_repeat('a',40),9002,9011),'wrong_tenant_has_no_refund');
        k1b_assert(C::consumePreTransportCancellation(str_repeat('a',40),9001,9011),'exact_pretransport_evidence');
        k1b_assert(!C::consumePreTransportCancellation(str_repeat('a',40),9001,9011),'evidence_consumed_once');
    });
    C::runReadiness(9001,9011,static function():void {
        try {C::captureReadinessTransport(static function():void {C::readinessEnteringTransport();throw new RuntimeException('wire_lost');});}catch(RuntimeException){}
        k1b_assert(!C::consumePreTransportCancellation(str_repeat('a',40),9001,9011),'sent_failure_never_refunds');
    });
    C::runReadiness(9001,9011,static function():void {
        try {C::captureReadinessTransport(static function():void {throw new App\Services\RemoteResultUncertainException(str_repeat('a',40));});}catch(RuntimeException){}
        k1b_assert(!C::consumePreTransportCancellation(str_repeat('a',40),9001,9011),'ambiguous_ack_never_refunds');
    });
});
echo "CALLS_READINESS_CONTEXT_OK\n";
if (!in_array('--mysql',$argv,true)) {return;}
require __DIR__.'/K1dSafeTestDatabase.php';
require __DIR__.'/calls_transport_wire_fixture.php';
use App\QueueV4Clean\QueueV4CleanCycleBudget as B;
use App\Services\Cap2DomainsWire as W;
use App\Services\ApiRhythmPolicyService as R;
$root='D:/Codex/tmp/erp-meli/calls-20260906/readiness/transport';
foreach(['APP_ENV'=>'test','ML_WRITE_ENABLED'=>'false','DB_HOST'=>'127.0.0.1','DB_PORT'=>'33079','DB_USER'=>'root','DB_PASS'=>'','DB_NAME'=>'erp_meli_k1d_test_readiness_transport_'.bin2hex(random_bytes(4)),'APP_KEY'=>'calls-disposable-test-only','PRIVATE_STORAGE_PATH'=>$root.'/private','MELI_API_BASE'=>'https://calls-wire.invalid'] as $k=>$v)putenv($k.'='.$v);
define('ERP_INSTALLATION_ROOT',$root.'/install-'.bin2hex(random_bytes(4)));mkdir(ERP_INSTALLATION_ROOT,0777,true);
$h=K1dSafeTestDatabase::createFromEnvironment();
// Fault injection keeps real PDO/MySQL mutations, losing only their reply.
final class ReadinessRefundAckPdo extends PDO {
    public function commit():bool {parent::commit();throw new RuntimeException('refund_commit_ack_lost');}
}
try {
    $pdo=$h->pdo();(new App\Services\Migrator($pdo,__DIR__.'/../database/migrations'))->run(301);
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9001,'Readiness transport',1)");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9011,9001,'Readiness transport',99011,'conectado')");
    $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at) VALUES(9011,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))')->execute([App\Core\Crypto::encrypt('test-access'),App\Core\Crypto::encrypt('test-refresh')]);
    $pdo->exec("UPDATE queue_engine_control SET active_engine='v4'");
    $pdo->exec("UPDATE app_settings SET setting_value='0' WHERE setting_key='alerts.email.enabled'");
    W::$responses['/users/me']=[200,['id'=>99011]];
    $call=static fn(?callable $guard)=>M::run(['source'=>'queue_v4_clean_readiness','company_id'=>9001,'account_id'=>9011],static fn()=>M::withTechnicalOperation('readiness',static fn()=>C::runReadiness(9001,9011,static fn()=>(new App\Services\MeliApiClient(9011))->get('/users/me'),$guard)));
    B::start(1,'manual',microtime(true)+45);$error=null;
    try {$call(static function():void {throw new RuntimeException('readiness_acl_revoked');});}catch(Throwable $caught){$error=$caught;}
    k1b_assert($error?->getMessage()==='readiness_acl_revoked'&&W::$calls===[]&&B::snapshot()['used']===0,'guard_cancellation_must_certify_zero_and_preserve_local_reason:'.($error?->getMessage()??'none'));
    k1b_assert(B::snapshot()['physical_http_calls']===0&&B::snapshot()['physical_http_calls_certainty']==='CERTIFIED','cancel_certified_zero');
    $row=$pdo->query("SELECT status,blocking_scope FROM api_remote_permits ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    k1b_assert($row['status']==='released'&&$row['blocking_scope']==='cancelled_before_transport','readiness_refunds_exact_dispatched_permit');
    k1b_assert((int)$pdo->query("SELECT calls_in_block FROM api_rhythm_states WHERE scope_key='global'")->fetchColumn()===0,'readiness_refunds_global_counter_once');
    B::clear();
    $fail=[];
    foreach(['no_guard','slow_guard','wrong_generation','wrong_owner','wrong_global_generation','missing_permit','lost_budget_window','commit_ack_lost','wire_lost','redirect','server_error','rate_limit','unauthorized','forbidden','app_blocked'] as $case) {
        // Independent initial fixture state; no guard or cap is bypassed during an attempt.
        $pdo->exec("DELETE FROM api_remote_permits");
        $pdo->exec("DELETE FROM api_rhythm_penalties");
        $pdo->exec("DELETE FROM api_budget_windows");
        $pdo->exec('DELETE FROM api_circuit_breakers');$pdo->exec('DELETE FROM api_request_logs');
        $pdo->exec("UPDATE api_rhythm_states SET next_allowed_at=NULL,block_pause_until=NULL,calls_in_block=0 WHERE scope_key='global'");
        $before=count(W::$calls);$error=null;$id='';
        $connection=new ReflectionProperty(App\Core\Database::class,'connection');$originalConnection=$connection->getValue();
        W::$responses['/users/me']=[match($case){'redirect'=>302,'server_error'=>500,'rate_limit'=>429,'unauthorized'=>401,'forbidden','app_blocked'=>403,default=>200},['id'=>99011,'message'=>($case==='app_blocked'?'application blocked':'fixture error').' ?access_token=READINESS_TEST_SENTINEL','access_token'=>'READINESS_TEST_SENTINEL']];
        W::$onWire=$case==='wire_lost'?static function():void {throw new RuntimeException('wire_lost');}:null;
        B::start(1,'manual',microtime(true)+45);
        $guard=$case==='no_guard'?null:static function()use($case,$pdo,&$id,$connection):void {
            $id=(string)M::current()['transport_request_id'];
            if($case==='slow_guard'){App\Services\CallsWireOptions::$clockOffset=46;return;}
            if($case==='wrong_generation'){$pdo->prepare('UPDATE api_remote_permits SET generation=generation+1 WHERE permit_token=?')->execute([$id]);}
            if($case==='wrong_owner'){$pdo->prepare('UPDATE api_remote_permits SET owner_token=? WHERE permit_token=?')->execute([bin2hex(random_bytes(16)),$id]);}
            if($case==='wrong_global_generation'){$pdo->exec("UPDATE api_rhythm_states SET generation=generation+1 WHERE scope_key='global'");}
            if($case==='missing_permit'){$pdo->prepare('DELETE FROM api_remote_permits WHERE permit_token=?')->execute([$id]);}
            if($case==='lost_budget_window'){$pdo->exec('DELETE FROM api_budget_windows');}
            if($case==='commit_ack_lost') {
                $pdo->exec('UPDATE api_budget_windows SET request_count=request_count+3');
                $connection->setValue(null,new ReadinessRefundAckPdo('mysql:host=127.0.0.1;port=33079;dbname='.getenv('DB_NAME').';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]));
            }
            if(in_array($case,['wrong_generation','wrong_owner','wrong_global_generation','missing_permit','lost_budget_window','commit_ack_lost'],true)){throw new RuntimeException('local_cancel');}
        };
        try{$call($guard);}catch(Throwable $caught){$error=$caught;}
        finally{App\Services\CallsWireOptions::$clockOffset=0;W::$onWire=null;$connection->setValue(null,$originalConnection);}
        $physical=count(W::$calls)-$before;$snapshot=B::snapshot();
        $sent=in_array($case,['wire_lost','redirect','server_error','rate_limit','unauthorized','forbidden','app_blocked'],true);
        if($physical!==($sent?1:0)||$snapshot['used']!==($sent?1:0)){$fail[]=$case.'_physical_count';}
        if(in_array($case,['wrong_generation','wrong_owner','wrong_global_generation','missing_permit','lost_budget_window','commit_ack_lost','wire_lost'],true)
            && (!$error instanceof App\Services\RemoteResultUncertainException||$snapshot['stopped_reason']!=='remote_result_uncertain')){$fail[]=$case.'_must_be_unknown_no_retry:'.($error?->getMessage()??'none');}
        if($case==='slow_guard'&&!$error instanceof App\Services\CronDeadlineDeferredException){$fail[]='guard_time_is_included';}
        if($case==='rate_limit'&&$snapshot['stopped_reason']!=='remote_429_global_pause'){$fail[]='first429_stops';}
        if(in_array($case,['redirect','server_error'],true)&&!$error instanceof App\Services\MeliApiException){$fail[]=$case.'_known_http_error';}
        if($case==='commit_ack_lost'&&(int)$pdo->query('SELECT MIN(request_count) FROM api_budget_windows')->fetchColumn()!==3){$fail[]='lost_ack_must_not_refund_budget_twice';}
        if(in_array($case,['unauthorized','forbidden','app_blocked'],true)) {
            if(!$error instanceof App\Services\MeliApiException){$fail[]=$case.'_known_http_error';}
            $serialized=json_encode($pdo->query('SELECT safe_message,error_type,is_app_blocked_signal FROM api_request_logs')->fetchAll(PDO::FETCH_ASSOC)).($error?->getMessage()??'').json_encode($error instanceof App\Services\MeliApiException?$error->response:[]);
            if(str_contains($serialized,'READINESS_TEST_SENTINEL')){$fail[]=$case.'_token_leak';}
            if($case==='app_blocked'&&(int)$pdo->query("SELECT COUNT(*) FROM api_circuit_breakers WHERE scope='app' AND reason='app_blocked' AND status='open'")->fetchColumn()!==1){$fail[]='app_blocked_opens_global_guard';}
            if($case==='app_blocked') {
                $blockedAgain=null;$beforeAgain=count(W::$calls);
                try{$call(static function():void {});}catch(Throwable $caught){$blockedAgain=$caught;}
                if($blockedAgain===null||count(W::$calls)!==$beforeAgain){$fail[]='app_blocked_prevents_continuation';}
            }
        }
        echo 'READINESS_CASE='.json_encode(['case'=>$case,'physical'=>$physical,'used'=>$snapshot['used'],'stop'=>$snapshot['stopped_reason'],'error'=>$error===null?null:$error::class])."\n";
        B::clear();
    }
    k1b_assert($fail===[],'readiness_transport_failures:'.implode(',',$fail));
    echo "CALLS_READINESS_TRANSPORT_MYSQL_OK\n";
} finally {B::clear();$h->cleanup();}
