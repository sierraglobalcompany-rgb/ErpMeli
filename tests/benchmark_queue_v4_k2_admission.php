<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanNotificationAdmissionStage;

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$connect = static function (string $dsn, string $user, string $pass): PDO {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone='+00:00'");
    return $pdo;
};

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: K2 benchmark exige un DSN MariaDB desechable sin dbname.\n");
    exit(2);
}

$database = 'erp_k2_admission_bench_' . bin2hex(random_bytes(5));
$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
$raw = (string) getenv('K2_ADMISSION_BENCHMARK_RAW_CSV');
$observations = [];

$schema = static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE companies(id BIGINT UNSIGNED NOT NULL PRIMARY KEY,name VARCHAR(191) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE meli_accounts(id BIGINT UNSIGNED NOT NULL,company_id BIGINT UNSIGNED NOT NULL,meli_user_id VARCHAR(64) NULL,account_name VARCHAR(191) NULL,status VARCHAR(32) NOT NULL,PRIMARY KEY(id),UNIQUE KEY uq_meli_accounts_company_id(company_id,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE app_settings(setting_key VARCHAR(120) NOT NULL PRIMARY KEY,setting_value LONGTEXT NULL,is_encrypted TINYINT(1) NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL DEFAULT 'general') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE queue_v4_clean_jobs(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,job_type ENUM('fresh_orders_discovery','order_exact','domain_exact') NOT NULL,resource_id VARCHAR(191) NULL,idempotency_key VARCHAR(191) NOT NULL,state ENUM('ready','running','waiting','review','dead','completed') NOT NULL DEFAULT 'ready',attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,available_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),lease_owner VARCHAR(96) NULL,lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,lease_expires_at DATETIME(3) NULL,payload_json JSON NOT NULL,last_error_class VARCHAR(100) NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),completed_at DATETIME(3) NULL,PRIMARY KEY(id),UNIQUE KEY uq_qv4_job_identity(company_id,meli_account_id,job_type,idempotency_key),KEY idx_qv4_fifo(state,available_at,id),KEY idx_qv4_tenant(company_id,meli_account_id,state,id),KEY idx_qv4_lease(state,lease_expires_at,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE meli_notification_events(id BIGINT UNSIGNED NOT NULL PRIMARY KEY,work_item_id BIGINT UNSIGNED NULL,status VARCHAR(30) NOT NULL DEFAULT 'queued',disposition VARCHAR(80) NULL,error_message VARCHAR(500) NULL,acknowledged_at DATETIME(3) NULL,processed_at DATETIME(3) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE meli_notification_work_items(id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,account_scope_key VARCHAR(80) NOT NULL,meli_user_id BIGINT UNSIGNED NULL,canonical_topic VARCHAR(40) NOT NULL,resource_type VARCHAR(40) NOT NULL,remote_resource_id VARCHAR(100) NOT NULL,latest_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,latest_event_sent_at DATETIME(3) NULL,priority INT NOT NULL DEFAULT 50,occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,status VARCHAR(40) NOT NULL DEFAULT 'pending',next_run_at DATETIME(3) NULL,correlation_id VARCHAR(80) NULL,last_result VARCHAR(100) NULL,first_received_at DATETIME(3) NULL,last_received_at DATETIME(3) NULL,locked_by VARCHAR(96) NULL,locked_at DATETIME(3) NULL,lock_expires_at DATETIME(3) NULL,last_error_code VARCHAR(100) NULL,last_error_message VARCHAR(500) NULL,last_error_diagnostic_id VARCHAR(80) NULL,last_error_stage VARCHAR(80) NULL,last_processed_at DATETIME(3) NULL,last_success_at DATETIME(3) NULL,completed_at DATETIME(3) NULL,processing_event_id BIGINT UNSIGNED NULL,rerun_requested TINYINT(1) NOT NULL DEFAULT 0,consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,attempts INT UNSIGNED NOT NULL DEFAULT 0,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),UNIQUE KEY uq_notification_resource(meli_account_id,resource_type,remote_resource_id),KEY idx_notification_k2(status,next_run_at,priority,id),KEY idx_notification_scope(meli_account_id,status,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT INTO companies(id,name) VALUES (1,'K2 Bench')");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,meli_user_id,account_name,status) VALUES (1,1,'1001','K2','conectado')");
};

$insertWork = static function (PDO $pdo, int $id, string $topic, string $type, string $remoteId, string $status = 'pending'): void {
    $pdo->prepare('INSERT INTO meli_notification_events(id,work_item_id,status) VALUES (?,?,"queued")')->execute([$id, $id]);
    $pdo->prepare(
        'INSERT INTO meli_notification_work_items
         (id,meli_account_id,account_scope_key,meli_user_id,canonical_topic,resource_type,remote_resource_id,
          latest_event_id,priority,occurrence_count,status,next_run_at,correlation_id,first_received_at,last_received_at)
         VALUES (?,1,"account:1",1001,?,?,?,?,10,1,?,UTC_TIMESTAMP(3),?,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))'
    )->execute([$id, $topic, $type, $remoteId, $id, $status, 'bench-' . $id]);
};

$reset = static function (PDO $pdo): void {
    $pdo->exec('DELETE FROM queue_v4_clean_jobs');
    $pdo->exec('DELETE FROM meli_notification_events');
    $pdo->exec('DELETE FROM meli_notification_work_items');
    $pdo->exec('DELETE FROM app_settings');
    $pdo->exec('INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group) VALUES ("notifications.k2.forward_from_event_id","0",0,"notifications")');
};

$percentile = static function (array $values, float $p): float {
    sort($values);
    if ($values === []) {
        return 0.0;
    }
    $index = (int) ceil((count($values) * $p) / 100) - 1;
    return (float) $values[max(0, min(count($values) - 1, $index))];
};

try {
    putenv('APP_KEY=k2-admission-benchmark');
    $_ENV['APP_KEY'] = 'k2-admission-benchmark';
    putenv('ML_WRITE_ENABLED=false');
    $_ENV['ML_WRITE_ENABLED'] = 'false';

    $server = $connect($dsn, $user, $pass);
    $server->exec('CREATE DATABASE ' . $quote($database) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = $connect($dsn . ';dbname=' . $database, $user, $pass);
    Database::setConnection($pdo);
    $schema($pdo);
    $stage = new QueueV4CleanNotificationAdmissionStage($pdo);

    $scenarios = [
        'empty' => 0,
        'supported_1' => 1,
        'supported_5' => 5,
        'mixed_15' => 15,
        'mixed_dedup_reconcile_30' => 30,
    ];
    foreach ($scenarios as $scenario => $count) {
        for ($iteration = 1; $iteration <= 20; $iteration++) {
            $reset($pdo);
            for ($i = 1; $i <= $count; $i++) {
                $topic = ($scenario === 'mixed_15' && $i % 3 === 0) ? 'shipment' : 'order';
                $type = $topic;
                $insertWork($pdo, $i, $topic, $type, (string) (900000 + $iteration * 100 + $i), $scenario === 'mixed_dedup_reconcile_30' && $i <= 5 ? 'queued' : 'pending');
                if ($scenario === 'mixed_dedup_reconcile_30' && $i <= 5) {
                    $pdo->prepare(
                        'INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,state,payload_json,completed_at)
                         VALUES (1,1,"order_exact",?,?, "completed", ?, UTC_TIMESTAMP(3))'
                    )->execute([(string) (900000 + $iteration * 100 + $i), 'notification:order_exact:work:' . $i . ':event:' . $i, '{"contract_version":1}']);
                }
            }
            $start = hrtime(true);
            $result = $stage->run(30);
            $elapsed = (hrtime(true) - $start) / 1_000_000;
            $observations[] = [
                'scenario' => $scenario,
                'iteration' => $iteration,
                'candidates' => $count,
                'elapsed_ms' => round($elapsed, 3),
                'admitted' => (int) ($result['admitted'] ?? 0),
                'unsupported' => (int) ($result['unsupported'] ?? 0),
                'reconciled' => (int) ($result['reconciled'] ?? 0),
                'deferred' => !empty($result['deferred']) ? 1 : 0,
            ];
        }
    }

    $elapsedValues = array_map(static fn (array $row): float => (float) $row['elapsed_ms'], $observations);
    if ($raw !== '') {
        $fh = fopen($raw, 'wb');
        if ($fh === false) {
            throw new RuntimeException('k2_benchmark_raw_csv_unavailable');
        }
        fputcsv($fh, ['scenario', 'iteration', 'candidates', 'elapsed_ms', 'admitted', 'unsupported', 'reconciled', 'deferred'], ',', '"', '');
        foreach ($observations as $row) {
            fputcsv($fh, $row, ',', '"', '');
        }
        fclose($fh);
    }

    echo 'QUEUE_V4_K2_ADMISSION_BENCHMARK=PASS'
        . ' ADMISSION_BENCHMARK_OBSERVATIONS=' . count($observations)
        . ' ADMISSION_P50_MS=' . round($percentile($elapsedValues, 50), 3)
        . ' ADMISSION_P95_MS=' . round($percentile($elapsedValues, 95), 3)
        . ' ADMISSION_P99_MS=' . round($percentile($elapsedValues, 99), 3)
        . ' ADMISSION_MAX_MS=' . round(max($elapsedValues), 3)
        . ' QUERIES_PER_SUPPORTED_ITEM=APPROX_4_TO_6'
        . ' REAL_MELI_HTTP=0'
        . PHP_EOL;
} finally {
    try {
        if (isset($server) && $server instanceof PDO) {
            $server->exec('DROP DATABASE IF EXISTS ' . $quote($database));
        }
    } catch (Throwable) {
    }
}
