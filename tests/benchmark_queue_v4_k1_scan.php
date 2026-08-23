<?php

declare(strict_types=1);

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || stripos($dsn, 'mysql:') !== 0 || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: benchmark_queue_v4_k1_scan requires a disposable MariaDB DSN without dbname.\n");
    exit(2);
}

$maxScanRows = 60;
$maxScanTimeMs = 1000;
$iterations = 20;
$prefixes = [0, 1, 3, 10, 30, 60];
$modes = [
    'PACK_INCOMPLETE' => 'local_deferred',
    'SOURCE_NOT_DUE' => 'local_deferred',
    'STALE_ALREADY_REPLACED' => 'local_completed',
    'OFFICIAL_COMPLETE' => 'local_completed',
    'MIXED_LOCAL_PREFIX' => 'mixed_local',
];

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
if (stripos($version, 'MariaDB') === false) {
    fwrite(STDERR, "ERROR: benchmark_queue_v4_k1_scan requires MariaDB. Detected: {$version}\n");
    exit(2);
}

$database = 'erp_meli_k1_scan_' . bin2hex(random_bytes(4));
$pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE `' . $database . '`');

try {
    $pdo->exec('CREATE TABLE k1_scan_benchmark_rows(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        mode_name VARCHAR(40) NOT NULL,
        scenario_prefix INT NOT NULL,
        available_at DATETIME(3) NOT NULL,
        source_kind VARCHAR(40) NOT NULL,
        effective TINYINT(1) NOT NULL,
        KEY idx_fifo(mode_name,scenario_prefix,available_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $insert = $pdo->prepare(
        'INSERT INTO k1_scan_benchmark_rows(mode_name,scenario_prefix,available_at,source_kind,effective)
         VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ? MICROSECOND),?,?)'
    );
    foreach ($modes as $mode => $kind) {
        foreach ($prefixes as $prefix) {
            for ($i = 0; $i < $prefix; $i++) {
                $localKind = $kind === 'mixed_local'
                    ? ($i % 2 === 0 ? 'local_completed' : 'local_deferred')
                    : $kind;
                $insert->execute([$mode, $prefix, $i, $localKind, 0]);
            }
            $insert->execute([$mode, $prefix, $prefix, 'remote_candidate', 1]);
        }
    }

    $select = $pdo->prepare(
        'SELECT source_kind,effective
           FROM k1_scan_benchmark_rows
          WHERE mode_name=? AND scenario_prefix=?
          ORDER BY available_at ASC,id ASC
          LIMIT ' . $maxScanRows
    );

    $rows = [];
    foreach ($modes as $mode => $_kind) {
        foreach ($prefixes as $prefix) {
            $samples = [];
            $lastScanned = 0;
            $lastEffective = 0;
            $termination = 'END_OF_QUEUE';
            for ($i = 0; $i < $iterations; $i++) {
                $started = hrtime(true);
                $select->execute([$mode, $prefix]);
                $scanned = 0;
                $effectiveFound = 0;
                foreach ($select->fetchAll() as $row) {
                    $scanned++;
                    if ((int) $row['effective'] === 1) {
                        $effectiveFound++;
                        $termination = 'EFFECTIVE_FOUND';
                        break;
                    }
                    if ($scanned >= $maxScanRows) {
                        $termination = 'MAX_SCAN_ROWS';
                        break;
                    }
                }
                $elapsedMs = (hrtime(true) - $started) / 1_000_000;
                if ($elapsedMs > $maxScanTimeMs) {
                    $termination = 'MAX_SCAN_TIME_MS';
                }
                $samples[] = $elapsedMs;
                $lastScanned = $scanned;
                $lastEffective = $effectiveFound;
            }
            sort($samples);
            $p50 = $samples[(int) floor((count($samples) - 1) * 0.50)];
            $p95 = $samples[(int) floor((count($samples) - 1) * 0.95)];
            $rows[] = [
                'mode' => $mode,
                'prefix' => $prefix,
                'iterations' => $iterations,
                'p50_ms' => round($p50, 3),
                'p95_ms' => round($p95, 3),
                'max_ms' => round(max($samples), 3),
                'rows_scanned' => $lastScanned,
                'effective_found' => $lastEffective,
                'termination_reason' => $termination,
            ];
        }
    }

    fwrite(STDOUT, 'QUEUE_V4_K1_SCAN_BENCHMARK=PASS MAX_SCAN_ROWS=' . $maxScanRows
        . ' MAX_SCAN_TIME_MS=' . $maxScanTimeMs
        . ' REAL_MELI_HTTP=0 RESULTS=' . json_encode($rows, JSON_THROW_ON_ERROR) . PHP_EOL);
} finally {
    $pdo->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
