<?php

declare(strict_types=1);

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: transition 2394 exige un DSN MariaDB desechable sin base seleccionada.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_r2394_transition_' . bin2hex(random_bytes(5));
$private = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-r2394-' . bin2hex(random_bytes(5));
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
    putenv('APP_KEY=erp-r2394-code-only-local');
    putenv('ML_WRITE_ENABLED=false');
    $_ENV['APP_KEY'] = 'erp-r2394-code-only-local';
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
    $pdo->exec("INSERT INTO app_settings VALUES ('app.version','2.39.3',0,'system')");
    $pdo->exec("INSERT INTO app_versions(version,notes,installed_at) VALUES ('2.39.3','baseline',UTC_TIMESTAMP())");

    $pdo->exec("CREATE TABLE queue_v4_clean_jobs(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        job_type ENUM('fresh_orders_discovery','order_exact','domain_exact') NOT NULL,
        state VARCHAR(20) NOT NULL,
        payload_json JSON NULL
    ) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO queue_v4_clean_jobs(job_type,state,payload_json) VALUES
        ('fresh_orders_discovery','ready','{\"cursor\":null}'),
        ('order_exact','waiting','{\"order_id\":1001}'),
        ('domain_exact','review','{\"capability\":\"financial_reconciliation\",\"source_id\":77}')");
    $pdo->exec('CREATE TABLE api_request_logs(id BIGINT UNSIGNED PRIMARY KEY,http_status INT NULL,safe_message VARCHAR(100) NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO api_request_logs VALUES (1,429,'persisted')");
    $pdo->exec('CREATE TABLE api_remote_permits(id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20) NOT NULL,endpoint_key VARCHAR(120) NOT NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO api_remote_permits VALUES (1,'completed','billing_orders')");
    $pdo->exec('CREATE TABLE api_rhythm_penalties(scope_key VARCHAR(180) PRIMARY KEY,reason VARCHAR(80) NOT NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO api_rhythm_penalties VALUES ('endpoint:shared:billing','http_429')");
    $pdo->exec('CREATE TABLE order_financial_recalc_jobs(id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20) NOT NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO order_financial_recalc_jobs VALUES (1,'pending')");
    $pdo->exec('CREATE TABLE sale_financial_reconciliation_jobs(id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20) NOT NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO sale_financial_reconciliation_jobs VALUES (1,'pending')");
    $pdo->exec('CREATE TABLE inventory_movements(id BIGINT UNSIGNED PRIMARY KEY,movement_type VARCHAR(30) NOT NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO inventory_movements VALUES (1,'sale_issue')");
    $pdo->exec('CREATE TABLE inventory_reviews(id BIGINT UNSIGNED PRIMARY KEY,state VARCHAR(20) NOT NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO inventory_reviews VALUES (1,'open')");
    $pdo->exec('CREATE TABLE meli_notification_work_items(id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20) NOT NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO meli_notification_work_items VALUES (1,'pending')");
    $pdo->exec('CREATE TABLE manual_campaign_sessions(id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20) NOT NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO manual_campaign_sessions VALUES (6,'running')");

    $businessTables = [
        'queue_v4_clean_jobs',
        'api_request_logs',
        'api_remote_permits',
        'api_rhythm_penalties',
        'order_financial_recalc_jobs',
        'sale_financial_reconciliation_jobs',
        'inventory_movements',
        'inventory_reviews',
        'meli_notification_work_items',
        'manual_campaign_sessions',
    ];
    $snapshot = static function () use ($pdo, $businessTables): array {
        $state = [];
        foreach ($businessTables as $table) {
            $rows = $pdo->query('SELECT * FROM `' . $table . '` ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
            $state[$table] = hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        return $state;
    };
    $before = $snapshot();

    $marker = new \App\Services\InstalledVersionMarkerService();
    $assert($marker->write('2.39.3', '299_queue_v4_domain_exact_admission_2_39_3.sql'), 'baseline_marker_failed');
    $promotion = (new \App\Services\DirectUpdateMetadataPromotionService())->promote(
        $pdo,
        '2.39.4',
        '299_queue_v4_domain_exact_admission_2_39_3.sql',
        'Code-only F2B and B429 rhythm containment.',
    );
    $after = $snapshot();
    $markerAfter = $marker->read();

    $assert((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 299, 'schema_changed');
    $assert(glob($root . '/database/migrations/300_*.sql') === [], 'migration300_present');
    $assert($after === $before, 'business_or_queue_data_changed');
    $assert(($promotion['previous_version'] ?? '') === '2.39.3', 'previous_version_invalid');
    $assert(($promotion['target_version'] ?? '') === '2.39.4', 'target_version_invalid');
    $assert((string) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn() === '2.39.4', 'app_version_not_promoted');
    $assert(($markerAfter['valid'] ?? false) === true && ($markerAfter['version'] ?? '') === '2.39.4', 'marker_not_promoted');
    $assert(($markerAfter['last_migration'] ?? '') === '299_queue_v4_domain_exact_admission_2_39_3.sql', 'marker_schema_drift');

    echo 'UPDATE_2393_TO_2394=PASS checks=' . $checks
        . ' schema_before=299 schema_after=299 migrations_applied=0 queue_rows_changed=0'
        . ' business_data_loss=0 meli_http=0' . PHP_EOL;
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    @unlink($private . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'installed-release.json');
    @rmdir($private . DIRECTORY_SEPARATOR . 'storage');
    @rmdir($private);
}
