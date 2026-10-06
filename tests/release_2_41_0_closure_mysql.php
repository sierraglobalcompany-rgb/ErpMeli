<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';

use App\Core\Database;
use App\Services\ManagedRuntimePublicationPolicy;
use App\Services\Migrator;
use App\Services\ReleaseIntegrityService;

function release2410Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL:' . $message);
    }
    echo 'PASS:' . $message . PHP_EOL;
}

function release2410Configure(string $dbName, string $qaRoot): void
{
    putenv('APP_ENV=test');
    putenv('ML_WRITE_ENABLED=false');
    putenv('DB_HOST=127.0.0.1');
    putenv('DB_PORT=' . (getenv('DB_PORT') ?: '3306'));
    putenv('DB_USER=' . (getenv('DB_USER') ?: 'root'));
    putenv('DB_PASS=' . (string) getenv('DB_PASS'));
    putenv('DB_NAME=' . $dbName);
    putenv('CALLS_VERIFY_QA_ROOT=' . $qaRoot);
}

function release2410AssertCaptureSchema(PDO $pdo): void
{
    $required = [
        'financial_job_id', 'financial_claim_generation', 'external_order_id', 'billing_resource_key',
        'request_id', 'dispatch_state', 'dispatch_committed_at', 'result_durable_at', 'aborted_at',
        'response_body_raw', 'unresolved_guard',
    ];
    $column = $pdo->prepare(
        'SELECT COLUMN_NAME, EXTRA, GENERATION_EXPRESSION
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="meli_billing_capture_runs" AND COLUMN_NAME=?'
    );
    $generated = null;
    foreach ($required as $name) {
        $column->execute([$name]);
        $row = $column->fetch(PDO::FETCH_ASSOC);
        release2410Assert(is_array($row), 'migration302_column_' . $name);
        if ($name === 'unresolved_guard') {
            $generated = $row;
        }
    }
    release2410Assert(
        is_array($generated)
            && stripos((string) ($generated['EXTRA'] ?? ''), 'GENERATED') !== false
            && str_contains(strtolower((string) ($generated['GENERATION_EXPRESSION'] ?? '')), 'dispatch_state'),
        'migration302_unresolved_guard_is_generated_from_dispatch_state'
    );

    foreach (['uq_billing_capture_unresolved_order', 'uq_billing_capture_request_id'] as $indexName) {
        $index = $pdo->prepare(
            'SELECT MIN(NON_UNIQUE) AS non_unique, COUNT(*) AS columns_count
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="meli_billing_capture_runs" AND INDEX_NAME=?'
        );
        $index->execute([$indexName]);
        $row = $index->fetch(PDO::FETCH_ASSOC) ?: [];
        release2410Assert((int) ($row['non_unique'] ?? 1) === 0 && (int) ($row['columns_count'] ?? 0) > 0,
            'migration302_unique_index_' . $indexName);
    }
}

function release2410LatestMigration(PDO $pdo): string
{
    return (string) $pdo->query('SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1')->fetchColumn();
}

function release2410SetAppVersion(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
         VALUES ("app.version","2.41.0",0,"system")
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group)'
    );
    $stmt->execute();
}

function release2410FreshInstall(string $qaRoot, string $migrationPath, string $repoRoot): void
{
    release2410Configure('erp_meli_k1d_test_release2410_fresh_' . bin2hex(random_bytes(4)), $qaRoot);
    $harness = K1dSafeTestDatabase::createFromEnvironment();
    try {
        $pdo = $harness->pdo();
        (new Migrator($pdo, $migrationPath))->run();
        release2410Assert(release2410LatestMigration($pdo) === '302_financial_v2_billing_capture_authority.sql', 'fresh_schema_authority_is_302');
        release2410Assert((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 302, 'fresh_install_applied_302_migrations');
        release2410AssertCaptureSchema($pdo);
        release2410SetAppVersion($pdo);
        $integrity = (new ReleaseIntegrityService())->inspectDirectory($repoRoot, true, false);
        release2410Assert(($integrity['ok'] ?? false) === true, 'fresh_install_release_integrity_2_41_0');
    } finally {
        $harness->cleanup();
    }
}

function release2410Upgrade301To302(string $qaRoot, string $migrationPath, string $repoRoot): void
{
    release2410Configure('erp_meli_k1d_test_release2410_upgrade_' . bin2hex(random_bytes(4)), $qaRoot);
    $harness = K1dSafeTestDatabase::createFromEnvironment();
    try {
        $pdo = $harness->pdo();
        $migrator = new Migrator($pdo, $migrationPath);
        $migrator->run(301);
        release2410Assert(release2410LatestMigration($pdo) === '301_k1d_api_safety_2_40_1.sql', 'upgrade_baseline_is_schema301');

        $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9001,'Release 2.41.0 upgrade fixture',1)");
        $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9011,9001,'Release fixture',99011,'conectado')");
        $pdo->prepare(
            'INSERT INTO meli_billing_capture_runs
                (company_id,meli_account_id,sale_key,external_sale_id,source_mode,http_status,response_class,
                 requested_order_ids_json,response_hash,missing_fields_json,safe_message,captured_at)
             VALUES (9001,9011,"O:RELEASE2410","RELEASE2410","daily_capture",200,"complete",
                     ?,?,"[]","pre-302 preserved fixture",UTC_TIMESTAMP())'
        )->execute(['["RELEASE2410"]', hash('sha256', 'preserved pre-302 capture')]);
        $captureId = (int) $pdo->lastInsertId();
        $before = $pdo->prepare('SELECT external_sale_id,response_hash,safe_message FROM meli_billing_capture_runs WHERE id=?');
        $before->execute([$captureId]);
        $beforeRow = $before->fetch(PDO::FETCH_ASSOC) ?: [];

        $results = $migrator->run();
        $applied = count(array_filter($results, static fn (array $result): bool => ($result['status'] ?? '') === 'applied'));
        release2410Assert($applied === 1, 'upgrade_applied_only_migration302');
        release2410Assert(release2410LatestMigration($pdo) === '302_financial_v2_billing_capture_authority.sql', 'upgrade_schema_authority_is_302');
        release2410Assert((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 302, 'upgrade_schema_migration_count_is_302');
        release2410AssertCaptureSchema($pdo);

        $after = $pdo->prepare(
            'SELECT external_sale_id,response_hash,safe_message,financial_job_id,request_id,dispatch_state
             FROM meli_billing_capture_runs WHERE id=?'
        );
        $after->execute([$captureId]);
        $afterRow = $after->fetch(PDO::FETCH_ASSOC) ?: [];
        release2410Assert(
            ($afterRow['external_sale_id'] ?? null) === ($beforeRow['external_sale_id'] ?? null)
                && ($afterRow['response_hash'] ?? null) === ($beforeRow['response_hash'] ?? null)
                && ($afterRow['safe_message'] ?? null) === ($beforeRow['safe_message'] ?? null)
                && ($afterRow['financial_job_id'] ?? null) === null
                && ($afterRow['request_id'] ?? null) === null
                && ($afterRow['dispatch_state'] ?? null) === null,
            'upgrade_preserves_preexisting_billing_capture_data'
        );
        release2410SetAppVersion($pdo);
        $integrity = (new ReleaseIntegrityService())->inspectDirectory($repoRoot, true, false);
        release2410Assert(($integrity['ok'] ?? false) === true, 'upgrade_release_integrity_2_41_0');
    } finally {
        $harness->cleanup();
    }
}

$root = realpath(__DIR__ . '/..');
$qaRoot = str_replace('\\', '/', (string) getenv('CALLS_VERIFY_QA_ROOT'));
if (!is_string($root) || $qaRoot === '') {
    throw new RuntimeException('EXPLICIT_RELEASE_CLOSURE_ROOTS_REQUIRED');
}

$oldProfile = [
    'version' => '2.40.1',
    'build_id' => 'erp-meli-2.40.1-worker-cycle-control-single-truth-rc1-20260827',
    'minimum_migration' => '301_k1d_api_safety_2_40_1.sql',
];
$currentManifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true, 64, JSON_THROW_ON_ERROR);
release2410Assert(ManagedRuntimePublicationPolicy::recognizesInstalledManifest($oldProfile), 'previous_2_40_1_profile_remains_supported');
release2410Assert(ManagedRuntimePublicationPolicy::recognizesInstalledManifest($currentManifest), 'current_2_41_0_profile_is_registered');
release2410Assert((string) ($currentManifest['minimum_migration'] ?? '') === '302_financial_v2_billing_capture_authority.sql', 'runtime_manifest_minimum_migration_is_302');

$migrationPath = $root . '/database/migrations';
release2410FreshInstall($qaRoot, $migrationPath, $root);
release2410Upgrade301To302($qaRoot, $migrationPath, $root);

echo "FRESH_INSTALL_TO_302=PASS\n";
echo "UPGRADE_301_TO_302=PASS\n";
echo "LATEST_MIGRATION=302\n";
echo "REAL_MELI_HTTP=0\n";
echo "REAL_OAUTH=0\n";
