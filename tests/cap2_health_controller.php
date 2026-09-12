<?php

declare(strict_types=1);

namespace App\Core {
    final class View
    {
        public static function render(string $view, array $data = [], bool $layout = true): void
        {
            $GLOBALS['cap2_rendered'] = ['view' => $view, 'data' => $data];
        }
    }
}

namespace {
    require __DIR__ . '/k1b_bootstrap.php';
    require __DIR__ . '/K1dSafeTestDatabase.php';
    require __DIR__ . '/cap2_health_fixture.php';

    use App\Core\HttpException;
    use App\Services\CapacityPolicyService;

    putenv('APP_ENV=test');
    putenv('ML_WRITE_ENABLED=false');
    putenv('DB_HOST=127.0.0.1');
    putenv('DB_PORT=' . (getenv('DB_PORT') ?: '33079'));
    putenv('DB_USER=root');
    putenv('DB_PASS=');
    putenv('APP_URL=https://local.test');

    if (isset($argv[1])) {
        cap2_health_connect_existing();
        cap2_health_authenticate();
        $_SERVER['HTTP_ORIGIN'] = 'https://local.test';
        $proposal = json_decode(base64_decode((string) ($argv[2] ?? ''), true) ?: '', true, 512, JSON_THROW_ON_ERROR);
        $_SESSION['capacity_proposal_manual'] = $proposal;
        $_POST = [
            '_token' => 'cap2-health-csrf',
            'capacity_action' => 'confirm',
            'confirmation_nonce' => $proposal['nonce'],
        ];
        try {
            (new App\Controllers\SettingsController())->saveManualCallBudget();
        } catch (HttpException $error) {
            echo 'DENIED:' . $error->status . ':' . $error->publicMessage;
        }
        exit;
    }

    putenv('DB_NAME=erp_meli_k1d_test_cap2_health_controller_' . bin2hex(random_bytes(4)));
    $db = K1dSafeTestDatabase::createFromEnvironment();
    try {
        $pdo = $db->pdo();
        cap2_health_create_schema($pdo);
        cap2_health_seed($pdo);
        cap2_health_authenticate();
        $_SERVER['HTTP_ORIGIN'] = 'https://local.test';

        $before = (new CapacityPolicyService($pdo))->snapshot('manual');
        $_POST = [
            '_token' => 'cap2-health-csrf',
            'capacity_action' => 'prepare',
            'manual_api_calls_per_step' => '16',
            'manual_api_calls_ceiling' => '55',
            'capacity_revision' => $before['revision'],
        ];
        $pdo->exec('DELETE FROM user_company_access WHERE company_id=2');
        $denied = false;
        try { (new App\Controllers\SettingsController())->saveManualCallBudget(); }
        catch (HttpException $error) { $denied = $error->status === 403; }
        k1b_assert($denied, 'prepare_must_apply_global_authorization');

        $pdo->exec("INSERT INTO user_company_access VALUES (7,2,'admin')");
        $pdo->exec('DELETE FROM user_company_access WHERE company_id=5');
        $denied = false;
        try { (new App\Controllers\SettingsController())->saveManualCallBudget(); }
        catch (HttpException $error) { $denied = $error->status === 403; }
        k1b_assert($denied, 'prepare_must_cover_active_account_without_readiness_or_work');
        $pdo->exec("INSERT INTO user_company_access VALUES (7,5,'admin')");

        $pdo->exec('DELETE FROM user_company_access WHERE company_id=4');
        $denied = false;
        try { (new App\Controllers\SettingsController())->saveManualCallBudget(); }
        catch (HttpException $error) { $denied = $error->status === 403; }
        k1b_assert($denied, 'prepare_must_cover_active_company_without_account');
        $pdo->exec("INSERT INTO user_company_access VALUES (7,4,'admin')");

        unset($GLOBALS['cap2_rendered']);
        (new App\Controllers\SettingsController())->saveManualCallBudget();
        k1b_assert(($GLOBALS['cap2_rendered']['view'] ?? '') === 'settings/capacity_confirmation', 'authorized_prepare_must_render_real_controller_confirmation');
        $proposal = $_SESSION['capacity_proposal_manual'] ?? null;
        k1b_assert(is_array($proposal), 'prepare_must_store_server_proposal');

        $runConfirm = static function (array $candidate): array {
            $encoded = base64_encode(json_encode($candidate, JSON_THROW_ON_ERROR));
            $process = proc_open([PHP_BINARY, __FILE__, 'confirm', $encoded], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [proc_close($process), $stdout, $stderr];
        };

        $pdo->exec('DELETE FROM user_company_access WHERE company_id=3');
        [$exit, $out, $err] = $runConfirm($proposal);
        k1b_assert($exit === 0 && str_contains($out, 'DENIED:403:'), 'confirm_must_recheck_revoked_grant:' . $out . $err);
        k1b_assert((int) $pdo->query("SELECT COUNT(*) FROM app_settings WHERE setting_key='manual.api_calls_per_step'")->fetchColumn() === 0, 'revoked_confirmation_must_not_write');

        $pdo->exec("INSERT INTO user_company_access VALUES (7,3,'admin')");
        [$exit, $out, $err] = $runConfirm($proposal);
        k1b_assert($exit === 0, 'healthy_confirm_process_failed:' . $out . $err);
        k1b_assert((int) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='manual.api_calls_per_step'")->fetchColumn() === 16, 'healthy_current_increase_must_persist');

        $pdo->exec('DROP TABLE api_request_logs');
        $current = (new CapacityPolicyService($pdo))->snapshot('manual');
        $ceilingOnly = [
            'module' => 'manual', 'user_id' => 7, 'before' => $current,
            'current' => 16, 'ceiling' => 100, 'revision' => $current['revision'],
            'nonce' => 'ceiling-only', 'expires_at' => time() + 600,
        ];
        [$exit, $out, $err] = $runConfirm($ceilingOnly);
        k1b_assert($exit === 0, 'ceiling_only_confirm_process_failed:' . $out . $err);
        k1b_assert((int) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='manual.api_calls_ceiling'")->fetchColumn() === 100, 'ceiling_only_must_not_call_unknown_health_gate');

        $current = (new CapacityPolicyService($pdo))->snapshot('manual');
        $unknownIncrease = [
            'module' => 'manual', 'user_id' => 7, 'before' => $current,
            'current' => 17, 'ceiling' => 100, 'revision' => $current['revision'],
            'nonce' => 'unknown-increase', 'expires_at' => time() + 600,
        ];
        [$exit, $out, $err] = $runConfirm($unknownIncrease);
        k1b_assert($exit === 0, 'unknown_health_confirm_process_failed:' . $out . $err);
        k1b_assert((int) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='manual.api_calls_per_step'")->fetchColumn() === 17, 'missing_health_log_table_must_not_block_capacity_increase');

        echo "STATUS=PASS CAP2_HEALTH_CONTROLLER REAL_DB=YES REAL_AUTH=YES REAL_CONTROLLER=YES REAL_GUARD=YES HEALTH_NOT_REQUIRED=YES REAL_MELI_HTTP=0\n";
    } finally {
        $db->cleanup();
    }
}
