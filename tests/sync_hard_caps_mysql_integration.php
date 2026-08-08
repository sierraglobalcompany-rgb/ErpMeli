<?php

declare(strict_types=1);

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: ERP_MIGRATOR_TEST_DSN MySQL sin base es obligatorio.\n");
    exit(2);
}

$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$database = 'erp_sync_caps_' . bin2hex(random_bytes(5));
$server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('CREATE TABLE app_settings (
        setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NULL,
        is_encrypted TINYINT(1) NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL
    ) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO app_settings VALUES
        ('sync.max_orders_per_run','999999999',0,'sync'),
        ('sync.max_api_pages_per_run','999999999',0,'sync')");

    require_once dirname(__DIR__) . '/vendor/autoload.php';
    App\Core\Database::setConnection($pdo);
    App\Services\AppSettingsService::clearCache();
    $settings = new App\Services\SyncSettingsService();
    if ($settings->maxOrdersPerRun() !== 500 || $settings->maxApiPagesPerRun() !== 10) {
        throw new RuntimeException('Valores extremos persistidos superaron los límites duros del runtime.');
    }
    if ($settings->maxApiPagesPerRun(PHP_INT_MAX) !== 10) {
        throw new RuntimeException('Un adaptador no puede ampliar el límite central de páginas API.');
    }

    echo "PASS sync_hard_caps_mysql_integration\n";
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
