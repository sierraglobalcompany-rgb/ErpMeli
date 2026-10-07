<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
require __DIR__.'/K1dSafeTestDatabase.php';
require __DIR__.'/calls_transport_wire_fixture.php';

use App\Core\Crypto;
use App\Services\Migrator;
use App\QueueV4Clean\OuterCronHttpReceipt;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\AppSettingsService;
use App\Services\Cap2DomainsWire;

foreach (['APP_ENV'=>'test','ML_WRITE_ENABLED'=>'false','DB_HOST'=>'127.0.0.1','DB_PORT'=>getenv('DB_PORT')?:'33338',
    'DB_USER'=>'root','DB_PASS'=>getenv('DB_PASS')?:'local-http-receipt-test-only','DB_NAME'=>'erp_meli_k1d_test_outer_wire_'.bin2hex(random_bytes(5)),
    'APP_KEY'=>'synthetic-http-receipt-only','MELI_API_BASE'=>'https://no-network.invalid',
    'CALLS_VERIFY_QA_ROOT'=>dirname(__DIR__).'/storage/codex-http-phase1'] as $key=>$value) { putenv($key.'='.$value); }
$h=K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo=$h->pdo();
    (new Migrator($pdo,dirname(__DIR__).'/database/migrations'))->run(303);
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9001,'HTTP receipt fixture',1)");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9011,9001,'Synthetic',99011,'conectado')");
    $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at) VALUES(9011,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))')->execute([Crypto::encrypt('synthetic-access'),Crypto::encrypt('synthetic-refresh')]);
    $pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE',readiness_state='CERTIFIED' WHERE control_key='primary'");
    $pdo->exec("UPDATE queue_engine_control SET active_engine='v4'");
    $settings=new AppSettingsService();
    foreach (['api.rhythm.profile'=>'maximum','api.rhythm.target_http_per_minute'=>'40','api.rhythm.pause_ms'=>'0','api.rhythm.minimum_interval_ms'=>'1000','api.rhythm.current_adaptive_limit'=>'40','alerts.email.enabled'=>'0','api.guard.jitter_min_ms'=>'0','api.guard.jitter_max_ms'=>'0'] as $key=>$value) { $settings->set($key,$value,'test'); }
    AppSettingsService::clearCache();
    for ($i=1;$i<=3;$i++) {
        $id=99000+$i;
        Cap2DomainsWire::$responses['/orders/'.$id]=[200,['id'=>$id,'status'=>'paid','total_amount'=>100,'currency_id'=>'COP','order_items'=>[],'payments'=>[]]];
        $pdo->prepare("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at) VALUES(9001,9011,'order_exact',?,?,'{}','ready','2000-01-01')")->execute([(string)$id,'receipt-order-'.$id]);
    }
    $result=OuterCronHttpReceipt::within($pdo,['max_calls'=>10,'ceiling'=>55,'max_calls_source'=>'TEST'],static function () use ($pdo): array {
        QueueV4CleanCycleBudget::start(10,'automatic',microtime(true)+45);
        try {
            $worker=(new QueueV4CleanWorker($pdo,new QueueV4CleanRepository($pdo)))->run('test',10,40);
            return ['status'=>'completed','worker'=>$worker];
        } finally { QueueV4CleanCycleBudget::clear(); }
    });
    k1b_assert(count(Cap2DomainsWire::$calls)===3,'fixture must physically simulate exactly 3 HTTP: '.json_encode($result));
    $receipt=OuterCronHttpReceipt::read($pdo,$result['cron_cycle_id']);
    k1b_assert($receipt['physical_http_total']===3,'RED: real worker transport not captured: '.json_encode($receipt));
    echo "REAL_WORKER_THREE_HTTP=PASS\nREAL_MELI_HTTP=0\nREAL_OAUTH=0\n";
    $direct=static function (int $resource,int $status=200) use ($pdo): array {
        $owner='receipt-'.bin2hex(random_bytes(4));
        $pdo->prepare("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,lease_owner,lease_generation,lease_expires_at,available_at) VALUES(9001,9011,'order_exact',?,?,'{}','running',?,1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 MINUTE),'2000-01-01')")->execute([(string)$resource,$owner,$owner]);
        $job=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO queue_v4_clean_attempts(job_id,run_id,company_id,meli_account_id,lease_owner,lease_generation) VALUES(?,9998,9001,9011,?,1)')->execute([$job,$owner]);
        $attempt=(int)$pdo->lastInsertId();
        Cap2DomainsWire::$responses['/orders/'.$resource]=[$status,['id'=>$resource,'message'=>'synthetic']];
        return App\Services\ApiExecutionMetadataContext::run(['source'=>'queue_v4_clean','company_id'=>9001,'account_id'=>9011,'queue_v4_job_id'=>$job,'queue_v4_attempt_id'=>$attempt,'queue_v4_lease_owner'=>$owner,'queue_v4_lease_generation'=>1],static fn (): array => (new App\Services\MeliApiClient(9011))->get('/orders/'.$resource));
    };
    $reset=static function () use ($pdo): void {
        $pdo->exec('DELETE FROM api_remote_permits');
        $pdo->exec('DELETE FROM api_rhythm_penalties');
        $pdo->exec("UPDATE api_rhythm_states SET next_allowed_at=NULL,block_pause_until=NULL,calls_in_block=0 WHERE scope_key='global'");
        Cap2DomainsWire::$calls=[];
        App\Services\CallsWireOptions::$onFinalOptions=null;
        QueueV4CleanCycleBudget::clear();
        App\Services\CronDeadlineContext::clear();
    };
    $wrap=static function (callable $callback) use ($pdo): array {
        $result=OuterCronHttpReceipt::within($pdo,['max_calls'=>10,'ceiling'=>55],static function () use ($callback): array {
            QueueV4CleanCycleBudget::start(10,'automatic',microtime(true)+45);
            try { return $callback(); } finally { QueueV4CleanCycleBudget::clear(); App\Services\CronDeadlineContext::clear(); }
        });
        return OuterCronHttpReceipt::read($pdo,$result['cron_cycle_id']);
    };
    $reset();
    $receipt=$wrap(static function () use ($direct): array {
        for ($i=1;$i<=10;$i++) { usleep(1100000); $direct(100000+$i); }
        usleep(1100000);
        try { $direct(100011); throw new RuntimeException('eleventh HTTP allowed'); }
        catch (App\Services\ApiBudgetExhaustedException) { return ['status'=>'budget_exhausted']; }
    });
    k1b_assert(count(Cap2DomainsWire::$calls)===10 && $receipt['physical_http_total']===10 && $receipt['budget_exhausted_before_transport']===1,'real transport eleventh blocked: '.json_encode($receipt));
    echo "REAL_TRANSPORT_11TH_BLOCKED=PASS\n";
    $reset();
    $pdo->exec("UPDATE api_rhythm_states SET next_allowed_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR) WHERE scope_key='global'");
    $receipt=$wrap(static function () use ($direct): array {
        try { $direct(100019); throw new RuntimeException('rhythm block allowed'); }
        catch (App\Services\ApiRhythmDeferredException) { return ['status'=>'completed']; }
    });
    k1b_assert(count(Cap2DomainsWire::$calls)===0 && $receipt['physical_http_total']===0 && $receipt['physical_http_unknown']===0 && $receipt['blocked_before_transport']===1,'RED real preflight rhythm zero transport');
    echo "REAL_PREFLIGHT_RHYTHM_ZERO_HTTP=PASS\n";
    $reset();
    App\Services\CallsWireOptions::$onFinalOptions=static function (): void { App\Services\CronDeadlineContext::start(1,1,1,1); usleep(1100000); };
    $receipt=$wrap(static function () use ($direct): array {
        try { $direct(100020); throw new RuntimeException('expired deadline allowed'); }
        catch (App\Services\CronDeadlineDeferredException) { return ['status'=>'completed']; }
    });
    k1b_assert(count(Cap2DomainsWire::$calls)===0 && $receipt['physical_http_total']===0 && $receipt['released_before_transport']===1,'real deadline cancellation zero wire');
    echo "REAL_TRANSPORT_CANCELLED_RESERVE=PASS\n";
    $reset();
    $settings->set('api.rhythm.billing_min_interval_seconds','1','test');
    $settings->set('sales_financial.commercial_pipeline_enabled','1','test');
    AppSettingsService::clearCache();
    $receipt=$wrap(static function () use ($pdo,$direct): array {
        $pdo->exec("INSERT INTO queue_v4_clean_leases(lease_key,owner_ref,expires_at) VALUES('scheduler','outer-http',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)) ON DUPLICATE KEY UPDATE owner_ref='outer-http',expires_at=VALUES(expires_at)");
        $pdo->exec("UPDATE meli_tokens SET refresh_version=1 WHERE meli_account_id=9011");
        $pdo->exec("INSERT INTO oauth_refresh_operations(company_id,meli_account_id,expected_meli_user_id,expected_refresh_version,state,lease_owner,lease_generation,lease_expires_at) VALUES(9001,9011,'99011',1,'RUNNING','outer-http',1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))");
        $oauthId=(int)$pdo->lastInsertId();
        Cap2DomainsWire::$responses['/oauth/token']=[200,['access_token'=>'synthetic-rotated','refresh_token'=>'synthetic-refresh','expires_in'=>21600,'token_type'=>'Bearer']];
        App\Services\ApiExecutionMetadataContext::run(['company_id'=>9001,'account_id'=>9011,'source'=>'queue_v4_clean_oauth','oauth_operation_id'=>$oauthId,'expected_refresh_version'=>1,'oauth_lease_owner'=>'outer-http','oauth_lease_generation'=>1,'scheduler_lease_owner'=>'outer-http'],
            static fn (): array => (new App\Services\MeliApiClient(9011))->exchangeOAuthToken(['grant_type'=>'refresh_token','refresh_token'=>'synthetic-refresh']));
        for ($i=1;$i<=5;$i++) { usleep(1100000); $direct(110000+$i); }
        foreach ([120001,120002] as $orderId) {
            $pdo->prepare("INSERT INTO meli_orders(meli_account_id,external_order_id,status,total_amount,paid_amount,currency_id,synced_at) VALUES(9011,?,'paid',100,100,'COP',UTC_TIMESTAMP())")->execute([(string)$orderId]);
            $state=(new App\Services\SaleFinancialStateService())->projectSale(9001,9011,'O:'.$orderId);
            $pdo->prepare("INSERT INTO sale_financial_reconciliation_jobs(company_id,meli_account_id,sale_key,external_sale_id,input_version,status,next_run_at) VALUES(9001,9011,?,?,?,'pending','2000-01-01')")->execute(['O:'.$orderId,(string)$orderId,$state['input_version']]);
            $source=(int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at) VALUES(9001,9011,'domain_exact',?,?,?,'ready','2000-01-01')")->execute([(string)$source,'outer-billing-'.$source,json_encode(['capability'=>'financial_reconciliation','source_id'=>$source],JSON_THROW_ON_ERROR)]);
            Cap2DomainsWire::$responses['/billing/integration/group/ML/order/details']=[200,[]];
            // Let the configured local interval expire; do not clear a live permit or bypass pacing.
            usleep(2200000);
            $stage=(new QueueV4CleanWorker($pdo,new QueueV4CleanRepository($pdo)))->run('test',QueueV4CleanCycleBudget::remaining(),10);
            echo 'SHARED_WORKER='.json_encode(['stop_reason'=>$stage['stop_reason']??null,'claimed'=>$stage['claimed']??null,'deferred'=>$stage['deferred']??null,'sources'=>$pdo->query('SELECT status,safe_message,next_run_at FROM sale_financial_reconciliation_jobs WHERE company_id=9001 AND meli_account_id=9011')->fetchAll(PDO::FETCH_ASSOC),'blocks'=>$pdo->query("SELECT error_code,safe_message FROM api_error_logs WHERE meli_account_id=9011 AND endpoint_path='/billing/integration/group/ML/order/details'")->fetchAll(PDO::FETCH_ASSOC)],JSON_THROW_ON_ERROR)."\n";
        }
        return ['status'=>'completed'];
    });
    if ($receipt['physical_http_total']!==8) {
        echo 'SHARED_DIAGNOSTIC='.json_encode(['wire_count'=>count(Cap2DomainsWire::$calls),'receipt'=>$receipt],JSON_THROW_ON_ERROR)."\n";
    }
    k1b_assert(count(Cap2DomainsWire::$calls)===8 && $receipt['physical_http_total']===8 && $receipt['http_oauth']===1 && $receipt['http_orders_exact']===5 && $receipt['http_billing']===2,'real shared OAuth/order/Financial total: '.json_encode($receipt));
    echo "REAL_SHARED_OAUTH_ORDERS_BILLING_8=PASS\n";
    $reset();
    $pdo->exec("CREATE TRIGGER receipt_result_fault BEFORE UPDATE ON system_execution_runs FOR EACH ROW BEGIN IF JSON_EXTRACT(NEW.http_receipt_json,'$.physical_http_known')>JSON_EXTRACT(OLD.http_receipt_json,'$.physical_http_known') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic_receipt_result_failure'; END IF; END");
    $receipt=$wrap(static function () use ($direct): array {
        $response=$direct(130001);
        k1b_assert(($response['id']??null)===130001,'known client response survives telemetry failure');
        return ['status'=>'completed'];
    });
    k1b_assert(count(Cap2DomainsWire::$calls)===1 && $receipt['terminal_status']==='incomplete' && $receipt['physical_http_total']===null && $receipt['physical_http_unknown']===1,'real client persistence fault remains unknown');
    $pdo->exec('DROP TRIGGER receipt_result_fault');
    echo "REAL_CLIENT_RESULT_PERSISTENCE_FAILURE=PASS\n";
    foreach ([429,503] as $status) {
        $reset();
        $receipt=$wrap(static function () use ($direct,$status): array {
            try { $direct(100000+$status,$status); }
            catch (App\Services\ApiRhythmDeferredException $error) { if ($status!==429) { throw $error; } }
            catch (RuntimeException $error) { if ($status!==503) { throw $error; } }
            return ['status'=>$status===429?'remote_429_global_pause':'completed'];
        });
        k1b_assert(count(Cap2DomainsWire::$calls)===1 && $receipt['physical_http_total']===1,'real known response exactly one');
        k1b_assert($receipt[$status===429?'http_429':'http_5xx']===1,'real response class');
        echo 'REAL_TRANSPORT_'.$status."=PASS\n";
    }
} finally { $h->cleanup(); }
