<?php
declare(strict_types=1);

// Run serially with other schema301 Migrator fixtures. Reuses cap2_manual_database
// and calls_browser_runner; all evidence/runtime data stays outside the worktree.
$qa = rtrim(str_replace('\\', '/', (string) getenv('CALLS_VERIFY_QA_ROOT')), '/');
if (PHP_SAPI !== 'cli'
    || !(str_starts_with($qa, 'D:/Codex/') || str_starts_with($qa, 'C:/codex/capacity-save-kiss/'))
    || str_contains($qa, '..')) {
    throw new RuntimeException('CAPACITY_BROWSER_QA_ROOT_REQUIRED');
}
$root = $qa . '/capacity-browser-' . bin2hex(random_bytes(4));
foreach ([$root, $root . '/sessions', $root . '/shared'] as $directory) mkdir($directory, 0770, true);
define('ERP_SHARED_ROOT', $root . '/shared');
putenv('CALLS_QA_STORAGE_ROOT=');
putenv('CAP2_MANUAL_QA_ROOT=' . $root);
putenv('ERP_PRIVATE_PATH=' . $root . '/private');
require __DIR__ . '/cap2_manual_fixture.php';
$database = cap2_manual_database();
$server = null;
try {
    $pdo = $database->pdo();
    $pdo->exec("INSERT INTO users(id,name,email,password_hash,role,status,is_temporary,expires_at) VALUES
        (9017,'Operator QA','operator@example.invalid','unused','operador',1,0,NULL),
        (9018,'Temporary QA','temporary@example.invalid','unused','admin',1,1,DATE_ADD(NOW(),INTERVAL 1 DAY))");
    $pdo->exec('INSERT INTO user_company_access(user_id,company_id) VALUES(9007,9002),(9017,9001),(9018,9001)');
    $pdo->prepare('UPDATE users SET password_hash=?')->execute([password_hash('capacity-disposable-fixture-only', PASSWORD_DEFAULT)]);
    $settings = new App\Services\AppSettingsService();
    foreach (['automation.max_api_calls_per_cycle'=>'50','automation.api_calls_ceiling'=>'55',
        'manual.api_calls_per_step'=>'50','manual.api_calls_ceiling'=>'55',
        'api.rhythm.billing_min_interval_seconds'=>'173'] as $key=>$value) $settings->set($key,$value,'capacity');
    App\Services\AppSettingsService::clearCache();
    k1b_assert((new App\Services\InstalledVersionMarkerService())->write(App\Services\AppVersionService::fileVersion(), '301'), 'installed_fixture_marker');
    // A real missing telemetry dependency, not a fake View or diagnostic service.
    $pdo->exec('RENAME TABLE api_request_logs TO capacity_browser_unavailable_api_logs');
    $snapshot = static function () use ($pdo): string {
        $state = [];
        foreach (['manual_campaign_previews','manual_campaign_preview_items','manual_campaigns','manual_campaign_items',
            'queue_core_jobs','queue_core_attempts','queue_v4_clean_jobs','queue_v4_clean_runs','queue_v4_clean_attempts',
            'queue_core_execution_leases','queue_v4_clean_control','queue_engine_control','meli_tokens'] as $table) {
            $rows = array_map(static fn(array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll());
            sort($rows); $state[$table] = $rows;
        }
        $state['rhythm'] = $pdo->query("SELECT setting_key,setting_value FROM app_settings WHERE setting_key LIKE 'api.rhythm.%' ORDER BY setting_key")->fetchAll();
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    };
    $before = $snapshot();
    file_put_contents($root . '/wire.jsonl', '');
    $port = 18149;
    $probe = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
    k1b_assert(is_resource($probe), 'capacity_browser_exclusive_port'); fclose($probe);
    foreach (['APP_URL'=>'http://127.0.0.1:' . $port, 'SESSION_SECURE'=>'false',
        'CAPACITY_SAVE_BROWSER_QA'=>'1','CAPACITY_SAVE_BROWSER_ROOT'=>$root,
        'CAPACITY_SAVE_BROWSER_INSTALL'=>ERP_INSTALLATION_ROOT,
        'CALLS_BROWSER_OUTPUT_ROOT'=>$root,'CALLS_BROWSER_BASE_URL'=>'http://127.0.0.1:' . $port] as $key=>$value) putenv($key . '=' . $value);
    $server = proc_open([PHP_BINARY, '-d', 'disable_functions=curl_exec,curl_multi_exec', '-d', 'allow_url_fopen=0',
        '-S', '127.0.0.1:' . $port, __DIR__ . '/capacity_save_browser_router.php'],
        [0=>['pipe','r'],1=>['file',$root . '/server.log','w'],2=>['file',$root . '/server-error.log','w']],
        $pipes, dirname(__DIR__), null, ['bypass_shell'=>true,'create_no_window'=>true]);
    k1b_assert(is_resource($server), 'capacity_browser_server_started'); fclose($pipes[0]);
    $ready = false;
    for ($i=0;$i<60;$i++) {
        $socket = @fsockopen('127.0.0.1',$port);
        if (is_resource($socket)) { fclose($socket); $ready=true; break; }
        usleep(100000);
    }
    k1b_assert($ready, 'capacity_browser_server_ready');
    echo 'CAPACITY_BROWSER_EVIDENCE=' . $root . PHP_EOL;
    foreach (['unavailable','healthy'] as $stage) {
        if ($stage === 'healthy') $pdo->exec('RENAME TABLE capacity_browser_unavailable_api_logs TO api_request_logs');
        $output = $root . '/' . $stage; mkdir($output,0770,true);
        putenv('CAPACITY_BROWSER_STAGE=' . $stage);
        putenv('CALLS_BROWSER_OUTPUT_ROOT=' . $output);
        $node = proc_open([(string)(getenv('CAPACITY_NODE_BINARY') ?: 'node'), __DIR__ . '/calls_browser_runner.cjs', __DIR__ . '/capacity_save_browser.cjs'],
            [0=>['pipe','r'],1=>['file',$output . '/browser.log','w'],2=>['file',$output . '/browser-error.log','w']],
            $pipes, dirname(__DIR__), null, ['bypass_shell'=>true,'create_no_window'=>true]);
        k1b_assert(is_resource($node), 'capacity_browser_runner_started'); fclose($pipes[0]);
        k1b_assert(proc_close($node) === 0, 'capacity_browser_' . $stage . '_exit_zero_see_browser_error_log');
    }
    k1b_assert(hash_equals($before,$snapshot()), 'capacity_browser_business_rhythm_tokens_unchanged');
    clearstatcache(true,$root . '/wire.jsonl');
    k1b_assert(filesize($root . '/wire.jsonl') === 0, 'capacity_browser_meli_wire_zero');
    foreach (['automation','manual'] as $module) {
        $saved = (new App\Services\CapacityPolicyService())->snapshot($module);
        k1b_assert($saved['current'] === 50 && $saved['ceiling'] === 60, $module . '_final_persisted_pair');
    }
    echo "STATUS=PASS CAPACITY_SAVE_BROWSER SCHEMA=301 AUTH=REAL CONTROLLER=REAL VIEW=FULL_LAYOUT MELI_HTTP=0\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $database->cleanup();
    echo "CLEANUP=server_stopped_disposable_database_dropped\n";
}
