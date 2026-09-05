<?php
declare(strict_types=1);

// Only the effect boundary is replaced. executeMany, persisted preview loading,
// policy, tenant ACL, projection, adapter/source inspection and identity are real.
namespace App\QueueCore {
    final class ManualQueueLauncher {
        public function runExactBatch(array $items, ?int $physicalCallBudget=null, ?float $requestDeadline=null, ?callable $admit=null): array {
            if ($admit!==null) $admit();
            return ['selected_count'=>count($items),'requested_api_calls'=>$physicalCallBudget,'remote_dispatches'=>0,'processed_count'=>count($items),'items'=>$items];
        }
    }
}
namespace {
require __DIR__.'/k1b_bootstrap.php';
require __DIR__.'/K1dSafeTestDatabase.php';
putenv('APP_ENV=test');putenv('ML_WRITE_ENABLED=false');putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT='.(getenv('DB_PORT')?:'33079'));putenv('DB_USER=root');
putenv('DB_NAME=erp_meli_k1d_test_manual_selection_'.bin2hex(random_bytes(4)));
$harness=K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo=$harness->pdo();
    $pdo->exec('CREATE TABLE app_settings(id BIGINT AUTO_INCREMENT PRIMARY KEY,setting_key VARCHAR(190) UNIQUE,setting_value TEXT,is_encrypted TINYINT DEFAULT 0,setting_group VARCHAR(80) DEFAULT "general",updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec("INSERT INTO app_settings(setting_key,setting_value) VALUES ('manual.api_calls_per_step','55'),('manual.api_calls_ceiling','55')");
    $pdo->exec('CREATE TABLE companies(id BIGINT PRIMARY KEY,name VARCHAR(100),status INT)');
    $pdo->exec("INSERT INTO companies VALUES(1,'QA',1)");
    $pdo->exec('CREATE TABLE meli_accounts(id BIGINT PRIMARY KEY,company_id BIGINT,account_name VARCHAR(100),meli_user_id BIGINT,site_id VARCHAR(10),status VARCHAR(20))');
    $pdo->exec("INSERT INTO meli_accounts VALUES(2,1,'QA',123,'MCO','conectado')");
    $pdo->exec('CREATE TABLE user_company_access(user_id BIGINT,company_id BIGINT)');
    $pdo->exec('INSERT INTO user_company_access VALUES(7,1)');
    $pdo->exec('CREATE TABLE system_work_queue_projection(queue_key VARCHAR(100),source_id VARCHAR(100),company_id BIGINT,meli_account_id BIGINT,human_label VARCHAR(100),display_status VARCHAR(20))');
    $pdo->exec('CREATE TABLE order_financial_recalc_jobs(id BIGINT PRIMARY KEY,meli_account_id BIGINT,status VARCHAR(20),created_at DATETIME,total_items INT,processed_items INT,source_type VARCHAR(20),source_id VARCHAR(100),input_version VARCHAR(100))');
    for($i=1;$i<=60;$i++){
        $pdo->exec("INSERT INTO order_financial_recalc_jobs VALUES($i,2,'pending',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR),1,0,'order','$i','v1')");
        $pdo->exec("INSERT INTO system_work_queue_projection VALUES('financial_recalc','$i',1,2,'QA local','pending')");
    }
    $schema=file_get_contents(__DIR__.'/../database/migrations/110_manual_campaign_preview_exact_notifications_2_20_6.sql');
    $pdo->exec(explode('SET @has_notification_manual_idx',$schema)[0]);
    \App\Core\Session::put('user',['id'=>7,'role'=>'admin','session_generation'=>(new \App\Services\SessionGenerationService())->current()]);
    $failures=[];
    foreach([[55,1,55,1],[1,30,30,30],[55,1,1,1],[55,30,2,2]] as [$capacity,$resources,$posted,$expected]) {
        $pdo->exec("UPDATE app_settings SET setting_value='$capacity' WHERE setting_key='manual.api_calls_per_step'");
        \App\Services\AppSettingsService::clearCache();
        $configuration=['preview_format'=>3,'scope'=>'financial','account_id'=>2,'block_size'=>$resources,'physical_api_call_budget'=>$capacity,'capacity_revision'=>(new \App\Services\CapacityPolicyService())->snapshot('manual')['revision']];
        $token=bin2hex(random_bytes(20));
        $pdo->prepare('INSERT INTO manual_campaign_previews(preview_token,created_by_user_id,scope_key,configuration_hash,configuration_json,summary_json,expires_at) VALUES(?,7,"financial",?,?,"{}",DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR))')->execute([$token,hash('sha256',$token),json_encode($configuration)]);
        $previewId=(int)$pdo->lastInsertId();
        for($i=1;$i<=60;$i++){
            $row=['queue_key'=>'financial_recalc','source_id'=>(string)$i,'meli_account_id'=>2];
            $pdo->prepare('INSERT INTO manual_campaign_preview_items(manual_campaign_preview_id,queue_key,source_id,meli_account_id,source_state,item_payload_json,position_no) VALUES(?,"financial_recalc",?,2,"pending",?,?)')->execute([$previewId,(string)$i,json_encode($row),$i]);
        }
        $result=(new \App\Services\ManualSingleStepService())->executeMany($token,7,$posted);
        if($result['selected_count']!==$expected)$failures[]="capacity=$capacity resources=$resources post=$posted selected={$result['selected_count']} expected=$expected";
        if($result['requested_api_calls']!==$capacity)$failures[]="Resource selection reduced physical budget from $capacity to {$result['requested_api_calls']}";
        k1b_assert($result['remote_dispatches']===0,'Local selections consume no physical HTTP.');
    }
    k1b_assert($failures===[],implode('; ',$failures));
    echo "STATUS=PASS CAPACITY_MANUAL_SELECTION REAL_EXECUTE_MANY=YES REAL_HTTP=0\n";
} finally {$harness->cleanup();}
}
