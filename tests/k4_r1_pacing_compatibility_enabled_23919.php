<?php

declare(strict_types=1);

use App\Core\Crypto;
use App\Core\Database;
use App\Services\MeliApiClient;
use App\Services\MeliHttpTransportInterface;

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: K4 R1 pacing exige un DSN MariaDB desechable sin dbname.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_k4_r1_pacing_' . bin2hex(random_bytes(5));
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';

try {
    putenv('APP_KEY=k4-r1-pacing-enabled');
    $_ENV['APP_KEY'] = 'k4-r1-pacing-enabled';
    putenv('ML_WRITE_ENABLED=false');
    $_ENV['ML_WRITE_ENABLED'] = 'false';

    spl_autoload_register(static function (string $class) use ($root): void {
        if (!str_starts_with($class, 'App\\')) {
            return;
        }
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });

    final class K4R1PacingEnabledTransport implements MeliHttpTransportInterface
    {
        public int $calls = 0;

        public function request(
            string $method,
            string $url,
            array $data,
            array $headers,
            bool $form,
            array $timeouts
        ): array {
            $this->calls++;
            return [
                'status' => 200,
                'body' => ['results' => [], 'paging' => ['total' => 0]],
                'headers' => [],
                'curl_error' => '',
                'duration_ms' => 7,
                'wire_bytes' => 1,
                'decoded_bytes' => 1,
            ];
        }
    }

    $server = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $version = (string) $server->query('SELECT VERSION()')->fetchColumn();
    if (stripos($version, 'mariadb') === false || version_compare(preg_replace('/[^0-9.].*$/', '', $version) ?: '0', '11.8.0', '<')) {
        fwrite(STDERR, "ERROR: K4 R1 pacing exige MariaDB 11.8+.\n");
        exit(2);
    }
    $server->exec('CREATE DATABASE ' . $quote($database) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET SESSION time_zone='+00:00'");
    Database::setConnection($pdo);

    $pdo->exec('CREATE TABLE app_settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NULL,is_encrypted TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_accounts(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_user_id BIGINT UNSIGNED NULL,account_name VARCHAR(160) NULL,status VARCHAR(40) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_tokens(meli_account_id BIGINT UNSIGNED PRIMARY KEY,access_token_encrypted TEXT NOT NULL,refresh_token_encrypted TEXT NULL,expires_at DATETIME NOT NULL,refresh_version BIGINT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_circuit_breakers(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,endpoint_path VARCHAR(255) NOT NULL,status VARCHAR(40) NOT NULL,blocked_until DATETIME NULL,reason VARCHAR(80) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_request_pacing_state(scope_key VARCHAR(255) PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,operation_key VARCHAR(80) NOT NULL,effective_rpm INT NOT NULL,next_allowed_at DATETIME(6) NOT NULL,last_reserved_at DATETIME(6) NULL,last_wait_ms INT NOT NULL DEFAULT 0,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_budget_windows(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,scope VARCHAR(40) NOT NULL,scope_key VARCHAR(255) NOT NULL,meli_account_id BIGINT UNSIGNED NULL,endpoint_path VARCHAR(255) NULL,job_type VARCHAR(80) NULL,window_started_at DATETIME NOT NULL,window_seconds INT UNSIGNED NOT NULL DEFAULT 900,request_limit INT UNSIGNED NOT NULL DEFAULT 0,request_count INT UNSIGNED NOT NULL DEFAULT 0,error_400_count INT UNSIGNED NOT NULL DEFAULT 0,error_401_count INT UNSIGNED NOT NULL DEFAULT 0,error_403_count INT UNSIGNED NOT NULL DEFAULT 0,error_429_count INT UNSIGNED NOT NULL DEFAULT 0,error_5xx_count INT UNSIGNED NOT NULL DEFAULT 0,last_request_at DATETIME NULL,cooldown_until DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_api_budget_window(scope_key,window_started_at,window_seconds)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_request_logs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,company_id BIGINT UNSIGNED NULL,scope_kind VARCHAR(30) NULL,request_id VARCHAR(64) NULL,method VARCHAR(10) NULL,endpoint_path VARCHAR(255) NULL,http_status INT NULL,duration_ms INT NULL,retry_after_seconds INT NULL,attempt INT NULL,was_blocked TINYINT(1) NULL,safe_message VARCHAR(500) NULL,diagnostic_id VARCHAR(100) NULL,error_type VARCHAR(80) NULL,error_code VARCHAR(120) NULL,is_retryable TINYINT(1) NULL,is_app_blocked_signal TINYINT(1) NULL,outcome_class VARCHAR(80) NULL,reached_remote TINYINT(1) NULL,actionable TINYINT(1) NULL,risk_signal TINYINT(1) NULL,incident_key VARCHAR(191) NULL,execution_source VARCHAR(40) NULL,job_type VARCHAR(80) NULL,source_queue_key VARCHAR(80) NULL,source_work_id VARCHAR(100) NULL,operation_key VARCHAR(80) NULL,load_class VARCHAR(40) NULL,workload_units INT NULL,wire_bytes BIGINT NULL,decoded_bytes BIGINT NULL,response_item_count INT NULL,response_count_state VARCHAR(40) NULL,response_resource_unit VARCHAR(40) NULL,fanout_count INT NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_operation_metrics_hourly(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,bucket_started_at DATETIME NOT NULL,account_scope_key BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NULL,operation_key VARCHAR(80) NOT NULL,load_class VARCHAR(40) NOT NULL,sample_count INT NOT NULL,remote_count INT NOT NULL,success_count INT NOT NULL,error_count INT NOT NULL,total_duration_ms BIGINT NOT NULL,max_duration_ms BIGINT NOT NULL,total_wire_bytes BIGINT NOT NULL,total_decoded_bytes BIGINT NOT NULL,total_items BIGINT NOT NULL,total_fanout BIGINT NOT NULL,updated_at DATETIME NOT NULL,UNIQUE KEY uq_metric(bucket_started_at,account_scope_key,operation_key,load_class)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_operation_metric_samples(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,bucket_started_at DATETIME NOT NULL,account_scope_key BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NULL,operation_key VARCHAR(80) NOT NULL,load_class VARCHAR(40) NOT NULL,duration_ms INT NOT NULL,wire_bytes BIGINT NOT NULL,decoded_bytes BIGINT NOT NULL,response_item_count INT NOT NULL,fanout_count INT NOT NULL,http_status INT NULL,reached_remote TINYINT(1) NOT NULL,successful TINYINT(1) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $pdo->prepare('INSERT INTO app_settings(setting_key,setting_value,is_encrypted) VALUES (?,?,0),(?,?,0),(?,?,0),(?,?,0)')
        ->execute(['api.guard.enabled', '1', 'api.budget.enabled', '1', 'api.pacing.enabled', '1', 'api.pacing.ceiling_rpm', '59']);
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,meli_user_id,account_name,status) VALUES (101,10,101001,'K4R1','conectado')");
    $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,refresh_version) VALUES (?,?,?,?,0)')
        ->execute([101, Crypto::encrypt('k4-r1-access'), Crypto::encrypt('k4-r1-refresh'), gmdate('Y-m-d H:i:s', time() + 3600)]);

    $transport = new K4R1PacingEnabledTransport();
    $response = (new MeliApiClient(101, $transport))->request('GET', '/orders/search', [], false, [
        'source' => 'manual_exact',
        'company_id' => 10,
        'job_type' => 'manual_exact',
    ]);

    $assert(is_array($response), 'MANUAL_COMPAT_RESPONSE_NOT_ARRAY');
    $assert($transport->calls === 1, 'MANUAL_COMPAT_TRANSPORT_NOT_EXACTLY_ONE');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM api_request_pacing_state')->fetchColumn() >= 1, 'PACING_RESERVATION_NOT_ESTABLISHED');
    $assert((int) $pdo->query('SELECT COALESCE(SUM(request_count),0) FROM api_budget_windows')->fetchColumn() >= 1, 'BUDGET_NOT_APPLIED');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM api_request_logs WHERE reached_remote=1 AND http_status=200')->fetchColumn() === 1, 'KNOWN_REMOTE_RESULT_NOT_LOGGED');

    fwrite(
        STDOUT,
        'K4_R1_PACING_COMPATIBILITY_ENABLED_23919=PASS'
        . ' PACING_RESERVATION_ESTABLISHED=PASS'
        . ' BUDGET_APPLIED=PASS'
        . ' MAXIMUM_ONE_FAKE_TRANSPORT_CALL=PASS'
        . ' REAL_MELI_HTTP=0'
        . ' checks=' . $checks
        . PHP_EOL
    );
} finally {
    if (isset($server, $database)) {
        $server->exec('DROP DATABASE IF EXISTS ' . $quote($database));
    }
}
