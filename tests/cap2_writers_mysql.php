<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';

use App\Core\Database;
use App\Repositories\SettingsDefinitionRepository;
use App\Services\AppSettingsService;
use App\Services\CapacityPolicyService;
use App\Services\SettingsSectionService;

putenv('APP_ENV=test');
putenv('ML_WRITE_ENABLED=false');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=' . (getenv('DB_PORT') ?: '33079'));
putenv('DB_USER=root');
putenv('DB_PASS=');
putenv('DB_NAME=erp_meli_k1d_test_cap2_writers_' . bin2hex(random_bytes(4)));

$db = K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo = $db->pdo();
    $pdo->exec('CREATE TABLE app_settings (
        setting_key VARCHAR(190) PRIMARY KEY,
        setting_value TEXT NULL,
        is_encrypted TINYINT NOT NULL DEFAULT 0,
        setting_group VARCHAR(80) NOT NULL DEFAULT "general",
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NULL,
        action VARCHAR(100) NOT NULL,
        module VARCHAR(100) NOT NULL,
        entity_type VARCHAR(100) NULL,
        entity_id BIGINT NULL,
        meli_account_id BIGINT NULL,
        ip_hash CHAR(64) NOT NULL,
        before_json LONGTEXT NULL,
        after_json LONGTEXT NULL
    ) ENGINE=InnoDB');
    $_SESSION = ['user' => ['id' => 7, 'role' => 'admin', 'session_generation' => '']];

    $definitions = new SettingsDefinitionRepository();
    $section = $definitions->section('mercadolibre');
    k1b_assert(is_array($section), 'real_mercadolibre_definition_missing');
    $managed = [];
    $submitted = [];
    foreach ($section['fields'] as $field) {
        $key = (string) $field['key'];
        $recommended = $field['recommended'];
        $submitted[$key] = is_bool($recommended) ? ($recommended ? '1' : '0') : (string) $recommended;
        if (isset($field['managed_elsewhere'])) {
            $managed[] = $key;
            $submitted[$key] = 'forged-invalid-value';
        }
    }
    foreach ([
        'automation.max_api_calls_per_cycle', 'automation.api_calls_ceiling',
        'manual.api_calls_per_step', 'manual.api_calls_ceiling',
    ] as $capacityKey) {
        k1b_assert(in_array($capacityKey, $managed, true), 'capacity_definition_not_managed:' . $capacityKey);
    }

    $seed = $pdo->prepare('INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group) VALUES (?,?,0,?)');
    foreach ([
        'automation.max_api_calls_per_cycle' => '7',
        'automation.api_calls_ceiling' => '55',
        'manual.api_calls_per_step' => '8',
        'manual.api_calls_ceiling' => '55',
        'alerts.email.to' => 'preserve@example.test',
        'api.guard.enabled' => '0',
    ] as $key => $value) {
        $seed->execute([$key, $value, 'seed']);
    }
    AppSettingsService::clearCache();
    $service = new SettingsSectionService($definitions, new AppSettingsService());
    $service->save('mercadolibre', $submitted, false);
    foreach ([
        'automation.max_api_calls_per_cycle' => '7',
        'automation.api_calls_ceiling' => '55',
        'manual.api_calls_per_step' => '8',
        'manual.api_calls_ceiling' => '55',
        'alerts.email.to' => 'preserve@example.test',
    ] as $key => $expected) {
        $read = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');
        $read->execute([$key]);
        k1b_assert($read->fetchColumn() === $expected, 'forged_managed_field_was_written:' . $key);
    }

    AppSettingsService::clearCache();
    $service->save('mercadolibre', [], true);
    foreach ([
        'automation.max_api_calls_per_cycle' => '7',
        'automation.api_calls_ceiling' => '55',
        'manual.api_calls_per_step' => '8',
        'manual.api_calls_ceiling' => '55',
        'alerts.email.to' => 'preserve@example.test',
    ] as $key => $expected) {
        $read = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');
        $read->execute([$key]);
        k1b_assert($read->fetchColumn() === $expected, 'restore_overwrote_managed_field:' . $key);
    }
    k1b_assert((int) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='api.guard.enabled'")->fetchColumn() === 1, 'restore_did_not_update_regular_field');

    $policy = new CapacityPolicyService($pdo);
    $rowCountBeforeReads = (int) $pdo->query('SELECT COUNT(*) FROM app_settings')->fetchColumn();
    $policy->snapshot('automation');
    $policy->snapshot('manual');
    k1b_assert((int) $pdo->query('SELECT COUNT(*) FROM app_settings')->fetchColumn() === $rowCountBeforeReads, 'capacity_read_wrote_settings');

    $pdo->exec("DELETE FROM app_settings WHERE setting_key IN ('automation.max_api_calls_per_cycle','automation.api_calls_ceiling')");
    $seed->execute(['automation.max_api_calls_per_cycle', '60', 'seed']);
    AppSettingsService::clearCache();
    $rawLegacy = $policy->snapshot('automation');
    k1b_assert($rawLegacy['current'] === 55 && $rawLegacy['ceiling'] === 55, 'explicit_raw60_bounded_by_default_ceiling');
    $ceilingOnly = $policy->save('automation', $rawLegacy['current'], 100, $rawLegacy['revision'], static fn (): array => throw new RuntimeException('ceiling_only_must_not_gate'));
    k1b_assert($ceilingOnly['current'] === 55 && $ceilingOnly['ceiling'] === 100, 'ceiling_only_changed_current');

    foreach ([[0, 1], [1, 101], [3, 2], ['02', 55], ['2.5', 55], [true, 55]] as [$current, $ceiling]) {
        $rejected = false;
        try {
            $policy->validatePair($current, $ceiling);
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        k1b_assert($rejected, 'strict_pair_accepted:' . json_encode([$current, $ceiling]));
    }
    k1b_assert($policy->validatePair('1', '100') === ['current' => 1, 'ceiling' => 100], 'strict_pair_boundaries_rejected');

    $pdo->exec("DELETE FROM app_settings WHERE setting_key IN ('manual.api_calls_per_step','manual.api_calls_ceiling')");
    $pdo->exec("DELETE FROM app_settings WHERE setting_key IN ('api.rhythm.profile','api.rhythm.target_http_per_minute','manual_campaign.default_block_size')");
    foreach ([
        'api.rhythm.profile' => 'maximum',
        'api.rhythm.target_http_per_minute' => '40',
        'manual_campaign.default_block_size' => '30',
    ] as $key => $value) {
        $seed->execute([$key, $value, 'legacy']);
    }
    AppSettingsService::clearCache();
    $legacyManual = $policy->snapshot('manual');
    k1b_assert(($legacyManual['legacy_derived'] ?? null) === false && $legacyManual['current'] === 1, 'manual_legacy_not_capacity');
    k1b_assert(!method_exists($policy, 'requiresManualAdoptionForRhythm'), 'legacy_rhythm_adoption_hook_removed');

    $adopted = $policy->save('manual', 15, 55, $legacyManual['revision'], static fn (): array => ['allowed' => true, 'message' => '']);
    k1b_assert(($adopted['legacy_derived'] ?? null) === false, 'manual_pair_not_marked_adopted');
    $adoptedRevision = $adopted['revision'];
    $pdo->exec("UPDATE app_settings SET setting_value='conservative' WHERE setting_key='api.rhythm.profile'");
    $pdo->exec("UPDATE app_settings SET setting_value='10' WHERE setting_key='api.rhythm.target_http_per_minute'");
    AppSettingsService::clearCache();
    $afterRhythm = $policy->snapshot('manual');
    k1b_assert($afterRhythm['current'] === 15 && $afterRhythm['revision'] === $adoptedRevision, 'adopted_revision_depends_on_legacy_rhythm');
    k1b_assert(!method_exists($policy, 'requiresManualAdoptionForRhythm'), 'adopted_manual_has_no_legacy_adoption_hook');

    echo "STATUS=PASS CAP2_WRITERS_MYSQL REAL_DEFINITIONS=YES REAL_SERVICES=YES REAL_MELI_HTTP=0\n";
} finally {
    $db->cleanup();
}
