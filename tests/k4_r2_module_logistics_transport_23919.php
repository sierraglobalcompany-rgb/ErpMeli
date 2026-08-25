<?php

declare(strict_types=1);

use App\Core\Crypto;
use App\Core\Database;
use App\Modules\Shared\Services\MeliReadGateway;
use App\Services\MeliApiClient;
use App\Services\MeliHttpTransportInterface;
use App\Services\MeliReadClientInterface;
use App\Services\MeliTransportSourcePolicy;

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: K4 R2 module logistics exige un DSN MariaDB desechable sin dbname.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_k4_r2_module_logistics_' . bin2hex(random_bytes(5));
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';

try {
    putenv('APP_KEY=k4-r2-module-logistics');
    $_ENV['APP_KEY'] = 'k4-r2-module-logistics';
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

    final class K4R2ModuleLogisticsTransport implements MeliHttpTransportInterface
    {
        public int $calls = 0;
        public string $lastMethod = '';
        public string $lastPath = '';

        public function request(
            string $method,
            string $url,
            array $data,
            array $headers,
            bool $form,
            array $timeouts
        ): array {
            $this->calls++;
            $this->lastMethod = strtoupper($method);
            $this->lastPath = (string) (parse_url($url, PHP_URL_PATH) ?: '');
            return [
                'status' => 200,
                'body' => [
                    'id' => 123,
                    'status' => 'ready_to_ship',
                    'substatus' => null,
                    'logistic_type' => 'fulfillment',
                ],
                'headers' => [],
                'curl_error' => '',
                'duration_ms' => 9,
                'wire_bytes' => 256,
                'decoded_bytes' => 128,
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
        fwrite(STDERR, "ERROR: K4 R2 module logistics exige MariaDB 11.8+.\n");
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

    $pdo->exec("CREATE TABLE app_settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NULL,is_encrypted TINYINT(1) NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL DEFAULT 'general',updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
    $pdo->exec('CREATE TABLE meli_accounts(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_user_id BIGINT UNSIGNED NULL,account_name VARCHAR(160) NULL,status VARCHAR(40) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_tokens(meli_account_id BIGINT UNSIGNED PRIMARY KEY,access_token_encrypted TEXT NOT NULL,refresh_token_encrypted TEXT NULL,expires_at DATETIME NOT NULL,refresh_version BIGINT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_circuit_breakers(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,endpoint_path VARCHAR(255) NOT NULL,status VARCHAR(40) NOT NULL,blocked_until DATETIME NULL,reason VARCHAR(80) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_rhythm_states(scope_key VARCHAR(120) PRIMARY KEY,generation BIGINT UNSIGNED NOT NULL DEFAULT 1,calls_in_block INT UNSIGNED NOT NULL DEFAULT 0,block_started_at DATETIME(3) NULL,next_allowed_at DATETIME(3) NULL,block_pause_until DATETIME(3) NULL,last_dispatched_at DATETIME(3) NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_remote_permits(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,permit_token CHAR(40) NOT NULL,owner_token CHAR(32) NOT NULL,generation BIGINT UNSIGNED NOT NULL,run_token VARCHAR(100) NULL,work_key VARCHAR(120) NULL,company_id BIGINT UNSIGNED NULL,meli_account_id BIGINT UNSIGNED NULL,endpoint_key VARCHAR(120) NOT NULL,job_type VARCHAR(80) NOT NULL,method VARCHAR(10) NOT NULL,status ENUM("reserved","dispatched","completed","released","expired") NOT NULL DEFAULT "reserved",requested_interval_ms INT UNSIGNED NOT NULL,effective_interval_ms INT UNSIGNED NOT NULL,blocking_scope VARCHAR(80) NULL,http_status SMALLINT UNSIGNED NULL,created_at DATETIME(3) NOT NULL,dispatched_at DATETIME(3) NULL,completed_at DATETIME(3) NULL,released_at DATETIME(3) NULL,expires_at DATETIME(3) NOT NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),UNIQUE KEY uq_permit_token(permit_token),KEY idx_permit_active(status,expires_at),KEY idx_permit_endpoint_dispatch(endpoint_key,dispatched_at)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_rhythm_penalties(scope_key VARCHAR(180) PRIMARY KEY,reduced_limit_per_minute SMALLINT UNSIGNED NOT NULL,blocked_until DATETIME(3) NULL,reduced_until DATETIME(3) NOT NULL,reason VARCHAR(80) NOT NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_request_pacing_state(scope_key VARCHAR(255) PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,operation_key VARCHAR(80) NOT NULL,effective_rpm INT NOT NULL,next_allowed_at DATETIME(6) NOT NULL,last_reserved_at DATETIME(6) NULL,last_wait_ms INT NOT NULL DEFAULT 0,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_budget_windows(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,scope VARCHAR(40) NOT NULL,scope_key VARCHAR(255) NOT NULL,meli_account_id BIGINT UNSIGNED NULL,endpoint_path VARCHAR(255) NULL,job_type VARCHAR(80) NULL,window_started_at DATETIME NOT NULL,window_seconds INT UNSIGNED NOT NULL DEFAULT 900,request_limit INT UNSIGNED NOT NULL DEFAULT 0,request_count INT UNSIGNED NOT NULL DEFAULT 0,error_400_count INT UNSIGNED NOT NULL DEFAULT 0,error_401_count INT UNSIGNED NOT NULL DEFAULT 0,error_403_count INT UNSIGNED NOT NULL DEFAULT 0,error_429_count INT UNSIGNED NOT NULL DEFAULT 0,error_5xx_count INT UNSIGNED NOT NULL DEFAULT 0,last_request_at DATETIME NULL,cooldown_until DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_api_budget_window(scope_key,window_started_at,window_seconds)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_request_logs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,company_id BIGINT UNSIGNED NULL,scope_kind VARCHAR(30) NULL,request_id VARCHAR(64) NULL,method VARCHAR(10) NULL,endpoint_path VARCHAR(255) NULL,http_status INT NULL,duration_ms INT NULL,retry_after_seconds INT NULL,attempt INT NULL,was_blocked TINYINT(1) NULL,safe_message VARCHAR(500) NULL,diagnostic_id VARCHAR(100) NULL,error_type VARCHAR(80) NULL,error_code VARCHAR(120) NULL,is_retryable TINYINT(1) NULL,is_app_blocked_signal TINYINT(1) NULL,outcome_class VARCHAR(80) NULL,reached_remote TINYINT(1) NULL,actionable TINYINT(1) NULL,risk_signal TINYINT(1) NULL,incident_key VARCHAR(191) NULL,execution_source VARCHAR(80) NULL,job_type VARCHAR(80) NULL,source_queue_key VARCHAR(80) NULL,source_work_id VARCHAR(100) NULL,operation_key VARCHAR(80) NULL,load_class VARCHAR(40) NULL,workload_units INT NULL,wire_bytes BIGINT NULL,decoded_bytes BIGINT NULL,response_item_count INT NULL,response_count_state VARCHAR(40) NULL,response_resource_unit VARCHAR(40) NULL,fanout_count INT NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_operation_metrics_hourly(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,bucket_started_at DATETIME NOT NULL,account_scope_key BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NULL,operation_key VARCHAR(80) NOT NULL,load_class VARCHAR(40) NOT NULL,sample_count INT NOT NULL,remote_count INT NOT NULL,success_count INT NOT NULL,error_count INT NOT NULL,total_duration_ms BIGINT NOT NULL,max_duration_ms BIGINT NOT NULL,total_wire_bytes BIGINT NOT NULL,total_decoded_bytes BIGINT NOT NULL,total_items BIGINT NOT NULL,total_fanout BIGINT NOT NULL,updated_at DATETIME NOT NULL,UNIQUE KEY uq_metric(bucket_started_at,account_scope_key,operation_key,load_class)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_operation_metric_samples(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,bucket_started_at DATETIME NOT NULL,account_scope_key BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NULL,operation_key VARCHAR(80) NOT NULL,load_class VARCHAR(40) NOT NULL,duration_ms INT NOT NULL,wire_bytes BIGINT NOT NULL,decoded_bytes BIGINT NOT NULL,response_item_count INT NOT NULL,fanout_count INT NOT NULL,http_status INT NULL,reached_remote TINYINT(1) NOT NULL,successful TINYINT(1) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $pdo->prepare('INSERT INTO app_settings(setting_key,setting_value,is_encrypted) VALUES (?,?,0),(?,?,0),(?,?,0),(?,?,0)')
        ->execute(['api.guard.enabled', '1', 'api.budget.enabled', '1', 'api.rhythm.enabled', '1', 'api.pacing.enabled', '1']);
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,meli_user_id,account_name,status) VALUES (101,10,101001,'K4R2','conectado')");
    $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,refresh_version) VALUES (?,?,?,?,0)')
        ->execute([101, Crypto::encrypt('k4-r2-access'), Crypto::encrypt('k4-r2-refresh'), gmdate('Y-m-d H:i:s', time() + 3600)]);

    $capabilities = MeliTransportSourcePolicy::capabilities(MeliTransportSourcePolicy::MODULE_MELI_LOGISTICS);
    $assert($capabilities['known'] === true, 'MODULE_LOGISTICS_SOURCE_NOT_KNOWN');
    $assert($capabilities['module_source'] === true, 'MODULE_LOGISTICS_NOT_MARKED_MODULE_SOURCE');
    $assert($capabilities['single_dispatch'] === true, 'MODULE_LOGISTICS_NOT_SINGLE_DISPATCH');
    $assert($capabilities['blocks_redirects'] === true, 'MODULE_LOGISTICS_DOES_NOT_BLOCK_REDIRECTS');
    $assert($capabilities['pacing_compatibility'] === true, 'MODULE_LOGISTICS_PACING_COMPAT_NOT_PRESERVED');
    $assert($capabilities['queue_rate_limit_deferral'] === false, 'MODULE_LOGISTICS_SHOULD_NOT_DEFER_QUEUE_RATE_LIMIT');
    $assert($capabilities['current_oauth_fence'] === false, 'MODULE_LOGISTICS_SHOULD_NOT_USE_OAUTH_FENCE');
    $assert($capabilities['queue_v4_read_fence'] === false, 'MODULE_LOGISTICS_SHOULD_NOT_USE_QUEUE_V4_READ_FENCE');

    $transport = new K4R2ModuleLogisticsTransport();
    $gateway = new MeliReadGateway(static fn (int $accountId): MeliReadClientInterface => new MeliApiClient($accountId, $transport));
    $response = $gateway->get('meli-logistics', 101, '/shipments/123', [], 'module_logistics_event');

    $assert(($response['id'] ?? null) === 123, 'MODULE_LOGISTICS_SHIPMENT_RESPONSE_NOT_RETURNED');
    $assert($transport->calls === 1, 'MODULE_LOGISTICS_TRANSPORT_NOT_EXACTLY_ONE');
    $assert($transport->lastMethod === 'GET', 'MODULE_LOGISTICS_METHOD_NOT_GET');
    $assert($transport->lastPath === '/shipments/123', 'MODULE_LOGISTICS_PATH_NOT_SHIPMENT');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM api_request_logs WHERE execution_source='module:meli-logistics' AND endpoint_path='/shipments/123' AND reached_remote=1 AND http_status=200")->fetchColumn() === 1, 'MODULE_LOGISTICS_LOG_SOURCE_NOT_DURABLE');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM api_remote_permits WHERE endpoint_key='shipment_exact' AND status='completed' AND http_status=200")->fetchColumn() === 1, 'MODULE_LOGISTICS_RHYTHM_PERMIT_NOT_COMPLETED');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM api_request_pacing_state')->fetchColumn() === 0, 'MODULE_LOGISTICS_SHOULD_NOT_USE_PACING_WHEN_RHYTHM_SCHEMA_EXISTS');
    $assert((int) $pdo->query('SELECT COALESCE(SUM(request_count),0) FROM api_budget_windows')->fetchColumn() >= 1, 'MODULE_LOGISTICS_BUDGET_NOT_USED');

    $denyGateway = static function (string $path) use ($assert): void {
        $transport = new K4R2ModuleLogisticsTransport();
        $gateway = new MeliReadGateway(static fn (int $accountId): MeliReadClientInterface => new MeliApiClient($accountId, $transport));
        try {
            $gateway->get('meli-logistics', 101, $path, [], 'module_logistics_event');
            throw new RuntimeException('MODULE_LOGISTICS_DENY_PATH_ALLOWED:' . $path);
        } catch (RuntimeException $exception) {
            $assert(
                str_contains($exception->getMessage(), 'module_meli_logistics_transport_capability_denied')
                || str_contains($exception->getMessage(), 'Endpoint de Mercado Libre no confirmado'),
                'MODULE_LOGISTICS_DENY_WRONG_ERROR:' . $path . ':' . $exception->getMessage()
            );
            $assert($transport->calls === 0, 'MODULE_LOGISTICS_DENY_REACHED_TRANSPORT:' . $path);
        }
    };
    foreach ([
        '/orders/123',
        '/orders/search',
        '/users/me',
        '/billing/integration/group/ML/order/details',
        '/questions/123',
        '/post-purchase/v1/claims/123',
    ] as $path) {
        $denyGateway($path);
    }

    $oauthTransport = new K4R2ModuleLogisticsTransport();
    try {
        (new MeliApiClient(101, $oauthTransport))->exchangeOAuthToken(
            ['grant_type' => 'authorization_code', 'code' => 'fake'],
            ['source' => MeliTransportSourcePolicy::MODULE_MELI_LOGISTICS, 'company_id' => 10, 'job_type' => 'module_logistics']
        );
        throw new RuntimeException('MODULE_LOGISTICS_OAUTH_ALLOWED');
    } catch (RuntimeException $exception) {
        $assert(str_contains($exception->getMessage(), 'module_meli_logistics_transport_capability_denied'), 'MODULE_LOGISTICS_OAUTH_WRONG_ERROR');
        $assert($oauthTransport->calls === 0, 'MODULE_LOGISTICS_OAUTH_REACHED_TRANSPORT');
    }

    foreach (['meli-ads', 'meli-growth', 'meli-insights', 'meli-postsale'] as $moduleId) {
        $transport = new K4R2ModuleLogisticsTransport();
        $gateway = new MeliReadGateway(static fn (int $accountId): MeliReadClientInterface => new MeliApiClient($accountId, $transport));
        try {
            $gateway->get($moduleId, 101, '/shipments/123', [], 'module_read');
            throw new RuntimeException('UNAUTHORIZED_MODULE_SOURCE_ALLOWED:' . $moduleId);
        } catch (RuntimeException $exception) {
            $assert(str_contains($exception->getMessage(), 'meli_transport_source_unknown'), 'UNAUTHORIZED_MODULE_SOURCE_WRONG_ERROR:' . $moduleId);
            $assert($transport->calls === 0, 'UNAUTHORIZED_MODULE_SOURCE_REACHED_TRANSPORT:' . $moduleId);
        }
    }

    fwrite(
        STDOUT,
        'K4_R2_MODULE_LOGISTICS_TRANSPORT_23919=PASS'
        . ' MODULE_LOGISTICS_BEHAVIORAL_GATE=PASS'
        . ' MODULE_LOGISTICS_PERMISSION_MATRIX=PASS'
        . ' MODULE_LOGISTICS_RHYTHM_AUTHORITY=PASS'
        . ' MODULE_LOGISTICS_PACING_COMPAT_SCHEMA299=PASS'
        . ' UNAUTHORIZED_MODULE_SOURCES_HTTP=0'
        . ' REAL_MELI_HTTP=0'
        . ' checks=' . $checks
        . PHP_EOL
    );
} finally {
    if (isset($server, $database)) {
        $server->exec('DROP DATABASE IF EXISTS ' . $quote($database));
    }
}
