<?php
declare(strict_types=1);
require __DIR__.'/calls_readiness_fixture.php';

$h=calls_readiness_database();$pdo=App\Core\Database::connection();
try {
    $missing=[];
    $service=new App\QueueV4Clean\QueueV4CleanReadinessService($pdo);
    $control=new App\QueueV4Clean\QueueV4CleanControlService($pdo);
    $before=(int)$pdo->query('SELECT COUNT(*) FROM queue_v4_clean_readiness_runs')->fetchColumn();
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9014,9001,'Missing token fourth account',99014,'conectado')");
    k1b_assert(cap2_manual_rejected(fn()=>$service->prepare(9007)),'Fourth connected account without token must not be hidden by INNER JOIN');
    $pdo->exec('DELETE FROM meli_accounts WHERE id=9014 AND company_id=9001');
    foreach(['status=0','is_temporary=1',"role='operador'"] as $change) {
        $pdo->exec('UPDATE users SET '.$change.' WHERE id=9007');
        k1b_assert(cap2_manual_rejected(fn()=>$service->prepare(9007)),'Fresh permanent active admin is required');
        $pdo->exec("UPDATE users SET status=1,is_temporary=0,role='admin' WHERE id=9007");
    }
    $pdo->exec('DELETE FROM user_company_access WHERE user_id=9007 AND company_id=9002');
    k1b_assert(cap2_manual_rejected(fn()=>$service->prepare(9007)),'Company revocation must reject full-scope prepare');
    $pdo->exec('INSERT INTO user_company_access(user_id,company_id) VALUES(9007,9002)');
    $pdo->exec('INSERT INTO user_meli_account_access(user_id,meli_account_id) VALUES(9007,9011)');
    k1b_assert(cap2_manual_rejected(fn()=>$service->prepare(9007)),'Configured incomplete account ACL must reject');
    $pdo->exec('DELETE FROM user_meli_account_access WHERE user_id=9007');
    k1b_assert((int)$pdo->query('SELECT COUNT(*) FROM queue_v4_clean_readiness_runs')->fetchColumn()===$before,'Rejected prepare must not create run');

    $run=$service->prepare(9007);
    $pdo->exec('UPDATE meli_tokens SET refresh_version=refresh_version+1 WHERE meli_account_id=9013');
    k1b_assert(cap2_manual_rejected(fn()=>calls_readiness_check($service,$run,1)),'Change to any manifest account OAuth version must reject before wire');
    $service->cancel(9007,$run['run_id'],'');
    $run=$service->prepare(9007);
    calls_readiness_session(static function():void {$_SESSION['_queue_v4_clean_readiness']['expires_at']=time()-1;});
    k1b_assert(cap2_manual_rejected(fn()=>calls_readiness_check($service,$run,1)),'Absolute expiry must reject');
    k1b_assert($service->snapshot()['run_token']===null,'Expired proof must not appear in snapshot');
    $service->cancel(9007,$run['run_id'],'');
    $run=$service->prepare(9007);
    // Simulate a crashed process after claim commit; retry must not adopt it.
    $pdo->prepare('INSERT INTO queue_v4_clean_readiness_accounts(readiness_run_id,company_id,meli_account_id,outcome,failure_class) VALUES(?,9001,9011,"FAIL",NULL)')->execute([$run['run_id']]);
    $claimed=calls_readiness_check($service,$run,1);
    k1b_assert(!$claimed['ok'] && $claimed['physical_http_calls']===0,'Abandoned claim never retried');
    k1b_assert(cap2_manual_rejected(fn()=>calls_readiness_check($service,$run,2)),'Abandoned FAIL cannot unlock next step');
    $service->cancel(9007,$run['run_id'],$run['run_token']);
    k1b_assert(count(App\Services\Cap2DomainsWire::$calls)===0,'All preflight failures must remain zero physical calls');

    $run=$service->prepare(9007);
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9014,9001,'Disconnected out-of-scope',99014,'desconectado')");
    $pdo->exec('INSERT INTO user_meli_account_access(user_id,meli_account_id) VALUES(9007,9011),(9007,9012),(9007,9013)');
    $scopedSnapshot=$service->snapshot();
    if($scopedSnapshot['active_run_id']!==null) $missing[]='Orphan cancellation hint must require current disconnected-account scope';
    k1b_assert($scopedSnapshot['run_id']===$run['run_id'],'Authorized own manifest must remain visible independently of orphan cancellation hint');
    $orphanRejected=cap2_manual_rejected(fn()=>$service->cancel(9007,$run['run_id'],''));
    if(!$orphanRejected) $missing[]='Orphan cancel must include disconnected current account ACL';
    $pdo->exec('DELETE FROM user_meli_account_access WHERE user_id=9007');
    $pdo->exec('DELETE FROM meli_accounts WHERE id=9014 AND company_id=9001');
    if($orphanRejected) $service->cancel(9007,$run['run_id'],'');

    $run=$service->prepare(9007);
    $process=proc_open([PHP_BINARY,__DIR__.'/calls_readiness_race_actor.php',session_id(),session_save_path(),ERP_INSTALLATION_ROOT,ERP_SHARED_ROOT,'hold-session'],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__.'/..');
    k1b_assert(is_resource($process),'Session lock actor must start');fclose($pipes[0]);
    k1b_assert(trim((string)fgets($pipes[1]))==='LOCKED','Child must hold persisted session lock');
    $began=microtime(true);$physicalDeadline=null;
    App\Services\Cap2DomainsWire::$onWire=static function()use(&$physicalDeadline):void {$physicalDeadline=App\QueueV4Clean\QueueV4CleanCycleBudget::snapshot()['deadline'];};
    $delayed=calls_readiness_check($service,$run,1);App\Services\Cap2DomainsWire::$onWire=null;
    fclose($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[2]);
    k1b_assert(proc_close($process)===0 && $errors==='','Session lock actor must finish cleanly');
    k1b_assert($delayed['ok'] && is_float($physicalDeadline),'Lock wait fixture must reach real physical call');
    if($physicalDeadline>$began+45.1) $missing[]='Physical deadline must include initial session/preflight wait, not restart after it';
    $service->cancel(9007,$run['run_id'],'');

    $run=$service->prepare(9007);
    $newRunId=null;
    App\Services\Cap2DomainsWire::$onWire=static function()use(&$newRunId):void {
        $started=microtime(true);
        $process=proc_open([PHP_BINARY,__DIR__.'/calls_readiness_race_actor.php',session_id(),session_save_path(),ERP_INSTALLATION_ROOT,ERP_SHARED_ROOT],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__.'/..');
        k1b_assert(is_resource($process),'Concurrent actor process must start');fclose($pipes[0]);
        $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        k1b_assert(proc_close($process)===0,'Concurrent actor failed: '.$errors);
        k1b_assert(microtime(true)-$started<5,'Stop/prepare must not wait for readiness GET_LOCK');
        $newRunId=(int)json_decode($output,true,8,JSON_THROW_ON_ERROR)['run_id'];
    };
    $old=calls_readiness_check($service,$run,1);App\Services\Cap2DomainsWire::$onWire=null;
    k1b_assert(!$old['ok'] && $old['physical_http_calls']===1,'Stopped in-flight response must not certify');
    k1b_assert(is_int($newRunId) && $newRunId>$run['run_id'],'New generation B must be prepared while A is in HTTP');
    k1b_assert($pdo->query('SELECT readiness_state FROM queue_v4_clean_control')->fetchColumn()==='TESTING','Old A cannot change primary state belonging to B');
    $s=$pdo->prepare('SELECT state FROM queue_v4_clean_readiness_runs WHERE id=?');$s->execute([$newRunId]);
    k1b_assert($s->fetchColumn()==='TESTING','New B must retain TESTING');
    if(isset($old['run_token'])) $missing[]='Invalidated final context must not return stale proof';
    k1b_assert(cap2_manual_rejected(fn()=>$service->cancel(9007,$run['run_id'],'')),'Orphan cancellation A cannot invalidate B');
    $service->cancel(9007,$newRunId,'');

    $run=$service->prepare(9007);$user=App\Core\Session::get('user');
    App\Services\Cap2DomainsWire::$onWire=static function():void {calls_readiness_session(static function():void{unset($_SESSION['user']);});};
    $logout=calls_readiness_check($service,$run,1);App\Services\Cap2DomainsWire::$onWire=null;
    k1b_assert(!$logout['ok'],'Persisted logout during HTTP must invalidate final result');
    k1b_assert(!isset($_SESSION['user']),'Fresh session reload must never resurrect logout snapshot');
    k1b_assert($service->snapshot()['run_token']===null,'Logged out snapshot must not reveal token');
    calls_readiness_session(static function()use($user):void{$_SESSION['user']=$user;});
    $service->cancel(9007,$run['run_id'],'');

    foreach([false,true] as $failTerminalWrite) {
        $run=$service->prepare(9007);
        if($failTerminalWrite) $pdo->exec('CREATE TRIGGER calls_readiness_fail_terminal BEFORE UPDATE ON queue_v4_clean_readiness_runs FOR EACH ROW BEGIN IF NEW.id='.(int)$run['run_id'].' AND NEW.state="FAILED" THEN SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT="fixture terminal persistence unavailable"; END IF; END');
        App\Services\Cap2DomainsWire::$onWire=static function()use($pdo,$run):void {
            $pdo->prepare('DELETE FROM queue_v4_clean_readiness_accounts WHERE readiness_run_id=? AND company_id=9001 AND meli_account_id=9011')->execute([$run['run_id']]);
        };
        $lostClaim=calls_readiness_check($service,$run,1);App\Services\Cap2DomainsWire::$onWire=null;
        if($lostClaim['ok'] || (int)$pdo->query('SELECT readiness_passed_accounts FROM queue_v4_clean_control')->fetchColumn()!==0) $missing[]='Missing final claim CAS cannot advance readiness or report PASS';
        $callsAfterLostClaim=count(App\Services\Cap2DomainsWire::$calls);
        if(!cap2_manual_rejected(fn()=>calls_readiness_check($service,$run,1)) || count(App\Services\Cap2DomainsWire::$calls)!==$callsAfterLostClaim) {
            $missing[]='Lost claim finalization must reject replay (terminal persistence '.($failTerminalWrite?'failed':'available').')';
        }
        if($failTerminalWrite) $pdo->exec('DROP TRIGGER calls_readiness_fail_terminal');
        if($pdo->query('SELECT readiness_state FROM queue_v4_clean_control')->fetchColumn()==='TESTING') $service->cancel(9007,$run['run_id'],'');
    }

    $run=$service->prepare(9007);$wireBefore=count(App\Services\Cap2DomainsWire::$calls);
    $revokingService=new App\QueueV4Clean\QueueV4CleanReadinessService($pdo,static function(int $id)use($pdo):App\Services\MeliReadClientInterface {
        // Coordinate a revocation after claim COMMIT, before production client/guards.
        // The client, transport, guards and journals are all real.
        $pdo->exec('UPDATE users SET status=0 WHERE id=9007');
        return new App\Services\MeliApiClient($id);
    });
    $revoked=calls_readiness_check($revokingService,$run,1);
    k1b_assert(!$revoked['ok'] && $revoked['physical_http_calls']===0 && $revoked['physical_http_calls_certainty']==='CERTIFIED','Fresh DB admin revocation must compensate before physical wire');
    k1b_assert(count(App\Services\Cap2DomainsWire::$calls)===$wireBefore,'Revoked admin must not reach cURL');
    $pdo->exec('UPDATE users SET status=1 WHERE id=9007');$service->cancel(9007,$run['run_id'],'');

    $run=$service->prepare(9007);
    $generation=(new App\Services\SessionGenerationService())->rotate();
    calls_readiness_session(static function()use($generation):void{$_SESSION['user']['session_generation']=$generation;});
    if(!cap2_manual_rejected(fn()=>calls_readiness_check($service,$run,1))) $missing[]='A proof cannot cross a session generation rotation even if current user is refreshed';
    $service->cancel(9007,$run['run_id'],'');

    $run=$service->prepare(9007);
    $firstBefore429=calls_readiness_check($service,$run,1);
    k1b_assert($firstBefore429['ok'] && $firstBefore429['physical_http_calls']===1,'First account must PASS before second-account429');
    App\Services\Cap2DomainsWire::$responses['/users/me']=[429,['message'=>'Too many requests']];
    $limited=$service->check(9007,$run['run_id'],$run['run_token'],2);
    if($limited['ok'] || $limited['failure_class']!=='remote_429') $missing[]='Typed remote429 stop reason must survive generic deferred exception';
    k1b_assert($limited['physical_http_calls']===1,'HTTP429 must consume exactly one physical call');
    $callsAfter429=count(App\Services\Cap2DomainsWire::$calls);
    k1b_assert(cap2_manual_rejected(fn()=>calls_readiness_check($service,$run,3)) && count(App\Services\Cap2DomainsWire::$calls)===$callsAfter429,'Second-account429 must prohibit third-account HTTP');
    k1b_assert($missing===[],implode('; ',$missing));

    echo "PASS readiness fresh ACL/OAuth/expiry/abandoned claim/Stop-A-B/logout real MariaDB\n";
} finally {App\Services\Cap2DomainsWire::$onWire=null;$h->cleanup();}
