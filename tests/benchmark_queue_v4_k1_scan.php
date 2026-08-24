<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Crypto;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\MeliApiClient;
use App\Services\MeliHttpTransportInterface;
use App\Services\SaleFinancialService;

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || stripos($dsn, 'mysql:') !== 0 || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: benchmark_queue_v4_k1_scan requires a disposable MariaDB DSN without dbname.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_meli_k1_real_scan_' . bin2hex(random_bytes(4));
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-k1-real-scan-' . bin2hex(random_bytes(5));
@mkdir($temporary . DIRECTORY_SEPARATOR . 'storage', 0770, true);
putenv('APP_KEY=queue-v4-k1-real-scan-local-only');
$_ENV['APP_KEY'] = 'queue-v4-k1-real-scan-local-only';
putenv('ML_WRITE_ENABLED=false');
$_ENV['ML_WRITE_ENABLED'] = 'false';
defined('ERP_SHARED_ROOT') || define('ERP_SHARED_ROOT', $temporary);

spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

final class K1ScanFakeBillingTransport implements MeliHttpTransportInterface
{
    /** @var list<array{path:string,order_ids:list<string>}> */
    public array $calls = [];

    public function request(string $method, string $url, array $data, array $headers, bool $form, array $timeouts): array
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $orderIds = array_values(array_filter(explode(',', (string) ($data['order_ids'] ?? ''))));
        $this->calls[] = ['path' => $path, 'order_ids' => $orderIds];
        $results = [];
        foreach ($orderIds as $orderId) {
            $results[] = [
                'order_id' => $orderId,
                'details' => [
                    ['detail_id' => 'fee-' . $orderId, 'detail_type' => 'SALE_FEE', 'detail_amount' => 10],
                ],
            ];
        }
        return [
            'status' => 200,
            'body' => ['results' => $results],
            'headers' => [],
            'curl_error' => '',
            'duration_ms' => 12,
            'wire_bytes' => 1,
            'decoded_bytes' => 1,
        ];
    }
}

/** @param list<float> $values @return array{50:float,95:float,99:float} */
function k1ScanPercentiles(array $values): array
{
    sort($values);
    $n = count($values);
    $at = static fn (float $p): float => $values[(int) min($n - 1, max(0, floor(($n - 1) * $p)))];
    return [50 => $at(0.50), 95 => $at(0.95), 99 => $at(0.99)];
}

$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$version = (string) $server->query('SELECT VERSION()')->fetchColumn();
if (stripos($version, 'mariadb') === false || version_compare(preg_replace('/[^0-9.].*$/', '', $version) ?: '0', '11.8.0', '<')) {
    fwrite(STDERR, "ERROR: benchmark_queue_v4_k1_scan requires MariaDB 11.8+. Detected: {$version}\n");
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

$maxScanRows = QueueV4CleanWorker::MAX_SCAN_ROWS;
$maxScanTimeMs = QueueV4CleanWorker::MAX_SCAN_TIME_MS;
$envList = static function (string $name, array $default): array {
    $value = trim((string) getenv($name));
    if ($value === '') {
        return $default;
    }
    return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
};
$iterations = max(1, (int) ((string) getenv('K1_SCAN_ITERATIONS') !== '' ? getenv('K1_SCAN_ITERATIONS') : 20));
$prefixes = array_map('intval', $envList('K1_SCAN_PREFIXES', [0, 1, 3, 10, 30, 59, 60]));
$modes = $envList('K1_SCAN_MODES', ['PACK_INCOMPLETE', 'SOURCE_NOT_DUE', 'STALE_ALREADY_REPLACED', 'OFFICIAL_COMPLETE', 'MIXED_LOCAL_PREFIX']);
$progress = (string) getenv('K1_SCAN_PROGRESS') === '1';
$rawCsvPath = trim((string) getenv('K1_SCAN_RAW_CSV'));
$packDue = (string) getenv('K1_SCAN_PACK_DUE') === '1';
if ($rawCsvPath !== '') {
    $rawCsvDir = dirname($rawCsvPath);
    if (!is_dir($rawCsvDir)) {
        mkdir($rawCsvDir, 0770, true);
    }
    $rawCsv = fopen($rawCsvPath, 'wb');
    if ($rawCsv === false) {
        throw new RuntimeException('k1_scan_raw_csv_open_failed');
    }
    fputcsv($rawCsv, ['mode', 'prefix', 'iteration', 'elapsed_ms', 'rows_scanned', 'claimed', 'effective', 'local_completed', 'local_deferred', 'termination_reason'], ',', '"', '\\');
} else {
    $rawCsv = null;
}
$rawRows = [];
$summaryRows = [];

try {
    $contract = json_decode(
        (string) file_get_contents($root . '/resources/release/queue-v4-canonical-db-contract-2.38.9.json'),
        true,
        64,
        JSON_THROW_ON_ERROR
    );
    foreach ($contract['tables'] as $table => $definition) {
        $columns = [];
        foreach ($definition['columns'] as $column) {
            $sql = $quote((string) $column['COLUMN_NAME']) . ' ' . (string) $column['COLUMN_TYPE'];
            if (($column['CHARACTER_SET_NAME'] ?? null) !== null) {
                $sql .= ' CHARACTER SET ' . (string) $column['CHARACTER_SET_NAME'];
            }
            if (($column['COLLATION_NAME'] ?? null) !== null) {
                $sql .= ' COLLATE ' . (string) $column['COLLATION_NAME'];
            }
            if (str_contains((string) $column['EXTRA'], 'GENERATED')) {
                $expression = match ((string) $column['COLUMN_NAME']) {
                    'default_slot' => "CASE WHEN status='active' AND is_default=1 THEN 1 ELSE NULL END",
                    'available' => 'on_hand-reserved',
                    default => throw new RuntimeException('unknown_generated_column'),
                };
                $sql .= ' GENERATED ALWAYS AS (' . $expression . ') STORED';
            } else {
                $sql .= (string) $column['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL';
                if ($column['COLUMN_DEFAULT'] !== null) {
                    $sql .= ' DEFAULT ' . (string) $column['COLUMN_DEFAULT'];
                }
                if (trim((string) $column['EXTRA']) !== '') {
                    $sql .= ' ' . (string) $column['EXTRA'];
                }
            }
            $columns[] = $sql;
        }
        $indexes = [];
        foreach ($definition['indexes'] as $index) {
            $indexes[(string) $index['INDEX_NAME']][] = $index;
        }
        foreach ($indexes as $name => $parts) {
            usort($parts, static fn (array $a, array $b): int => (int) $a['SEQ_IN_INDEX'] <=> (int) $b['SEQ_IN_INDEX']);
            $indexed = array_map(
                static fn (array $part): string => $quote((string) $part['COLUMN_NAME'])
                    . ($part['SUB_PART'] !== null ? '(' . (int) $part['SUB_PART'] . ')' : ''),
                $parts
            );
            $prefix = $name === 'PRIMARY'
                ? 'PRIMARY KEY'
                : ((int) $parts[0]['NON_UNIQUE'] === 0 ? 'UNIQUE KEY ' . $quote($name) : 'KEY ' . $quote($name));
            $columns[] = $prefix . ' (' . implode(',', $indexed) . ')';
        }
        $pdo->exec(
            'CREATE TABLE ' . $quote((string) $table) . ' (' . implode(',', $columns) . ') ENGINE='
            . $definition['engine'] . ' DEFAULT CHARSET=utf8mb4 COLLATE=' . $definition['collation']
        );
    }
    $pdo->exec('CREATE TABLE companies(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(160) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE internal_products(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE IF NOT EXISTS app_settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NULL,is_encrypted TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $pdo->exec((string) file_get_contents($root . '/database/migrations/299_queue_v4_domain_exact_admission_2_39_3.sql'));
    $pdo->exec('CREATE TABLE order_financial_recalc_jobs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NULL,meli_account_id BIGINT UNSIGNED NULL,mode VARCHAR(30) NOT NULL,source_type VARCHAR(40) NOT NULL,source_id BIGINT NULL,status VARCHAR(30) NOT NULL DEFAULT "pending",created_by BIGINT NULL,current_phase VARCHAR(30) NULL,settings_snapshot_json LONGTEXT NULL,total_items INT NOT NULL DEFAULT 0,processed_items INT NOT NULL DEFAULT 0,error_items INT NOT NULL DEFAULT 0,safe_message VARCHAR(500) NULL,started_at DATETIME NULL,completed_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE order_financial_recalc_job_items(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_financial_recalc_job_id BIGINT UNSIGNED NOT NULL,meli_order_id BIGINT UNSIGNED NOT NULL,external_order_id VARCHAR(64) NOT NULL,status VARCHAR(30) NOT NULL DEFAULT "pending",phase VARCHAR(30) NULL,billing_status VARCHAR(30) NULL,financial_status VARCHAR(30) NULL,UNIQUE KEY uq_recalc_item(order_financial_recalc_job_id,meli_order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec("INSERT INTO companies(id,name) VALUES (10,'K1 Scan')");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,meli_user_id,account_name,status) VALUES (101,10,101001,'K1 Scan','conectado')");
    $pdo->exec("INSERT INTO queue_v4_clean_control(control_key,engine_state,readiness_state,scheduler_enabled) VALUES ('primary','ACTIVE','CERTIFIED',1)");
    $pdo->exec("INSERT INTO app_settings(setting_key,setting_value,is_encrypted) VALUES ('sync.page_limit','50',0)");
    $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,refresh_version) VALUES (?,?,?,?,0)')
        ->execute([101, Crypto::encrypt('k1-scan-access'), Crypto::encrypt('k1-scan-refresh'), gmdate('Y-m-d H:i:s', time() + 3600)]);

    $pdo->exec('CREATE TABLE meli_orders(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_order_id VARCHAR(80) NOT NULL,external_pack_id VARCHAR(80) NULL,external_shipping_id VARCHAR(80) NULL,date_created DATETIME NULL,date_created_local DATETIME NULL,status VARCHAR(40) NULL,total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,currency_id VARCHAR(10) NULL,enrichment_status VARCHAR(40) NULL,UNIQUE KEY uq_order_account_external(meli_account_id,external_order_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE meli_order_items(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_order_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,external_item_id VARCHAR(80) NOT NULL,external_variation_id VARCHAR(80) NULL,quantity DECIMAL(18,4) NOT NULL DEFAULT 1,unit_price DECIMAL(18,2) NOT NULL DEFAULT 100,full_unit_price DECIMAL(18,2) NULL,sale_fee DECIMAL(18,2) NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE meli_payments(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,meli_order_id BIGINT UNSIGNED NOT NULL,external_payment_id VARCHAR(80) NOT NULL,status VARCHAR(40) NULL,status_detail VARCHAR(100) NULL,transaction_amount DECIMAL(18,2) NOT NULL DEFAULT 0,shipping_cost DECIMAL(18,2) NOT NULL DEFAULT 0,coupon_amount DECIMAL(18,2) NOT NULL DEFAULT 0,total_paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,marketplace_fee DECIMAL(18,2) NOT NULL DEFAULT 0,date_approved_utc DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE meli_shipments(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_shipment_id VARCHAR(80),status VARCHAR(40),substatus VARCHAR(40),logistic_type VARCHAR(40),gross_cost DECIMAL(18,2),seller_cost DECIMAL(18,2),buyer_cost DECIMAL(18,2),discounts DECIMAL(18,2)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_packs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_pack_id VARCHAR(80) NOT NULL,integrity_status VARCHAR(40) NULL,UNIQUE KEY uq_pack(meli_account_id,external_pack_id)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_order_financials(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,meli_order_id BIGINT UNSIGNED NOT NULL,product_sold_amount DECIMAL(18,2) NOT NULL DEFAULT 100,local_estimated_net_amount DECIMAL(18,2) NULL,ml_net_amount DECIMAL(18,2) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE sale_financial_state(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,sale_key VARCHAR(191) NOT NULL,external_sale_id VARCHAR(191) NOT NULL,identity_type VARCHAR(20) NOT NULL,input_version CHAR(64) NOT NULL,currency_id VARCHAR(8) NOT NULL,commercial_status VARCHAR(30) NOT NULL,logistics_status VARCHAR(30) NOT NULL,provisional_status VARCHAR(30) NOT NULL,official_status VARCHAR(30) NOT NULL DEFAULT "missing",products_amount DECIMAL(18,2) NULL,provisional_net_amount DECIMAL(18,2) NULL,official_net_amount DECIMAL(18,2) NULL,unknown_concepts_amount DECIMAL(18,2) NULL,official_capture_id BIGINT UNSIGNED NULL,missing_flags_json LONGTEXT NULL,close_impact VARCHAR(40) NULL,sales_control_close_id BIGINT UNSIGNED NULL,projected_at DATETIME NULL,official_at DATETIME NULL,UNIQUE KEY uq_sale_state(company_id,meli_account_id,sale_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE sale_financial_evidence(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,sale_key VARCHAR(191) NOT NULL,input_version CHAR(64) NOT NULL,evidence_type VARCHAR(60) NOT NULL,evidence_status VARCHAR(40) NOT NULL,source_id BIGINT UNSIGNED NULL,payload_hash CHAR(64) NOT NULL,provisional_net_amount DECIMAL(18,2) NULL,official_net_amount DECIMAL(18,2) NULL,evidence_json LONGTEXT NOT NULL,UNIQUE KEY uq_evidence(company_id,meli_account_id,sale_key,input_version,evidence_type,payload_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE sale_financial_reconciliation_jobs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,sale_key VARCHAR(191) NOT NULL,external_sale_id VARCHAR(191) NOT NULL,input_version CHAR(64) NOT NULL,status VARCHAR(30) NOT NULL DEFAULT "pending",priority_tier INT NOT NULL DEFAULT 30,origin_type VARCHAR(40) NOT NULL,origin_id BIGINT NULL,created_by BIGINT NULL,next_run_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,remote_pending_since DATETIME NULL,retry_until DATETIME NULL,last_remote_state VARCHAR(30) NULL,safe_message VARCHAR(500) NULL,completed_at DATETIME NULL,lock_owner VARCHAR(96) NULL,lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,lease_expires_at DATETIME NULL,heartbeat_at DATETIME NULL,attempts INT NOT NULL DEFAULT 0,UNIQUE KEY uq_sale_reconciliation(company_id,meli_account_id,sale_key,input_version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE meli_billing_capture_runs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,sale_key VARCHAR(191) NOT NULL,external_sale_id VARCHAR(191) NOT NULL,input_version CHAR(64) NOT NULL,source_mode VARCHAR(40) NOT NULL,requested_order_ids_json LONGTEXT NOT NULL,http_status INT NULL,response_class VARCHAR(40) NULL,response_hash CHAR(64) NULL,missing_fields_json LONGTEXT NULL,safe_message VARCHAR(500) NULL,captured_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE meli_sale_financials(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,sale_key VARCHAR(191) NOT NULL,external_sale_id VARCHAR(191) NOT NULL,identity_type VARCHAR(20) NOT NULL,currency_id VARCHAR(8) NOT NULL,products_amount DECIMAL(18,2) NULL,sale_fee_amount DECIMAL(18,2) NULL,shipping_charge_amount DECIMAL(18,2) NULL,taxes_amount DECIMAL(18,2) NULL,discounts_amount DECIMAL(18,2) NULL,credits_amount DECIMAL(18,2) NULL,adjustments_amount DECIMAL(18,2) NULL,net_amount DECIMAL(18,2) NULL,local_estimate_amount DECIMAL(18,2) NULL,legacy_difference_amount DECIMAL(18,2) NULL,source VARCHAR(40) NULL,capture_run_id BIGINT UNSIGNED NULL,reconciliation_status VARCHAR(30) NOT NULL,safe_message VARCHAR(500) NULL,reconciled_at DATETIME NULL,methodology_version VARCHAR(40) NULL,UNIQUE KEY uq_sale_financial(meli_account_id,sale_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE meli_sale_financial_lines(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_sale_financial_id BIGINT UNSIGNED NOT NULL,meli_billing_capture_run_id BIGINT UNSIGNED NOT NULL,external_order_id VARCHAR(80) NULL,detail_id VARCHAR(120) NULL,line_group VARCHAR(40),line_type VARCHAR(80),line_subtype VARCHAR(80),description VARCHAR(500),amount DECIMAL(18,2),direction VARCHAR(20),is_shared TINYINT(1),source_status VARCHAR(40),line_hash CHAR(64),occurred_at DATETIME NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_sale_financial_allocations(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_sale_financial_id BIGINT UNSIGNED NOT NULL,meli_order_item_id BIGINT UNSIGNED NOT NULL,gross_amount DECIMAL(18,2),weight_basis VARCHAR(40),sale_fee_allocated DECIMAL(18,2),shipping_allocated DECIMAL(18,2),tax_allocated DECIMAL(18,2),discount_allocated DECIMAL(18,2),credit_allocated DECIMAL(18,2),other_allocated DECIMAL(18,2),net_allocated DECIMAL(18,2)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_sale_financial_history(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_sale_financial_id BIGINT UNSIGNED NOT NULL,revision_no INT NOT NULL,methodology_version VARCHAR(40),totals_json LONGTEXT,lines_summary_json LONGTEXT,allocations_summary_json LONGTEXT,source VARCHAR(40),safe_message VARCHAR(500)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE manual_campaigns(id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20) NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE manual_campaign_reservations(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,manual_campaign_id BIGINT UNSIGNED NOT NULL,queue_key VARCHAR(80) NOT NULL,source_id VARCHAR(80) NOT NULL,status VARCHAR(20) NOT NULL,expires_at DATETIME(3) NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_rhythm_states(scope_key VARCHAR(120) PRIMARY KEY,generation BIGINT UNSIGNED NOT NULL DEFAULT 1,calls_in_block INT UNSIGNED NOT NULL DEFAULT 0,block_started_at DATETIME(3) NULL,next_allowed_at DATETIME(3) NULL,block_pause_until DATETIME(3) NULL,last_dispatched_at DATETIME(3) NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_remote_permits(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,permit_token CHAR(40) NOT NULL,owner_token CHAR(32) NOT NULL,generation BIGINT UNSIGNED NOT NULL,run_token VARCHAR(100) NULL,work_key VARCHAR(120) NULL,company_id BIGINT UNSIGNED NULL,meli_account_id BIGINT UNSIGNED NULL,endpoint_key VARCHAR(120) NOT NULL,job_type VARCHAR(80) NOT NULL,method VARCHAR(10) NOT NULL,status ENUM("reserved","dispatched","completed","released","expired") NOT NULL DEFAULT "reserved",requested_interval_ms INT UNSIGNED NOT NULL,effective_interval_ms INT UNSIGNED NOT NULL,blocking_scope VARCHAR(80) NULL,http_status SMALLINT UNSIGNED NULL,created_at DATETIME(3) NOT NULL,dispatched_at DATETIME(3) NULL,completed_at DATETIME(3) NULL,released_at DATETIME(3) NULL,expires_at DATETIME(3) NOT NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),UNIQUE KEY uq_permit_token(permit_token),KEY idx_permit_active(status,expires_at),KEY idx_permit_endpoint_dispatch(endpoint_key,dispatched_at)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_rhythm_penalties(scope_key VARCHAR(180) PRIMARY KEY,reduced_limit_per_minute SMALLINT UNSIGNED NOT NULL,blocked_until DATETIME(3) NULL,reduced_until DATETIME(3) NOT NULL,reason VARCHAR(80) NOT NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_circuit_breakers(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,endpoint_path VARCHAR(255) NOT NULL,status VARCHAR(40) NOT NULL,blocked_until DATETIME NULL,reason VARCHAR(80) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_request_logs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,company_id BIGINT UNSIGNED NULL,scope_kind VARCHAR(30) NULL,request_id VARCHAR(64) NULL,method VARCHAR(10) NULL,endpoint_path VARCHAR(255) NULL,http_status INT NULL,duration_ms INT NULL,retry_after_seconds INT NULL,attempt INT NULL,was_blocked TINYINT(1) NULL,safe_message VARCHAR(500) NULL,diagnostic_id VARCHAR(100) NULL,error_type VARCHAR(80) NULL,error_code VARCHAR(120) NULL,is_retryable TINYINT(1) NULL,is_app_blocked_signal TINYINT(1) NULL,outcome_class VARCHAR(80) NULL,reached_remote TINYINT(1) NULL,actionable TINYINT(1) NULL,risk_signal TINYINT(1) NULL,incident_key VARCHAR(191) NULL,execution_source VARCHAR(40) NULL,job_type VARCHAR(80) NULL,source_queue_key VARCHAR(80) NULL,source_work_id VARCHAR(100) NULL,operation_key VARCHAR(80) NULL,load_class VARCHAR(40) NULL,workload_units INT NULL,wire_bytes BIGINT NULL,decoded_bytes BIGINT NULL,response_item_count INT NULL,response_count_state VARCHAR(40) NULL,response_resource_unit VARCHAR(40) NULL,fanout_count INT NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),KEY idx_log_created(created_at),KEY idx_log_endpoint_status(endpoint_path,http_status,created_at),KEY idx_log_request(request_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE api_operation_metrics_hourly(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,bucket_started_at DATETIME NOT NULL,account_scope_key BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NULL,operation_key VARCHAR(80) NOT NULL,load_class VARCHAR(40) NOT NULL,sample_count INT NOT NULL,remote_count INT NOT NULL,success_count INT NOT NULL,error_count INT NOT NULL,total_duration_ms BIGINT NOT NULL,max_duration_ms BIGINT NOT NULL,total_wire_bytes BIGINT NOT NULL,total_decoded_bytes BIGINT NOT NULL,total_items BIGINT NOT NULL,total_fanout BIGINT NOT NULL,updated_at DATETIME NOT NULL,UNIQUE KEY uq_metric(bucket_started_at,account_scope_key,operation_key,load_class)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_operation_metric_samples(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,bucket_started_at DATETIME NOT NULL,account_scope_key BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NULL,operation_key VARCHAR(80) NOT NULL,load_class VARCHAR(40) NOT NULL,duration_ms INT NOT NULL,wire_bytes BIGINT NOT NULL,decoded_bytes BIGINT NOT NULL,response_item_count INT NOT NULL,fanout_count INT NOT NULL,http_status INT NULL,reached_remote TINYINT(1) NOT NULL,successful TINYINT(1) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE system_logs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,level VARCHAR(20) NOT NULL,message VARCHAR(500) NOT NULL,context_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE api_budget_windows(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,scope VARCHAR(40) NOT NULL,scope_key VARCHAR(255) NOT NULL,meli_account_id BIGINT UNSIGNED NULL,endpoint_path VARCHAR(255) NULL,job_type VARCHAR(80) NULL,window_started_at DATETIME NOT NULL,window_seconds INT UNSIGNED NOT NULL DEFAULT 900,request_limit INT UNSIGNED NOT NULL DEFAULT 0,request_count INT UNSIGNED NOT NULL DEFAULT 0,error_400_count INT UNSIGNED NOT NULL DEFAULT 0,error_401_count INT UNSIGNED NOT NULL DEFAULT 0,error_403_count INT UNSIGNED NOT NULL DEFAULT 0,error_429_count INT UNSIGNED NOT NULL DEFAULT 0,error_5xx_count INT UNSIGNED NOT NULL DEFAULT 0,last_request_at DATETIME NULL,cooldown_until DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_api_budget_window(scope_key,window_started_at,window_seconds)) ENGINE=InnoDB');

    $reset = static function () use ($pdo): void {
        foreach ([
            'queue_v4_clean_attempts', 'queue_v4_clean_runs', 'queue_v4_clean_jobs',
            'order_financial_recalc_job_items', 'order_financial_recalc_jobs',
            'sale_financial_reconciliation_jobs', 'sale_financial_state', 'sale_financial_evidence',
            'meli_billing_capture_runs', 'meli_sale_financial_lines', 'meli_sale_financial_allocations',
            'meli_sale_financial_history', 'meli_sale_financials', 'meli_order_financials',
            'meli_order_items', 'meli_payments', 'meli_orders', 'meli_packs', 'manual_campaign_reservations',
            'manual_campaigns', 'api_remote_permits', 'api_rhythm_states', 'api_rhythm_penalties',
            'api_circuit_breakers', 'api_request_logs', 'api_operation_metrics_hourly',
            'api_operation_metric_samples', 'api_budget_windows', 'system_logs',
        ] as $table) {
            $pdo->exec('DELETE FROM ' . $table);
        }
    };

    /**
     * @return array{source_id:int,pointer_id:int}
     */
    $seedRecalcEffective = static function (string $prefix) use ($pdo): array {
        $external = '301' . sprintf('%010u', crc32($prefix . microtime(true))) . random_int(1000, 9999);
        $pdo->prepare('INSERT INTO meli_orders(meli_account_id,external_order_id,date_created,date_created_local,status,total_amount,paid_amount,currency_id) VALUES (101,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),"paid",100,100,"COP")')->execute([$external]);
        $orderId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO order_financial_recalc_jobs(company_id,meli_account_id,mode,source_type,status,total_items,processed_items) VALUES (10,101,"repaired","k1_scan","pending",1,0)')->execute();
        $sourceId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO order_financial_recalc_job_items(order_financial_recalc_job_id,meli_order_id,external_order_id,status) VALUES (?,?,?,"pending")')->execute([$sourceId, $orderId, $external]);
        $payload = json_encode(['capability' => 'financial_recalc', 'source_id' => $sourceId], JSON_THROW_ON_ERROR);
        $pdo->prepare('INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at,max_attempts) VALUES (10,101,"domain_exact",?,?,?,"ready","2026-01-01 00:00:01.000",3)')->execute([(string) $sourceId, 'k1-scan-recalc:' . $prefix . ':' . $sourceId, $payload]);
        return ['source_id' => $sourceId, 'pointer_id' => (int) $pdo->lastInsertId()];
    };

    /**
     * @return array{source_id:int,pointer_id:int}
     */
    $seedSimple = static function (string $prefix) use ($pdo): array {
        $external = '101' . sprintf('%010u', crc32($prefix . microtime(true))) . random_int(1000, 9999);
        $pdo->prepare('INSERT INTO meli_orders(meli_account_id,external_order_id,date_created,date_created_local,status,total_amount,paid_amount,currency_id) VALUES (101,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),"paid",100,100,"COP")')->execute([$external]);
        $orderId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO meli_order_items(meli_order_id,meli_account_id,external_item_id,quantity,unit_price,sale_fee) VALUES (?,101,?,1,100,10)')->execute([$orderId, 'ITEM-' . $external]);
        $pdo->prepare('INSERT INTO meli_payments(meli_order_id,meli_account_id,external_payment_id,status,transaction_amount,total_paid_amount,marketplace_fee) VALUES (?,101,?,"approved",100,100,10)')->execute([$orderId, 'PAY-' . $external]);
        $pdo->prepare('INSERT INTO meli_order_financials(company_id,meli_account_id,meli_order_id,product_sold_amount,local_estimated_net_amount) VALUES (10,101,?,100,90)')->execute([$orderId]);
        $saleKey = 'O:' . $external;
        $inputVersion = hash('sha256', 'simple:' . $saleKey . ':' . $prefix);
        $pdo->prepare('INSERT INTO sale_financial_state(company_id,meli_account_id,sale_key,external_sale_id,identity_type,input_version,currency_id,commercial_status,logistics_status,provisional_status,official_status,products_amount,provisional_net_amount) VALUES (10,101,?,?,"order",?,"COP","complete","complete","complete","missing",100,90)')
            ->execute([$saleKey, $external, $inputVersion]);
        $pdo->prepare('INSERT INTO sale_financial_reconciliation_jobs(company_id,meli_account_id,sale_key,external_sale_id,input_version,status,origin_type,next_run_at,attempts) VALUES (10,101,?,?,?,"pending","k1_scan",UTC_TIMESTAMP(),0)')->execute([$saleKey, $external, $inputVersion]);
        $sourceId = (int) $pdo->lastInsertId();
        $payload = json_encode(['capability' => 'financial_reconciliation', 'source_id' => $sourceId], JSON_THROW_ON_ERROR);
        $pdo->prepare('INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at,max_attempts) VALUES (10,101,"domain_exact",?,?,?,"ready","2026-01-01 00:00:00.000",3)')->execute([(string) $sourceId, 'k1-scan:' . $prefix . ':' . $sourceId, $payload]);
        return ['source_id' => $sourceId, 'pointer_id' => (int) $pdo->lastInsertId()];
    };

    /**
     * @return array{source_id:int,pointer_id:int}
     */
    $seedPack = static function (string $prefix) use ($pdo, $packDue): array {
        $external = '201' . sprintf('%010u', crc32($prefix . microtime(true))) . random_int(1000, 9999);
        $saleKey = 'P:' . $external;
        $inputVersion = hash('sha256', 'pack:' . $prefix . ':' . microtime(true));
        $pdo->prepare('INSERT INTO meli_orders(meli_account_id,external_order_id,external_pack_id,date_created,date_created_local,status,total_amount,paid_amount,currency_id) VALUES (101,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),"paid",100,100,"COP")')->execute(['PACK-ORDER-' . $prefix . '-' . random_int(1000, 9999), $external]);
        $pdo->prepare('INSERT INTO meli_packs(meli_account_id,external_pack_id,integrity_status) VALUES (101,?,"incomplete")')->execute([$external]);
        $pdo->prepare('INSERT INTO sale_financial_state(company_id,meli_account_id,sale_key,external_sale_id,identity_type,input_version,currency_id,commercial_status,logistics_status,provisional_status,official_status) VALUES (10,101,?,?,"pack",?,"COP","complete","complete","complete","missing")')->execute([$saleKey, $external, $inputVersion]);
        $nextRunSql = $packDue ? 'UTC_TIMESTAMP()' : 'DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR)';
        $pdo->prepare('INSERT INTO sale_financial_reconciliation_jobs(company_id,meli_account_id,sale_key,external_sale_id,input_version,status,origin_type,next_run_at,attempts,safe_message) VALUES (10,101,?,?,?,"retry","k1_scan_pack",' . $nextRunSql . ',0,"PACK_INCOMPLETE")')->execute([$saleKey, $external, $inputVersion]);
        $sourceId = (int) $pdo->lastInsertId();
        $payload = json_encode(['capability' => 'financial_reconciliation', 'source_id' => $sourceId], JSON_THROW_ON_ERROR);
        $pdo->prepare('INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at,max_attempts) VALUES (10,101,"domain_exact",?,?,?,"ready","2026-01-01 00:00:00.000",3)')->execute([(string) $sourceId, 'k1-scan-pack:' . $prefix . ':' . $sourceId, $payload]);
        return ['source_id' => $sourceId, 'pointer_id' => (int) $pdo->lastInsertId()];
    };

    /**
     * @return array{source_id:int,pointer_id:int}
     */
    $seedLocal = static function (string $mode, string $prefix) use ($pdo, $seedSimple, $seedPack): array {
        if ($mode === 'PACK_INCOMPLETE') {
            return $seedPack($prefix);
        }
        $seeded = $seedSimple($prefix);
        $sourceId = $seeded['source_id'];
        if ($mode === 'SOURCE_NOT_DUE') {
            $pdo->prepare('UPDATE sale_financial_reconciliation_jobs SET next_run_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE id=?')->execute([$sourceId]);
        } elseif ($mode === 'STALE_ALREADY_REPLACED') {
            $current = $pdo->prepare('SELECT sale_key,external_sale_id,input_version,priority_tier FROM sale_financial_reconciliation_jobs WHERE id=?');
            $current->execute([$sourceId]);
            $row = $current->fetch(PDO::FETCH_ASSOC);
            $pdo->prepare('UPDATE sale_financial_reconciliation_jobs SET input_version=? WHERE id=?')->execute([str_repeat('0', 64), $sourceId]);
            $pdo->prepare('INSERT INTO sale_financial_reconciliation_jobs(company_id,meli_account_id,sale_key,external_sale_id,input_version,status,priority_tier,origin_type,origin_id,next_run_at,attempts,completed_at) VALUES (10,101,?,?,?,"complete",?,"domain_input_changed",?,UTC_TIMESTAMP(),0,UTC_TIMESTAMP())')->execute([(string) $row['sale_key'], (string) $row['external_sale_id'], (string) $row['input_version'], (int) $row['priority_tier'], $sourceId]);
            $pdo->prepare('UPDATE sale_financial_reconciliation_jobs SET status="complete",completed_at=UTC_TIMESTAMP(),safe_message="STALE_ALREADY_REPLACED" WHERE id=?')->execute([$sourceId]);
        } elseif ($mode === 'OFFICIAL_COMPLETE') {
            $pdo->prepare('UPDATE sale_financial_state st JOIN sale_financial_reconciliation_jobs s ON s.company_id=st.company_id AND s.meli_account_id=st.meli_account_id AND s.sale_key=st.sale_key SET st.official_status="complete",st.official_net_amount=90 WHERE s.id=?')->execute([$sourceId]);
            $pdo->prepare('UPDATE sale_financial_reconciliation_jobs SET status="complete",completed_at=UTC_TIMESTAMP(),safe_message="OFFICIAL_ALREADY_COMPLETE" WHERE id=?')->execute([$sourceId]);
        }
        return $seeded;
    };

    foreach ($modes as $mode) {
        foreach ($prefixes as $prefix) {
            $observations = [];
            for ($iteration = 1; $iteration <= $iterations; $iteration++) {
                $reset();
                for ($i = 0; $i < $prefix; $i++) {
                    $localMode = $mode === 'MIXED_LOCAL_PREFIX'
                        ? ['PACK_INCOMPLETE', 'SOURCE_NOT_DUE', 'STALE_ALREADY_REPLACED', 'OFFICIAL_COMPLETE'][$i % 4]
                        : $mode;
                    $seedLocal($localMode, $mode . '-' . $prefix . '-' . $iteration . '-' . $i);
                }
                $effective = $seedRecalcEffective($mode . '-effective-' . $prefix . '-' . $iteration);
                $effectiveSourceId = $effective['source_id'];
                $fifoCheck = $pdo->query(
                    'SELECT payload_json
                     FROM queue_v4_clean_jobs
                     WHERE state="ready"
                     ORDER BY available_at ASC,id ASC
                     LIMIT 1'
                )->fetchColumn();
                if ($prefix > 0 && is_string($fifoCheck) && str_contains($fifoCheck, '"financial_recalc"')) {
                    throw new RuntimeException('k1_scan_fixture_fifo_prefix_not_ahead');
                }
                $transport = new K1ScanFakeBillingTransport();
                $repo = new QueueV4CleanRepository($pdo);
                $service = new SaleFinancialService(static fn (int $accountId): MeliApiClient => new MeliApiClient($accountId, $transport));
                $domainHandler = static function (string $capability, int $sourceId, int $accountId) use ($pdo): array {
                    if ($capability !== 'financial_recalc') {
                        throw new RuntimeException('unexpected_domain_handler_capability');
                    }
                    $pdo->prepare('UPDATE order_financial_recalc_jobs SET processed_items=processed_items+1 WHERE id=? AND meli_account_id=?')
                        ->execute([$sourceId, $accountId]);
                    return ['processed' => 1];
                };
                $worker = new QueueV4CleanWorker($pdo, $repo, null, null, null, $domainHandler, static fn (): SaleFinancialService => $service);
                $started = hrtime(true);
                $worker->run('test', 1, 45);
                $elapsed = (hrtime(true) - $started) / 1_000_000;
                $metrics = $worker->lastMetrics();
                $termination = (int) $metrics['effective'] > 0
                    ? 'EFFECTIVE_FOUND'
                    : ((int) $metrics['scanned'] >= $maxScanRows ? 'MAX_SCAN_ROWS' : 'END_OF_QUEUE');
                if ($termination === 'END_OF_QUEUE' && $elapsed > $maxScanTimeMs) {
                    $termination = 'MAX_SCAN_TIME_MS';
                }
                $rawRows[] = [
                    'mode' => $mode,
                    'prefix' => $prefix,
                    'iteration' => $iteration,
                    'elapsed_ms' => round($elapsed, 3),
                    'rows_scanned' => (int) $metrics['scanned'],
                    'claimed' => (int) $metrics['claimed'],
                    'effective' => (int) $metrics['effective'],
                    'local_completed' => (int) $metrics['local_completed'],
                    'local_deferred' => (int) $metrics['local_deferred'],
                    'termination_reason' => $termination,
                ];
                if (is_resource($rawCsv)) {
                    fputcsv($rawCsv, [
                        $mode,
                        $prefix,
                        $iteration,
                        round($elapsed, 3),
                        (int) $metrics['scanned'],
                        (int) $metrics['claimed'],
                        (int) $metrics['effective'],
                        (int) $metrics['local_completed'],
                        (int) $metrics['local_deferred'],
                        $termination,
                    ], ',', '"', '\\');
                    fflush($rawCsv);
                }
                if ($progress) {
                    fwrite(
                        STDERR,
                        'K1_SCAN_PROGRESS mode=' . $mode
                        . ' prefix=' . $prefix
                        . ' iteration=' . $iteration . '/' . $iterations
                        . ' elapsed_ms=' . round($elapsed, 3)
                        . ' scanned=' . (int) $metrics['scanned']
                        . ' effective=' . (int) $metrics['effective']
                        . ' termination=' . $termination
                        . PHP_EOL
                    );
                }
                $observations[] = $elapsed;
            }
            $p = k1ScanPercentiles($observations);
            $summaryRows[] = [
                'mode' => $mode,
                'prefix' => $prefix,
                'iterations' => $iterations,
                'p50_ms' => round($p[50], 3),
                'p95_ms' => round($p[95], 3),
                'p99_ms' => round($p[99], 3),
                'max_ms' => round(max($observations), 3),
            ];
        }
    }
} finally {
    if (isset($rawCsv) && is_resource($rawCsv)) {
        fclose($rawCsv);
    }
    $server->exec('DROP DATABASE IF EXISTS ' . $quote($database));
}

$prefix59 = array_values(array_filter($rawRows, static fn (array $row): bool => (int) $row['prefix'] === 59 && (int) $row['effective'] === 1 && (int) $row['rows_scanned'] === 60 && (string) $row['termination_reason'] === 'EFFECTIVE_FOUND'));
$prefix60 = array_values(array_filter($rawRows, static fn (array $row): bool => (int) $row['prefix'] === 60 && (int) $row['effective'] === 0 && (int) $row['rows_scanned'] === 60 && (string) $row['termination_reason'] === 'MAX_SCAN_ROWS'));
$allElapsed = array_map(static fn (array $row): float => (float) $row['elapsed_ms'], $rawRows);
$allP = k1ScanPercentiles($allElapsed);
$timeStopped = array_values(array_filter($rawRows, static fn (array $row): bool => (string) $row['termination_reason'] === 'MAX_SCAN_TIME_MS'));
$pass = count($prefix59) === count($modes) * $iterations
    && count($prefix60) === count($modes) * $iterations
    && max(array_map(static fn (array $row): int => (int) $row['rows_scanned'], $rawRows)) <= $maxScanRows
    && $timeStopped === [];

fwrite(STDOUT, 'QUEUE_V4_K1_REAL_WORKER_SCAN_BENCHMARK=' . ($pass ? 'PASS' : 'FAIL')
    . ' MAX_SCAN_ROWS=' . $maxScanRows
    . ' MAX_SCAN_TIME_MS=' . $maxScanTimeMs
    . ' PREFIX_59_EFFECTIVE_REACHED=' . (count($prefix59) === count($modes) * $iterations ? 'PASS' : 'FAIL')
    . ' PREFIX_60_ROW_CAP=' . (count($prefix60) === count($modes) * $iterations ? 'PASS' : 'FAIL')
    . ' SCAN_P50_MS=' . round($allP[50], 3)
    . ' SCAN_P95_MS=' . round($allP[95], 3)
    . ' SCAN_P99_MS=' . round($allP[99], 3)
    . ' SCAN_MAX_MS=' . round(max($allElapsed), 3)
    . ' REAL_MELI_HTTP=0'
    . ($rawCsvPath !== '' ? ' RAW_CSV_FILE=' . $rawCsvPath : ' RAW_CSV=' . json_encode($rawRows, JSON_THROW_ON_ERROR))
    . ' SUMMARY=' . json_encode($summaryRows, JSON_THROW_ON_ERROR)
    . PHP_EOL);
exit($pass ? 0 : 1);
