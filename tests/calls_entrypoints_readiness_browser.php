<?php
declare(strict_types=1);

/** CLI fixture stage AFTER G08/G05; no automatic task or HTTP preparation. */
function calls_entrypoints_readiness_browser(PDO $pdo, Closure $assert): void
{
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9013,9001,'Readiness third account',99013,'conectado')");
    $pdo->exec('INSERT INTO user_company_access(user_id,company_id) VALUES(9007,9002)');
    foreach ([9012,9013] as $id) $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at) VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))')
        ->execute([$id, App\Core\Crypto::encrypt('fixture-access-' . $id), App\Core\Crypto::encrypt('fixture-refresh-' . $id)]);
    $pdo->prepare('UPDATE users SET password_hash=? WHERE id=9007')->execute([password_hash('entrypoints-fixture-password', PASSWORD_DEFAULT)]);
    $pdo->exec("UPDATE queue_v4_clean_control SET engine_state='STOPPED',scheduler_enabled=0,readiness_state='NOT_READY' WHERE control_key='primary'");
    (new App\Services\EmergencyControlService())->stopAutomation('fixture','readiness browser local fixture');
    (new App\Services\InstalledVersionMarkerService())->write(App\Services\AppVersionService::fileVersion(), '301');
    (new App\Services\AppSettingsService())->set('app.version', App\Services\AppVersionService::fileVersion(), 'system');
    (new App\Services\AppSettingsService())->set('manual.api_calls_per_step', '1', 'manual');
    App\Services\AppSettingsService::clearCache();
    $counts = static function () use ($pdo): string {
        $result = [];
        foreach (['queue_core_jobs','queue_core_attempts','queue_v4_clean_jobs','queue_v4_clean_runs','queue_v4_clean_attempts','manual_campaigns'] as $table)
            $result[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        return json_encode($result, JSON_THROW_ON_ERROR);
    };
    $before = $counts();
    $root = rtrim((string) (getenv('CALLS_ENTRYPOINTS_ROOT') ?: 'D:/Codex/tmp/erp-meli/calls-20260906/entrypoints'), '/\\');
    if (!is_dir($root)) { mkdir($root, 0770, true); }
    $node = proc_open(['node', str_replace('\\', '/', __DIR__ . '/calls_browser_runner.cjs'),
        str_replace('\\', '/', __DIR__ . '/calls_entrypoints_readiness_browser.js')],
        [0 => ['pipe','r'], 1 => ['file',$root . '/browser.log','w'], 2 => ['file',$root . '/browser-error.log','w']],
        $pipes, dirname(__DIR__), array_merge($_ENV, [
            'CALLS_BROWSER_OUTPUT_ROOT' => $root,
            'CALLS_BROWSER_BASE_URL' => (string) (getenv('APP_URL') ?: 'http://127.0.0.1:18145'),
        ]), ['bypass_shell' => true, 'create_no_window' => true]);
    $assert(is_resource($node), 'readiness_real_browser_started');
    fclose($pipes[0]);
    $exit = proc_close($node);
    if ($exit !== 0 && in_array('--hold-browser', $GLOBALS['argv'] ?? [], true)) {
        // Debug only: retain this one local server/schema for inspection and
        // browser retries. Creating release-browser.flag exits into finally,
        // which stops our server and drops our disposable database.
        file_put_contents($root . '/browser-debug.json', json_encode(['db_name' => getenv('DB_NAME'),
            'installation_root' => ERP_INSTALLATION_ROOT, 'port' => 18145], JSON_THROW_ON_ERROR));
        echo "BROWSER_DEBUG_HOLD=waiting_for_entrypoints_release-browser.flag\n";
        while (!is_file($root . '/release-browser.flag')) sleep(1);
        unlink($root . '/release-browser.flag');
    }
    $assert($exit === 0, 'readiness_real_browser_exit_zero_see_browser_log');
    $assert($before === $counts(), 'readiness_browser_no_business_jobs_created_or_attempted');
    $assert((string) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='manual.api_calls_per_step'")->fetchColumn() === '1', 'readiness_browser_manual_capacity_stays_one');
    echo "STATUS=PASS CALLS_ENTRYPOINTS_READINESS_BROWSER schema301 reused_after_G08_G05\n";
}
