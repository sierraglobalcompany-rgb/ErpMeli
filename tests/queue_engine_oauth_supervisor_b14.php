<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/bootstrap.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
});

use App\Core\Crypto;
use App\Core\Database;
use App\Core\AppPaths;
use App\QueueCore\QueueCapabilityRegistry;
use App\QueueCore\QueueCoreOAuthRefreshHandler;
use App\QueueCore\QueueCoreOAuthSupervisor;
use App\QueueCore\QueueCoreConvergenceService;
use App\QueueCore\QueueCoreCanaryService;
use App\QueueCore\QueueCoreRepository;
use App\QueueCore\QueueCoreReadinessReceiptService;
use App\QueueCore\QueueCoreReleaseEvidenceService;
use App\QueueCore\QueueEngineControlService;
use App\QueueCore\QueueEngineRuntimePermit;
use App\QueueCore\QueueExecutionContext;
use App\QueueCore\QueueHandler;
use App\QueueCore\QueueHandlerRegistry;
use App\QueueCore\QueueJob;
use App\QueueCore\QueueResult;
use App\QueueCore\QueueRunner;
use App\QueueCore\QueueRunRequest;
use App\Services\ApiExecutionMetadataContext;
use App\Services\AppSettingsService;
use App\Services\MeliApiException;
use App\Services\OAuthRefreshBusyException;
use App\Services\OAuthTokenRefreshService;
use App\Services\QueueOAuthDurableRecoveryStore;
use App\Services\RotatedCredentialRecoveryUnavailableException;

$dsn = (string) (getenv('QUEUE_CORE_TEST_DSN') ?: '');
$user = (string) (getenv('QUEUE_CORE_TEST_USER') ?: '');
$pass = (string) (getenv('QUEUE_CORE_TEST_PASS') ?: '');
if ($dsn === '') {
    fwrite(STDERR, "QUEUE_CORE_TEST_DSN is required\n");
    exit(2);
}

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("SET time_zone='+00:00'");
Database::setConnection($pdo);
putenv('APP_KEY=queue-core-b14-local-test-key');
$private = sys_get_temp_dir() . '/erp-meli-b14-' . bin2hex(random_bytes(6));
putenv('ERP_PRIVATE_PATH=' . $private);
$_ENV['ERP_PRIVATE_PATH'] = $private;
$_SERVER['ERP_PRIVATE_PATH'] = $private;

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['queue_core_readiness_capture_items', 'queue_core_readiness_captures',
    'queue_core_release_evidence', 'queue_core_health_snapshots', 'queue_core_readiness_receipts', 'queue_core_runs',
    'queue_core_historical_reviews', 'queue_core_historical_receipts', 'queue_core_historical_checkpoints',
    'queue_core_webhook_triggers', 'queue_core_capability_dependencies', 'queue_core_feature_flags',
    'queue_core_pending_capabilities', 'queue_core_dispatch_journal', 'queue_core_attempts',
    'queue_core_events', 'queue_core_jobs', 'queue_core_producer_checkpoints',
    'queue_core_scheduler_state', 'queue_core_execution_leases', 'queue_engine_control',
    'api_remote_permits', 'api_rhythm_states',
    'schema_migrations', 'app_settings', 'meli_tokens', 'meli_orders', 'meli_accounts'] as $table) {
    $pdo->exec("DROP TABLE IF EXISTS `$table`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE meli_accounts (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_user_id VARCHAR(80) NULL,
    account_name VARCHAR(100) NULL,
    status VARCHAR(40) NOT NULL,
    last_error VARCHAR(500) NULL
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE meli_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL UNIQUE,
    access_token_encrypted MEDIUMTEXT NOT NULL,
    refresh_token_encrypted MEDIUMTEXT NULL,
    expires_at DATETIME NULL,
    scope VARCHAR(500) NULL,
    token_type VARCHAR(40) NULL,
    refresh_version BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE app_settings (
    setting_key VARCHAR(190) NOT NULL PRIMARY KEY,
    setting_value LONGTEXT NULL,
    is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
    setting_group VARCHAR(80) NOT NULL DEFAULT 'general',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE schema_migrations (
    version VARCHAR(191) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE meli_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    external_order_id BIGINT UNSIGNED NOT NULL,
    synced_at DATETIME NULL,
    date_created DATETIME NULL
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE api_rhythm_states (
    scope_key VARCHAR(64) NOT NULL PRIMARY KEY,generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
    calls_in_block INT UNSIGNED NOT NULL DEFAULT 0,block_started_at DATETIME(3) NULL,
    next_allowed_at DATETIME(3) NULL,block_pause_until DATETIME(3) NULL,
    last_dispatched_at DATETIME(3) NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE api_remote_permits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,permit_token CHAR(40) NOT NULL,
    owner_token CHAR(32) NOT NULL,generation BIGINT UNSIGNED NOT NULL,run_token VARCHAR(100) NULL,
    work_key VARCHAR(120) NULL,company_id BIGINT UNSIGNED NULL,meli_account_id BIGINT UNSIGNED NULL,
    endpoint_key VARCHAR(120) NOT NULL,job_type VARCHAR(80) NOT NULL,method VARCHAR(10) NOT NULL,
    status ENUM('reserved','dispatched','completed','released','expired') NOT NULL DEFAULT 'reserved',
    requested_interval_ms INT UNSIGNED NOT NULL,effective_interval_ms INT UNSIGNED NOT NULL,
    blocking_scope VARCHAR(80) NULL,http_status SMALLINT UNSIGNED NULL,created_at DATETIME(3) NOT NULL,
    dispatched_at DATETIME(3) NULL,completed_at DATETIME(3) NULL,released_at DATETIME(3) NULL,
    expires_at DATETIME(3) NOT NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_api_remote_permit_token(permit_token),KEY idx_api_remote_permit_active(status,expires_at)
) ENGINE=InnoDB");
$pdo->exec("INSERT INTO app_settings(setting_key,setting_value,setting_group)
    VALUES ('oauth.token_expiry_skew_seconds','120','oauth'),('oauth.refresh_lock_wait_seconds','0','oauth')");

$apply = static function (PDO $pdo, string $path): void {
    $sql = file_get_contents($path);
    if (!is_string($sql)) {
        throw new RuntimeException('migration missing: ' . basename($path));
    }
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
        $statement = trim($statement);
        $statement = preg_replace('/^--[^\n]*\n(?:--[^\n]*\n)*/', '', $statement) ?? $statement;
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
};
$migrations = [
    '280_queue_core_cron_v4_phase_b1.sql',
    '281_queue_core_reaudit1_fifo_fencing.sql',
    '282_queue_core_architecture_closeout_b1_2.sql',
    '283_queue_engine_control_oauth_supervisor_b1_4.sql',
    '284_queue_core_sales_pipeline_b2.sql',
    '285_queue_core_webhook_ownership_b2.sql',
    '286_queue_core_historical_deploy_b2.sql',
    '287_queue_core_readiness_observability_b2.sql',
    '288_queue_core_readiness_authority_b2_1.sql',
    '289_queue_core_webhook_lifecycle_b2_1.sql',
    '290_queue_core_sales_dependency_graph_b2_1.sql',
    '291_queue_core_release_health_capacity_b2_1.sql',
    '292_queue_core_authoritative_convergence_b2_1.sql',
    '293_queue_core_runtime_profile_defaults_b2_1.sql',
];
foreach ([1, 2] as $passNumber) {
    foreach ($migrations as $migration) {
        $apply($pdo, $root . '/database/migrations/' . $migration);
        $record = $pdo->prepare('INSERT IGNORE INTO schema_migrations(version) VALUES (?)');
        $record->execute([$migration]);
    }
}

$passed = 0;
$total = 0;
$check = static function (bool $condition, string $message) use (&$passed, &$total): void {
    $total++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passed++;
};
$check(AppPaths::privateRoot() === $private, 'test private root was not isolated: ' . AppPaths::privateRoot());
$preflightSource=(string)file_get_contents($root.'/app/QueueCore/QueueCorePreflightService.php');
$check(str_contains($preflightSource,"max(30,min(600,(new AppSettingsService())->int('oauth.token_expiry_skew_seconds',120)))"),
    'Preflight OAuth skew diverged from the bounded runtime authority.');

// B2 cutover is intentionally fail-closed. The fixture must establish the
// same explicit safety state, feature authorities and scoped receipts that a
// real preflight/canary would produce; the runtime gate itself is not bypassed.
if (!is_dir($private) && !mkdir($private, 0700, true) && !is_dir($private)) {
    throw new RuntimeException('test private root could not be created');
}
$automationMarker = $root . '/PAUSE_ERP_AUTOMATION';
$savedAutomationMarker = is_file($automationMarker) ? file_get_contents($automationMarker) : null;
file_put_contents($automationMarker, 'test');
register_shutdown_function(static function () use ($automationMarker, $savedAutomationMarker): void {
    if (is_string($savedAutomationMarker)) {
        file_put_contents($automationMarker, $savedAutomationMarker);
    } else {
        @unlink($automationMarker);
    }
});
foreach ([
    'CRON_V4_ENABLED' => 'true',
    'CRON_V3_ENABLED' => 'false',
    'CRON_V3_SHADOW_ENABLED' => 'false',
    'ML_WRITE_ENABLED' => 'false',
    'MELI_CLIENT_ID' => 'fixture-client',
    'MELI_CLIENT_SECRET' => 'fixture-secret',
    'MELI_REDIRECT_URI' => 'https://example.invalid/oauth/callback',
    'QUEUE_CORE_APPROVED_BACKUP_SHA256' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
] as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
$pdo->exec("INSERT INTO meli_accounts VALUES
    (1,1,'101','A','conectado',NULL),(2,1,'102','B','conectado',NULL),
    (3,1,'103','C','conectado',NULL)");
$tokenFixture=$pdo->prepare("INSERT INTO meli_tokens
    (meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,scope,token_type,refresh_version)
    VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR),'read','Bearer',0)");
foreach([1,2,3] as $accountFixture){
    $tokenFixture->execute([$accountFixture,Crypto::encrypt('fixture-access'),Crypto::encrypt('fixture-refresh')]);
}
$backupFixture=$private.'/readiness-backup.sql';
$backupTables=$pdo->query("SELECT LOWER(table_name) FROM information_schema.tables
    WHERE table_schema=DATABASE() AND table_type='BASE TABLE'
      AND table_name NOT LIKE 'queue\\_core\\_%' AND table_name<>'queue_engine_control'")->fetchAll(PDO::FETCH_COLUMN);
$actualBackupTables=array_map('strval',$backupTables);
$backupTables=array_values(array_unique(array_merge(array_map('strval',$backupTables),[
    'companies','users','app_settings','schema_migrations','meli_accounts','meli_tokens',
    'meli_orders','meli_order_items','meli_payments','meli_shipments',
])));sort($backupTables,SORT_STRING);$backupSql='';
foreach($backupTables as $backupTable){
    if(in_array($backupTable,$actualBackupTables,true)){
        $showCreate=$pdo->query('SHOW CREATE TABLE `'.$backupTable.'`')->fetch(PDO::FETCH_NUM);
        $backupSql.=(string)($showCreate[1]??'').";\n";
    }else{$backupSql.='CREATE TABLE `'.$backupTable."` (`id` BIGINT);\n";}
}
foreach(['companies','users','meli_accounts','meli_tokens'] as $backupTable){
    if(!in_array($backupTable,$actualBackupTables,true))continue;
    $backupRows=$pdo->query('SELECT * FROM `'.$backupTable.'`')->fetchAll(PDO::FETCH_NUM);
    if($backupRows===[])continue;
    $tuples=[];
    foreach($backupRows as $backupRow){
        $values=array_map(static fn(mixed $value): string=>$value===null?'NULL':$pdo->quote((string)$value),$backupRow);
        $tuples[]='('.implode(',',$values).')';
    }
    $backupSql.='INSERT INTO `'.$backupTable.'` VALUES '.implode(',',$tuples).";\n";
}
$backupSql.="INSERT INTO `app_settings` VALUES ('fixture');\nINSERT INTO `schema_migrations` VALUES ('fixture');\n";
file_put_contents($backupFixture,$backupSql);
$backupFixtureSha=(string)hash_file('sha256',$backupFixture);
foreach([
    'QUEUE_CORE_APPROVED_BACKUP_PATH'=>$backupFixture,
    'QUEUE_CORE_APPROVED_BACKUP_SHA256'=>$backupFixtureSha,
] as $backupEnvKey=>$backupEnvValue){
    putenv($backupEnvKey.'='.$backupEnvValue);
    $_ENV[$backupEnvKey]=$backupEnvValue;
    $_SERVER[$backupEnvKey]=$backupEnvValue;
}
$pdo->exec("UPDATE queue_core_feature_flags SET enabled=1,generation=generation+1
            WHERE feature_key IN ('fresh_producer','webhook_producer','pack_shipment_followups')");
$recordReadiness = static function (int $generation, array $accountIds) use ($pdo): void {
    $receipts = new QueueCoreReadinessReceiptService($pdo);
    $receipts->record($generation, 'preflight', true, ['fixture' => 'b14'], 3600);
    foreach ($accountIds as $accountId) {
        $receipts->record($generation, 'canary', true, ['fixture' => 'b14'], 3600, 1, $accountId);
        $receipts->record($generation, 'convergence', true, ['fixture' => 'b14'], 3600, 1, $accountId);
    }
    $contextHash=$receipts->currentContextHash($generation);
    $release=new QueueCoreReleaseEvidenceService($pdo);
    foreach(['backup','capacity','manifest'] as $type){
        $release->record($generation,$type,true,$contextHash,['fixture'=>'b14'],3600);
    }
};
$control = new QueueEngineControlService($pdo);
$initial = $control->snapshot();
$check($initial['active_engine'] === 'disabled' && $initial['generation'] === 0, 'engine default is not disabled');
$preparing=$control->compareAndSwapReadiness('preparing',0,'test');
$check($preparing['ok']&&$preparing['generation']===1&&$preparing['readiness_mode']==='preparing','readiness preparation failed');
$recordReadiness(1, [1, 2, 3]);
$readinessReceipts=new QueueCoreReadinessReceiptService($pdo);
$readinessReceipts->record(1,'canary',false,['fixture'=>'newer-fail'],3600,1,1);
$latestFail=$readinessReceipts->canActivateV4(1);
$check(
    !$latestFail['ok'] && $latestFail['reason'] === 'canary_account_receipts_missing',
    'newer failed canary did not invalidate an older PASS: ' . json_encode($latestFail)
);
$readinessReceipts->record(1,'canary',true,['fixture'=>'recovered-pass'],3600,1,1);
$contextHash=$readinessReceipts->currentContextHash(1);
$releaseEvidence=new QueueCoreReleaseEvidenceService($pdo);
$releaseEvidence->record(1,'manifest',false,$contextHash,['fixture'=>'newer-fail'],3600);
$latestReleaseFail=$readinessReceipts->canActivateV4(1);
$check(!$latestReleaseFail['ok']&&$latestReleaseFail['reason']==='manifest_latest_failed','newer failed release evidence did not invalidate older PASS');
$releaseEvidence->record(1,'manifest',true,$contextHash,['fixture'=>'recovered-pass'],3600);
$pdo->exec("UPDATE queue_core_feature_flags SET generation=generation+1 WHERE feature_key='fresh_producer'");
$changedContext=$readinessReceipts->canActivateV4(1);
$check(!$changedContext['ok']&&$changedContext['reason']==='readiness_context_changed','changed readiness context reused stale receipts');
$pdo->exec("UPDATE queue_core_feature_flags SET generation=generation-1 WHERE feature_key='fresh_producer'");
$contextBeforeCanaryProgress=$readinessReceipts->currentContextHash(1);
$pdo->exec("INSERT INTO queue_core_producer_checkpoints
    (producer_key,company_id,meli_account_id,watermark_at,window_from,window_to,cursor_value,generation,next_due_at)
    VALUES('fresh_orders',1,1,UTC_TIMESTAMP(3),DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 10 MINUTE),
           UTC_TIMESTAMP(3),'20',1,UTC_TIMESTAMP(3))
    ON DUPLICATE KEY UPDATE watermark_at=VALUES(watermark_at),cursor_value='20',generation=generation+1");
$check(hash_equals($contextBeforeCanaryProgress,$readinessReceipts->currentContextHash(1)),
    'Normal canary checkpoint progress invalidated its own readiness context.');
$windowFrom=gmdate('Y-m-d H:i:s',time()-600);$windowTo=gmdate('Y-m-d H:i:s',time()-300);
$evidenceRepository=new QueueCoreRepository($pdo);
$emptyDiscoveryId=$evidenceRepository->enqueue(new QueueJob(
    1,1,'fresh_orders_discovery','orders_window',null,'fresh_orders',100,
    'readiness-empty-window','v1','fresh_orders_producer','checkpoint:fresh_orders:1',
    ['from'=>$windowFrom,'to'=>$windowTo,'cursor'=>null,'generation'=>0,'limit'=>20],[],5
));
$pdo->prepare("UPDATE queue_core_jobs SET state='completed',dispatch_state='DISPATCHED_RESULT_KNOWN',last_http_status=200,completed_at=UTC_TIMESTAMP(3) WHERE id=?")
    ->execute([$emptyDiscoveryId]);
$pdo->prepare("INSERT INTO queue_core_attempts(job_id,company_id,meli_account_id,lease_owner,lease_generation,launcher,outcome,dispatch_state,physical_http_calls,resources_discovered,resources_persisted,http_status,response_known_at,source_closed_at,finished_at) VALUES (?,?,?,'readiness-fixture',1,'canary_v4','completed','DISPATCHED_RESULT_KNOWN',1,0,0,200,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))")
    ->execute([$emptyDiscoveryId,1,1]);
$pdo->prepare("INSERT INTO queue_core_readiness_captures
    (engine_generation,readiness_context_hash,company_id,meli_account_id,discovery_job_id,
     window_from,window_to,page_offset,response_count,capture_hash,complete)
    VALUES (1,?,1,1,?,?,?,0,0,?,1)")
    ->execute([$contextHash,$emptyDiscoveryId,$windowFrom,$windowTo,hash('sha256','')]);
$emptyConvergence=(new QueueCoreConvergenceService($pdo))->compare(1,1,$windowFrom,$windowTo,20);
$check($emptyConvergence['ok']&&!empty($emptyConvergence['authoritative_empty_window']),'known complete empty window did not converge');
$mismatchFrom=gmdate('Y-m-d H:i:s',time()-1800);$mismatchTo=gmdate('Y-m-d H:i:s',time()-1500);
$pdo->prepare("INSERT INTO queue_core_readiness_captures
    (engine_generation,readiness_context_hash,company_id,meli_account_id,discovery_job_id,
     window_from,window_to,page_offset,response_count,capture_hash,complete)
    VALUES (1,?,1,1,9001,?,?,0,3,?,1)")
    ->execute([$contextHash,$mismatchFrom,$mismatchTo,hash('sha256',"7001\n7002\n7003")]);
$captureId=(int)$pdo->lastInsertId();
$captureItem=$pdo->prepare('INSERT INTO queue_core_readiness_capture_items(capture_id,resource_id) VALUES (?,?)');
foreach(['7001','7002','7003'] as $identity){$captureItem->execute([$captureId,$identity]);}
$insertLocal=$pdo->prepare('INSERT INTO meli_orders(meli_account_id,external_order_id,synced_at,date_created) VALUES (1,?,UTC_TIMESTAMP(3),?)');
$insertLocal->execute([7001,$mismatchFrom]);$insertLocal->execute([7002,$mismatchFrom]);
$remoteExtra=(new QueueCoreConvergenceService($pdo))->compare(1,1,$mismatchFrom,$mismatchTo,20);
$check(!$remoteExtra['ok']&&$remoteExtra['missing_local_count']===1,'REMOTE {A,B,C} versus LOCAL {A,B} passed convergence');
$unexpectedFrom=gmdate('Y-m-d H:i:s',time()-2400);$unexpectedTo=gmdate('Y-m-d H:i:s',time()-2100);
$pdo->prepare("INSERT INTO queue_core_readiness_captures
    (engine_generation,readiness_context_hash,company_id,meli_account_id,discovery_job_id,
     window_from,window_to,page_offset,response_count,capture_hash,complete)
    VALUES (1,?,1,1,9002,?,?,0,2,?,1)")
    ->execute([$contextHash,$unexpectedFrom,$unexpectedTo,hash('sha256',"8001\n8002")]);
$captureId=(int)$pdo->lastInsertId();
foreach(['8001','8002'] as $identity){$captureItem->execute([$captureId,$identity]);}
foreach([8001,8002,8003] as $identity){$insertLocal->execute([$identity,$unexpectedFrom]);}
$localExtra=(new QueueCoreConvergenceService($pdo))->compare(1,1,$unexpectedFrom,$unexpectedTo,20);
$check(!$localExtra['ok']&&$localExtra['unexpected_local_count']===1,'REMOTE {A,B} versus LOCAL {A,B,C} passed convergence');
$incompleteFrom=gmdate('Y-m-d H:i:s',time()-3000);$incompleteTo=gmdate('Y-m-d H:i:s',time()-2700);
$pdo->prepare("INSERT INTO queue_core_readiness_captures
    (engine_generation,readiness_context_hash,company_id,meli_account_id,discovery_job_id,
     window_from,window_to,page_offset,response_count,capture_hash,complete)
    VALUES (1,?,1,1,9003,?,?,0,1,?,0)")
    ->execute([$contextHash,$incompleteFrom,$incompleteTo,hash('sha256','90001')]);
$captureId=(int)$pdo->lastInsertId();$captureItem->execute([$captureId,'90001']);
$insertLocal->execute([90001,$incompleteFrom]);
$incomplete=(new QueueCoreConvergenceService($pdo))->compare(1,1,$incompleteFrom,$incompleteTo,20);
$check(!$incomplete['ok']&&!$incomplete['continuous_coverage'],'first page without terminal page passed convergence');
$crossCompanyBlocked=false;
try{(new QueueCoreConvergenceService($pdo))->compare(99,1,$windowFrom,$windowTo,20);}catch(RuntimeException){$crossCompanyBlocked=true;}
$check($crossCompanyBlocked,'cross-company convergence scope was accepted');
$authorityConnection=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$authorityConnection->query("SELECT GET_LOCK('erp_meli_queue_readiness_authority',0)")->fetchColumn();
$canaryReceiptsBefore=(int)$pdo->query("SELECT COUNT(*) FROM queue_core_readiness_receipts WHERE receipt_type='canary'")->fetchColumn();
$busyCanary=(new QueueCoreCanaryService($pdo))->run(1,1,0,5);
$canaryReceiptsAfter=(int)$pdo->query("SELECT COUNT(*) FROM queue_core_readiness_receipts WHERE receipt_type='canary'")->fetchColumn();
$check(!$busyCanary['ok']&&$busyCanary['reason']==='readiness_authority_busy'
    &&$canaryReceiptsAfter===$canaryReceiptsBefore,
    'A contending canary wrote a late FAIL or crossed the readiness authority.');
$busyReadiness=$control->compareAndSwap('v4',1,'concurrent-evidence');
$check(!$busyReadiness['ok']&&$busyReadiness['reason']==='readiness_authority_busy',
    'Activation crossed a concurrent readiness evidence operation.');
$authorityConnection->query("SELECT RELEASE_LOCK('erp_meli_queue_readiness_authority')")->fetchColumn();
$backupBytes=(string)file_get_contents($backupFixture);unlink($backupFixture);
$missingBackup=$control->compareAndSwap('v4',1,'missing-backup');
$check(!$missingBackup['ok']&&$missingBackup['reason']==='backup_artifact_unavailable',
    'Activation accepted a certified backup artifact that no longer exists.');
file_put_contents($backupFixture,$backupBytes);
$restoredBackupVerification=(new QueueCoreReleaseEvidenceService($pdo))->verifyBackup(
    $backupFixture,
    (string)getenv('QUEUE_CORE_APPROVED_BACKUP_SHA256'),
    true,
);
$check($restoredBackupVerification['ok'],'restored certified backup failed static re-verification: '.json_encode($restoredBackupVerification));
$toV4 = $control->compareAndSwap('v4', 1, 'test');
$check($toV4['ok'] && $toV4['generation'] === 2, 'v4 CAS failed: ' . json_encode($toV4));
$permitResult = $control->acquireRuntime('v4', 'operational');
$permit = $permitResult['permit'] ?? null;
$check($permitResult['ok'] && $permit instanceof QueueEngineRuntimePermit, 'v4 runtime permit failed');
$sameConnectionCutover = $control->compareAndSwap('v3', 2, 'same-process');
$check(
    !$sameConnectionCutover['ok'] && $sameConnectionCutover['reason'] === 'engine_runtime_busy',
    'cutover crossed a runtime lock re-entered by the same DB connection'
);

$second = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$second->exec("SET time_zone='+00:00'");
$busyCutover = (new QueueEngineControlService($second))->compareAndSwap('v3', 2, 'other-process');
$check(!$busyCutover['ok'] && $busyCutover['reason'] === 'engine_runtime_busy', 'cutover crossed an active runtime');
$control->releaseRuntime($permit);
$toV3 = (new QueueEngineControlService($second))->compareAndSwap('v3', 2, 'other-process');
$check($toV3['ok'] && $toV3['generation'] === 3, 'v3 CAS after release failed');
$stale = $control->compareAndSwap('v4', 2, 'stale');
$check(!$stale['ok'] && $stale['reason'] === 'stale_generation', 'stale generation changed engine');

// Cron V4 must have exactly one operational QueueRunner. OAuth is produced
// before that run but cannot receive a privileged claim pass ahead of FIFO.
$cronV4Source = file_get_contents($root . '/app/QueueCore/CronV4Cli.php');
$check(
    is_string($cronV4Source) && substr_count($cronV4Source, "\$core['runner']->run(") === 1,
    'Cron V4 does not expose exactly one operational QueueRunner'
);
$check(
    is_string($cronV4Source)
        && !str_contains($cronV4Source, "['oauth_refresh']")
        && !str_contains($cronV4Source, '$oauthRun'),
    'Cron V4 still gives OAuth a priority pass outside FIFO'
);

$encryptedAccess = Crypto::encrypt('access-old');
$encryptedRefresh = Crypto::encrypt('refresh-old');
$insertToken = $pdo->prepare(
    "INSERT INTO meli_tokens
     (meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,scope,token_type,refresh_version)
     VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),'read','Bearer',0)
     ON DUPLICATE KEY UPDATE
       access_token_encrypted=VALUES(access_token_encrypted),
       refresh_token_encrypted=VALUES(refresh_token_encrypted),
       expires_at=VALUES(expires_at),scope=VALUES(scope),token_type=VALUES(token_type),refresh_version=0"
);
$insertToken->execute([1, $encryptedAccess, $encryptedRefresh, -60]);
$insertToken->execute([2, $encryptedAccess, $encryptedRefresh, 3600]);

$repository = new QueueCoreRepository($pdo);
$supervisor = new QueueCoreOAuthSupervisor($pdo, $repository);
$firstSchedule = $supervisor->scheduleDueAccounts();
$secondSchedule = $supervisor->scheduleDueAccounts();
$oauthJobs = (int) $pdo->query("SELECT COUNT(*) FROM queue_core_jobs WHERE work_type='oauth_refresh'")->fetchColumn();
$check($firstSchedule['due'] === 1 && $firstSchedule['enqueued']===1
    && $secondSchedule['enqueued']===0 && $oauthJobs === 1,
    'OAuth due producer is not idempotent or overreported a deduplicated job');

// Three accounts due at once remain independent and create one logical job
// per account/version without a cross-account identity.
$pdo->exec("INSERT INTO meli_accounts VALUES
    (11,2,'201','C','conectado',NULL),(12,2,'202','D','conectado',NULL),(13,2,'203','E','conectado',NULL)");
foreach ([11, 12, 13] as $dueAccount) {
    $insertToken->execute([$dueAccount, $encryptedAccess, $encryptedRefresh, -60]);
}
$threeDue = $supervisor->scheduleDueAccounts(10);
$threeIds = $pdo->query(
    "SELECT COUNT(DISTINCT meli_account_id) FROM queue_core_jobs
     WHERE work_type='oauth_refresh' AND meli_account_id IN (11,12,13)"
)->fetchColumn();
$check($threeDue['due'] >= 3 && (int) $threeIds === 3, 'three due accounts did not receive isolated OAuth work');
$pdo->exec("UPDATE queue_core_jobs SET state='completed',completed_at=UTC_TIMESTAMP(3)
            WHERE work_type='oauth_refresh' AND meli_account_id IN (11,12,13)");

$waitingId = $repository->enqueue(new QueueJob(
    1, 1, 'order_exact', 'order', '9001', 'normal', 0,
    'waiting-order', 'v1', 'test', null, [], [], 3, null, 'operational'
));
$pdo->prepare("UPDATE queue_core_jobs SET state='waiting_oauth',wait_refresh_version=0 WHERE id=?")
    ->execute([$waitingId]);

$refreshCalls = 0;
$handler = new QueueCoreOAuthRefreshHandler(
    static function (int $accountId) use (&$refreshCalls, $pdo): array {
        $refreshCalls++;
        $pdo->prepare("UPDATE meli_tokens
            SET refresh_version=refresh_version+1,expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 6 HOUR)
            WHERE meli_account_id=?")->execute([$accountId]);
        return ['ok' => true];
    },
    $pdo,
);
$registry = new QueueHandlerRegistry();
$registry->register('oauth_refresh', $handler);
$registry->register('order_exact', new class implements QueueHandler {
    public function handle(\App\QueueCore\QueueClaim $job, QueueExecutionContext $context): QueueResult
    {
        return QueueResult::completed(1);
    }
});
$runner = new QueueRunner($repository, $registry, null, new QueueCapabilityRegistry());
$oauthRun = $runner->run(new QueueRunRequest(
    'test', 'oauth-test', 1, microtime(true) + 10, 30, [], ['oauth_refresh'], null, null, 'operational'
));
$check($oauthRun['completed'] === 1 && $refreshCalls === 1, 'OAuth exact handler did not complete once');
$resumed = $repository->claimNext(new QueueRunRequest(
    'test', 'resume-test', 1, microtime(true) + 10, 30, [], ['order_exact'], null, null, 'operational'
), ['order_exact']);
$check($resumed?->id === $waitingId, 'waiting_oauth did not resume after refresh generation advanced');

$store = new QueueOAuthDurableRecoveryStore($private . '/store-contract');
$store->assertStorageReady();
$store->stage(1, 1, '101', 1, [
    'access_token_encrypted' => Crypto::encrypt('access-staged'),
    'refresh_token_encrypted' => Crypto::encrypt('refresh-staged'),
    'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    'scope' => 'read',
    'token_type' => 'Bearer',
]);
$recovery = $store->load(1, 1, '101');
$check(is_array($recovery) && (int) $recovery['target_refresh_version'] === 2, 'durable recovery could not authenticate its fence');
$store->clear(1, 2);
$check($store->load(1, 1, '101') === null, 'durable recovery was not cleared');

// Real OAuthTokenRefreshService with fake response: validates queue metadata,
// durable preflight/stage, CAS persistence and cleanup without real HTTP.
$pdo->prepare("UPDATE meli_accounts SET status='conectado',last_error=NULL WHERE id=1")->execute();
$pdo->prepare("UPDATE meli_tokens SET refresh_version=2,expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE meli_account_id=1")->execute();
$check(!is_file($private . '/queue-oauth-recovery/account-1.json'), 'unexpected default escrow before real refresh');
$realCalls = 0;
$refreshed = ApiExecutionMetadataContext::run([
    'source' => 'queue_core',
    'company_id' => 1,
    'account_id' => 1,
    'queue_core_work_type' => 'oauth_refresh',
], static function () use (&$realCalls): array {
    return ApiExecutionMetadataContext::withTransportMetadata([
        'transport_meli_account_id' => 1,
        'expected_meli_user_id' => '101',
        'expected_refresh_version' => 2,
        'queue_core_oauth_refresh' => 1,
    ], static function () use (&$realCalls): array {
        return (new OAuthTokenRefreshService(1))->refresh(static function () use (&$realCalls): array {
            $realCalls++;
            return [
                'access_token' => 'access-new',
                'refresh_token' => 'refresh-new',
                'expires_in' => 21600,
                'token_type' => 'Bearer',
                'scope' => 'read',
            ];
        });
    });
});
$check(
    $realCalls === 1 && (int) ($refreshed['refresh_version'] ?? 0) === 3,
    'real refresh service did not advance exactly one generation: calls=' . $realCalls
        . ' version=' . (int) ($refreshed['refresh_version'] ?? 0)
        . ' db=' . (int) $pdo->query('SELECT refresh_version FROM meli_tokens WHERE meli_account_id=1')->fetchColumn()
);
$check(!is_file($private . '/queue-oauth-recovery/account-1.json'), 'queue OAuth escrow remained after committed token');

// The durable preflight must happen before the remote boundary. If the
// filesystem becomes unavailable after that boundary and the fenced DB CAS
// also loses its row, the caller must receive the stable recovery class.
$pdo->prepare("UPDATE meli_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE meli_account_id=1")
    ->execute();
$recoveryRoot = $private . '/queue-oauth-recovery';
$combinedFailure = null;
$combinedCalls = 0;
try {
    ApiExecutionMetadataContext::run([
        'source' => 'queue_core',
        'company_id' => 1,
        'account_id' => 1,
        'queue_core_work_type' => 'oauth_refresh',
    ], static function () use (&$combinedCalls, $pdo, $recoveryRoot): array {
        return ApiExecutionMetadataContext::withTransportMetadata([
            'transport_meli_account_id' => 1,
            'expected_meli_user_id' => '101',
            'expected_refresh_version' => 3,
            'queue_core_oauth_refresh' => 1,
        ], static function () use (&$combinedCalls, $pdo, $recoveryRoot): array {
            return (new OAuthTokenRefreshService(1))->refresh(
                static function () use (&$combinedCalls, $pdo, $recoveryRoot): array {
                    $combinedCalls++;
                    if (!is_dir($recoveryRoot)) {
                        throw new RuntimeException('OAuth recovery preflight did not precede transport.');
                    }
                    if (!@rmdir($recoveryRoot) || file_put_contents($recoveryRoot, 'blocked') === false) {
                        throw new RuntimeException('Could not build the post-transport escrow failure fixture.');
                    }
                    $pdo->exec('DELETE FROM meli_tokens WHERE meli_account_id=1');
                    return [
                        'access_token' => 'access-rotated-unavailable',
                        'refresh_token' => 'refresh-rotated-unavailable',
                        'expires_in' => 21600,
                        'token_type' => 'Bearer',
                        'scope' => 'read',
                    ];
                }
            );
        });
    });
} catch (Throwable $error) {
    $combinedFailure = $error;
} finally {
    if (is_file($recoveryRoot)) {
        @unlink($recoveryRoot);
    }
    $insertToken->execute([1, $encryptedAccess, $encryptedRefresh, -60]);
}
$check($combinedCalls === 1, 'combined escrow/DB failure did not cross exactly one fake transport boundary');
$check(
    $combinedFailure instanceof RotatedCredentialRecoveryUnavailableException,
    'combined escrow/DB failure did not preserve rotated_credential_recovery_unavailable'
);

// Single-flight uses a per-account database advisory lock.
$pdo->prepare("UPDATE meli_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE meli_account_id=1")->execute();
$lock = $second->prepare('SELECT GET_LOCK(?,0)');
$lock->execute(['erp_meli_oauth_refresh_1']);
$busyCaught = false;
try {
    ApiExecutionMetadataContext::run([
        'source' => 'queue_core', 'company_id' => 1, 'account_id' => 1,
        'queue_core_work_type' => 'oauth_refresh', 'transport_meli_account_id' => 1,
        'expected_meli_user_id' => '101', 'expected_refresh_version' => 0,
        'queue_core_oauth_refresh' => 1,
    ], static fn (): array => (new OAuthTokenRefreshService(1))->refresh(
        static fn (): array => throw new RuntimeException('must not call transport')
    ));
} catch (OAuthRefreshBusyException) {
    $busyCaught = true;
} finally {
    $release = $second->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute(['erp_meli_oauth_refresh_1']);
}
$check($busyCaught, 'per-account OAuth single-flight did not block the second worker');

// The seller identity is checked again after the single-flight lock and
// immediately before the callback that represents POST /oauth/token.
$pdo->prepare("UPDATE meli_accounts SET meli_user_id='changed-seller',status='conectado' WHERE id=1 AND company_id=1")
    ->execute();
$identityCalls = 0;
$identityRejected = false;
try {
    ApiExecutionMetadataContext::run([
        'source' => 'queue_core', 'company_id' => 1, 'account_id' => 1,
        'queue_core_work_type' => 'oauth_refresh', 'transport_meli_account_id' => 1,
        'expected_meli_user_id' => '101', 'expected_refresh_version' => 0,
        'queue_core_oauth_refresh' => 1,
    ], static fn (): array => (new OAuthTokenRefreshService(1))->refresh(
        static function () use (&$identityCalls): array {
            $identityCalls++;
            return [];
        }
    ));
} catch (Throwable) {
    $identityRejected = true;
}
$check($identityRejected && $identityCalls === 0, 'stale seller identity crossed the OAuth transport boundary');
$pdo->prepare("UPDATE meli_accounts SET meli_user_id='101' WHERE id=1 AND company_id=1")->execute();

// A job from an older refresh generation is a local no-op even when the
// current token remains inside the refresh skew.
$pdo->prepare("UPDATE meli_tokens SET refresh_version=1,expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE meli_account_id=1")
    ->execute();
$advancedCalls = 0;
$advanced = ApiExecutionMetadataContext::run([
    'source' => 'queue_core', 'company_id' => 1, 'account_id' => 1,
    'queue_core_work_type' => 'oauth_refresh', 'transport_meli_account_id' => 1,
    'expected_meli_user_id' => '101', 'expected_refresh_version' => 0,
    'queue_core_oauth_refresh' => 1,
], static fn (): array => (new OAuthTokenRefreshService(1))->refresh(
    static function () use (&$advancedCalls): array {
        $advancedCalls++;
        return [];
    }
));
$check($advancedCalls === 0 && (int) ($advanced['refresh_version'] ?? -1) === 1,
    'an already advanced OAuth generation started another transport');

// invalid_grant is terminal for one account only.
$pdo->prepare("UPDATE meli_tokens SET refresh_version=0,expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE meli_account_id=1")
    ->execute();
$invalidHandler = new QueueCoreOAuthRefreshHandler(
    static fn (): array => throw new MeliApiException('invalid_grant', 400, 'fake', ['error' => 'invalid_grant']),
    $pdo,
);
$job = $repository->job((int) $pdo->query("SELECT id FROM queue_core_jobs WHERE work_type='oauth_refresh' LIMIT 1")->fetchColumn());
$claim = new \App\QueueCore\QueueClaim(
    (int) $job['id'], 1, 1, 'oauth_refresh', 'oauth_account', '1', 'recovery', 0,
    'running', 1, 5, 'invalid-test', 1, 'NOT_DISPATCHED',
    ['expected_meli_user_id' => '101', 'expected_refresh_version' => 0], 'test', null
);
$invalid = $invalidHandler->handle($claim, new QueueExecutionContext(1, microtime(true) + 10, 'test'));
$statuses = $pdo->query('SELECT id,status FROM meli_accounts ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
$check($invalid->outcome === 'review' && $invalid->errorClass === 'oauth_invalid_grant', 'invalid_grant was not isolated for review');
$check(($statuses[1] ?? '') === 'vencido' && ($statuses[2] ?? '') === 'conectado', 'invalid_grant contaminated another account');

echo 'PASS queue_engine_oauth_supervisor_b14 ' . $passed . '/' . $total . PHP_EOL;
