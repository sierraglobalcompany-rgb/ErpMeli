<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';

use App\Services\ApiRhythmPolicyService;
use App\Services\AppSettingsService;
use App\Services\CapacityPolicyService;

putenv('APP_ENV=test');
putenv('ML_WRITE_ENABLED=false');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=' . (getenv('DB_PORT') ?: '33079'));
putenv('DB_USER=root');
putenv('DB_PASS=');
putenv('DB_NAME=erp_meli_k1d_test_phase6_rhythm_' . bin2hex(random_bytes(4)));

$assert = static function (bool $condition, string $label, array $context = []): void {
    if (!$condition) {
        throw new RuntimeException('FAIL:' . $label . ':' . json_encode($context, JSON_UNESCAPED_SLASHES));
    }
    echo 'PASS:' . $label . PHP_EOL;
};

$db = K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo = $db->pdo();
    $pdo->exec('CREATE TABLE app_settings (setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NULL,is_encrypted TINYINT NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL DEFAULT "general",updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB');
    AppSettingsService::clearCache();

    $policy = new ApiRhythmPolicyService();
    $capacity = new CapacityPolicyService($pdo);

    $assert($policy->billingMinIntervalSeconds() === 300, 'missing_billing_interval_defaults_to_300');
    $assert(ApiRhythmPolicyService::billingPacingDiagnosticPolicy()['interval_seconds'] === 300, 'diagnostic_uses_default_billing_interval');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM app_settings')->fetchColumn() === 0, 'billing_interval_read_does_not_insert_setting');

    $beforeAuto = $capacity->snapshot('automation');
    $beforeManual = $capacity->snapshot('manual');
    $settings = new AppSettingsService();
    foreach ([300, 60, 10, 2, 1, 3600] as $seconds) {
        $settings->set('api.rhythm.billing_min_interval_seconds', ApiRhythmPolicyService::normalizeBillingMinIntervalSeconds((string) $seconds), 'api_rhythm');
        AppSettingsService::clearCache();
        $reloaded = (new ApiRhythmPolicyService())->billingMinIntervalSeconds();
        $diagnostic = ApiRhythmPolicyService::billingPacingDiagnosticPolicy();
        $assert($reloaded === $seconds, 'valid_billing_interval_persisted_' . $seconds, ['actual' => $reloaded]);
        $assert((int) $diagnostic['interval_seconds'] === $seconds, 'diagnostic_reads_persisted_billing_interval_' . $seconds);
        $assert($capacity->snapshot('automation') === $beforeAuto, 'billing_interval_no_auto_capacity_write_' . $seconds);
        $assert($capacity->snapshot('manual') === $beforeManual, 'billing_interval_no_manual_capacity_write_' . $seconds);
    }

    foreach ([0, 3601, -1, '2.5', '1e1', ' 2', '02', '', [], true, null] as $bad) {
        $rejected = false;
        try {
            ApiRhythmPolicyService::normalizeBillingMinIntervalSeconds($bad);
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        $assert($rejected, 'invalid_billing_interval_rejected_' . json_encode($bad));
    }

    $settings->set('api.rhythm.billing_min_interval_seconds', 'bad', 'api_rhythm');
    AppSettingsService::clearCache();
    $assert((new ApiRhythmPolicyService())->billingMinIntervalSeconds() === 300, 'invalid_stored_interval_falls_back_to_300');

    echo "STATUS=PASS CALLS_V2_PHASE6_BILLING_RHYTHM_POLICY\n";
} finally {
    $db->cleanup();
}
