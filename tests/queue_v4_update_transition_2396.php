<?php

declare(strict_types=1);

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: transition 2396 exige un DSN MariaDB desechable sin base seleccionada.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_r2396_transition_' . bin2hex(random_bytes(5));
$private = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-r2396-' . bin2hex(random_bytes(5));
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
    putenv('APP_KEY=erp-r2396-code-only-local');
    putenv('ML_WRITE_ENABLED=false');
    $_ENV['APP_KEY'] = 'erp-r2396-code-only-local';
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
    $pdo->exec("INSERT INTO app_settings VALUES ('app.version','2.39.5',0,'system')");
    $pdo->exec("INSERT INTO app_versions(version,notes,installed_at) VALUES ('2.39.5','baseline',UTC_TIMESTAMP())");

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
        'sale_financial_reconciliation_jobs' => 'id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20),order_id BIGINT UNSIGNED',
        'meli_billing_capture_runs' => 'id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20),http_status INT NULL,captured_at DATETIME NULL,response_class VARCHAR(40) NULL',
        'sale_financial_state' => 'id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,state VARCHAR(20),version_no INT',
        'meli_sale_financials' => 'id BIGINT UNSIGNED PRIMARY KEY,order_id BIGINT UNSIGNED,gross_amount DECIMAL(18,2),currency_id VARCHAR(10)',
        'inventory_movements' => 'id BIGINT UNSIGNED PRIMARY KEY,movement_type VARCHAR(30)',
        'inventory_reviews' => 'id BIGINT UNSIGNED PRIMARY KEY,state VARCHAR(20)',
        'meli_notification_work_items' => 'id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20)',
        'manual_campaign_sessions' => 'id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20)',
    ];
    foreach ($definitions as $table => $columns) {
        $pdo->exec('CREATE TABLE `' . $table . '`(' . $columns . ') ENGINE=InnoDB');
    }

    $pdo->exec("INSERT INTO queue_v4_clean_jobs VALUES
        (1,'completed','{\"order_id\":1001}'),
        (2,'review','{\"order_id\":1002}')");
    $pdo->exec("INSERT INTO queue_v4_clean_attempts VALUES (1,1,'completed',1),(2,2,'review',1)");
    $pdo->exec("INSERT INTO queue_v4_clean_runs VALUES (1,'completed',2)");
    $pdo->exec("INSERT INTO queue_v4_clean_transport_events VALUES (1,'queue_v4','response_known',200)");
    $pdo->exec("INSERT INTO api_remote_permits VALUES (1,'completed','orders_get')");
    $pdo->exec("INSERT INTO api_rhythm_penalties VALUES (1,'endpoint:shared:billing','http_429')");
    $pdo->exec("INSERT INTO api_request_logs VALUES (1,429,'persisted')");
    $pdo->exec("INSERT INTO oauth_refresh_operations VALUES (1,11,'completed',1)");
    $pdo->exec("INSERT INTO order_financial_recalc_jobs VALUES (1,'pending')");
    $pdo->exec("INSERT INTO sale_financial_reconciliation_jobs VALUES (1,'complete',1001),(2,'review',1002)");
    $pdo->exec("INSERT INTO meli_billing_capture_runs VALUES (1,'complete',200,UTC_TIMESTAMP(),'known')");
    $pdo->exec("INSERT INTO sale_financial_state VALUES (1,1001,'complete',7),(2,1002,'review',3)");
    $pdo->exec("INSERT INTO meli_sale_financials VALUES (1,1001,125000.50,'COP')");
    $pdo->exec("INSERT INTO inventory_movements VALUES (1,'sale_issue')");
    $pdo->exec("INSERT INTO inventory_reviews VALUES (1,'open')");
    $pdo->exec("INSERT INTO meli_notification_work_items VALUES (1,'pending')");
    $pdo->exec("INSERT INTO manual_campaign_sessions VALUES (6,'running')");

    $groups = [
        'queue' => ['queue_v4_clean_jobs', 'queue_v4_clean_attempts', 'queue_v4_clean_runs', 'queue_v4_clean_transport_events'],
        'business' => array_keys($definitions),
        'finance' => ['order_financial_recalc_jobs', 'sale_financial_reconciliation_jobs', 'meli_billing_capture_runs', 'sale_financial_state', 'meli_sale_financials'],
        'oauth' => ['oauth_refresh_operations'],
        'inventory' => ['inventory_movements', 'inventory_reviews'],
        'notification' => ['meli_notification_work_items', 'manual_campaign_sessions'],
    ];
    $snapshot = static function (array $tables) use ($pdo): string {
        $state = [];
        foreach ($tables as $table) {
            $state[$table] = $pdo->query('SELECT * FROM `' . $table . '` ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        }
        return hash('sha256', json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    };
    $before = [];
    foreach ($groups as $group => $tables) {
        $before[$group] = $snapshot($tables);
    }

    $marker = new \App\Services\InstalledVersionMarkerService();
    $assert($marker->write('2.39.5', '299_queue_v4_domain_exact_admission_2_39_3.sql'), 'baseline_marker_failed');
    $promotion = (new \App\Services\DirectUpdateMetadataPromotionService())->promote(
        $pdo,
        '2.39.6',
        '299_queue_v4_domain_exact_admission_2_39_3.sql',
        'Code-only H2 billing terminal HY093 parameter binding.',
    );

    $after = [];
    foreach ($groups as $group => $tables) {
        $after[$group] = $snapshot($tables);
    }
    $markerAfter = $marker->read();

    $assert((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 299, 'schema_changed');
    $assert(glob($root . '/database/migrations/300_*.sql') === [], 'migration300_present');
    foreach (array_keys($groups) as $group) {
        $assert(hash_equals($before[$group], $after[$group]), $group . '_data_changed');
    }
    $assert((string) $pdo->query('SELECT status FROM sale_financial_reconciliation_jobs WHERE id=1')->fetchColumn() === 'complete', 'source_complete_reactivated');
    $assert((string) $pdo->query('SELECT status FROM sale_financial_reconciliation_jobs WHERE id=2')->fetchColumn() === 'review', 'source_review_reactivated');
    $assert((string) $pdo->query('SELECT state FROM queue_v4_clean_jobs WHERE id=1')->fetchColumn() === 'completed', 'pointer_completed_reactivated');
    $assert((string) $pdo->query('SELECT state FROM queue_v4_clean_jobs WHERE id=2')->fetchColumn() === 'review', 'pointer_review_reactivated');
    $assert($promotion['previous_version'] === '2.39.5', 'previous_version_invalid');
    $assert($promotion['target_version'] === '2.39.6', 'target_version_invalid');
    $assert((string) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn() === '2.39.6', 'app_version_not_promoted');
    $assert($markerAfter['valid'] === true && $markerAfter['version'] === '2.39.6', 'marker_not_promoted');
    $assert($markerAfter['last_migration'] === '299_queue_v4_domain_exact_admission_2_39_3.sql', 'marker_schema_drift');

    echo 'UPDATE_2395_TO_2396=PASS checks=' . $checks
        . ' schema_before=299 schema_after=299 migrations_applied=0'
        . ' business_data_preserved=yes queue_data_preserved=yes finance_data_preserved=yes'
        . ' oauth_data_preserved=yes inventory_data_preserved=yes notification_data_preserved=yes'
        . ' terminal_state_preservation=pass version_after=2.39.6 meli_http=0' . PHP_EOL;
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    @unlink($private . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'installed-release.json');
    @rmdir($private . DIRECTORY_SEPARATOR . 'storage');
    @rmdir($private);
}
