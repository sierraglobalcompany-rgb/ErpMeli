<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';

use App\Services\AppSettingsService;
use App\Services\AutomationCallBudgetService;
use App\Services\CapacityPolicyService;

putenv('APP_ENV=test');
putenv('ML_WRITE_ENABLED=false');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=33079');
putenv('DB_USER=root');
putenv('DB_PASS=');
putenv('DB_NAME=erp_meli_k1d_test_cap2_auto_budget_' . bin2hex(random_bytes(4)));

$harness = K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo = $harness->pdo();
    $pdo->exec('CREATE TABLE app_settings (
        setting_key VARCHAR(190) PRIMARY KEY,
        setting_value TEXT NULL,
        is_encrypted TINYINT NOT NULL DEFAULT 0,
        setting_group VARCHAR(80) NOT NULL DEFAULT "general",
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');
    AppSettingsService::clearCache();

    $policy = new CapacityPolicyService($pdo);
    $allow = static fn (): array => ['allowed' => true, 'message' => ''];
    $save = static function (int $current, int $ceiling) use ($policy, $allow): void {
        $before = $policy->snapshot('automation');
        $policy->save('automation', $current, $ceiling, $before['revision'], $allow);
        AppSettingsService::clearCache();
    };
    $budget = new AutomationCallBudgetService();

    $default = $budget->resolve();
    k1b_assert($default['max_calls'] === 1, 'no_argument_uses_default_current');
    k1b_assert(($default['requested_max_calls'] ?? null) === 1, 'no_argument_preserves_current_as_request');

    $save(1, 100);
    $ceilingOnly = $budget->resolve();
    k1b_assert($ceilingOnly['max_calls'] === 1, 'ceiling_only_does_not_raise_current');

    foreach ([1, 2, 3, 15, 55, 100] as $current) {
        $save($current, max(55, $current));
        $resolved = $budget->resolve();
        k1b_assert($resolved['max_calls'] === $current, 'no_argument_boundary_' . $current);
        k1b_assert($resolved['requested_max_calls'] === $current, 'request_boundary_' . $current);
    }

    $save(1, 55);
    $canonicalLegacySixty = $budget->resolve(60);
    $aliasLegacyHundred = $budget->resolve(null, 100);
    k1b_assert($canonicalLegacySixty['requested_max_calls'] === 60 && $canonicalLegacySixty['max_calls'] === 1,
        'canonical_legacy_override_cannot_raise_saved_one');
    k1b_assert($aliasLegacyHundred['requested_max_calls'] === 100 && $aliasLegacyHundred['max_calls'] === 1,
        'legacy_alias_cannot_raise_saved_one');

    $save(15, 55);
    k1b_assert($budget->resolve(60)['max_calls'] === 15, 'canonical_legacy_override_cannot_raise_saved_fifteen');
    k1b_assert($budget->resolve(null, 100)['max_calls'] === 15, 'legacy_alias_cannot_raise_saved_fifteen');
    k1b_assert($budget->resolve(2)['max_calls'] === 2, 'canonical_override_can_reduce_saved_current');
    k1b_assert($budget->resolve(null, 3)['max_calls'] === 3, 'legacy_alias_can_reduce_saved_current');

    $save(3, 100);
    $three = $budget->resolve(50);
    k1b_assert($three['requested_max_calls'] === 50, 'requested_value_is_not_destroyed_by_resolution');
    k1b_assert($three['configured_max_calls'] === 3 && $three['ceiling'] === 100 && $three['max_calls'] === 3,
        'erp_current_wins_over_cli_fifty');

    echo "STATUS=PASS CAP2_AUTOMATIC_BUDGET_MYSQL\nREAL_MELI_HTTP=0\nREAL_EMAIL_SENT=0\n";
} finally {
    AppSettingsService::clearCache();
    $harness->cleanup();
}
