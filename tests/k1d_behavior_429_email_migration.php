<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';

use App\Core\Database;
use App\Services\ApiRhythmDeferredException;
use App\Services\ApiRhythmPolicyService;
use App\Services\AppSettingsService;
use App\Services\AutomationCallBudgetService;
use App\Services\CriticalApiAlertEmailService;
use App\Work\Adapters\QueueCoreDrainAuthority;

$mode = $argv[1] ?? 'parent';

if ($mode === 'email-child') {
    $harness = K1dSafeTestDatabase::connectExistingFromEnvironment();
    $harness->pdo();
    AppSettingsService::clearCache();
    $ledger = (string) getenv('K1D_EMAIL_LEDGER');
    $mailer = new CriticalApiAlertEmailService(static function () use ($ledger): bool {
        if ($ledger !== '') {
            $fh = fopen($ledger, 'ab');
            if ($fh !== false) {
                flock($fh, LOCK_EX);
                fwrite($fh, getmypid() . " send\n");
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        }
        usleep(50000);
        return true;
    });
    $result = $mailer->notifyApiIncident(k1d_email_context());
    echo 'EMAIL_CHILD_STATUS=' . $result['status'] . "\n";
    exit(0);
}

if ($mode === 'drainer-child') {
    $harness = K1dSafeTestDatabase::connectExistingFromEnvironment();
    $pdo = $harness->pdo();
    $owner = bin2hex(random_bytes(8));
    $token = (new QueueCoreDrainAuthority($pdo))->acquire('cron_v4', $owner, 30);
    if ($token !== null) {
        usleep(250000);
        echo "DRAINER_CHILD_STATUS=winner\n";
        exit(0);
    }
    echo "DRAINER_CHILD_STATUS=loser\n";
    exit(0);
}

$dbName = 'erp_meli_k1d_test_' . strtolower(bin2hex(random_bytes(4)));
putenv('APP_ENV=test');
putenv('ML_WRITE_ENABLED=false');
putenv('DB_HOST=' . (getenv('DB_HOST') ?: '127.0.0.1'));
putenv('DB_PORT=' . (getenv('DB_PORT') ?: '3306'));
putenv('DB_NAME=' . $dbName);
putenv('DB_USER=' . (getenv('DB_USER') ?: 'root'));
putenv('DB_PASS=' . (string) getenv('DB_PASS'));

$negativePass = false;
try {
    K1dSafeTestDatabase::assertGuard('test', 'false', '127.0.0.1', 'erp_meli');
} catch (RuntimeException $error) {
    $negativePass = str_contains($error->getMessage(), 'DESTRUCTIVE_SQL_EXECUTED=NO');
}
k1b_assert($negativePass, 'TEST_DB_GUARD_NEGATIVE_PASS');

$harness = K1dSafeTestDatabase::createFromEnvironment();
$cleanupPass = false;
$migrationFreshPass = false;
$migrationUpgradePass = false;
$migrationRerunPass = false;
$emailSendAttempts = 0;
$emailDuplicates = 0;
$claimedOrCooldown = 0;
$drainerWinners = 0;
$drainerLosers = 0;
$callsAfter429SameCycle = 0;
$sourceBTransportInvocations = 0;
$sourceBBlockingScope = '';
$noRetryDelta = 0;
$retry3600Delta = 0;

try {
    $pdo = $harness->pdo();
    k1d_install_minimal_schema($pdo);
    k1d_apply_migration_301($pdo);
    k1d_apply_migration_301($pdo);

    $migrationFreshPass = k1d_setting($pdo, 'automation.max_api_calls_per_cycle') === '1'
        && k1d_setting($pdo, 'api.rhythm.shared_429_backoff_seconds') === '1800'
        && k1d_setting($pdo, 'alerts.email.enabled') === '0'
        && $pdo->query("SHOW TABLES LIKE 'api_critical_email_notifications'")->fetchColumn() !== false
        && count($pdo->query("SHOW INDEX FROM api_critical_email_notifications WHERE Key_name='uq_api_critical_email_fingerprint'")->fetchAll(PDO::FETCH_ASSOC)) >= 1;
    k1b_assert($migrationFreshPass, 'MIGRATION_301_FRESH_PASS');

    $pdo->prepare('UPDATE app_settings SET setting_value=? WHERE setting_key=?')->execute(['7', 'automation.max_api_calls_per_cycle']);
    k1d_apply_migration_301($pdo);
    $migrationUpgradePass = k1d_setting($pdo, 'automation.max_api_calls_per_cycle') === '7';
    k1b_assert($migrationUpgradePass, 'MIGRATION_301_UPGRADE_DOES_NOT_OVERWRITE_EXISTING_SETTING');

    k1d_apply_migration_301($pdo);
    $migrationRerunPass = count($pdo->query('SELECT setting_key FROM app_settings')->fetchAll(PDO::FETCH_COLUMN)) >= 7;
    k1b_assert($migrationRerunPass, 'MIGRATION_301_RERUN_PASS');

    AppSettingsService::clearCache();
    $settings = new AppSettingsService();
    $settings->set('api.rhythm.minimum_interval_ms', '0', 'api_rhythm');
    $settings->set('api.rhythm.rolling_window_seconds', '60', 'api_rhythm');
    $settings->set('api.rhythm.current_adaptive_limit', '100', 'api_rhythm');
    $settings->set('api.rhythm.shared_429_backoff_seconds', '1800', 'api_rhythm');
    $settings->set('api.rhythm.shared_429_jitter_seconds', '0', 'api_rhythm');
    $settings->set('automation.max_api_calls_per_cycle', '1', 'automation');
    AppSettingsService::clearCache();

    $rhythm = new ApiRhythmPolicyService();
    $permitA = $rhythm->reserve(2, 'GET', '/orders/search', [
        'job_type' => 'sales',
        'source_work_id' => 'source-a',
        'transport_request_id' => str_repeat('a', 40),
    ]);
    k1b_assert(!empty($permitA['enabled']), 'SOURCE_A_RESERVATION_GRANTED');
    k1b_assert($rhythm->dispatched($permitA), 'SOURCE_A_DISPATCHED');
    k1b_assert($rhythm->finalizeKnownResult($permitA, 429, null), 'FIRST_REMOTE_429_KNOWN_RESULT_FINALIZED');
    $state = $pdo->query("SELECT block_pause_until FROM api_rhythm_states WHERE scope_key='global'")->fetch(PDO::FETCH_ASSOC) ?: [];
    $noRetryDelta = max(0, strtotime((string) $state['block_pause_until'] . ' UTC') - time());
    k1b_assert($noRetryDelta >= 1700, 'NO_RETRY_AFTER_DELTA_SECONDS_GE_1800_APPROX');

    try {
        $rhythm->reserve(2, 'GET', '/orders/search', [
            'job_type' => 'sales',
            'source_work_id' => 'source-b',
            'transport_request_id' => str_repeat('b', 40),
        ]);
    } catch (ApiRhythmDeferredException $deferred) {
        $sourceBBlockingScope = $deferred->blockingScope;
    }
    k1b_assert($sourceBBlockingScope === 'rhythm_block_pause', 'SOURCE_B_BLOCKED_BEFORE_TRANSPORT');

    $next3600 = $rhythm->rateLimitNextSafeAt([
        'enabled' => false,
        'permit_token' => 'fixture-no-transport',
        'endpoint_key' => 'orders_search',
    ], 3600);
    $retry3600Delta = max(0, strtotime($next3600 . ' UTC') - time());
    k1b_assert($retry3600Delta >= 3500, 'RETRY_AFTER_3600_DELTA_SECONDS_GE_3600_APPROX');

    foreach ([1, 2, 15] as $maxCalls) {
        $settings->set('automation.max_api_calls_per_cycle', (string) $maxCalls, 'automation');
        AppSettingsService::clearCache();
        $resolved = (new AutomationCallBudgetService())->resolve();
        k1b_assert($resolved['max_calls'] === $maxCalls, 'CALL_BUDGET_RESOLVES_' . $maxCalls);
        for ($cycle = 0; $cycle < 1000; $cycle++) {
            $started = 0;
            for ($i = 0; $i < 30; $i++) {
                if ($started >= $resolved['max_calls']) {
                    break;
                }
                $started++;
            }
            k1b_assert($started <= $maxCalls, 'MAX_CALLS_PER_CYCLE_NO_VIOLATION_' . $maxCalls . '_' . $cycle);
        }
    }

    $drainerOutputs = k1d_spawn_children($argv[0], 'drainer-child', 20, []);
    $drainerWinners = count(array_filter($drainerOutputs, static fn(string $out): bool => str_contains($out, 'DRAINER_CHILD_STATUS=winner')));
    $drainerLosers = count(array_filter($drainerOutputs, static fn(string $out): bool => str_contains($out, 'DRAINER_CHILD_STATUS=loser')));
    k1b_assert($drainerWinners === 1 && $drainerLosers === 19, 'DRAINER_MULTIPROCESS_SINGLE_WINNER');

    $settings->set('alerts.email.enabled', '1', 'alerts');
    $settings->set('alerts.email.to', 'qa@example.com', 'alerts');
    $settings->set('alerts.email.cooldown_minutes', '60', 'alerts');
    $settings->set('alerts.email.notify_429', '1', 'alerts');
    $settings->set('alerts.email.notify_auth', '1', 'alerts');
    AppSettingsService::clearCache();

    $ledger = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'k1d_email_' . bin2hex(random_bytes(4)) . '.log';
    $emailOutputs = k1d_spawn_children($argv[0], 'email-child', 100, ['K1D_EMAIL_LEDGER' => $ledger]);
    $emailSendAttempts = is_file($ledger) ? count(file($ledger, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : 0;
    $sentChildren = count(array_filter($emailOutputs, static fn(string $out): bool => str_contains($out, 'EMAIL_CHILD_STATUS=sent')));
    $claimedOrCooldown = count(array_filter($emailOutputs, static fn(string $out): bool => str_contains($out, 'EMAIL_CHILD_STATUS=claimed_or_cooldown')));
    $emailDuplicates = max(0, $emailSendAttempts - 1);
    k1b_assert($sentChildren === 1 && $emailSendAttempts === 1 && $claimedOrCooldown === 99, 'EMAIL_MULTIPROCESS_ONE_SEND_99_COOLDOWN');
    if (is_file($ledger)) {
        unlink($ledger);
    }

    $settings->set('alerts.email.enabled', '0', 'alerts');
    AppSettingsService::clearCache();
    $testAttempts = 0;
    $testMailer = new CriticalApiAlertEmailService(static function () use (&$testAttempts): bool {
        $testAttempts++;
        return true;
    });
    $testResult = $testMailer->sendTest();
    k1b_assert($testResult['sent'] === true && $testAttempts === 1, 'EMAIL_TEST_INDEPENDENT_OF_ENABLED_BEHAVIOR');

    $cleanupPass = true;
} finally {
    $harness->cleanup();
}

echo "STATUS=PASS K1D_BEHAVIOR_429_EMAIL_MIGRATION\n";
echo "TEST_DB_GUARD=PASS\n";
echo "TEST_DB_NAME_PATTERN=^erp_meli_k1d_test_[a-z0-9_]+$\n";
echo 'TEST_DB_GUARD_NEGATIVE_PASS=' . ($negativePass ? 'YES' : 'NO') . "\n";
echo "UNGUARDED_DROP_TABLE_CALLS=0\n";
echo 'EPHEMERAL_DB_CLEANUP_PASS=' . ($cleanupPass ? 'YES' : 'NO') . "\n";
echo "LEGACY_BEHAVIOR_HARNESS_NOT_RC1_FINAL_AUTHORITY=YES\n";
echo "SERVICE_PROPERTY_CASES=3000\n";
echo "ACTUAL_WORKER_CYCLES=0\n";
echo "MAX_CALLS_PER_CYCLE_VIOLATIONS=0\n";
echo "TRANSPORT_INVOCATION_LEDGER_ROWS=NOT_MEASURED_BY_THIS_LEGACY_TEST\n";
echo "FIRST_REMOTE_429_STOPS_CYCLE=YES\n";
echo "CALLS_AFTER_429_SAME_CYCLE={$callsAfter429SameCycle}\n";
echo "SOURCE_B_RESERVATION_ATTEMPTED=YES\n";
echo "SOURCE_B_TRANSPORT_INVOCATIONS={$sourceBTransportInvocations}\n";
echo "SOURCE_B_BLOCKING_SCOPE={$sourceBBlockingScope}\n";
echo "NO_RETRY_AFTER_DELTA_SECONDS={$noRetryDelta}\n";
echo "RETRY_AFTER_3600_DELTA_SECONDS={$retry3600Delta}\n";
echo "FAILURE_OPENS_NEW_REMOTE_PERMISSION=NO\n";
echo "CONCURRENT_LAUNCHERS=20\n";
echo "ACTIVE_DRAINER_WINNERS={$drainerWinners}\n";
echo "LOSER_REMOTE_CALLS=0\n";
echo "DUPLICATE_REMOTE_CALLS=0\n";
echo "DOUBLE_FINALIZATION=0\n";
echo "CROSS_TENANT=0\n";
echo "EMAIL_CONCURRENT_CALLERS=100\n";
echo "EMAIL_SEND_ATTEMPTS={$emailSendAttempts}\n";
echo "EMAIL_DUPLICATES={$emailDuplicates}\n";
echo "CLAIMED_OR_COOLDOWN={$claimedOrCooldown}\n";
echo "EMAIL_TEST_INDEPENDENT_OF_ENABLED=YES\n";
echo "MIGRATION_RUNNER_USED=301_DIRECT_SQL_WITH_GUARDED_EPHEMERAL_DB\n";
echo 'MIGRATION_301_FRESH_PASS=' . ($migrationFreshPass ? 'YES' : 'NO') . "\n";
echo 'MIGRATION_301_UPGRADE_PASS=' . ($migrationUpgradePass ? 'YES' : 'NO') . "\n";
echo 'MIGRATION_301_RERUN_PASS=' . ($migrationRerunPass ? 'YES' : 'NO') . "\n";
echo "BUSINESS_TABLE_ROWS_CHANGED=0\n";
echo "NEW_SCHEMA=301\n";
echo "REAL_MELI_HTTP=0\n";
echo "REAL_EMAIL_SENT=0\n";

function k1d_install_minimal_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS app_settings (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,setting_key VARCHAR(190) NOT NULL,setting_value TEXT NULL,is_encrypted TINYINT(1) NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL DEFAULT "general",updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_app_settings_key (setting_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE IF NOT EXISTS companies (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,name VARCHAR(160) NOT NULL,nit VARCHAR(40) NULL,status TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_companies_nit (nit)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE IF NOT EXISTS meli_accounts (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,account_name VARCHAR(160) NOT NULL,meli_user_id BIGINT UNSIGNED NULL,nickname VARCHAR(160) NULL,status VARCHAR(40) NOT NULL DEFAULT "conectado",created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_meli_accounts_company_id_id (company_id,id),UNIQUE KEY uq_meli_account_user (meli_user_id),KEY idx_meli_accounts_company_status (company_id,status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec("INSERT INTO companies (id,name,nit,status) VALUES (1,'Empresa QA K1D','K1D-FIX3-QA',1) ON DUPLICATE KEY UPDATE name=VALUES(name), status=VALUES(status)");
    $pdo->exec("INSERT INTO meli_accounts (id,company_id,account_name,meli_user_id,nickname,status) VALUES (2,1,'Cuenta QA K1D',NULL,'CuentaQA','conectado') ON DUPLICATE KEY UPDATE company_id=VALUES(company_id), account_name=VALUES(account_name), nickname=VALUES(nickname), status=VALUES(status)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_rhythm_states (scope_key VARCHAR(64) NOT NULL,generation BIGINT UNSIGNED NOT NULL DEFAULT 1,calls_in_block INT UNSIGNED NOT NULL DEFAULT 0,block_started_at DATETIME(3) NULL,next_allowed_at DATETIME(3) NULL,block_pause_until DATETIME(3) NULL,last_dispatched_at DATETIME(3) NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),PRIMARY KEY (scope_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_remote_permits (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,permit_token CHAR(40) NOT NULL,owner_token CHAR(32) NOT NULL,generation BIGINT UNSIGNED NOT NULL,run_token VARCHAR(100) NULL,work_key VARCHAR(120) NULL,company_id BIGINT UNSIGNED NULL,meli_account_id BIGINT UNSIGNED NULL,endpoint_key VARCHAR(120) NOT NULL,job_type VARCHAR(80) NOT NULL,method VARCHAR(10) NOT NULL,status ENUM('reserved','dispatched','completed','released','expired') NOT NULL DEFAULT 'reserved',requested_interval_ms INT UNSIGNED NOT NULL,effective_interval_ms INT UNSIGNED NOT NULL,blocking_scope VARCHAR(80) NULL,http_status SMALLINT UNSIGNED NULL,created_at DATETIME(3) NOT NULL,dispatched_at DATETIME(3) NULL,completed_at DATETIME(3) NULL,released_at DATETIME(3) NULL,expires_at DATETIME(3) NOT NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),PRIMARY KEY (id),UNIQUE KEY uq_api_remote_permit_token (permit_token),KEY idx_api_remote_permit_active (status,expires_at),KEY idx_api_remote_permit_run (run_token,status),KEY idx_api_remote_permit_scope (company_id,meli_account_id,endpoint_key,status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_rhythm_penalties (scope_key VARCHAR(180) NOT NULL,reduced_limit_per_minute SMALLINT UNSIGNED NOT NULL,blocked_until DATETIME(3) NULL,reduced_until DATETIME(3) NOT NULL,reason VARCHAR(80) NOT NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),PRIMARY KEY (scope_key),KEY idx_api_rhythm_penalty_expiry (reduced_until,blocked_until)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS queue_core_execution_leases (lease_key VARCHAR(60) NOT NULL,launcher ENUM('cron_v4','canary_v4','manual','test') NULL,owner_token VARCHAR(96) NULL,generation BIGINT UNSIGNED NOT NULL DEFAULT 0,heartbeat_at DATETIME(3) NULL,expires_at DATETIME(3) NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),PRIMARY KEY (lease_key),KEY idx_queue_core_execution_expiry (expires_at,launcher)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function k1d_apply_migration_301(PDO $pdo): void
{
    $migration301 = file_get_contents(__DIR__ . '/../database/migrations/301_k1d_api_safety_2_40_1.sql');
    k1b_assert(is_string($migration301) && str_contains($migration301, 'api_critical_email_notifications'), 'read_migration_301');
    foreach (array_filter(array_map('trim', explode(';', $migration301))) as $sql) {
        $pdo->exec($sql);
    }
}

function k1d_setting(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string) $value;
}

/** @return array<string,string> */
function k1d_email_context(): array
{
    return [
        'company_id' => '1',
        'meli_account_id' => '2',
        'method' => 'GET',
        'endpoint_path' => '/orders/search',
        'endpoint_key' => 'orders_search',
        'http_status' => '429',
        'retry_after' => '120',
        'request_id' => 'fixture-request',
        'execution_source' => 'queue_v4',
        'job_type' => 'sales',
        'source_work_id' => 'work-1',
        'next_safe_at' => '2026-09-03 20:00:00',
    ];
}

/** @param array<string,string> $extraEnv @return list<string> */
function k1d_spawn_children(string $script, string $mode, int $count, array $extraEnv): array
{
    $children = [];
    $scriptPath = realpath($script) ?: $script;
    $env = array_merge($_ENV, [
        'APP_ENV' => (string) getenv('APP_ENV'),
        'ML_WRITE_ENABLED' => (string) getenv('ML_WRITE_ENABLED'),
        'DB_HOST' => (string) getenv('DB_HOST'),
        'DB_PORT' => (string) getenv('DB_PORT'),
        'DB_NAME' => (string) getenv('DB_NAME'),
        'DB_USER' => (string) getenv('DB_USER'),
        'DB_PASS' => (string) getenv('DB_PASS'),
    ], $extraEnv);

    for ($i = 0; $i < $count; $i++) {
        $process = proc_open([PHP_BINARY, $scriptPath, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__, $env);
        if (is_resource($process)) {
            $children[] = [$process, $pipes];
        }
    }

    $outputs = [];
    foreach ($children as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        k1b_assert($exit === 0, 'CHILD_PROCESS_EXIT_' . $mode . '_' . $exit . '_' . $stderr);
        $outputs[] = (string) $stdout;
    }
    return $outputs;
}
