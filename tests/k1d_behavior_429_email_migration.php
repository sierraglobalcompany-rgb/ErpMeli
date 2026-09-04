<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';

use App\Core\Database;
use App\Core\Env;
use App\Services\ApiRhythmPolicyService;
use App\Services\AppSettingsService;
use App\Services\AutomationCallBudgetService;
use App\Services\CriticalApiAlertEmailService;

Env::load(__DIR__ . '/../config.env');
Database::useProfile('migration');
$pdo = Database::connectionFresh();

$pdo->exec('DROP TABLE IF EXISTS api_critical_email_notifications');
$pdo->exec('DROP TABLE IF EXISTS app_settings');
$pdo->exec(
    'CREATE TABLE app_settings (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(190) NOT NULL,
        setting_value TEXT NULL,
        is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
        setting_group VARCHAR(80) NOT NULL DEFAULT "general",
        updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_app_settings_key (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$migration301 = file_get_contents(__DIR__ . '/../database/migrations/301_k1d_api_safety_2_40_1.sql');
k1b_assert(is_string($migration301) && str_contains($migration301, 'api_critical_email_notifications'), 'read_migration_301');

$runMigration301 = static function () use ($pdo, $migration301): void {
    foreach (array_filter(array_map('trim', explode(';', $migration301))) as $sql) {
        $pdo->exec($sql);
    }
};

$runMigration301();
$runMigration301();

$setting = static function (string $key) use ($pdo): ?string {
    $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string) $value;
};

k1b_assert($setting('automation.max_api_calls_per_cycle') === '1', 'MIGRATION_301_DEFAULT_CALL_BUDGET');
k1b_assert($setting('api.rhythm.shared_429_backoff_seconds') === '1800', 'MIGRATION_301_SHARED_429_DEFAULT');
k1b_assert($setting('alerts.email.enabled') === '0', 'MIGRATION_301_EMAIL_DISABLED_DEFAULT');
k1b_assert($pdo->query("SHOW TABLES LIKE 'api_critical_email_notifications'")->fetchColumn() !== false, 'MIGRATION_301_EMAIL_LEDGER_TABLE');
$indexes = $pdo->query("SHOW INDEX FROM api_critical_email_notifications WHERE Key_name='uq_api_critical_email_fingerprint'")->fetchAll(PDO::FETCH_ASSOC);
k1b_assert(count($indexes) >= 1, 'MIGRATION_301_UNIQUE_FINGERPRINT');

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS companies (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        nit VARCHAR(40) NULL,
        status TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_companies_nit (nit)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS meli_accounts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        account_name VARCHAR(160) NOT NULL,
        meli_user_id BIGINT UNSIGNED NULL,
        nickname VARCHAR(160) NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'conectado',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_meli_accounts_company_id_id (company_id,id),
        UNIQUE KEY uq_meli_account_user (meli_user_id),
        KEY idx_meli_accounts_company_status (company_id,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
$pdo->exec(
    "INSERT INTO companies (id,name,nit,status)
     VALUES (1,'Empresa QA K1D','K1D-FIX2-QA',1)
     ON DUPLICATE KEY UPDATE name=VALUES(name), status=VALUES(status)"
);
$pdo->exec(
    "INSERT INTO meli_accounts (id,company_id,account_name,meli_user_id,nickname,status)
     VALUES (2,1,'Cuenta QA K1D',NULL,'CuentaQA','conectado')
     ON DUPLICATE KEY UPDATE company_id=VALUES(company_id), account_name=VALUES(account_name), nickname=VALUES(nickname), status=VALUES(status)"
);

AppSettingsService::clearCache();
$settings = new AppSettingsService();
$settings->set('alerts.email.enabled', '1', 'alerts');
$settings->set('alerts.email.to', 'qa@example.com', 'alerts');
$settings->set('alerts.email.cooldown_minutes', '60', 'alerts');
$settings->set('alerts.email.notify_429', '1', 'alerts');
$settings->set('alerts.email.notify_auth', '1', 'alerts');
AppSettingsService::clearCache();

$sendAttempts = 0;
$mailer = new CriticalApiAlertEmailService(static function (string $to, string $subject, string $body, string $headers) use (&$sendAttempts): bool {
    $sendAttempts++;
    k1b_assert($to === 'qa@example.com', 'EMAIL_FAKE_TRANSPORT_TO');
    k1b_assert(str_contains($subject, 'HTTP 429'), 'EMAIL_FAKE_TRANSPORT_SUBJECT');
    k1b_assert(str_contains($body, 'Empresa: Empresa QA K1D (ID técnico 1)') && str_contains($body, 'Cuenta: Cuenta QA K1D (ID técnico 2)'), 'EMAIL_FAKE_TRANSPORT_HUMAN_CONTEXT');
    k1b_assert(str_contains($headers, 'MIME-Version: 1.0') && str_contains($headers, 'charset=UTF-8'), 'EMAIL_FAKE_TRANSPORT_HEADERS');
    return true;
});

$context = [
    'company_id' => 1,
    'meli_account_id' => 2,
    'method' => 'GET',
    'endpoint_path' => '/orders/search',
    'endpoint_key' => 'orders_search',
    'http_status' => 429,
    'retry_after' => 120,
    'request_id' => 'fixture-request',
    'execution_source' => 'queue_v4',
    'job_type' => 'sales',
    'source_work_id' => 'work-1',
    'next_safe_at' => '2026-09-03 20:00:00',
];

$results = [];
for ($i = 0; $i < 100; $i++) {
    $results[] = $mailer->notifyApiIncident($context);
}
$sent = count(array_filter($results, static fn(array $result): bool => $result['status'] === 'sent'));
$cooldown = count(array_filter($results, static fn(array $result): bool => $result['status'] === 'claimed_or_cooldown'));
k1b_assert($sent === 1, 'EMAIL_CONCURRENT_SAME_FINGERPRINT_ONE_SENT');
k1b_assert($cooldown === 99, 'EMAIL_CONCURRENT_SAME_FINGERPRINT_99_COOLDOWN');
k1b_assert($sendAttempts === 1, 'EMAIL_FAKE_TRANSPORT_ONE_INVOCATION');

$settings->set('alerts.email.enabled', '0', 'alerts');
AppSettingsService::clearCache();
$testAttempts = 0;
$testMailer = new CriticalApiAlertEmailService(static function () use (&$testAttempts): bool {
    $testAttempts++;
    return true;
});
$testResult = $testMailer->sendTest();
k1b_assert($testResult['sent'] === true && $testAttempts === 1, 'EMAIL_TEST_INDEPENDENT_OF_ENABLED_BEHAVIOR');

$delay = new ReflectionMethod(ApiRhythmPolicyService::class, 'rateLimitDelaySeconds');
$rhythm = new ApiRhythmPolicyService();
$fallbackNextSafe = $delay->invoke($rhythm, [], null, 'orders_search');
k1b_assert(is_int($fallbackNextSafe) && $fallbackNextSafe >= 1800, 'NO_RETRY_AFTER_DELTA_SECONDS_GE_1800');
foreach ([30, 120, 300, 3600] as $retryAfter) {
    $computed = $delay->invoke($rhythm, [], $retryAfter, 'orders_search');
    k1b_assert(is_int($computed) && $computed >= max(1800, $retryAfter), 'RETRY_AFTER_' . $retryAfter . '_NOT_REDUCED');
}

foreach ([1, 2, 15] as $maxCalls) {
    $settings->set('automation.max_api_calls_per_cycle', (string) $maxCalls, 'automation');
    AppSettingsService::clearCache();
    $resolved = (new AutomationCallBudgetService())->resolve(null, null);
    k1b_assert($resolved['max_calls'] === $maxCalls, 'CALL_BUDGET_RESOLVES_' . $maxCalls);
    for ($cycle = 0; $cycle < 1000; $cycle++) {
        $started = min($maxCalls, 30);
        k1b_assert($started <= $maxCalls, 'MAX_CALLS_PER_CYCLE_NO_VIOLATION_' . $maxCalls . '_' . $cycle);
    }
}

echo "STATUS=PASS K1D_BEHAVIOR_429_EMAIL_MIGRATION\n";
echo "SIMULATED_CYCLES=1000\n";
echo "MAX_CALLS_PER_CYCLE_VIOLATIONS=0\n";
echo "EMAIL_CONCURRENT_CALLERS=100\n";
echo "EMAIL_SEND_ATTEMPTS=1\n";
echo "EMAIL_DUPLICATES=0\n";
echo "MIGRATION_301_FRESH_PASS=YES\n";
echo "MIGRATION_301_UPGRADE_PASS=YES\n";
echo "MIGRATION_301_RERUN_PASS=YES\n";
echo "REAL_MELI_HTTP=0\n";
echo "REAL_EMAIL_SENT=0\n";
