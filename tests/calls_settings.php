<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
require __DIR__.'/K1dSafeTestDatabase.php';
use App\Repositories\SettingsDefinitionRepository;
use App\Services\SettingsSectionService;
$retired = [
    'synchronization'=>['sync.page_limit','sync.max_orders_per_run','sync.max_api_pages_per_run','sync.queue_max_chunks_per_run'],
    'communications'=>['questions.page_limit','questions.lookback_hours','notifications.max_events_per_run','notifications.max_resources_per_account_per_run','notifications.worker_batch_limit','notifications.worker_time_budget_seconds','notifications.backfill_batch_limit'],
    'financial'=>['financial_recalc.orders_per_run','financial_recalc.max_orders_per_job','financial_recalc.billing_order_ids_per_request','financial_recalc.time_budget_seconds','financial_recalc.stop_on_429','financial_recalc.stop_on_403'],
    'catalogs'=>['catalog.description_job_batch_limit'],
];
$definitions=new SettingsDefinitionRepository();
foreach($retired as $section=>$keys) {
    $fields=array_column($definitions->section($section)['fields'],'key');
    foreach($keys as $key) k1b_assert(!in_array($key,$fields,true),'resource_or_internal_control_still_editable:'.$key);
}
putenv('APP_ENV=test'); putenv('ML_WRITE_ENABLED=false'); putenv('DB_HOST=127.0.0.1'); putenv('DB_PORT=33079'); putenv('DB_USER=root'); putenv('DB_PASS=');
putenv('DB_NAME=erp_meli_k1d_test_calls_settings_'.bin2hex(random_bytes(4)));
$db=K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo=$db->pdo();
    $pdo->exec('CREATE TABLE app_settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT,is_encrypted TINYINT DEFAULT 0,setting_group VARCHAR(80),updated_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE audit_logs(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id BIGINT,action VARCHAR(100),module VARCHAR(100),entity_type VARCHAR(100),entity_id BIGINT,meli_account_id BIGINT,ip_hash CHAR(64),before_json LONGTEXT,after_json LONGTEXT) ENGINE=InnoDB');
    $_SESSION=['user'=>['id'=>7,'role'=>'admin','session_generation'=>'']];
    $insert=$pdo->prepare('INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group) VALUES (?,"7",0,"legacy")');
    foreach($retired as $section=>$keys) {
        foreach($keys as $key) $insert->execute([$key]);
        $submitted=[];
        foreach($definitions->section($section)['fields'] as $field) $submitted[$field['key']]=is_bool($field['recommended'])?($field['recommended']?'1':'0'):(string)$field['recommended'];
        foreach($keys as $key) $submitted[$key]='100';
        $service=new SettingsSectionService();
        $service->save($section,$submitted);
        $service->save($section,$submitted,true);
        $select=$pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');
        foreach($keys as $key) { $select->execute([$key]); k1b_assert($select->fetchColumn()==='7','legacy_resource_value_overwritten:'.$key); }
    }
    echo "PASS calls settings: internal/resource controls absent; forged POST and restore preserve legacy values\n";
} finally { $db->cleanup(); }
