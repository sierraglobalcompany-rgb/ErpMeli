<?php

declare(strict_types=1);

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
if ($dsn === '') {
    fwrite(STDERR, "ERROR: ERP_MIGRATOR_TEST_DSN es obligatorio para 2.28.13.\n");
    exit(2);
}

$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$database = 'erp_cron_22813_' . bin2hex(random_bytes(5));
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-cron-22813-' . bin2hex(random_bytes(5));
$migrations = $tmp . DIRECTORY_SEPARATOR . 'migrations';
mkdir($migrations, 0770, true);

try {
    $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('CREATE TABLE api_request_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,created_at DATETIME NOT NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE system_work_queue_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,origin VARCHAR(40) NOT NULL,
        status VARCHAR(30) NOT NULL,started_at DATETIME NOT NULL,finished_at DATETIME NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE system_component_schema_contracts (
        component_key VARCHAR(100) PRIMARY KEY,required_migration VARCHAR(190) NOT NULL,
        contract_kind VARCHAR(40) NOT NULL,enabled TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE app_settings (
        setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NULL,
        is_encrypted TINYINT(1) NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE app_versions (version VARCHAR(30) PRIMARY KEY,notes VARCHAR(500) NULL) ENGINE=InnoDB');

    copy(
        dirname(__DIR__) . '/database/migrations/193_cron_api_health_adversarial_2_28_13.sql',
        $migrations . '/193_cron_api_health_adversarial_2_28_13.sql'
    );
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    App\Core\Database::setConnection($pdo);
    $migrator = new App\Services\Migrator($pdo, $migrations);
    $first = $migrator->run();
    $second = $migrator->run();
    if (($first[0]['status'] ?? '') !== 'applied' || ($second[0]['status'] ?? '') !== 'skip') {
        throw new RuntimeException('La migración 193 no fue idempotente.');
    }
    $indexes = (int) $pdo->query(
        "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME IN (
           'idx_api_request_retention_created','idx_work_runs_origin_finished','idx_work_runs_status_finished'
         )"
    )->fetchColumn();
    if ($indexes !== 3) {
        throw new RuntimeException('La migración 193 no creó los índices operativos.');
    }
    if ((string) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='cron.adversarial_contract'")->fetchColumn() !== '2.28.13') {
        throw new RuntimeException('Falta el contrato adversarial 2.28.13.');
    }
    echo "PASS cron_adversarial_22813_mysql_integration\n";
} finally {
    try { $server->exec('DROP DATABASE IF EXISTS `' . $database . '`'); } catch (Throwable) {}
    if (is_file($migrations . '/193_cron_api_health_adversarial_2_28_13.sql')) {
        @unlink($migrations . '/193_cron_api_health_adversarial_2_28_13.sql');
    }
    @rmdir($migrations);
    @rmdir($tmp);
}
