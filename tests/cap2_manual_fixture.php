<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
require __DIR__.'/K1dSafeTestDatabase.php';
require __DIR__.'/cap2_domains_wire_fixture.php';

function cap2_manual_database(): K1dSafeTestDatabase
{
    putenv('APP_ENV=test'); putenv('ML_WRITE_ENABLED=false');
    putenv('DB_HOST=127.0.0.1'); putenv('DB_PORT=33079'); putenv('DB_USER=root'); putenv('DB_PASS=');
    putenv('DB_NAME=erp_meli_k1d_test_cap2_manual_'.bin2hex(random_bytes(4)));
    putenv('APP_KEY=cap2-disposable-test-only-not-a-real-secret');
    $qaRoot=rtrim((string)(getenv('CAP2_MANUAL_QA_ROOT')?:'D:/Codex/tmp/erp-meli/cap2-20260905/qa'),'/\\');
    putenv('PRIVATE_STORAGE_PATH='.$qaRoot.'/manual-private');
    putenv('MELI_API_BASE=https://cap2-wire.invalid');
    // Emergency markers/logs belong to this disposable fixture, never the worktree.
    if (!defined('ERP_INSTALLATION_ROOT')) define('ERP_INSTALLATION_ROOT',$qaRoot.'/manual-install-'.bin2hex(random_bytes(4)));
    if (!is_dir(ERP_INSTALLATION_ROOT)) mkdir(ERP_INSTALLATION_ROOT,0777,true);
    $h=K1dSafeTestDatabase::createFromEnvironment(); $pdo=$h->pdo();
    try {(new App\Services\Migrator($pdo,__DIR__.'/../database/migrations'))->run(301);}
    catch(Throwable $error) {$h->cleanup();throw $error;}
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9001,'CAP2 manual',1),(9002,'Other scope',1)");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9011,9001,'CAP2 account',99011,'conectado'),(9012,9002,'Other account',99012,'conectado')");
    $pdo->exec("INSERT INTO users(id,name,email,password_hash,role,status) VALUES(9007,'CAP2 user','manual@example.invalid','unused','admin',1),(9008,'Other user','other@example.invalid','unused','admin',1)");
    $pdo->exec('INSERT INTO user_company_access(user_id,company_id) VALUES(9007,9001),(9008,9002)');
    $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at) VALUES(9011,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))')->execute([App\Core\Crypto::encrypt('test-access'),App\Core\Crypto::encrypt('test-refresh')]);
    $pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE',readiness_state='CERTIFIED' WHERE control_key='primary'");
    $pdo->exec("UPDATE queue_engine_control SET active_engine='v4'");
    $s=new App\Services\AppSettingsService();
    foreach(['notifications.debounce_seconds'=>'0','items.hybrid_notification_updates_enabled'=>'0','api.rhythm.burst_size'=>'100','manual.api_calls_per_step'=>'3','manual.api_calls_ceiling'=>'55'] as $key=>$value) $s->set($key,$value,'manual');
    App\Services\AppSettingsService::clearCache();
    App\Core\Session::put('user',['id'=>9007,'role'=>'admin','session_generation'=>(new App\Services\SessionGenerationService())->rotate()]);
    return $h;
}

/** Durable preview fixture: only selection persistence, not replaced services or handlers. */
function cap2_manual_preview(PDO $pdo,array $rows=[],array $overrides=[]): string
{
    $config=['preview_format'=>4,'scope'=>'recommended','account_id'=>9011]
        + App\Services\ManualPhysicalCallBudget::previewConfiguration([], (new App\Services\CapacityPolicyService())->snapshot('manual'));
    $config=array_replace($config,$overrides); $token=bin2hex(random_bytes(20));
    $pdo->prepare('INSERT INTO manual_campaign_previews(preview_token,created_by_user_id,scope_key,meli_account_id,configuration_hash,configuration_json,summary_json,expires_at) VALUES(?,9007,?,9011,?,?,"{}",DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 10 MINUTE))')->execute([$token,$config['scope'],hash('sha256',$token),json_encode($config)]);
    $id=(int)$pdo->lastInsertId();
    foreach($rows as $position=>$row) {
        $queueKey=(string)$row['queue_key'];$sourceId=(string)$row['source_id'];$accountId=(int)$row['meli_account_id'];
        $companyId=(int)$pdo->query('SELECT company_id FROM meli_accounts WHERE id='.$accountId)->fetchColumn();
        $adapter=(new App\Services\ManualCampaignAdapterRegistry())->forQueue($queueKey);
        if($adapter===null||!$adapter->supportsExact())throw new RuntimeException('fixture_exact_adapter_required');
        $state=$adapter->inspect($sourceId,$accountId);
        $authorityService=new App\QueueCore\ManualSourceAuthorityService();
        $authority=$authorityService->inspect($queueKey,$sourceId,$accountId,$companyId,$state);
        $snapshot=array_replace($row,[
            'company_id'=>$companyId,
            'selection_id'=>'exact:'.$queueKey.':'.$sourceId,
            'source_authority_version'=>$authority->durableInputVersion,
            'operation_key'=>$authority->operationKey,
            'uses_api'=>$authority->usesApi,
            'remote_contract'=>$authority->remoteContract,
            'related_resource_ids'=>$authorityService->relatedResourceIds($queueKey,$sourceId,$accountId,$companyId),
        ]);
        $snapshot['selection_version']=App\Services\ManualCampaignPreviewService::exactSelectionVersion($snapshot);
        $pdo->prepare('INSERT INTO manual_campaign_preview_items(manual_campaign_preview_id,queue_key,source_id,meli_account_id,source_state,item_payload_json,position_no) VALUES(?,?,?,?,"ready",?,?)')->execute([$id,$queueKey,$sourceId,$accountId,json_encode($snapshot),$position+1]);
    }
    return $token;
}
function cap2_manual_state(PDO $pdo,string $token): string
{
    $s=$pdo->prepare('SELECT status FROM manual_campaign_previews WHERE preview_token=?');$s->execute([$token]);return (string)$s->fetchColumn();
}
function cap2_manual_rejected(callable $call): bool
{
    try {$call();return false;} catch(RuntimeException) {return true;}
}
function cap2_manual_connection(): PDO
{
    $pdo=new PDO('mysql:host=127.0.0.1;port=33079;dbname='.getenv('DB_NAME').';charset=utf8mb4','root','',[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec("SET time_zone='+00:00'"); return $pdo;
}
function cap2_manual_notification(PDO $pdo,string $remote,string $type='question'): array
{
    $path=match($type){'question'=>'/questions/','item'=>'/items/',default=>throw new RuntimeException('fixture type')};
    $pdo->prepare('INSERT INTO meli_notification_events(meli_account_id,topic,resource,payload_json,payload_hash) VALUES(9011,?,?,"{}",?)')->execute([$type,$path.$remote,hash('sha256',$remote.random_bytes(8))]);
    $id=(int)(new App\Services\NotificationWorkItemService())->enqueue((int)$pdo->lastInsertId(),9011,99011,['valid'=>true,'actionable'=>true,'canonical_topic'=>$type,'resource_type'=>$type,'resource_id'=>$remote,'priority'=>10],null);
    (new App\Services\WorkQueueProjectionService())->refreshQueue('notification_fallback');
    return ['queue_key'=>'notification_fallback','source_id'=>(string)$id,'meli_account_id'=>9011];
}
function cap2_manual_orders(PDO $pdo,int $sequence): array
{
    $pdo->exec("INSERT IGNORE INTO sync_batches(id,meli_account_id,period_year,period_month,date_from,date_to,status) VALUES(9001,9011,2026,8,'2026-08-01','2026-08-31','queued')");
    $pdo->prepare("INSERT INTO sync_batch_chunks(sync_batch_id,meli_account_id,sequence_no,date_from,date_to,status) VALUES(9001,9011,?,'2026-08-01','2026-08-31','queued')")->execute([$sequence]);
    $id=(int)$pdo->lastInsertId();
    (new App\Services\WorkQueueProjectionService())->refreshQueue('orders_sync');
    return ['queue_key'=>'orders_sync','source_id'=>(string)$id,'meli_account_id'=>9011];
}
