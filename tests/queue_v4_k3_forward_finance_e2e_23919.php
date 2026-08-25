<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Crypto;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\MeliApiClient;
use App\Services\MeliHttpTransportInterface;
use App\Services\OrderFinancialRecalcJobService;
use App\Services\SaleFinancialService;

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: forward finance e2e exige un DSN MariaDB desechable sin dbname.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_k3_forward_finance_' . bin2hex(random_bytes(5));
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';

try {
    putenv('APP_KEY=queue-v4-k3-forward-finance-e2e');
    $_ENV['APP_KEY'] = 'queue-v4-k3-forward-finance-e2e';
    putenv('ML_WRITE_ENABLED=false');
    $_ENV['ML_WRITE_ENABLED'] = 'false';
    $temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-k3-forward-finance-' . bin2hex(random_bytes(5));
    @mkdir($temporary . DIRECTORY_SEPARATOR . 'storage', 0770, true);
    define('ERP_SHARED_ROOT', $temporary);
    spl_autoload_register(static function (string $class) use ($root): void {
        if (!str_starts_with($class, 'App\\')) {
            return;
        }
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });

    final class K3ForwardFinanceBillingTransport implements MeliHttpTransportInterface
    {
        /** @var list<array{path:string,order_ids:list<string>}> */
        public array $calls = [];

        public function request(
            string $method,
            string $url,
            array $data,
            array $headers,
            bool $form,
            array $timeouts
        ): array {
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

    $server = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $version = (string) $server->query('SELECT VERSION()')->fetchColumn();
    if (stripos($version, 'mariadb') === false || version_compare(preg_replace('/[^0-9.].*$/', '', $version) ?: '0', '11.8.0', '<')) {
        fwrite(STDERR, "ERROR: forward finance e2e exige MariaDB 11.8+.\n");
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

    $contract = json_decode((string) file_get_contents($root . '/resources/release/queue-v4-canonical-db-contract-2.38.9.json'), true, 64, JSON_THROW_ON_ERROR);
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
    $pdo->exec((string) file_get_contents($root . '/database/migrations/299_queue_v4_domain_exact_admission_2_39_3.sql'));
    $pdo->exec('CREATE TABLE companies(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(160) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE users(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(160) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE IF NOT EXISTS app_settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NULL,is_encrypted TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE system_logs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,level VARCHAR(20) NOT NULL,message VARCHAR(500) NOT NULL,context_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_orders(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_order_id VARCHAR(80) NOT NULL,external_pack_id VARCHAR(80) NULL,external_shipping_id VARCHAR(80) NULL,date_created DATETIME NULL,date_created_local DATETIME NULL,status VARCHAR(40) NULL,total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,currency_id VARCHAR(10) NULL,enrichment_status VARCHAR(40) NULL,UNIQUE KEY uq_order_account_external(meli_account_id,external_order_id)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_order_items(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_order_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,external_item_id VARCHAR(80) NOT NULL,external_variation_id VARCHAR(80) NULL,quantity DECIMAL(18,4) NOT NULL DEFAULT 1,unit_price DECIMAL(18,2) NOT NULL DEFAULT 100,full_unit_price DECIMAL(18,2) NULL,sale_fee DECIMAL(18,2) NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_payments(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,meli_order_id BIGINT UNSIGNED NOT NULL,external_payment_id VARCHAR(80) NOT NULL,status VARCHAR(40) NULL,status_detail VARCHAR(100) NULL,transaction_amount DECIMAL(18,2) NOT NULL DEFAULT 0,shipping_cost DECIMAL(18,2) NOT NULL DEFAULT 0,coupon_amount DECIMAL(18,2) NOT NULL DEFAULT 0,total_paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,marketplace_fee DECIMAL(18,2) NOT NULL DEFAULT 0,date_approved DATETIME NULL,date_approved_utc DATETIME NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_shipments(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,meli_order_id BIGINT UNSIGNED NULL,external_shipment_id VARCHAR(80),status VARCHAR(40),substatus VARCHAR(40),logistic_type VARCHAR(40),gross_cost DECIMAL(18,2),seller_cost DECIMAL(18,2),buyer_cost DECIMAL(18,2),discounts DECIMAL(18,2)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_packs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_pack_id VARCHAR(80) NOT NULL,integrity_status VARCHAR(40) NULL,UNIQUE KEY uq_pack(meli_account_id,external_pack_id)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_items(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_item_id VARCHAR(80) NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE product_meli_links(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,meli_item_id BIGINT UNSIGNED NOT NULL,meli_variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,internal_product_id BIGINT UNSIGNED NOT NULL,status VARCHAR(20) NOT NULL,conversion_factor DECIMAL(18,6) NOT NULL DEFAULT 1) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE internal_products(id BIGINT UNSIGNED PRIMARY KEY,manual_cost DECIMAL(18,2) NULL,deleted_at DATETIME NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE sale_financial_state(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,sale_key VARCHAR(191) NOT NULL,external_sale_id VARCHAR(191) NOT NULL,identity_type VARCHAR(20) NOT NULL,input_version CHAR(64) NOT NULL,currency_id VARCHAR(8) NOT NULL,commercial_status VARCHAR(30) NOT NULL,logistics_status VARCHAR(30) NOT NULL,provisional_status VARCHAR(30) NOT NULL,official_status VARCHAR(30) NOT NULL DEFAULT "missing",products_amount DECIMAL(18,2) NULL,provisional_net_amount DECIMAL(18,2) NULL,official_net_amount DECIMAL(18,2) NULL,unknown_concepts_amount DECIMAL(18,2) NULL,official_capture_id BIGINT UNSIGNED NULL,missing_flags_json LONGTEXT NULL,close_impact VARCHAR(40) NULL,sales_control_close_id BIGINT UNSIGNED NULL,projected_at DATETIME NULL,official_at DATETIME NULL,UNIQUE KEY uq_sale_state(company_id,meli_account_id,sale_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE sale_financial_evidence(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,sale_key VARCHAR(191) NOT NULL,input_version CHAR(64) NOT NULL,evidence_type VARCHAR(60) NOT NULL,evidence_status VARCHAR(40) NOT NULL,source_id BIGINT UNSIGNED NULL,payload_hash CHAR(64) NOT NULL,provisional_net_amount DECIMAL(18,2) NULL,official_net_amount DECIMAL(18,2) NULL,evidence_json LONGTEXT NOT NULL,UNIQUE KEY uq_evidence(company_id,meli_account_id,sale_key,input_version,evidence_type,payload_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE sale_financial_reconciliation_jobs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,sale_key VARCHAR(191) NOT NULL,external_sale_id VARCHAR(191) NOT NULL,input_version CHAR(64) NOT NULL,status VARCHAR(30) NOT NULL DEFAULT "pending",priority_tier INT NOT NULL DEFAULT 30,origin_type VARCHAR(40) NOT NULL,origin_id BIGINT NULL,created_by BIGINT NULL,next_run_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,remote_pending_since DATETIME NULL,retry_until DATETIME NULL,last_remote_state VARCHAR(30) NULL,safe_message VARCHAR(500) NULL,completed_at DATETIME NULL,lock_owner VARCHAR(96) NULL,lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,lease_expires_at DATETIME NULL,heartbeat_at DATETIME NULL,attempts INT NOT NULL DEFAULT 0,UNIQUE KEY uq_sale_reconciliation(company_id,meli_account_id,sale_key,input_version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE meli_billing_capture_runs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,sale_key VARCHAR(191) NOT NULL,external_sale_id VARCHAR(191) NOT NULL,input_version CHAR(64) NOT NULL,source_mode VARCHAR(40) NOT NULL,requested_order_ids_json LONGTEXT NOT NULL,http_status INT NULL,response_class VARCHAR(40) NULL,response_hash CHAR(64) NULL,missing_fields_json LONGTEXT NULL,safe_message VARCHAR(500) NULL,captured_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE meli_sale_financials(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,sale_key VARCHAR(191) NOT NULL,external_sale_id VARCHAR(191) NOT NULL,identity_type VARCHAR(20) NOT NULL,currency_id VARCHAR(8) NOT NULL,products_amount DECIMAL(18,2) NULL,sale_fee_amount DECIMAL(18,2) NULL,shipping_charge_amount DECIMAL(18,2) NULL,taxes_amount DECIMAL(18,2) NULL,discounts_amount DECIMAL(18,2) NULL,credits_amount DECIMAL(18,2) NULL,adjustments_amount DECIMAL(18,2) NULL,net_amount DECIMAL(18,2) NULL,local_estimate_amount DECIMAL(18,2) NULL,legacy_difference_amount DECIMAL(18,2) NULL,source VARCHAR(40) NULL,capture_run_id BIGINT UNSIGNED NULL,reconciliation_status VARCHAR(30) NOT NULL,safe_message VARCHAR(500) NULL,reconciled_at DATETIME NULL,methodology_version VARCHAR(40) NULL,UNIQUE KEY uq_sale_financial(meli_account_id,sale_key)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_sale_financial_lines(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_sale_financial_id BIGINT UNSIGNED NOT NULL,meli_billing_capture_run_id BIGINT UNSIGNED NOT NULL,external_order_id VARCHAR(80) NULL,detail_id VARCHAR(120) NULL,line_group VARCHAR(40),line_type VARCHAR(80),line_subtype VARCHAR(80),description VARCHAR(500),amount DECIMAL(18,2),direction VARCHAR(20),is_shared TINYINT(1),source_status VARCHAR(40),line_hash CHAR(64),occurred_at DATETIME NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_sale_financial_allocations(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_sale_financial_id BIGINT UNSIGNED NOT NULL,meli_order_item_id BIGINT UNSIGNED NOT NULL,gross_amount DECIMAL(18,2),weight_basis VARCHAR(40),sale_fee_allocated DECIMAL(18,2),shipping_allocated DECIMAL(18,2),tax_allocated DECIMAL(18,2),discount_allocated DECIMAL(18,2),credit_allocated DECIMAL(18,2),other_allocated DECIMAL(18,2),net_allocated DECIMAL(18,2)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_sale_financial_history(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_sale_financial_id BIGINT UNSIGNED NOT NULL,revision_no INT NOT NULL,methodology_version VARCHAR(40),totals_json LONGTEXT,lines_summary_json LONGTEXT,allocations_summary_json LONGTEXT,source VARCHAR(40),safe_message VARCHAR(500)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_order_financials(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,meli_order_id BIGINT UNSIGNED NOT NULL,external_order_id VARCHAR(80) NOT NULL,currency_id VARCHAR(10) NULL,product_sold_amount DECIMAL(18,2) NOT NULL DEFAULT 0,discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0,buyer_shipping_paid DECIMAL(18,2) NOT NULL DEFAULT 0,buyer_paid_total DECIMAL(18,2) NOT NULL DEFAULT 0,sale_fee_amount DECIMAL(18,2) NOT NULL DEFAULT 0,sale_fee_rate DECIMAL(18,4) NULL,ml_shipping_charge DECIMAL(18,2) NOT NULL DEFAULT 0,shipping_net_amount DECIMAL(18,2) NOT NULL DEFAULT 0,other_charges_amount DECIMAL(18,2) NOT NULL DEFAULT 0,bonifications_amount DECIMAL(18,2) NOT NULL DEFAULT 0,taxes_amount DECIMAL(18,2) NOT NULL DEFAULT 0,withholdings_amount DECIMAL(18,2) NOT NULL DEFAULT 0,adjustments_amount DECIMAL(18,2) NOT NULL DEFAULT 0,ml_net_amount DECIMAL(18,2) NOT NULL DEFAULT 0,internal_cost_amount DECIMAL(18,2) NULL,packaging_cost_amount DECIMAL(18,2) NOT NULL DEFAULT 0,other_internal_costs_amount DECIMAL(18,2) NOT NULL DEFAULT 0,erp_profit_amount DECIMAL(18,2) NULL,erp_margin_percent DECIMAL(18,4) NULL,erp_calculated_net DECIMAL(18,2) NOT NULL DEFAULT 0,difference_amount DECIMAL(18,2) NOT NULL DEFAULT 0,missing_cost_items_count INT NOT NULL DEFAULT 0,missing_link_items_count INT NOT NULL DEFAULT 0,billing_status VARCHAR(40) NOT NULL DEFAULT "pending",reconciliation_status VARCHAR(40) NOT NULL DEFAULT "pending",source VARCHAR(40) NULL,safe_message VARCHAR(500) NULL,last_billing_sync_at DATETIME NULL,raw_summary_json LONGTEXT NULL,tax_withholding_amount DECIMAL(18,2) NULL,tax_retention_source_amount DECIMAL(18,2) NULL,tax_reteiva_amount DECIMAL(18,2) NULL,tax_other_amount DECIMAL(18,2) NULL,local_estimated_net_amount DECIMAL(18,2) NULL,billing_reconciled_net_amount DECIMAL(18,2) NULL,reconciliation_difference DECIMAL(18,2) NULL,financial_source VARCHAR(40) NULL,billing_import_status VARCHAR(40) NULL,billing_imported_at DATETIME NULL,UNIQUE KEY uq_order_financial(meli_order_id)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE order_financial_recalc_jobs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NULL,meli_account_id BIGINT UNSIGNED NULL,date_from DATETIME NULL,date_to DATETIME NULL,mode VARCHAR(30) NOT NULL,source_type VARCHAR(40) NOT NULL,source_id BIGINT NULL,status VARCHAR(30) NOT NULL DEFAULT "pending",created_by BIGINT NULL,current_phase VARCHAR(30) NULL,settings_snapshot_json LONGTEXT NULL,total_items INT NOT NULL DEFAULT 0,processed_items INT NOT NULL DEFAULT 0,error_items INT NOT NULL DEFAULT 0,local_recalc_items INT NOT NULL DEFAULT 0,billing_needed_items INT NOT NULL DEFAULT 0,billing_imported_items INT NOT NULL DEFAULT 0,matched_items INT NOT NULL DEFAULT 0,partial_items INT NOT NULL DEFAULT 0,safe_message VARCHAR(500) NULL,started_at DATETIME NULL,completed_at DATETIME NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE order_financial_recalc_job_items(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_financial_recalc_job_id BIGINT UNSIGNED NOT NULL,meli_order_id BIGINT UNSIGNED NOT NULL,external_order_id VARCHAR(64) NOT NULL,status VARCHAR(30) NOT NULL DEFAULT "pending",phase VARCHAR(30) NULL,requires_billing TINYINT(1) NULL,billing_status VARCHAR(30) NULL,financial_status VARCHAR(30) NULL,missing_flags VARCHAR(255) NULL,error_message VARCHAR(500) NULL,diagnostic_message VARCHAR(500) NULL,processed_at DATETIME NULL,UNIQUE KEY uq_recalc_item(order_financial_recalc_job_id,meli_order_id)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_rhythm_states(scope_key VARCHAR(120) PRIMARY KEY,generation BIGINT UNSIGNED NOT NULL DEFAULT 1,calls_in_block INT UNSIGNED NOT NULL DEFAULT 0,block_started_at DATETIME(3) NULL,next_allowed_at DATETIME(3) NULL,block_pause_until DATETIME(3) NULL,last_dispatched_at DATETIME(3) NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_remote_permits(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,permit_token CHAR(40) NOT NULL,owner_token CHAR(32) NOT NULL,generation BIGINT UNSIGNED NOT NULL,run_token VARCHAR(100) NULL,work_key VARCHAR(120) NULL,company_id BIGINT UNSIGNED NULL,meli_account_id BIGINT UNSIGNED NULL,endpoint_key VARCHAR(120) NOT NULL,job_type VARCHAR(80) NOT NULL,method VARCHAR(10) NOT NULL,status ENUM("reserved","dispatched","completed","released","expired") NOT NULL DEFAULT "reserved",requested_interval_ms INT UNSIGNED NOT NULL,effective_interval_ms INT UNSIGNED NOT NULL,blocking_scope VARCHAR(80) NULL,http_status SMALLINT UNSIGNED NULL,created_at DATETIME(3) NOT NULL,dispatched_at DATETIME(3) NULL,completed_at DATETIME(3) NULL,released_at DATETIME(3) NULL,expires_at DATETIME(3) NOT NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),UNIQUE KEY uq_permit_token(permit_token),KEY idx_permit_endpoint_dispatch(endpoint_key,dispatched_at)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_rhythm_penalties(scope_key VARCHAR(180) PRIMARY KEY,reduced_limit_per_minute SMALLINT UNSIGNED NOT NULL,blocked_until DATETIME(3) NULL,reduced_until DATETIME(3) NOT NULL,reason VARCHAR(80) NOT NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_circuit_breakers(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,endpoint_path VARCHAR(255) NOT NULL,status VARCHAR(40) NOT NULL,blocked_until DATETIME NULL,reason VARCHAR(80) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_request_logs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,company_id BIGINT UNSIGNED NULL,method VARCHAR(10) NULL,endpoint_path VARCHAR(255) NULL,http_status INT NULL,duration_ms INT NULL,retry_after_seconds INT NULL,attempt INT NULL,was_blocked TINYINT(1) NULL,safe_message VARCHAR(500) NULL,diagnostic_id VARCHAR(100) NULL,error_type VARCHAR(80) NULL,error_code VARCHAR(120) NULL,is_retryable TINYINT(1) NULL,is_app_blocked_signal TINYINT(1) NULL,outcome_class VARCHAR(80) NULL,reached_remote TINYINT(1) NULL,actionable TINYINT(1) NULL,risk_signal TINYINT(1) NULL,incident_key VARCHAR(191) NULL,execution_source VARCHAR(40) NULL,job_type VARCHAR(80) NULL,source_queue_key VARCHAR(80) NULL,source_work_id VARCHAR(100) NULL,operation_key VARCHAR(80) NULL,load_class VARCHAR(40) NULL,workload_units INT NULL,wire_bytes BIGINT NULL,decoded_bytes BIGINT NULL,response_item_count INT NULL,response_count_state VARCHAR(40) NULL,response_resource_unit VARCHAR(40) NULL,fanout_count INT NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_operation_metrics_hourly(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,bucket_started_at DATETIME NOT NULL,account_scope_key BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NULL,operation_key VARCHAR(80) NOT NULL,load_class VARCHAR(40) NOT NULL,sample_count INT NOT NULL,remote_count INT NOT NULL,success_count INT NOT NULL,error_count INT NOT NULL,total_duration_ms BIGINT NOT NULL,max_duration_ms BIGINT NOT NULL,total_wire_bytes BIGINT NOT NULL,total_decoded_bytes BIGINT NOT NULL,total_items BIGINT NOT NULL,total_fanout BIGINT NOT NULL,updated_at DATETIME NOT NULL,UNIQUE KEY uq_metric(bucket_started_at,account_scope_key,operation_key,load_class)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_operation_metric_samples(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,bucket_started_at DATETIME NOT NULL,account_scope_key BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NULL,operation_key VARCHAR(80) NOT NULL,load_class VARCHAR(40) NOT NULL,duration_ms INT NOT NULL,wire_bytes BIGINT NOT NULL,decoded_bytes BIGINT NOT NULL,response_item_count INT NOT NULL,fanout_count INT NOT NULL,http_status INT NULL,reached_remote TINYINT(1) NOT NULL,successful TINYINT(1) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_budget_windows(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,scope VARCHAR(40) NOT NULL,scope_key VARCHAR(255) NOT NULL,meli_account_id BIGINT UNSIGNED NULL,endpoint_path VARCHAR(255) NULL,job_type VARCHAR(80) NULL,window_started_at DATETIME NOT NULL,window_seconds INT UNSIGNED NOT NULL DEFAULT 900,request_limit INT UNSIGNED NOT NULL DEFAULT 0,request_count INT UNSIGNED NOT NULL DEFAULT 0,error_400_count INT UNSIGNED NOT NULL DEFAULT 0,error_401_count INT UNSIGNED NOT NULL DEFAULT 0,error_403_count INT UNSIGNED NOT NULL DEFAULT 0,error_429_count INT UNSIGNED NOT NULL DEFAULT 0,error_5xx_count INT UNSIGNED NOT NULL DEFAULT 0,last_request_at DATETIME NULL,cooldown_until DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_api_budget_window(scope_key,window_started_at,window_seconds)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE manual_campaigns(id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20) NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE manual_campaign_reservations(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,manual_campaign_id BIGINT UNSIGNED NOT NULL,queue_key VARCHAR(80) NOT NULL,source_id VARCHAR(80) NOT NULL,status VARCHAR(20) NOT NULL,expires_at DATETIME(3) NOT NULL) ENGINE=InnoDB');

    $pdo->exec("INSERT INTO companies(id,name) VALUES (10,'K3')");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,meli_user_id,account_name,status) VALUES (101,10,101001,'K3','conectado')");
    $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,refresh_version) VALUES (?,?,?,?,0)')
        ->execute([101, Crypto::encrypt('k3-access'), Crypto::encrypt('k3-refresh'), gmdate('Y-m-d H:i:s', time() + 3600)]);
    $pdo->exec("INSERT INTO queue_v4_clean_control(control_key,engine_state,scheduler_enabled) VALUES ('primary','ACTIVE',1)");
    $pdo->exec("INSERT INTO app_settings(setting_key,setting_value,is_encrypted) VALUES ('sync.page_limit','50',0),('sales_financial.commercial_pipeline_enabled','1',0)");
    $pdo->exec('INSERT INTO internal_products(id,manual_cost,deleted_at) VALUES (9001,50,NULL)');

    $external = '10177700001';
    $pdo->prepare('INSERT INTO meli_orders(meli_account_id,external_order_id,date_created,date_created_local,status,total_amount,paid_amount,currency_id,enrichment_status) VALUES (?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),"paid",100,100,"COP","complete")')
        ->execute([101, $external]);
    $orderId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO meli_items(meli_account_id,external_item_id) VALUES (?,?)')->execute([101, 'ITEM-' . $external]);
    $itemId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO product_meli_links(meli_account_id,meli_item_id,meli_variation_id,internal_product_id,status) VALUES (?,?,?,?,"active")')
        ->execute([101, $itemId, 0, 9001]);
    $pdo->prepare('INSERT INTO meli_order_items(meli_order_id,meli_account_id,external_item_id,quantity,unit_price,sale_fee) VALUES (?,?,?,1,100,10)')
        ->execute([$orderId, 101, 'ITEM-' . $external]);
    $pdo->prepare('INSERT INTO meli_payments(meli_order_id,meli_account_id,external_payment_id,status,transaction_amount,total_paid_amount,marketplace_fee,date_approved,date_approved_utc) VALUES (?,?,?,"approved",100,100,10,UTC_TIMESTAMP(),UTC_TIMESTAMP())')
        ->execute([$orderId, 101, 'PAY-' . $external]);

    $recalcService = new OrderFinancialRecalcJobService();
    $recalcJobId = $recalcService->createForOrderIds([$orderId], 'repaired', 'sales_repair', 7001, null);
    $assert($recalcJobId > 0, 'RECALC_SOURCE_CREATED=1');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type="domain_exact" AND resource_id="' . $recalcJobId . '" AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.capability"))="financial_recalc"')->fetchColumn() === 1, 'RECALC_POINTER_CREATED=1');

    $recalcWorker = new QueueV4CleanWorker($pdo, new QueueV4CleanRepository($pdo));
    $recalcSummary = $recalcWorker->run('test', 1, 45);
    $assert((int) ($recalcSummary['completed'] ?? 0) === 1, 'RECALC_PROCESSED summary=' . json_encode($recalcSummary) . ' jobs=' . json_encode($pdo->query('SELECT id,status,current_phase,total_items,processed_items,error_items,safe_message FROM order_financial_recalc_jobs')->fetchAll(PDO::FETCH_ASSOC)) . ' attempts=' . json_encode($pdo->query('SELECT outcome,error_class FROM queue_v4_clean_attempts')->fetchAll(PDO::FETCH_ASSOC)));
    $assert((string) $pdo->query('SELECT status FROM order_financial_recalc_jobs WHERE id=' . $recalcJobId)->fetchColumn() === 'complete', 'RECALC_SOURCE_FINAL=complete');
    $assert((string) $pdo->query('SELECT state FROM queue_v4_clean_jobs WHERE resource_id="' . $recalcJobId . '" AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.capability"))="financial_recalc"')->fetchColumn() === 'completed', 'RECALC_POINTER_FINAL=completed');

    $reconSourceId = (int) $pdo->query('SELECT id FROM sale_financial_reconciliation_jobs WHERE origin_type="financial_recalc_local" AND origin_id=' . $recalcJobId . ' LIMIT 1')->fetchColumn();
    $assert($reconSourceId > 0, 'FINANCIAL_RECONCILIATION_SOURCE_CREATED=1');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type="domain_exact" AND resource_id="' . $reconSourceId . '" AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.capability"))="financial_reconciliation"')->fetchColumn() === 1, 'CANONICAL_RECONCILIATION_POINTER_CREATED=1');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM sale_financial_reconciliation_jobs WHERE id=' . $reconSourceId . ' AND company_id=10 AND meli_account_id=101')->fetchColumn() === 1, 'RECONCILIATION_TENANT_MATCH=PASS');

    $transport = new K3ForwardFinanceBillingTransport();
    $billingService = new SaleFinancialService(static fn (int $accountId): MeliApiClient => new MeliApiClient($accountId, $transport));
    $billingWorker = new QueueV4CleanWorker($pdo, new QueueV4CleanRepository($pdo), null, null, null, null, static fn (): SaleFinancialService => $billingService);
    $billingSummary = $billingWorker->run('test', 1, 45);
    $assert((int) ($billingSummary['completed'] ?? 0) === 1, 'RECONCILIATION_PROCESSED=1 summary=' . json_encode($billingSummary) . ' recon=' . json_encode($pdo->query('SELECT id,status,safe_message,attempts,next_run_at FROM sale_financial_reconciliation_jobs')->fetchAll(PDO::FETCH_ASSOC)) . ' queue=' . json_encode($pdo->query('SELECT state,last_error_class,attempt_count FROM queue_v4_clean_jobs WHERE resource_id="' . $reconSourceId . '"')->fetchAll(PDO::FETCH_ASSOC)) . ' calls=' . json_encode($transport->calls) . ' attempts=' . json_encode($pdo->query('SELECT outcome,error_class FROM queue_v4_clean_attempts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)));
    $assert(count($transport->calls) === 1 && $transport->calls[0]['order_ids'] === [$external], 'FAKE_BILLING_REQUESTS=1');
    $assert((string) $pdo->query('SELECT status FROM sale_financial_reconciliation_jobs WHERE id=' . $reconSourceId)->fetchColumn() === 'complete', 'RECON_SOURCE_FINAL=complete');
    $assert((string) $pdo->query('SELECT state FROM queue_v4_clean_jobs WHERE resource_id="' . $reconSourceId . '" AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.capability"))="financial_reconciliation"')->fetchColumn() === 'completed', 'RECON_POINTER_FINAL=completed');

    $recalcAgain = $recalcService->createForOrderIds([$orderId], 'repaired', 'sales_repair', 7001, null);
    $assert($recalcAgain > $recalcJobId, 'IDEMPOTENT_RECALC_TRIGGER_CREATED_NEW_SOURCE_VERSION_ALLOWED');
    $recalcWorker->run('test', 1, 45);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM sale_financial_reconciliation_jobs WHERE sale_key="O:' . $external . '"')->fetchColumn() === 1, 'NO_DUPLICATE_RECONCILIATION_SOURCE_VERSION');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE JSON_UNQUOTE(JSON_EXTRACT(payload_json,"$.capability"))="financial_reconciliation"')->fetchColumn() === 1, 'NO_DUPLICATE_CANONICAL_RECONCILIATION_POINTER');
    $billingWorker->run('test', 1, 45);
    $assert(count($transport->calls) === 1, 'NO_SECOND_BILLING_EXECUTION_FOR_COMPLETED_VERSION');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs q LEFT JOIN sale_financial_reconciliation_jobs s ON s.id=CAST(q.resource_id AS UNSIGNED) WHERE JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,"$.capability"))="financial_reconciliation" AND (s.id IS NULL OR s.company_id<>q.company_id OR s.meli_account_id<>q.meli_account_id)')->fetchColumn() === 0, 'LOST_WORK=0');

    fwrite(
        STDOUT,
        'QUEUE_V4_K3_FORWARD_FINANCE_E2E_23919=PASS'
        . ' RECALC_SOURCE_CREATED=1'
        . ' RECALC_POINTER_CREATED=1'
        . ' RECALC_SOURCE_FINAL=complete'
        . ' RECALC_POINTER_FINAL=completed'
        . ' FINANCIAL_RECONCILIATION_SOURCE_CREATED=1'
        . ' CANONICAL_RECONCILIATION_POINTER_CREATED=1'
        . ' FAKE_BILLING_REQUESTS=1'
        . ' RECON_SOURCE_FINAL=complete'
        . ' RECON_POINTER_FINAL=completed'
        . ' LOST_WORK=0'
        . ' DUPLICATE_WORK=0'
        . ' DOUBLE_FINALIZATION=0'
        . ' CROSS_TENANT=0'
        . ' REAL_MELI_HTTP=0'
        . ' checks=' . $checks
        . PHP_EOL
    );
} finally {
    if (isset($server, $database)) {
        $server->exec('DROP DATABASE IF EXISTS ' . $quote($database));
    }
}
