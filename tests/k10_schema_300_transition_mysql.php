<?php

declare(strict_types=1);

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: k10_schema_300_transition_mysql requires a disposable MariaDB DSN without dbname.\n");
    exit(2);
}

$root = dirname(__DIR__);
$sql = (string) file_get_contents($root . '/database/migrations/300_manual_drain_sessions_2_40_0.sql');
$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assertTable = static function (PDO $pdo) use ($assert): void {
    $columns = $pdo->query(
        "SELECT COLUMN_NAME,DATA_TYPE,IS_NULLABLE
         FROM information_schema.columns
         WHERE table_schema=DATABASE() AND table_name='manual_drain_sessions'"
    )->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($columns, 'COLUMN_NAME');
    foreach ([
        'session_token',
        'owner_user_id',
        'status_ui',
        'drain_generation',
        'profile_snapshot_json',
        'jobs_processed',
        'remote_calls_observed',
        'manual_interval_until',
        'manual_cooldown_until',
        'heartbeat_at',
        'expires_at',
    ] as $column) {
        $assert(in_array($column, $columnNames, true), 'manual_drain_column_missing:' . $column);
    }
    $unique = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema=DATABASE()
           AND table_name='manual_drain_sessions'
           AND index_name='uq_manual_drain_session_token'
           AND non_unique=0"
    )->fetchColumn();
    $assert($unique === 1, 'manual_drain_session_token_unique_missing');
    $legacy = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name='queue_core_jobs'"
    )->fetchColumn();
    $assert($legacy === 0, 'k10_migration_must_not_create_queue_core_jobs');
};

$databases = [
    'fresh' => 'erp_k10_fresh_' . bin2hex(random_bytes(5)),
    'upgrade' => 'erp_k10_upgrade_' . bin2hex(random_bytes(5)),
];

try {
    foreach ($databases as $mode => $database) {
        $server->exec('CREATE DATABASE ' . $quote($database) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET SESSION time_zone='+00:00'");
        if ($mode === 'upgrade') {
            $pdo->exec(
                'CREATE TABLE schema_migrations(
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    version VARCHAR(191) NOT NULL UNIQUE,
                    applied_at DATETIME NULL
                ) ENGINE=InnoDB'
            );
            $pdo->exec("INSERT INTO schema_migrations(version,applied_at) VALUES ('299_queue_v4_domain_exact_admission_2_39_3.sql',UTC_TIMESTAMP())");
        }
        $pdo->exec($sql);
        if ($mode === 'upgrade') {
            $pdo->exec("INSERT INTO schema_migrations(version,applied_at) VALUES ('300_manual_drain_sessions_2_40_0.sql',UTC_TIMESTAMP())");
            $schemaRows = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
            $assert($schemaRows === 2, 'upgrade_schema_marker_count_invalid');
        }
        $assertTable($pdo);
    }
} finally {
    foreach ($databases as $database) {
        $server->exec('DROP DATABASE IF EXISTS ' . $quote($database));
    }
}

fwrite(STDOUT, 'K10_SCHEMA_300_TRANSITION_MYSQL=PASS checks=' . $checks . " fresh=yes upgrade_299_to_300=yes new_queue=no real_meli_http=0\n");
