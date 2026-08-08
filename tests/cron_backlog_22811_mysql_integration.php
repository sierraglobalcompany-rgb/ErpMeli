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
$database = 'erp_cron_truth_' . bin2hex(random_bytes(5));
$server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('CREATE TABLE cron_task_state (
        task_key VARCHAR(100) PRIMARY KEY, oldest_due_at DATETIME NULL,
        last_processed INT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE system_work_queue_run_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        selection_reason VARCHAR(120) NULL,
        deferred_count INT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE system_component_schema_contracts (
        component_key VARCHAR(100) PRIMARY KEY, required_migration VARCHAR(255) NOT NULL,
        contract_kind VARCHAR(30) NOT NULL, enabled TINYINT(1) NOT NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE app_settings (
        setting_key VARCHAR(190) PRIMARY KEY, setting_value TEXT NULL,
        is_encrypted TINYINT(1) NOT NULL DEFAULT 0, setting_group VARCHAR(80) NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE app_versions (version VARCHAR(32) PRIMARY KEY, notes TEXT NULL) ENGINE=InnoDB');

    $sql = (string) file_get_contents(dirname(__DIR__) . '/database/migrations/191_cron_backlog_api_health_truth_2_28_11.sql');
    $statements = array_values(array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [])));
    foreach ([1, 2] as $passNo) {
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
    }

    $columns = $pdo->query(
        'SELECT CONCAT(TABLE_NAME,".",COLUMN_NAME) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND (
           (TABLE_NAME="cron_task_state" AND COLUMN_NAME IN
             ("last_work_observed_at","last_batch_started","last_batch_completed","last_batch_deferred","last_batch_remote_calls"))
           OR (TABLE_NAME="system_work_queue_run_items" AND COLUMN_NAME IN
             ("backlog_before","backlog_after","batch_limit","work_unit","completed_count"))
         )'
    )->fetchAll(PDO::FETCH_COLUMN);
    if (count($columns) !== 10) {
        throw new RuntimeException('La migración 191 no creó sus diez columnas técnicas.');
    }
    if ((string) $pdo->query('SELECT setting_value FROM app_settings WHERE setting_key="commercial_path.version"')->fetchColumn() !== '2.28.11') {
        throw new RuntimeException('La migración 191 no registró la versión operativa.');
    }
    fwrite(STDOUT, "PASS cron_backlog_22811_mysql_integration\n");
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
