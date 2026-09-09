<?php
declare(strict_types=1);
require __DIR__.'/calls_readiness_fixture.php';

$h=calls_readiness_database(); $pdo=App\Core\Database::connection();
try {
    $service=new App\QueueV4Clean\QueueV4CleanReadinessService($pdo);
    k1b_assert(method_exists($service,'prepare'),'Missing zero-HTTP prepare operation');
    $prepareStarted=time();$run=$service->prepare(9007);
    k1b_assert($run['state']==='TESTING' && $run['next_step']===1,'Prepare must create pending step 1');
    k1b_assert(preg_match('/^[a-f0-9]{64}$/D',$run['run_token'])===1,'One opaque run token required');
    k1b_assert($run['expires_at']>=$prepareStarted+600 && $run['expires_at']<=time()+600,'Absolute TTL must be 600 seconds');
    k1b_assert(count(App\Services\Cap2DomainsWire::$calls)===0,'Prepare must make zero HTTP calls');
    k1b_assert(!str_contains(json_encode($run),'fingerprint') && !str_contains(json_encode($run),'encrypted'),'Private proofs must not leak');
    k1b_assert((int)$pdo->query('SELECT COUNT(*) FROM queue_v4_clean_readiness_accounts')->fetchColumn()===0,'Prepare must not preclaim steps');
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9014,9001,'Disconnected scope fixture',99014,'desconectado')");
    $pdo->exec('INSERT INTO user_meli_account_access(user_id,meli_account_id) VALUES(9007,9011),(9007,9012),(9007,9013)');
    $scoped=$service->snapshot();
    k1b_assert($scoped['active_run_id']===null,'Orphan cancellation hint must require current disconnected-account scope');
    k1b_assert($scoped['run_id']===$run['run_id'] && $scoped['run_token']===$run['run_token'],'Own authorized manifest is independent of orphan cancellation hint');
    $pdo->exec('DELETE FROM user_meli_account_access WHERE user_id=9007');
    $pdo->exec('DELETE FROM meli_accounts WHERE id=9014 AND company_id=9001');
    k1b_assert(cap2_manual_rejected(fn()=>calls_readiness_check($service,$run,2)),'Future step before PASS must reject');
    k1b_assert(cap2_manual_rejected(fn()=>$service->check(9007,$run['run_id'],str_repeat('0',64),1)),'Forged token must reject');
    App\QueueV4Clean\QueueV4CleanCycleBudget::start(3,'manual');
    try {k1b_assert(cap2_manual_rejected(fn()=>calls_readiness_check($service,$run,1)),'Nested budget must reject');}
    finally {App\QueueV4Clean\QueueV4CleanCycleBudget::clear();}
    $observer=cap2_manual_connection();
    k1b_assert((int)$observer->query("SELECT GET_LOCK('erp_meli_queue_v4_clean_readiness',0)")->fetchColumn()===1,'Other PDO must own readiness lock');
    try {k1b_assert(cap2_manual_rejected(fn()=>calls_readiness_check($service,$run,1)),'Busy readiness lock must reject without HTTP');}
    finally {$observer->query("SELECT RELEASE_LOCK('erp_meli_queue_v4_clean_readiness')");}
    k1b_assert((int)$pdo->query('SELECT COUNT(*) FROM queue_v4_clean_readiness_accounts')->fetchColumn()===0,'Busy lock must not consume claim');
    App\Services\Cap2DomainsWire::$onWire=static function()use($observer,$run):void {
        k1b_assert(session_status()!==PHP_SESSION_ACTIVE,'HTTP must release session lock');
        $s=$observer->prepare('SELECT outcome,failure_class FROM queue_v4_clean_readiness_accounts WHERE readiness_run_id=?');$s->execute([$run['run_id']]);
        $claims=$s->fetchAll();k1b_assert(count($claims)===1 && $claims[0]['outcome']==='FAIL' && $claims[0]['failure_class']===null,'FAIL/NULL claim must COMMIT before physical wire');
    };
    $first=calls_readiness_check($service,$run,1);
    App\Services\Cap2DomainsWire::$onWire=null;
    k1b_assert($first['ok']===true && $first['state']==='TESTING' && $first['next_step']===2,'First PASS keeps readiness pending');
    k1b_assert($first['physical_http_calls']===1 && $first['physical_http_calls_certainty']==='CERTIFIED','First receipt must certify actual one call');
    $replay=calls_readiness_check($service,$run,1);
    k1b_assert($replay['physical_http_calls']===0 && count(App\Services\Cap2DomainsWire::$calls)===1,'PASS replay must not send again');
    k1b_assert($replay['expires_at']===$run['expires_at'],'Replay must not extend TTL');
    calls_readiness_check($service,$run,2);$last=calls_readiness_check($service,$run,3);
    k1b_assert($last['state']==='CERTIFIED' && $last['next_step']===null && count(App\Services\Cap2DomainsWire::$calls)===3,'Exactly three explicit calls must certify');
    $control=new App\QueueV4Clean\QueueV4CleanControlService($pdo);
    k1b_assert(cap2_manual_rejected(fn()=>$control->activate(9007,$run['run_id'],str_repeat('0',64))),'Activation requires exact token, not historical MAX certificate');
    $active=$control->activate(9007,$run['run_id'],$run['run_token']);
    k1b_assert($active['state']==='ACTIVE' && (new App\Services\EmergencyControlService())->automationStopped(),'Activation must keep automation stopped');
    k1b_assert($pdo->query('SELECT last_scheduler_at FROM queue_v4_clean_control')->fetchColumn()===null,'Readiness must not invent heartbeat');
    k1b_assert((string)$pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='manual.api_calls_per_step'")->fetchColumn()==='1','Readiness never increases capacity');
    echo "PASS readiness prepare/claims/steps/replay/activation real MariaDB and physical boundary\n";
} finally {App\Services\Cap2DomainsWire::$onWire=null; $h->cleanup();}
