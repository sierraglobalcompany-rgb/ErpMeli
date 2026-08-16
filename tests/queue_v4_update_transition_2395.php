<?php

declare(strict_types=1);

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: transition 2395 exige un DSN MariaDB desechable sin base seleccionada.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_r2395_transition_' . bin2hex(random_bytes(5));
$private = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-r2395-' . bin2hex(random_bytes(5));
@mkdir($private . DIRECTORY_SEPARATOR . 'storage', 0770, true);
$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    define('ERP_TEST_RUNTIME', true);
    define('ERP_INSTALLATION_ROOT', $private);
    define('ERP_SHARED_ROOT', $private);
    define('ERP_RELEASE_ROOT', $root);
    putenv('ERP_PRIVATE_PATH=' . $private);
    putenv('APP_KEY=erp-r2395-code-only-local');
    putenv('ML_WRITE_ENABLED=false');
    $_ENV['APP_KEY'] = 'erp-r2395-code-only-local';
    $_ENV['ML_WRITE_ENABLED'] = 'false';
    require $root . '/bootstrap.php';

    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET SESSION time_zone='+00:00'");

    $pdo->exec('CREATE TABLE schema_migrations(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        version VARCHAR(191) NOT NULL UNIQUE
    ) ENGINE=InnoDB');
    for ($number = 1; $number <= 298; $number++) {
        $pdo->prepare('INSERT INTO schema_migrations(version) VALUES (?)')
            ->execute([sprintf('%03d_historical_schema_authority.sql', $number)]);
    }
    $pdo->prepare('INSERT INTO schema_migrations(version) VALUES (?)')
        ->execute(['299_queue_v4_domain_exact_admission_2_39_3.sql']);

    $pdo->exec('CREATE TABLE app_settings(
        setting_key VARCHAR(191) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
        setting_group VARCHAR(80) NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE app_versions(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        version VARCHAR(30) NOT NULL UNIQUE,
        notes TEXT NULL,
        installed_at DATETIME NOT NULL
    ) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO app_settings VALUES ('app.version','2.39.4',0,'system')");
    $pdo->exec("INSERT INTO app_versions(version,notes,installed_at) VALUES ('2.39.4','baseline',UTC_TIMESTAMP())");

    $definitions = [
        'queue_v4_clean_jobs' => 'id BIGINT UNSIGNED PRIMARY KEY,state VARCHAR(20),payload_json JSON NULL',
        'queue_v4_clean_attempts' => 'id BIGINT UNSIGNED PRIMARY KEY,job_id BIGINT UNSIGNED,outcome VARCHAR(30),physical_http_calls INT',
        'queue_v4_clean_runs' => 'id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(30),claimed_jobs INT',
        'queue_v4_clean_transport_events' => 'id BIGINT UNSIGNED PRIMARY KEY,source_kind VARCHAR(30),event_type VARCHAR(30),http_status INT NULL',
        'api_remote_permits' => 'id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20),endpoint_key VARCHAR(120)',
        'api_rhythm_penalties' => 'id BIGINT UNSIGNED PRIMARY KEY,scope_key VARCHAR(180),reason VARCHAR(80)',
        'api_request_logs' => 'id BIGINT UNSIGNED PRIMARY KEY,http_status INT NULL,safe_message VARCHAR(100) NULL',
        'oauth_refresh_operations' => 'id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED,state VARCHAR(30),remote_attempt_count INT',
        'order_financial_recalc_jobs' => 'id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20)',
        'sale_financial_reconciliation_jobs' => 'id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20)',
        'inventory_movements' => 'id BIGINT UNSIGNED PRIMARY KEY,movement_type VARCHAR(30)',
        'inventory_reviews' => 'id BIGINT UNSIGNED PRIMARY KEY,state VARCHAR(20)',
        'meli_notification_work_items' => 'id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20)',
        'manual_campaign_sessions' => 'id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20)',
    ];
    foreach ($definitions as $table => $columns) {
        $pdo->exec('CREATE TABLE `' . $table . '`(' . $columns . ') ENGINE=InnoDB');
    }

    $pdo->exec("INSERT INTO queue_v4_clean_jobs VALUES (1,'waiting','{\"order_id\":1001}')");
    $pdo->exec("INSERT INTO queue_v4_clean_attempts VALUES (1,1,'waiting',0)");
    $pdo->exec("INSERT INTO queue_v4_clean_runs VALUES (1,'completed',2)");
    $pdo->exec("INSERT INTO queue_v4_clean_transport_events VALUES (1,'queue_v4','response_known',200)");
    $pdo->exec("INSERT INTO api_remote_permits VALUES (1,'completed','orders_get')");
    $pdo->exec("INSERT INTO api_rhythm_penalties VALUES (1,'endpoint:shared:billing','http_429')");
    $pdo->exec("INSERT INTO api_request_logs VALUES (1,429,'persisted')");
    $pdo->exec("INSERT INTO oauth_refresh_operations VALUES (1,11,'completed',1)");
    $pdo->exec("INSERT INTO order_financial_recalc_jobs VALUES (1,'pending')");
    $pdo->exec("INSERT INTO sale_financial_reconciliation_jobs VALUES (1,'pending')");
    $pdo->exec("INSERT INTO inventory_movements VALUES (1,'sale_issue')");
    $pdo->exec("INSERT INTO inventory_reviews VALUES (1,'open')");
    $pdo->exec("INSERT INTO meli_notification_work_items VALUES (1,'pending')");
    $pdo->exec("INSERT INTO manual_campaign_sessions VALUES (6,'running')");

    $tables = array_keys($definitions);
    $snapshot = static function () use ($pdo, $tables): array {
        $state = [];
        foreach ($tables as $table) {
            $rows = $pdo->query('SELECT * FROM `' . $table . '` ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
            $state[$table] = hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        return $state;
    };
    $before = $snapshot();

    $marker = new \App\Services\InstalledVersionMarkerService();
    $assert($marker->write('2.39.4', '299_queue_v4_domain_exact_admission_2_39_3.sql'), 'baseline_marker_failed');
    $promotion = (new \App\Services\DirectUpdateMetadataPromotionService())->promote(
        $pdo,
        '2.39.5',
        '299_queue_v4_domain_exact_admission_2_39_3.sql',
        'Code-only H1 order_exact execution context.',
    );
    $after = $snapshot();
    $markerAfter = $marker->read();

    $assert((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 299, 'schema_changed');
    $assert(glob($root . '/database/migrations/300_*.sql') === [], 'migration300_present');
    $assert($after === $before, 'business_or_queue_data_changed');
    $assert($promotion['previous_version'] === '2.39.4', 'previous_version_invalid');
    $assert($promotion['target_version'] === '2.39.5', 'target_version_invalid');
    $assert((string) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn() === '2.39.5', 'app_version_not_promoted');
    $assert($markerAfter['valid'] === true && $markerAfter['version'] === '2.39.5', 'marker_not_promoted');
    $assert($markerAfter['last_migration'] === '299_queue_v4_domain_exact_admission_2_39_3.sql', 'marker_schema_drift');

    echo 'UPDATE_2394_TO_2395=PASS checks=' . $checks
        . ' schema_before=299 schema_after=299 migrations_applied=0 business_data_changed_by_updater=0'
        . ' queue_data_changed_by_updater=0 version_after=2.39.5 meli_http=0' . PHP_EOL;
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    @unlink($private . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'installed-release.json');
    @rmdir($private . DIRECTORY_SEPARATOR . 'storage');
    @rmdir($private);
}
