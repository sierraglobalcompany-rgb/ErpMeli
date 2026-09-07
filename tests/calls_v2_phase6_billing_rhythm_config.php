<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';

use App\Services\ApiRhythmPolicyService;
use App\Services\AppSettingsService;
use App\Services\SettingsSectionService;
use App\Repositories\SettingsDefinitionRepository;

putenv('APP_ENV=test');
putenv('ML_WRITE_ENABLED=false');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=' . (getenv('DB_PORT') ?: '33079'));
putenv('DB_USER=root');
putenv('DB_PASS=');
putenv('DB_NAME=erp_meli_k1d_test_calls_phase6_' . bin2hex(random_bytes(4)));

$harness = K1dSafeTestDatabase::createFromEnvironment();
$failures = [];
$assert = static function (bool $ok, string $label) use (&$failures): void {
    if ($ok) {
        echo 'PASS:' . $label . PHP_EOL;
        return;
    }
    $failures[] = $label;
    echo 'FAIL:' . $label . PHP_EOL;
};

try {
    $pdo = $harness->pdo();
    $pdo->exec('CREATE TABLE app_settings (setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NULL,is_encrypted TINYINT NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL DEFAULT "general",updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE audit_logs(id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id BIGINT NULL,action VARCHAR(100),module VARCHAR(100),entity_type VARCHAR(100),entity_id BIGINT NULL,meli_account_id BIGINT NULL,ip_hash CHAR(64),before_json LONGTEXT NULL,after_json LONGTEXT NULL) ENGINE=InnoDB');
    $settings = new AppSettingsService();

    $assert(ApiRhythmPolicyService::billingPacingDiagnosticPolicy()['interval_seconds'] === 300, 'missing_setting_defaults_to_300_seconds');
    foreach ([300, 60, 10, 2, 1, 3600] as $seconds) {
        $settings->set('api.rhythm.billing_min_interval_seconds', (string) $seconds, 'api_rhythm');
        AppSettingsService::clearCache();
        $policy = ApiRhythmPolicyService::billingPacingDiagnosticPolicy();
        $assert($policy['interval_seconds'] === $seconds, 'diagnostic_uses_configured_billing_interval_' . $seconds);
        $assert($policy['label'] === 'Ritmo Billing: 1 llamada cada ' . $seconds . ' segundos', 'diagnostic_label_' . $seconds);
    }

    foreach (['0', '3601', '-1', '2.5', '1e1', 'abc', '', ' 2', '02'] as $bad) {
        $settings->set('api.rhythm.billing_min_interval_seconds', $bad, 'api_rhythm');
        AppSettingsService::clearCache();
        $policy = ApiRhythmPolicyService::billingPacingDiagnosticPolicy();
        $assert($policy['interval_seconds'] === 300, 'invalid_billing_interval_falls_back_to_safe_default_' . str_replace([' ', '.'], ['space', 'dot'], $bad));
    }

    $section = (new SettingsDefinitionRepository())->section('mercadolibre');
    $field = null;
    foreach ($section['fields'] ?? [] as $candidate) {
        if (($candidate['key'] ?? '') === 'api.rhythm.billing_min_interval_seconds') {
            $field = $candidate;
            break;
        }
    }
    $assert(is_array($field), 'billing_interval_field_declared_in_mercadolibre_settings');
    if (is_array($field)) {
        $assert(($field['recommended'] ?? null) === 300, 'billing_interval_field_default_300');
        $assert(($field['min'] ?? null) === 1 && ($field['max'] ?? null) === 3600, 'billing_interval_field_range_1_3600');
        $assert(($field['unit'] ?? null) === 'seg', 'billing_interval_field_unit_seconds');
        $assert(str_contains((string) ($field['label'] ?? ''), 'Ritmo Billing'), 'billing_interval_field_label_clear');
        $assert(isset($field['managed_elsewhere']), 'billing_interval_field_has_single_writer');
    }

    $submitted = [];
    foreach ($section['fields'] ?? [] as $candidate) {
        if (isset($candidate['managed_elsewhere'])) {
            continue;
        }
        $key = (string) ($candidate['key'] ?? '');
        if ($key !== '') {
            $submitted[$key] = $candidate['recommended'] ?? '';
        }
    }
    $writer = new SettingsSectionService();
    $settings->set('api.rhythm.billing_min_interval_seconds', '300', 'api_rhythm');
    AppSettingsService::clearCache();
    $submitted['api.rhythm.billing_min_interval_seconds'] = '60';
    $saved = $writer->save('mercadolibre', $submitted);
    $assert(($saved['api.rhythm.billing_min_interval_seconds'] ?? null) === '300', 'generic_settings_writer_preserves_managed_billing_interval');
    $assert((new ApiRhythmPolicyService())->billingMinIntervalSeconds() === 300, 'generic_settings_writer_does_not_persist_billing_interval');
    foreach (['0', '3601', '-1', '2.5', '1e1', 'abc', '', ' 2', '02'] as $bad) {
        $submitted['api.rhythm.billing_min_interval_seconds'] = $bad;
        $writer->save('mercadolibre', $submitted);
        $assert((new ApiRhythmPolicyService())->billingMinIntervalSeconds() === 300, 'generic_settings_writer_ignores_invalid_managed_billing_interval_' . str_replace([' ', '.'], ['space', 'dot'], (string) $bad));
    }

    $controller = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/SettingsController.php');
    $assert(str_contains($controller, 'billing_min_interval_seconds'), 'cron_rhythm_writer_handles_billing_interval');
    $assert(str_contains($controller, 'normalizeBillingMinIntervalSeconds'), 'cron_rhythm_writer_reuses_policy_validation');
    $assert(str_contains($controller, '$billingInterval < $previousBillingInterval'), 'cron_rhythm_writer_gates_more_aggressive_billing_interval');
} finally {
    $harness->cleanup();
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL CALLS_V2_PHASE6_BILLING_RHYTHM_CONFIG ' . implode(',', $failures) . PHP_EOL);
    exit(1);
}

echo 'STATUS=PASS CALLS_V2_PHASE6_BILLING_RHYTHM_CONFIG' . PHP_EOL;
