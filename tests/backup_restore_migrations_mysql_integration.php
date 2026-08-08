<?php

declare(strict_types=1);

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: ERP_MIGRATOR_TEST_DSN debe apuntar a MariaDB/MySQL sin seleccionar una base.\n");
    exit(2);
}

putenv('APP_KEY=base64:' . base64_encode(str_repeat('b', 32)));
putenv('ML_WRITE_ENABLED=false');
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Database;
use App\Services\Migrator;

$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$version = (string) $server->query('SELECT VERSION()')->fetchColumn();
$numericVersion = preg_match('/^([0-9]+\.[0-9]+\.[0-9]+)/', $version, $match) === 1
    ? (string) $match[1]
    : '0.0.0';
if (stripos($version, 'MariaDB') === false || version_compare($numericVersion, '11.8.0', '<')) {
    throw new RuntimeException('Esta certificación exige MariaDB 11.8 real. Detectado: ' . $version);
}
$database = 'erp_backup_restore_' . bin2hex(random_bytes(5));

try {
    $server->exec(
        'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci'
    );
    $pdo = new PDO(
        rtrim($dsn, ';') . ';dbname=' . $database,
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    Database::setConnection($pdo);
    $migrator = new Migrator($pdo, dirname(__DIR__) . '/database/migrations');
    $migrator->run();
    foreach ([
        'system_backup_archives',
        'system_backup_jobs',
        'system_backup_table_checks',
        'system_restore_plans',
        'system_restore_checks',
        'system_restore_switches',
    ] as $table) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE BINARY TABLE_SCHEMA=BINARY DATABASE() AND BINARY TABLE_NAME=BINARY :table'
        );
        $stmt->execute(['table' => $table]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Falta la tabla certificada ' . $table . '.');
        }
    }
    foreach ([
        '141_backup_recovery_center_2_25_16.sql',
        '142_safe_restore_diagnostic_clone_2_25_17.sql',
        '143_database_growth_observability_2_25_18.sql',
        '144_storage_retention_payload_archive_2_25_19.sql',
        '145_web_sql_runtime_consolidation_2_25_20.sql',
        '146_runtime_consolidation_physical_recovery_2_26_0.sql',
        '147_notification_retention_partition_2_26_1.sql',
        '148_interactive_database_sanitation_2_26_2.sql',
        '149_forensic_noise_sanitation_2_26_2.sql',
        '150_imported_meli_data_reset_2_26_3.sql',
        '151_technical_retention_membership_2_26_3.sql',
        '152_managed_maintenance_emergency_recovery_2_26_4.sql',
        '153_runtime_safety_backup_recovery_2_26_5.sql',
        '154_updater_backup_recovery_2_26_6.sql',
        '155_updater_identity_recovery_2_26_7.sql',
        '156_updater_origin_session_recovery_2_26_8.sql',
        '157_backup_lifecycle_sanitation_bridge_2_26_9.sql',
        '158_local_maintenance_fencing_exact_backup_2_26_10.sql',
    ] as $migration) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=:version');
        $stmt->execute(['version' => $migration]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException('No se registró la migración ' . $migration . '.');
        }
    }
    $second = $migrator->run();
    foreach ($second as $result) {
        if (!in_array((string) ($result['status'] ?? ''), ['skip', 'adopted'], true)) {
            throw new RuntimeException('La segunda ejecución no fue idempotente.');
        }
    }
    echo "OK: instalación limpia e idempotente hasta 158 en MariaDB 11.8.\n";
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
