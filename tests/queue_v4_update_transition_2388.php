<?php

declare(strict_types=1);

$dsn = (string) (getenv('QUEUE_V4_CLEAN_TEST_DSN') ?: '');
if ($dsn === '') {
    fwrite(STDERR, "QUEUE_V4_CLEAN_TEST_DSN is required\n");
    exit(2);
}

$root = dirname(__DIR__);
$private = sys_get_temp_dir() . '/erp-qv4-update-2388-' . bin2hex(random_bytes(6));
mkdir($private, 0700, true);
define('ERP_TEST_RUNTIME', true);
define('ERP_INSTALLATION_ROOT', $private);
define('ERP_SHARED_ROOT', $private);
define('ERP_RELEASE_ROOT', $root);
putenv('ERP_PRIVATE_PATH=' . $private);
putenv('APP_KEY=queue-v4-update-2388-local-only');
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

$migrator = new Migrator($pdo, $root . '/database/migrations');
$assert($migrator->pendingCount() === 0, 'schema_297_not_current_before_update');
$assert((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 297,
    'schema_migration_count_not_297');
$assert(glob($root . '/database/migrations/298_*.sql') === [], 'migration_298_present');

$tables = [
    'meli_tokens', 'oauth_refresh_operations', 'queue_v4_clean_jobs', 'queue_v4_clean_attempts',
    'sync_sales_audit_runs', 'sync_sales_audit_jobs',
    'queue_v4_clean_transport_events', 'inventory_balances', 'inventory_movements',
    'inventory_reviews',
];
$counts = static function () use ($pdo, $tables): array {
    $result = [];
    foreach ($tables as $table) {
        $result[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }
    return $result;
};
$before = $counts();
$controlBefore = $pdo->query(
    "SELECT engine_state,readiness_state,scheduler_enabled,last_scheduler_at
     FROM queue_v4_clean_control WHERE control_key='primary'"
)->fetch(PDO::FETCH_ASSOC);

$pdo->prepare(
    "INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group)
     VALUES ('app.version','2.38.7',0,'system')
     ON DUPLICATE KEY UPDATE setting_value='2.38.7',is_encrypted=0"
)->execute();
$pdo->prepare(
    "INSERT INTO app_versions(version,notes,installed_at) VALUES ('2.38.7','local baseline',UTC_TIMESTAMP())
     ON DUPLICATE KEY UPDATE notes=VALUES(notes)"
)->execute();
$marker = new InstalledVersionMarkerService();
$assert($marker->write('2.38.7', '297_queue_v4_transport_sales_api_health_2_38_5.sql'),
    'baseline_marker_failed');
$assert(AppVersionService::fileVersion() === '2.38.8', 'file_version_not_2388');

$promotion = (new DirectUpdateMetadataPromotionService())->promote(
    $pdo,
    '2.38.8',
    '297_queue_v4_transport_sales_api_health_2_38_5.sql',
    'Simple Exact Sales Repair Queue V4 integration.',
);
$markerAfter = $marker->read();
$assert(($promotion['previous_version'] ?? '') === '2.38.7'
    && ($promotion['target_version'] ?? '') === '2.38.8'
    && (string) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn() === '2.38.8'
    && ($markerAfter['valid'] ?? false) === true
    && ($markerAfter['version'] ?? '') === '2.38.8', 'metadata_transition_2387_2388_failed');
$assert($counts() === $before, 'business_or_operational_rows_changed_by_metadata_update');
$controlAfter = $pdo->query(
    "SELECT engine_state,readiness_state,scheduler_enabled,last_scheduler_at
     FROM queue_v4_clean_control WHERE control_key='primary'"
)->fetch(PDO::FETCH_ASSOC);
$assert($controlAfter === $controlBefore, 'queue_v4_control_changed_by_metadata_update');
$assert((new Migrator($pdo, $root . '/database/migrations'))->pendingCount() === 0,
    'pending_migrations_after_update');

echo 'QUEUE_V4_UPDATE_TRANSITION_2388=PASS checks=' . $checks
    . ' schema=297 pending=0 new_migrations=0 meli_http=0 raw_storage_touched=false' . PHP_EOL;
