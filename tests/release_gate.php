<?php

declare(strict_types=1);

if (trim((string) getenv('ERP_MIGRATOR_TEST_DSN')) === '') {
    fwrite(
        STDERR,
        "ERROR: ERP_MIGRATOR_TEST_DSN es obligatorio. La release no puede certificarse sin MariaDB/MySQL real.\n"
    );
    exit(2);
}

putenv('ERP_RELEASE_STRICT=1');
$scripts = [
    'information_schema_collation_mysql_integration.php',
    'sales_control_2211_mysql_integration.php',
    'storage_retention_22519_mysql_integration.php',
    'cron_runtime_scope_22812_mysql_integration.php',
    'tenant_account_scope_22818_mysql_integration.php',
    'api_rhythm_mysql_integration_22815.php',
    'campaign_exact_claim_mysql_integration_22823.php',
    'api_health_incident_truth_22824_mysql_integration.php',
    'api_rhythm_profiles_mysql_integration_22831.php',
    'release_22834_migrations_mysql_integration.php',
    'sync_hard_caps_mysql_integration.php',
    'api_health_automation_performance_mysql.php',
];
foreach ($scripts as $script) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $script);
    passthru($command, $status);
    if ($status !== 0) {
        fwrite(STDERR, "ERROR: {$script} no superó la puerta de release.\n");
        exit($status);
    }
}
