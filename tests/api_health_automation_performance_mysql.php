<?php

declare(strict_types=1);

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: ERP_MIGRATOR_TEST_DSN MySQL sin base es obligatorio.\n");
    exit(2);
}

$server = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'erp_api_health_perf_' . bin2hex(random_bytes(5));
$server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('CREATE TABLE system_work_queue_runs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        origin VARCHAR(40) NOT NULL,status VARCHAR(30) NOT NULL,
        started_at DATETIME NOT NULL,finished_at DATETIME NULL,
        INDEX idx_work_runs_origin_finished (origin,finished_at,id)
    ) ENGINE=InnoDB');
    $insert = $pdo->prepare(
        'INSERT INTO system_work_queue_runs(origin,status,started_at,finished_at)
         VALUES (:origin,"completed",UTC_TIMESTAMP(),UTC_TIMESTAMP())'
    );
    $pdo->beginTransaction();
    for ($i = 0; $i < 20000; $i++) {
        $insert->execute(['origin' => $i % 4 === 0 ? 'scheduled_cli' : 'manual_web']);
    }
    $pdo->commit();

    $sql = 'SELECT * FROM system_work_queue_runs
            WHERE origin="scheduled_cli" AND finished_at IS NOT NULL
            ORDER BY finished_at DESC,id DESC LIMIT 1';
    $plan = $pdo->query('EXPLAIN ' . $sql)->fetch(PDO::FETCH_ASSOC);
    if (!is_array($plan) || (string) ($plan['key'] ?? '') !== 'idx_work_runs_origin_finished') {
        throw new RuntimeException('La lectura ligera de automatización no usa su índice compuesto.');
    }

    $times = [];
    for ($i = 0; $i < 50; $i++) {
        $started = hrtime(true);
        $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        $times[] = (hrtime(true) - $started) / 1_000_000;
    }
    sort($times);
    $p95 = $times[(int) floor((count($times) - 1) * 0.95)];
    if ($p95 >= 100.0) {
        throw new RuntimeException('La lectura de automatización excedió 100 ms p95: ' . round($p95, 2));
    }

    echo 'PASS api_health_automation_performance_mysql p95_ms=' . number_format($p95, 2, '.', '') . "\n";
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
