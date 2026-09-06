<?php
declare(strict_types=1);
putenv('CAP2_MANUAL_QA_ROOT=D:/Codex/tmp/erp-meli/calls-20260906/readiness');
putenv('CALLS_QA_STORAGE_ROOT=D:/Codex/tmp/erp-meli/calls-20260906/readiness/shared');
require __DIR__.'/cap2_manual_fixture.php';

function calls_readiness_database(): K1dSafeTestDatabase
{
    $sessionPath='D:/Codex/tmp/erp-meli/calls-20260906/readiness/sessions';
    if(!is_dir($sessionPath)) mkdir($sessionPath,0770,true);
    session_save_path($sessionPath); session_name('erp_meli_session'); session_id(bin2hex(random_bytes(16))); session_start();
    $h=cap2_manual_database(); $pdo=App\Core\Database::connection();
    fwrite(STDERR,'READINESS_TEST_DB='.$h->dbName.PHP_EOL);
    try {
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9013,9001,'Third account',99013,'conectado')");
    $pdo->exec('INSERT INTO user_company_access(user_id,company_id) VALUES(9007,9002)');
    foreach([9012,9013] as $id) $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at) VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))')->execute([$id,App\Core\Crypto::encrypt('fixture-access-'.$id),App\Core\Crypto::encrypt('fixture-refresh-'.$id)]);
    $pdo->exec("UPDATE queue_v4_clean_control SET engine_state='STOPPED',scheduler_enabled=0,readiness_state='NOT_READY' WHERE control_key='primary'");
    (new App\Services\EmergencyControlService())->stopAutomation('fixture','readiness local fixture');
    (new App\Services\InstalledVersionMarkerService())->write(App\Services\AppVersionService::fileVersion(),'301');
    (new App\Services\AppSettingsService())->set('app.version',App\Services\AppVersionService::fileVersion(),'system');
    (new App\Services\AppSettingsService())->set('manual.api_calls_per_step','1','manual');
    App\Services\AppSettingsService::clearCache();
    App\Core\Session::closeReadOnly();
    return $h;
    } catch(Throwable $error) {
        if(session_status()===PHP_SESSION_ACTIVE) session_write_close();
        $h->cleanup(); throw $error;
    }
}

function calls_readiness_session(callable $change): void
{
    if(session_status()!==PHP_SESSION_ACTIVE) session_start();
    $change(); session_write_close();
}

function calls_readiness_check(App\QueueV4Clean\QueueV4CleanReadinessService $service,array $run,int $step): array
{
    $ids=[1=>99011,2=>99013,3=>99012];
    App\Services\Cap2DomainsWire::$responses['/users/me']=[200,['id'=>$ids[$step]]];
    return $service->check(9007,$run['run_id'],$run['run_token'],$step);
}
