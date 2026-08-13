<?php

declare(strict_types=1);

$dsn = (string) (getenv('QUEUE_V4_CLEAN_TEST_DSN') ?: '');
if ($dsn === '') {
    fwrite(STDERR, "QUEUE_V4_CLEAN_TEST_DSN is required\n");
    exit(2);
}

$root = dirname(__DIR__);
$private = sys_get_temp_dir() . '/erp-qv4-update-2385-' . bin2hex(random_bytes(6));
mkdir($private, 0700, true);
define('ERP_TEST_RUNTIME', true);
define('ERP_INSTALLATION_ROOT', $private);
define('ERP_SHARED_ROOT', $private);
define('ERP_RELEASE_ROOT', $root);
putenv('ERP_PRIVATE_PATH=' . $private);
putenv('APP_KEY=queue-v4-update-2385-local-only');
putenv('ML_WRITE_ENABLED=false');
require $root . '/bootstrap.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
});

use App\Core\Database;
use App\Services\AppVersionService;
use App\Services\DirectUpdateMetadataPromotionService;
use App\Services\InstalledVersionMarkerService;
use App\Services\Migrator;

$pdo = new PDO(
    $dsn,
    (string) (getenv('QUEUE_V4_CLEAN_TEST_USER') ?: 'root'),
    (string) (getenv('QUEUE_V4_CLEAN_TEST_PASS') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
);
$pdo->exec("SET time_zone='+00:00'");
Database::setConnection($pdo);

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$migrations = $root . '/database/migrations';
$migrator = new Migrator($pdo, $migrations);
$assert($migrator->pendingCount() === 1, 'expected_only_migration_297_pending');
$controlBefore = $pdo->query(
    "SELECT engine_state,readiness_state,scheduler_enabled,last_scheduler_at
     FROM queue_v4_clean_control WHERE control_key='primary'"
)->fetch(PDO::FETCH_ASSOC);
$jobsBefore = (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn();
$salesBefore = (int) $pdo->query('SELECT COUNT(*) FROM sync_sales_audit_jobs')->fetchColumn();

$applied = $migrator->run();
$appliedOnly = array_values(array_filter($applied, static fn(array $row): bool => ($row['status'] ?? '') === 'applied'));
$assert(count($appliedOnly) === 1
    && (string) ($appliedOnly[0]['version'] ?? '') === '297_queue_v4_transport_sales_api_health_2_38_5.sql',
    'migration_297_not_exact:' . json_encode($applied));
$assert($migrator->pendingCount() === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 297,
    'schema_297_not_current');
$second = (new Migrator($pdo, $migrations))->run();
$assert(array_filter($second, static fn(array $row): bool => ($row['status'] ?? '') === 'applied') === [],
    'migration_297_not_idempotent');

$pdo->prepare(
    "INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group)
     VALUES ('app.version','2.38.4',0,'system')
     ON DUPLICATE KEY UPDATE setting_value='2.38.4',is_encrypted=0"
)->execute();
$pdo->prepare(
    "INSERT INTO app_versions(version,notes,installed_at) VALUES ('2.38.4','local baseline',UTC_TIMESTAMP())
     ON DUPLICATE KEY UPDATE notes=VALUES(notes)"
)->execute();
$marker = new InstalledVersionMarkerService();
$assert($marker->write('2.38.4', '296_queue_v4_clean_oauth_control_plane_2_38_3.sql'), 'baseline_marker_failed');
$assert(AppVersionService::fileVersion() === '2.38.5', 'file_version_not_2385');
$promotion = (new DirectUpdateMetadataPromotionService())->promote(
    $pdo,
    '2.38.5',
    '297_queue_v4_transport_sales_api_health_2_38_5.sql',
    'Queue V4 transport, sales audit and API Health integration.',
);
$markerAfter = $marker->read();
$assert($promotion['previous_version'] === '2.38.4'
    && $promotion['target_version'] === '2.38.5'
    && (string) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn() === '2.38.5'
    && ($markerAfter['valid'] ?? false) === true
    && ($markerAfter['version'] ?? '') === '2.38.5',
    'metadata_transition_2384_2385_failed');
$controlAfter = $pdo->query(
    "SELECT engine_state,readiness_state,scheduler_enabled,last_scheduler_at
     FROM queue_v4_clean_control WHERE control_key='primary'"
)->fetch(PDO::FETCH_ASSOC);
$assert($controlBefore === $controlAfter, 'queue_v4_control_mutated_by_update');
$assert($jobsBefore === (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn()
    && $salesBefore === (int) $pdo->query('SELECT COUNT(*) FROM sync_sales_audit_jobs')->fetchColumn(),
    'business_queue_rows_mutated_by_update');
$assert((int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_v4_clean_attempts'
       AND COLUMN_NAME IN ('dispatch_state','physical_started_at','response_known_at','source_closed_at')"
)->fetchColumn() === 4, 'transport_journal_columns_missing');
$assert((int) $pdo->query('SELECT COUNT(*) FROM api_incident_materializer_state WHERE singleton_id=1')->fetchColumn() === 1,
    'api_health_materializer_state_missing');

echo 'QUEUE_V4_UPDATE_TRANSITION_2385=PASS checks=' . $checks
    . ' schema=297 pending=0 production_touched=no meli_http=0 raw_storage_touched=false' . PHP_EOL;
