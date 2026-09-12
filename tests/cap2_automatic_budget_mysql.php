<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';

use App\Services\AppSettingsService;
use App\Services\AutomationCallBudgetService;
use App\Services\AutomationCliCapacityArgumentParser;
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
    $save = static function (int $current, int $ceiling) use ($policy): void {
        $before = $policy->snapshot('automation');
        $policy->save('automation', $current, $ceiling, $before['revision']);
        AppSettingsService::clearCache();
    };
    $budget = new AutomationCallBudgetService();

    $default = $budget->resolve();
    k1b_assert($default['max_calls'] === 1, 'no_argument_uses_default_current');
    k1b_assert(($default['requested_max_calls'] ?? null) === 1, 'no_argument_preserves_current_as_request');

    $save(1, 100);
    $ceilingOnly = $budget->resolve();
    k1b_assert($ceilingOnly['max_calls'] === 1, 'ceiling_only_does_not_raise_current');

    foreach ([1, 2, 3, 5, 9, 10, 15, 50, 55, 100] as $current) {
        $save($current, max(55, $current));
        $resolved = $budget->resolve();
        k1b_assert($resolved['max_calls'] === $current, 'no_argument_boundary_' . $current);
        k1b_assert($resolved['requested_max_calls'] === $current, 'request_boundary_' . $current);
    }

    $save(1, 55);
    $canonicalLegacySixty = $budget->resolve(60);
    k1b_assert($canonicalLegacySixty['requested_max_calls'] === 60 && $canonicalLegacySixty['max_calls'] === 1,
        'canonical_legacy_override_cannot_raise_saved_one');

    $save(15, 55);
    k1b_assert($budget->resolve(60)['max_calls'] === 15, 'canonical_legacy_override_cannot_raise_saved_fifteen');
    k1b_assert($budget->resolve(2)['max_calls'] === 2, 'canonical_override_can_reduce_saved_current');

    $parser = new AutomationCliCapacityArgumentParser();
    k1b_assert($parser->parse(['max-calls' => '3']) === ['max_calls' => 3], 'cli_max_calls_supported');
    $legacyRejected = false;
    try {
        $parser->parse(['max-jobs' => '3']);
    } catch (\InvalidArgumentException $error) {
        $legacyRejected = $error->getMessage() === 'legacy_capacity_argument_removed';
    }
    k1b_assert($legacyRejected, 'cli_max_jobs_rejected');

    $save(3, 100);
    $three = $budget->resolve(50);
    k1b_assert($three['requested_max_calls'] === 50, 'requested_value_is_not_destroyed_by_resolution');
    k1b_assert($three['configured_max_calls'] === 3 && $three['ceiling'] === 100 && $three['max_calls'] === 3,
        'erp_current_wins_over_cli_fifty');

    echo "STATUS=PASS CAP2_AUTOMATIC_BUDGET_MYSQL\nREAL_MELI_HTTP=0\nREAL_EMAIL_SENT=0\n";
    echo "CLI_MAX_JOBS_ACCEPTED=NO\nCLI_MAX_CALLS_CAN_ELEVATE_ERP=NO\n";
} finally {
    AppSettingsService::clearCache();
    $harness->cleanup();
}
