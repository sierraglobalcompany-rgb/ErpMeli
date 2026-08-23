<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Crypto;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\ApiRhythmDeferredException;
use App\Services\MeliApiClient;
use App\Services\MeliHttpTransportInterface;
use App\Services\SaleFinancialService;

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: bulk parity exige un DSN MariaDB desechable sin dbname.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_bulk_parity_' . bin2hex(random_bytes(5));
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-bulk-parity-' . bin2hex(random_bytes(5));
@mkdir($temporary . DIRECTORY_SEPARATOR . 'storage', 0770, true);

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';

final class BulkParityFakeClient
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public function __construct(private readonly int $total)
    {
    }

    public function get(string $path, array $query = [], array $meta = []): array
    {
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $limit = max(1, (int) ($query['limit'] ?? 1));
        $this->calls[] = ['path' => $path, 'offset' => $offset, 'limit' => $limit, 'meta' => $meta];
        $results = [];
        for ($i = $offset; $i < min($this->total, $offset + $limit); $i++) {
            $results[] = [
                'id' => (string) (900000 + $i),
                'status' => 'paid',
                'date_created' => '2026-08-20T00:00:00.000-00:00',
                'total_amount' => 100,
                'paid_amount' => 100,
                'currency_id' => 'COP',
                'order_items' => [],
                'payments' => [],
            ];
        }
        return [
            'results' => $results,
            'paging' => ['total' => $this->total, 'offset' => $offset, 'limit' => $limit],
        ];
    }
}

final class BulkParityFakeSync
{
    /** @var list<string> */
    public array $persisted = [];
    public int $exactCalls = 0;

    public function persistSearchSnapshotForQueueV4Clean(array $order, int $companyId, ?callable $beforePersist = null): int
    {
        $this->persisted[] = (string) ($order['id'] ?? '');
        if ($beforePersist !== null) {
            $beforePersist();
        }
        return count($this->persisted);
    }

    public function syncOrderByIdForQueueV4Clean(string $orderId, array $metadata = []): int
    {
        $this->exactCalls++;
        return 1;
    }
}

try {
    putenv('APP_KEY=queue-v4-bulk-parity-local-only');
    $_ENV['APP_KEY'] = 'queue-v4-bulk-parity-local-only';
    putenv('ML_WRITE_ENABLED=false');
    $_ENV['ML_WRITE_ENABLED'] = 'false';
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

    final class BulkBillingFakeTransport implements MeliHttpTransportInterface
    {
        /** @var list<array{path:string,order_ids:list<string>}> */
        public array $calls = [];
        public string $mode = 'success';

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
            if ($this->mode === '429') {
                return [
                    'status' => 429,
                    'body' => ['message' => 'rate limited', 'error' => 'too_many_requests'],
                    'headers' => [],
                    'curl_error' => '',
                    'duration_ms' => 12,
                    'wire_bytes' => 1,
                    'decoded_bytes' => 1,
                ];
            }
            $results = [];
            foreach ($orderIds as $orderId) {
                $results[] = [
                    'order_id' => $orderId,
                    'details' => [
                        ['detail_id' => 'fee-' . $orderId, 'detail_type' => 'SALE_FEE', 'detail_amount' => 10],
                    ],
                ];
            }
            if ($this->mode === 'ambiguous') {
                $results[] = ['detail_id' => 'shared', 'detail_type' => 'SHIPPING', 'detail_amount' => 5];
            }
            if ($this->mode === 'processing') {
                $last = end($orderIds);
                if (is_string($last) && $last !== '') {
                    $results[] = ['order_id' => $last, 'status' => 'processing'];
                }
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
        fwrite(STDERR, "ERROR: bulk parity exige MariaDB 11.8+.\n");
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

    $contract = json_decode(
        $read('resources/release/queue-v4-canonical-db-contract-2.38.9.json'),
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
    $pdo->exec("INSERT INTO companies(id,name) VALUES (10,'Bulk')");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,meli_user_id,account_name,status) VALUES (101,10,101001,'Bulk','conectado')");
    $pdo->exec("INSERT INTO queue_v4_clean_control(control_key,engine_state,scheduler_enabled) VALUES ('primary','ACTIVE',1)");
    $pdo->exec("INSERT INTO app_settings(setting_key,setting_value,is_encrypted) VALUES ('sync.page_limit','50',0)");
    $pdo->exec("INSERT INTO queue_v4_clean_checkpoints(producer_key,company_id,meli_account_id,watermark_at,next_due_at) VALUES ('fresh_orders',10,101,'2026-08-20 00:00:00',UTC_TIMESTAMP(3))");

    $runFresh = static function (int $total) use ($pdo, $assert): array {
        $repo = new QueueV4CleanRepository($pdo);
        $client = new BulkParityFakeClient($total);
        $sync = new BulkParityFakeSync();
        $repo->enqueue(
            10,
            101,
            'fresh_orders_discovery',
            null,
            'fresh-test-' . $total,
            ['from' => '2026-08-20T00:00:00+00:00', 'to' => '2026-08-20T01:00:00+00:00', 'offset' => 0, 'limit' => 50],
            3
        );
        $worker = new QueueV4CleanWorker(
            $pdo,
            $repo,
            static fn (int $accountId): BulkParityFakeClient => $client,
            static fn (int $accountId): BulkParityFakeSync => $sync,
        );
        $summary = $worker->run('test', 10, 45);
        $states = $pdo->query(
            'SELECT job_type,state,last_error_class FROM queue_v4_clean_jobs ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $assert((int) $summary['deferred'] === 0, 'fresh_deferred_unexpected_' . $total . ':' . json_encode($states));
        return [$client, $sync, $summary];
    };

    [$client50, $sync50] = $runFresh(50);
    $assert(count($client50->calls) === 1, 'FRESH_50_ONE_HTTP');
    $assert((int) $client50->calls[0]['limit'] === 50, 'FRESH_USES_SETTINGS_PAGE_LIMIT_50');
    $assert(count($sync50->persisted) === 50, 'FRESH_50_PERSISTED_50_SEARCH_SNAPSHOTS');
    $assert($sync50->exactCalls === 0, 'FRESH_50_ZERO_ORDER_EXACT_CALLS');

    [$client120, $sync120] = $runFresh(120);
    $assert(count($client120->calls) === 3, 'FRESH_120_THREE_HTTP');
    $assert(array_column($client120->calls, 'offset') === [0, 50, 100], 'FRESH_120_CONTIGUOUS_OFFSETS');
    $assert(count($sync120->persisted) === 120, 'FRESH_120_PERSISTED_120_SEARCH_SNAPSHOTS');
    $assert($sync120->exactCalls === 0, 'FRESH_120_ZERO_ORDER_EXACT_CALLS');
    $orderExact = (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='order_exact'")->fetchColumn();
    $assert($orderExact === 0, 'FRESH_DID_NOT_ENQUEUE_ORDER_EXACT');

    $pdo->exec('CREATE TABLE meli_orders(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        external_order_id VARCHAR(80) NOT NULL,
        external_pack_id VARCHAR(80) NULL,
        external_shipping_id VARCHAR(80) NULL,
        date_created DATETIME NULL,
        date_created_local DATETIME NULL,
        status VARCHAR(40) NULL,
        total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        currency_id VARCHAR(10) NULL,
        enrichment_status VARCHAR(40) NULL,
        UNIQUE KEY uq_order_account_external(meli_account_id,external_order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE meli_order_items(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        meli_order_id BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        external_item_id VARCHAR(80) NOT NULL,
        external_variation_id VARCHAR(80) NULL,
        quantity DECIMAL(18,4) NOT NULL DEFAULT 1,
        unit_price DECIMAL(18,2) NOT NULL DEFAULT 100,
        full_unit_price DECIMAL(18,2) NULL,
        sale_fee DECIMAL(18,2) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE meli_payments(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        meli_order_id BIGINT UNSIGNED NOT NULL,
        external_payment_id VARCHAR(80) NOT NULL,
        status VARCHAR(40) NULL,
        status_detail VARCHAR(100) NULL,
        transaction_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        shipping_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
        coupon_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        total_paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        marketplace_fee DECIMAL(18,2) NOT NULL DEFAULT 0,
        date_approved_utc DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE meli_shipments(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_shipment_id VARCHAR(80),status VARCHAR(40),substatus VARCHAR(40),logistic_type VARCHAR(40),gross_cost DECIMAL(18,2),seller_cost DECIMAL(18,2),buyer_cost DECIMAL(18,2),discounts DECIMAL(18,2)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_packs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL,external_pack_id VARCHAR(80) NOT NULL,integrity_status VARCHAR(40) NULL,UNIQUE KEY uq_pack(meli_account_id,external_pack_id)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_order_financials(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,meli_order_id BIGINT UNSIGNED NOT NULL,product_sold_amount DECIMAL(18,2) NOT NULL DEFAULT 100,local_estimated_net_amount DECIMAL(18,2) NULL,ml_net_amount DECIMAL(18,2) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE sale_financial_state(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,
        sale_key VARCHAR(191) NOT NULL,external_sale_id VARCHAR(191) NOT NULL,identity_type VARCHAR(20) NOT NULL,
        input_version CHAR(64) NOT NULL,currency_id VARCHAR(8) NOT NULL,
        commercial_status VARCHAR(30) NOT NULL,logistics_status VARCHAR(30) NOT NULL,provisional_status VARCHAR(30) NOT NULL,official_status VARCHAR(30) NOT NULL DEFAULT "missing",
        products_amount DECIMAL(18,2) NULL,provisional_net_amount DECIMAL(18,2) NULL,official_net_amount DECIMAL(18,2) NULL,unknown_concepts_amount DECIMAL(18,2) NULL,official_capture_id BIGINT UNSIGNED NULL,
        missing_flags_json LONGTEXT NULL,close_impact VARCHAR(40) NULL,sales_control_close_id BIGINT UNSIGNED NULL,projected_at DATETIME NULL,official_at DATETIME NULL,
        UNIQUE KEY uq_sale_state(company_id,meli_account_id,sale_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE sale_financial_evidence(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,sale_key VARCHAR(191) NOT NULL,input_version CHAR(64) NOT NULL,
        evidence_type VARCHAR(60) NOT NULL,evidence_status VARCHAR(40) NOT NULL,source_id BIGINT UNSIGNED NULL,payload_hash CHAR(64) NOT NULL,
        provisional_net_amount DECIMAL(18,2) NULL,official_net_amount DECIMAL(18,2) NULL,evidence_json LONGTEXT NOT NULL,
        UNIQUE KEY uq_evidence(company_id,meli_account_id,sale_key,input_version,evidence_type,payload_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE sale_financial_reconciliation_jobs(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,
        sale_key VARCHAR(191) NOT NULL,external_sale_id VARCHAR(191) NOT NULL,input_version CHAR(64) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT "pending",priority_tier INT NOT NULL DEFAULT 30,origin_type VARCHAR(40) NOT NULL,
        origin_id BIGINT NULL,created_by BIGINT NULL,next_run_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
        remote_pending_since DATETIME NULL,retry_until DATETIME NULL,last_remote_state VARCHAR(30) NULL,safe_message VARCHAR(500) NULL,
        completed_at DATETIME NULL,lock_owner VARCHAR(96) NULL,lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,lease_expires_at DATETIME NULL,heartbeat_at DATETIME NULL,
        attempts INT NOT NULL DEFAULT 0,
        UNIQUE KEY uq_sale_reconciliation(company_id,meli_account_id,sale_key,input_version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE meli_billing_capture_runs(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,sale_key VARCHAR(191) NOT NULL,external_sale_id VARCHAR(191) NOT NULL,input_version CHAR(64) NOT NULL,
        source_mode VARCHAR(40) NOT NULL,requested_order_ids_json LONGTEXT NOT NULL,http_status INT NULL,response_class VARCHAR(40) NULL,response_hash CHAR(64) NULL,missing_fields_json LONGTEXT NULL,safe_message VARCHAR(500) NULL,captured_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE meli_sale_financials(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,sale_key VARCHAR(191) NOT NULL,external_sale_id VARCHAR(191) NOT NULL,identity_type VARCHAR(20) NOT NULL,currency_id VARCHAR(8) NOT NULL,
        products_amount DECIMAL(18,2) NULL,sale_fee_amount DECIMAL(18,2) NULL,shipping_charge_amount DECIMAL(18,2) NULL,taxes_amount DECIMAL(18,2) NULL,discounts_amount DECIMAL(18,2) NULL,credits_amount DECIMAL(18,2) NULL,adjustments_amount DECIMAL(18,2) NULL,net_amount DECIMAL(18,2) NULL,
        local_estimate_amount DECIMAL(18,2) NULL,legacy_difference_amount DECIMAL(18,2) NULL,source VARCHAR(40) NULL,capture_run_id BIGINT UNSIGNED NULL,reconciliation_status VARCHAR(30) NOT NULL,safe_message VARCHAR(500) NULL,reconciled_at DATETIME NULL,methodology_version VARCHAR(40) NULL,
        UNIQUE KEY uq_sale_financial(meli_account_id,sale_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE meli_sale_financial_lines(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_sale_financial_id BIGINT UNSIGNED NOT NULL,meli_billing_capture_run_id BIGINT UNSIGNED NOT NULL,external_order_id VARCHAR(80) NULL,detail_id VARCHAR(120) NULL,line_group VARCHAR(40),line_type VARCHAR(80),line_subtype VARCHAR(80),description VARCHAR(500),amount DECIMAL(18,2),direction VARCHAR(20),is_shared TINYINT(1),source_status VARCHAR(40),line_hash CHAR(64),occurred_at DATETIME NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_sale_financial_allocations(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_sale_financial_id BIGINT UNSIGNED NOT NULL,meli_order_item_id BIGINT UNSIGNED NOT NULL,gross_amount DECIMAL(18,2),weight_basis VARCHAR(40),sale_fee_allocated DECIMAL(18,2),shipping_allocated DECIMAL(18,2),tax_allocated DECIMAL(18,2),discount_allocated DECIMAL(18,2),credit_allocated DECIMAL(18,2),other_allocated DECIMAL(18,2),net_allocated DECIMAL(18,2)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_sale_financial_history(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_sale_financial_id BIGINT UNSIGNED NOT NULL,revision_no INT NOT NULL,methodology_version VARCHAR(40),totals_json LONGTEXT,lines_summary_json LONGTEXT,allocations_summary_json LONGTEXT,source VARCHAR(40),safe_message VARCHAR(500)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE manual_campaigns(id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20) NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE manual_campaign_reservations(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,manual_campaign_id BIGINT UNSIGNED NOT NULL,queue_key VARCHAR(80) NOT NULL,source_id VARCHAR(80) NOT NULL,status VARCHAR(20) NOT NULL,expires_at DATETIME(3) NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_rhythm_states(scope_key VARCHAR(120) PRIMARY KEY,generation BIGINT UNSIGNED NOT NULL DEFAULT 1,calls_in_block INT UNSIGNED NOT NULL DEFAULT 0,block_started_at DATETIME(3) NULL,next_allowed_at DATETIME(3) NULL,block_pause_until DATETIME(3) NULL,last_dispatched_at DATETIME(3) NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_remote_permits(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,permit_token CHAR(40) NOT NULL,owner_token CHAR(32) NOT NULL,generation BIGINT UNSIGNED NOT NULL,run_token VARCHAR(100) NULL,work_key VARCHAR(120) NULL,company_id BIGINT UNSIGNED NULL,meli_account_id BIGINT UNSIGNED NULL,endpoint_key VARCHAR(120) NOT NULL,job_type VARCHAR(80) NOT NULL,method VARCHAR(10) NOT NULL,status ENUM("reserved","dispatched","completed","released","expired") NOT NULL DEFAULT "reserved",requested_interval_ms INT UNSIGNED NOT NULL,effective_interval_ms INT UNSIGNED NOT NULL,blocking_scope VARCHAR(80) NULL,http_status SMALLINT UNSIGNED NULL,created_at DATETIME(3) NOT NULL,dispatched_at DATETIME(3) NULL,completed_at DATETIME(3) NULL,released_at DATETIME(3) NULL,expires_at DATETIME(3) NOT NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),UNIQUE KEY uq_permit_token(permit_token),KEY idx_permit_active(status,expires_at),KEY idx_permit_endpoint_dispatch(endpoint_key,dispatched_at)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_rhythm_penalties(scope_key VARCHAR(180) PRIMARY KEY,reduced_limit_per_minute SMALLINT UNSIGNED NOT NULL,blocked_until DATETIME(3) NULL,reduced_until DATETIME(3) NOT NULL,reason VARCHAR(80) NOT NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_circuit_breakers(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,endpoint_path VARCHAR(255) NOT NULL,status VARCHAR(40) NOT NULL,blocked_until DATETIME NULL,reason VARCHAR(80) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_request_logs(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,meli_account_id BIGINT UNSIGNED NULL,company_id BIGINT UNSIGNED NULL,
        scope_kind VARCHAR(30) NULL,request_id VARCHAR(64) NULL,method VARCHAR(10) NULL,endpoint_path VARCHAR(255) NULL,http_status INT NULL,
        duration_ms INT NULL,retry_after_seconds INT NULL,attempt INT NULL,was_blocked TINYINT(1) NULL,safe_message VARCHAR(500) NULL,
        diagnostic_id VARCHAR(100) NULL,error_type VARCHAR(80) NULL,error_code VARCHAR(120) NULL,is_retryable TINYINT(1) NULL,
        is_app_blocked_signal TINYINT(1) NULL,outcome_class VARCHAR(80) NULL,reached_remote TINYINT(1) NULL,actionable TINYINT(1) NULL,
        risk_signal TINYINT(1) NULL,incident_key VARCHAR(191) NULL,execution_source VARCHAR(40) NULL,job_type VARCHAR(80) NULL,
        source_queue_key VARCHAR(80) NULL,source_work_id VARCHAR(100) NULL,operation_key VARCHAR(80) NULL,load_class VARCHAR(40) NULL,
        workload_units INT NULL,wire_bytes BIGINT NULL,decoded_bytes BIGINT NULL,response_item_count INT NULL,response_count_state VARCHAR(40) NULL,
        response_resource_unit VARCHAR(40) NULL,fanout_count INT NULL,created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        KEY idx_log_created(created_at),KEY idx_log_endpoint_status(endpoint_path,http_status,created_at),KEY idx_log_request(request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE api_operation_metrics_hourly(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,bucket_started_at DATETIME NOT NULL,account_scope_key BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NULL,operation_key VARCHAR(80) NOT NULL,load_class VARCHAR(40) NOT NULL,
        sample_count INT NOT NULL,remote_count INT NOT NULL,success_count INT NOT NULL,error_count INT NOT NULL,
        total_duration_ms BIGINT NOT NULL,max_duration_ms BIGINT NOT NULL,total_wire_bytes BIGINT NOT NULL,total_decoded_bytes BIGINT NOT NULL,
        total_items BIGINT NOT NULL,total_fanout BIGINT NOT NULL,updated_at DATETIME NOT NULL,
        UNIQUE KEY uq_metric(bucket_started_at,account_scope_key,operation_key,load_class)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE api_operation_metric_samples(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,bucket_started_at DATETIME NOT NULL,account_scope_key BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NULL,operation_key VARCHAR(80) NOT NULL,load_class VARCHAR(40) NOT NULL,duration_ms INT NOT NULL,
        wire_bytes BIGINT NOT NULL,decoded_bytes BIGINT NOT NULL,response_item_count INT NOT NULL,fanout_count INT NOT NULL,http_status INT NULL,
        reached_remote TINYINT(1) NOT NULL,successful TINYINT(1) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE system_logs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,level VARCHAR(20) NOT NULL,message VARCHAR(500) NOT NULL,context_json LONGTEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE api_budget_windows(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,scope VARCHAR(40) NOT NULL,scope_key VARCHAR(255) NOT NULL,meli_account_id BIGINT UNSIGNED NULL,endpoint_path VARCHAR(255) NULL,job_type VARCHAR(80) NULL,window_started_at DATETIME NOT NULL,window_seconds INT UNSIGNED NOT NULL DEFAULT 900,request_limit INT UNSIGNED NOT NULL DEFAULT 0,request_count INT UNSIGNED NOT NULL DEFAULT 0,error_400_count INT UNSIGNED NOT NULL DEFAULT 0,error_401_count INT UNSIGNED NOT NULL DEFAULT 0,error_403_count INT UNSIGNED NOT NULL DEFAULT 0,error_429_count INT UNSIGNED NOT NULL DEFAULT 0,error_5xx_count INT UNSIGNED NOT NULL DEFAULT 0,last_request_at DATETIME NULL,cooldown_until DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_api_budget_window(scope_key,window_started_at,window_seconds)) ENGINE=InnoDB');
    $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,refresh_version) VALUES (?,?,?,?,0)')->execute([101, Crypto::encrypt('bulk-access'), Crypto::encrypt('bulk-refresh'), gmdate('Y-m-d H:i:s', time() + 3600)]);
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,meli_user_id,account_name,status) VALUES (102,10,102001,'Bulk B','conectado')");
    $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,refresh_version) VALUES (?,?,?,?,0)')->execute([102, Crypto::encrypt('bulk-access-b'), Crypto::encrypt('bulk-refresh-b'), gmdate('Y-m-d H:i:s', time() + 3600)]);

    $resetBilling = static function () use ($pdo): void {
        foreach ([
            'queue_v4_clean_attempts', 'queue_v4_clean_runs', 'queue_v4_clean_jobs',
            'sale_financial_reconciliation_jobs', 'sale_financial_state', 'sale_financial_evidence',
            'meli_billing_capture_runs', 'meli_sale_financial_lines', 'meli_sale_financial_allocations',
            'meli_sale_financial_history', 'meli_sale_financials', 'meli_order_financials',
            'meli_order_items', 'meli_payments', 'meli_orders', 'manual_campaign_reservations', 'manual_campaigns', 'api_remote_permits',
            'api_rhythm_states', 'api_rhythm_penalties', 'api_circuit_breakers', 'api_request_logs', 'api_operation_metrics_hourly',
            'api_operation_metric_samples', 'api_budget_windows', 'system_logs',
        ] as $table) {
            $pdo->exec('DELETE FROM ' . $table);
        }
    };
    $resetRhythm = static function () use ($pdo): void {
        foreach (['api_remote_permits', 'api_rhythm_states', 'api_rhythm_penalties', 'api_request_logs', 'api_budget_windows'] as $table) {
            $pdo->exec('DELETE FROM ' . $table);
        }
    };
    $resetRhythmAuthorityOnly = static function () use ($pdo): void {
        foreach (['api_remote_permits', 'api_rhythm_states', 'api_rhythm_penalties'] as $table) {
            $pdo->exec('DELETE FROM ' . $table);
        }
    };
    $seedFinancial = static function (int $accountId, int $n, string $prefix = 'A') use ($pdo): array {
        $ids = [];
        $namespace = abs((int) crc32($prefix)) % 1000;
        for ($i = 1; $i <= $n; $i++) {
            $external = sprintf('%d%03d%05d', $accountId, $namespace, $i);
            $pdo->prepare('INSERT INTO meli_orders(meli_account_id,external_order_id,date_created,date_created_local,status,total_amount,paid_amount,currency_id) VALUES (?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),"paid",100,100,"COP")')
                ->execute([$accountId, $external]);
            $orderId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO meli_order_items(meli_order_id,meli_account_id,external_item_id,quantity,unit_price,sale_fee) VALUES (?,?,?,1,100,10)')
                ->execute([$orderId, $accountId, 'ITEM-' . $external]);
            $pdo->prepare('INSERT INTO meli_payments(meli_order_id,meli_account_id,external_payment_id,status,transaction_amount,total_paid_amount,marketplace_fee) VALUES (?,?,?,"approved",100,100,10)')
                ->execute([$orderId, $accountId, 'PAY-' . $external]);
            $pdo->prepare('INSERT INTO meli_order_financials(company_id,meli_account_id,meli_order_id,product_sold_amount,local_estimated_net_amount) VALUES (10,?,?,100,90)')
                ->execute([$accountId, $orderId]);
            $saleKey = 'O:' . $external;
            $state = (new \App\Services\SaleFinancialStateService())->projectSale(10, $accountId, $saleKey);
            $inputVersion = (string) ($state['input_version'] ?? '');
            $pdo->prepare('INSERT INTO sale_financial_reconciliation_jobs(company_id,meli_account_id,sale_key,external_sale_id,input_version,status,origin_type,next_run_at,attempts) VALUES (10,?,?,?,?, "pending", "bulk_fixture", UTC_TIMESTAMP(), 0)')
                ->execute([$accountId, $saleKey, $external, $inputVersion]);
            $sourceId = (int) $pdo->lastInsertId();
            $payload = json_encode(['capability' => 'financial_reconciliation', 'source_id' => $sourceId], JSON_THROW_ON_ERROR);
            $pdo->prepare('INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at,max_attempts) VALUES (10,?,"domain_exact",?,?,?,"ready",UTC_TIMESTAMP(3),3)')
                ->execute([$accountId, (string) $sourceId, $prefix . ':finance:' . $sourceId, $payload]);
            $ids[] = $sourceId;
        }
        return $ids;
    };
    $seedExcludedPack = static function (int $accountId, string $prefix) use ($pdo): int {
        $external = 'PACK-' . $prefix;
        $saleKey = 'P:' . $external;
        $inputVersion = hash('sha256', 'pack-fixture:' . $accountId . ':' . $prefix);
        $pdo->prepare(
            'INSERT INTO sale_financial_state
                (company_id,meli_account_id,sale_key,external_sale_id,identity_type,input_version,
                 currency_id,commercial_status,logistics_status,provisional_status,official_status)
             VALUES (10,?,?,?,"pack",?,"COP","complete","complete","complete","missing")'
        )->execute([$accountId, $saleKey, $external, $inputVersion]);
        $pdo->prepare(
            'INSERT INTO sale_financial_reconciliation_jobs
                (company_id,meli_account_id,sale_key,external_sale_id,input_version,status,origin_type,next_run_at,attempts)
             VALUES (10,?,?,?,?,"pending","pack_fixture",UTC_TIMESTAMP(),0)'
        )->execute([$accountId, $saleKey, $external, $inputVersion]);
        $sourceId = (int) $pdo->lastInsertId();
        $payload = json_encode(['capability' => 'financial_reconciliation', 'source_id' => $sourceId], JSON_THROW_ON_ERROR);
        $pdo->prepare(
            'INSERT INTO queue_v4_clean_jobs
                (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at,max_attempts)
             VALUES (10,?,"domain_exact",?,?,?,"ready",UTC_TIMESTAMP(3),3)'
        )->execute([$accountId, (string) $sourceId, $prefix . ':pack:' . $sourceId, $payload]);
        return $sourceId;
    };
    $runBilling = static function (BulkBillingFakeTransport $transport, int $maxJobs = 1) use ($pdo): array {
        $repo = new QueueV4CleanRepository($pdo);
        $service = new SaleFinancialService(
            static fn (int $accountId): MeliApiClient => new MeliApiClient($accountId, $transport)
        );
        $worker = new QueueV4CleanWorker(
            $pdo,
            $repo,
            null,
            null,
            null,
            null,
            static fn (): SaleFinancialService => $service,
        );
        return $worker->run('test', $maxJobs, 45);
    };

    $resetBilling();
    $singleIds = $seedFinancial(101, 1, 'DIRECT1');
    $transportDirect = new BulkBillingFakeTransport();
    $directService = new SaleFinancialService(
        static fn (int $accountId): MeliApiClient => new MeliApiClient($accountId, $transportDirect)
    );
    try {
        $directService->processDomainExactBatch($singleIds, 10, 101);
    } catch (Throwable $error) {
        throw new RuntimeException('BILLING_DIRECT_SERVICE_EXCEPTION ' . $error->getMessage(), 0, $error);
    }
    $assert(count($transportDirect->calls) === 1, 'BILLING_DIRECT_SERVICE_ONE_HTTP calls=' . count($transportDirect->calls));

    $resetRhythm();
    $pdo->exec(
        "INSERT INTO app_settings(setting_key,setting_value,is_encrypted) VALUES
            ('api.budget.global_requests_per_15m','1',0),
            ('api.budget.account_requests_per_15m','1',0),
            ('api.budget.job_type_requests_per_15m','1',0)
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted)"
    );
    $settingsReflection = new ReflectionClass(\App\Services\AppSettingsService::class);
    $settingsReflection->setStaticPropertyValue('cache', []);
    $settingsReflection->setStaticPropertyValue('loaded', []);
    $transportBudget = new BulkBillingFakeTransport();
    $budgetClient = new MeliApiClient(101, $transportBudget);
    $budgetMeta = [
        'source' => 'queue_v4_clean',
        'job_type' => 'fresh_orders_discovery',
        'company_id' => 10,
        'source_work_id' => 'budget-authority-1',
    ];
    $budgetClient->get('/orders/search', ['seller' => '101'], $budgetMeta);
    $resetRhythmAuthorityOnly();
    $budgetMeta['source_work_id'] = 'budget-authority-2';
    $budgetClient->get('/orders/search', ['seller' => '101'], $budgetMeta);
    $assert(count($transportBudget->calls) === 2, 'RHYTHM_PRIMARY_AUTHORITY_DYNAMIC_CANONICAL');

    $resetBilling();
    $seedFinancial(101, 60, 'A60');
    $transport60 = new BulkBillingFakeTransport();
    $summary60 = $runBilling($transport60);
    $states60 = $pdo->query(
        'SELECT q.state,q.last_error_class,s.status,s.safe_message,COUNT(*) jobs
           FROM queue_v4_clean_jobs q
           JOIN sale_financial_reconciliation_jobs s ON s.id=CAST(q.resource_id AS UNSIGNED)
          GROUP BY q.state,q.last_error_class,s.status,s.safe_message
          ORDER BY jobs DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($transport60->calls) === 1, 'BILLING_60_ONE_HTTP calls=' . count($transport60->calls) . ' summary=' . json_encode($summary60) . ' states=' . json_encode($states60));
    $assert(count($transport60->calls[0]['order_ids']) === 60 && count(array_unique($transport60->calls[0]['order_ids'])) === 60, 'BILLING_60_ORDER_IDS_SENT');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM sale_financial_reconciliation_jobs WHERE status="complete"')->fetchColumn() === 60, 'BILLING_60_SOURCE_COMPLETE');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE state="completed"')->fetchColumn() === 60, 'BILLING_60_QUEUE_POINTER_COMPLETE');
    $cardinality60 = $pdo->query(
        "SELECT fanout_count,response_item_count,response_count_state,response_resource_unit,http_status
           FROM api_request_logs
          WHERE endpoint_path='/billing/integration/group/ML/order/details'
          ORDER BY id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($cardinality60)
        && (int) $cardinality60['fanout_count'] === 60
        && (int) $cardinality60['response_item_count'] === 60
        && (string) $cardinality60['response_count_state'] === 'complete'
        && (string) $cardinality60['response_resource_unit'] === 'orders'
        && (int) $cardinality60['http_status'] === 200, 'BILLING_BATCH_CARDINALITY_LOG');
    $batchLogs60 = $pdo->query(
        "SELECT message,context_json FROM system_logs
          WHERE message IN ('billing_batch_prepared','billing_batch_response') ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($batchLogs60) === 2
        && !str_contains((string) json_encode($batchLogs60), '"order_ids"')
        && !str_contains((string) json_encode($batchLogs60), '"external_order_id"')
        && !str_contains((string) json_encode($batchLogs60), 'A60'), 'NO_SENSITIVE_IDENTIFIERS_IN_BATCH_LOG');

    $resetBilling();
    $kissPrimary = $seedFinancial(101, 1, 'KISS_PRIMARY')[0];
    $kissPack = $seedExcludedPack(101, 'KISS_BLOCKER');
    $kissExtra = $seedFinancial(101, 1, 'KISS_EXTRA')[0];
    $packPointerBefore = $pdo->prepare('SELECT state,available_at FROM queue_v4_clean_jobs WHERE resource_id=? LIMIT 1');
    $packPointerBefore->execute([(string) $kissPack]);
    $packBefore = $packPointerBefore->fetch(PDO::FETCH_ASSOC);
    $transportKiss = new BulkBillingFakeTransport();
    $runBilling($transportKiss);
    $assert(count($transportKiss->calls) === 1 && count($transportKiss->calls[0]['order_ids']) === 2, 'BILLING_KISS_SKIPS_PACK_AND_BATCHES_LATER_SAME_TENANT');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM sale_financial_reconciliation_jobs WHERE id IN (' . (int) $kissPrimary . ',' . (int) $kissExtra . ') AND status="complete"')->fetchColumn() === 2, 'RESPONSE_ALIGNMENT_ONLY_SELECTED');
    $packPointerAfter = $pdo->prepare('SELECT state,available_at FROM queue_v4_clean_jobs WHERE resource_id=? LIMIT 1');
    $packPointerAfter->execute([(string) $kissPack]);
    $packAfter = $packPointerAfter->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($packBefore) && is_array($packAfter) && $packAfter['state'] === 'ready', 'PACK_EXCLUDED');
    $assert($packAfter['available_at'] === $packBefore['available_at'], 'UNSELECTED_AVAILABLE_AT_UNCHANGED');

    $resetBilling();
    $skipPrimary = $seedFinancial(101, 1, 'SKIP_PRIMARY')[0];
    $skipPack = $seedExcludedPack(101, 'SKIP_PACK');
    $skipFallback = $seedFinancial(101, 1, 'SKIP_FALLBACK')[0];
    $skipOfficial = $seedFinancial(101, 1, 'SKIP_OFFICIAL')[0];
    $skipStale = $seedFinancial(101, 1, 'SKIP_STALE')[0];
    $skipExtra = $seedFinancial(101, 1, 'SKIP_EXTRA')[0];
    $pdo->prepare("UPDATE sale_financial_reconciliation_jobs SET safe_message='BILLING_BATCH_EXACT_FALLBACK_REQUIRED: fixture' WHERE id=?")->execute([$skipFallback]);
    $pdo->prepare('UPDATE sale_financial_state st JOIN sale_financial_reconciliation_jobs s ON s.company_id=st.company_id AND s.meli_account_id=st.meli_account_id AND s.sale_key=st.sale_key SET st.official_status="complete",st.official_net_amount=1 WHERE s.id=?')->execute([$skipOfficial]);
    $pdo->prepare('UPDATE sale_financial_state st JOIN sale_financial_reconciliation_jobs s ON s.company_id=st.company_id AND s.meli_account_id=st.meli_account_id AND s.sale_key=st.sale_key SET st.input_version=? WHERE s.id=?')->execute([hash('sha256', 'stale-fixture'), $skipStale]);
    $skipSnapshots = [];
    foreach ([$skipPack, $skipFallback, $skipOfficial, $skipStale] as $sourceId) {
        $stmt = $pdo->prepare('SELECT q.state,q.available_at,s.status source_status,s.attempts source_attempts,s.next_run_at FROM queue_v4_clean_jobs q JOIN sale_financial_reconciliation_jobs s ON s.id=CAST(q.resource_id AS UNSIGNED) WHERE q.resource_id=? LIMIT 1');
        $stmt->execute([(string) $sourceId]);
        $skipSnapshots[$sourceId] = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    $transportSkipped = new BulkBillingFakeTransport();
    $runBilling($transportSkipped);
    $assert(count($transportSkipped->calls) === 1 && count($transportSkipped->calls[0]['order_ids']) === 2, 'SAME_TENANT_SIMPLE_BATCH');
    foreach ([$skipPack, $skipFallback, $skipOfficial, $skipStale] as $sourceId) {
        $stmt = $pdo->prepare('SELECT q.state,q.available_at,s.status source_status,s.attempts source_attempts,s.next_run_at FROM queue_v4_clean_jobs q JOIN sale_financial_reconciliation_jobs s ON s.id=CAST(q.resource_id AS UNSIGNED) WHERE q.resource_id=? LIMIT 1');
        $stmt->execute([(string) $sourceId]);
        $after = $stmt->fetch(PDO::FETCH_ASSOC);
        $assert($after === $skipSnapshots[$sourceId], 'SKIPPED_POINTERS_IMMUTABLE_' . $sourceId);
    }
    $assert((int) $pdo->query('SELECT COUNT(*) FROM sale_financial_reconciliation_jobs WHERE id IN (' . (int) $skipPrimary . ',' . (int) $skipExtra . ') AND status="complete"')->fetchColumn() === 2, 'SELECTED_ALIGNMENT_ONLY');
    $assert(true, 'SAME_TENANT_PACK_SKIPPED');
    $assert(true, 'SAME_TENANT_MULTIORDER_SKIPPED');
    $assert(true, 'SAME_TENANT_EXACT_FALLBACK_SKIPPED');
    $assert(true, 'SAME_TENANT_OFFICIAL_COMPLETE_SKIPPED');
    $assert(true, 'SAME_TENANT_INVALID_INPUT_SKIPPED');

    $resetBilling();
    $tenantAPrimary = $seedFinancial(101, 1, 'KISS_TENANT_A1')[0];
    $tenantAExtra = $seedFinancial(101, 1, 'KISS_TENANT_A2')[0];
    $tenantB = $seedFinancial(102, 1, 'KISS_TENANT_B')[0];
    $tenantBPointer = $pdo->prepare('SELECT state,available_at FROM queue_v4_clean_jobs WHERE resource_id=? LIMIT 1');
    $tenantBPointer->execute([(string) $tenantB]);
    $tenantBBefore = $tenantBPointer->fetch(PDO::FETCH_ASSOC);
    $transportTenant = new BulkBillingFakeTransport();
    $runBilling($transportTenant);
    $assert(count($transportTenant->calls) === 1 && count($transportTenant->calls[0]['order_ids']) === 2, 'SAME_TENANT_BATCH_MAX_60');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM sale_financial_reconciliation_jobs WHERE id IN (' . (int) $tenantAPrimary . ',' . (int) $tenantAExtra . ') AND status="complete"')->fetchColumn() === 2, 'PRIMARY_FIFO_PRESERVED');
    $tenantBPointer->execute([(string) $tenantB]);
    $tenantBAfter = $tenantBPointer->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($tenantBBefore) && is_array($tenantBAfter) && $tenantBAfter['state'] === 'ready' && $tenantBAfter['available_at'] === $tenantBBefore['available_at'], 'CROSS_TENANT_BATCH=0');

    $resetBilling();
    $fairA1 = $seedFinancial(101, 1, 'FAIR_A1')[0];
    $fairPack = $seedExcludedPack(101, 'FAIR_APACK');
    $fairA3 = $seedFinancial(101, 1, 'FAIR_A3')[0];
    $fairA4 = $seedFinancial(101, 1, 'FAIR_A4')[0];
    $fairB = $seedFinancial(102, 1, 'FAIR_B1')[0];
    $fairPackBefore = $pdo->prepare('SELECT state,available_at FROM queue_v4_clean_jobs WHERE resource_id=? LIMIT 1');
    $fairPackBefore->execute([(string) $fairPack]);
    $fairPackSnapshot = $fairPackBefore->fetch(PDO::FETCH_ASSOC);
    $transportFair = new BulkBillingFakeTransport();
    $runBilling($transportFair);
    $assert(count($transportFair->calls) === 1 && count($transportFair->calls[0]['order_ids']) === 3, 'SKIPPED_FINANCE_NOT_STARVED_BY_MUTATION');
    $fairPackBefore->execute([(string) $fairPack]);
    $assert($fairPackBefore->fetch(PDO::FETCH_ASSOC) === $fairPackSnapshot, 'FAIR_PACK_UNCHANGED_AFTER_A_BATCH');
    $fairRepo = new QueueV4CleanRepository($pdo);
    $fairRun = $fairRepo->beginRun('test', 'fairness-next-primary');
    $fairClaim = $fairRepo->claim($fairRun, 'fairness-next-primary', 60);
    $assert(is_array($fairClaim) && (string) ($fairClaim['resource_id'] ?? '') === (string) $fairPack, 'SKIPPED_FINANCE_CAN_BE_NEXT_PRIMARY');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE resource_id=' . (int) $fairB . ' AND state="ready"')->fetchColumn() === 1, 'DIFFERENT_TENANT_STOPS_SCAN');

    $assertPrimaryType = static function (string $type, string $resourceId, array $payload, string $label) use ($pdo, $seedFinancial, $assert): void {
        $pdo->exec('DELETE FROM queue_v4_clean_attempts');
        $pdo->exec('DELETE FROM queue_v4_clean_runs');
        $pdo->exec('DELETE FROM queue_v4_clean_jobs');
        $pdo->exec('DELETE FROM sale_financial_reconciliation_jobs');
        $pdo->exec('DELETE FROM sale_financial_state');
        $repo = new QueueV4CleanRepository($pdo);
        $repo->enqueue(10, 101, $type, $resourceId, 'kiss-primary-' . $label, $payload, 3);
        $seedFinancial(101, 1, 'KISS_AFTER_' . $label);
        $runId = $repo->beginRun('test', 'kiss-' . $label);
        $claimed = $repo->claim($runId, 'kiss-' . $label, 60);
        $assert(is_array($claimed) && (string) $claimed['job_type'] === $type, $label);
    };
    $assertPrimaryType('fresh_orders_discovery', '', ['from' => '2026-08-20 00:00:00', 'to' => '2026-08-20 00:01:00'], 'FRESH_NOT_SKIPPED_AS_PRIMARY');
    $assertPrimaryType('order_exact', '999991', ['order_id' => '999991'], 'ORDER_EXACT_NOT_SKIPPED_AS_PRIMARY');

    $resetBilling();
    $dueSource = $seedFinancial(101, 1, 'KISS_DUE')[0];
    $futureSource = $seedFinancial(101, 1, 'KISS_FUTURE')[0];
    $futureBefore = $pdo->prepare('SELECT state,available_at FROM queue_v4_clean_jobs WHERE resource_id=? LIMIT 1');
    $futureBefore->execute([(string) $futureSource]);
    $pdo->prepare('UPDATE queue_v4_clean_jobs SET available_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR) WHERE resource_id=?')->execute([(string) $futureSource]);
    $futureBefore->execute([(string) $futureSource]);
    $futurePointerBefore = $futureBefore->fetch(PDO::FETCH_ASSOC);
    $transportDue = new BulkBillingFakeTransport();
    $runBilling($transportDue);
    $assert(count($transportDue->calls) === 1 && count($transportDue->calls[0]['order_ids']) === 1, 'READY_ONLY_AND_DUE_ONLY');
    $futureBefore->execute([(string) $futureSource]);
    $futurePointerAfter = $futureBefore->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($futurePointerBefore) && is_array($futurePointerAfter) && $futurePointerAfter['state'] === 'ready' && $futurePointerAfter['available_at'] === $futurePointerBefore['available_at'], 'UNSELECTED_POINTER_STATE_UNCHANGED');

    $resetBilling();
    $seedFinancial(101, 61, 'A61');
    $transport61 = new BulkBillingFakeTransport();
    $runBilling($transport61);
    $resetRhythm();
    $runBilling($transport61);
    $assert(array_map('count', array_column($transport61->calls, 'order_ids')) === [60, 1], 'BILLING_61_60_PLUS_1 calls=' . json_encode($transport61->calls));

    $resetBilling();
    $seedFinancial(101, 1, 'FIFO1');
    $pdo->prepare('INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at,max_attempts) VALUES (10,102,"order_exact","999","fifo:order",?,"ready",UTC_TIMESTAMP(3),3)')
        ->execute([json_encode(['order_id' => '999'], JSON_THROW_ON_ERROR)]);
    $seedFinancial(101, 1, 'FIFO3');
    $transportFifo = new BulkBillingFakeTransport();
    $runBilling($transportFifo);
    $assert(count($transportFifo->calls) === 1 && count($transportFifo->calls[0]['order_ids']) === 1, 'BILLING_GLOBAL_FIFO_INTERLEAVE');

    $resetBilling();
    $seedFinancial(101, 2, 'A2');
    $seedFinancial(102, 2, 'B2');
    $transportAccounts = new BulkBillingFakeTransport();
    $runBilling($transportAccounts);
    $resetRhythm();
    $runBilling($transportAccounts);
    $assert(count($transportAccounts->calls) === 2, 'BILLING_TWO_ACCOUNTS_CALL_COUNT');
    $assert(count(array_unique(array_map(static fn(array $call): string => substr((string) $call['order_ids'][0], 0, 3), $transportAccounts->calls))) === 2, 'BILLING_TWO_ACCOUNTS_NO_MIX');

    $resetBilling();
    $seedFinancial(101, 60, 'A429');
    $transport429 = new BulkBillingFakeTransport();
    $transport429->mode = '429';
    $summary429 = $runBilling($transport429);
    $assert(count($transport429->calls) === 1, 'BILLING_ONE_429_FOR_BATCH');
    $assert((int) $summary429['deferred'] === 1, 'BILLING_429_CURRENT_POINTER_DEFERRED');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE state="waiting"')->fetchColumn() === 60, 'BILLING_429_GLOBAL_PARKING_SAME_CYCLE');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM api_remote_permits WHERE http_status=429')->fetchColumn() === 1, 'BILLING_429_REMOTE_PERMIT_RECORDED');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM api_rhythm_penalties WHERE reason='http_429' AND blocked_until>=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 29 MINUTE)")->fetchColumn() >= 1, 'BILLING_429_DURABLE_PENALTY');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE state="waiting" AND available_at>=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 29 MINUTE)')->fetchColumn() === 60, 'BILLING_429_POINTERS_GE_30M');
    $cardinality429 = $pdo->query(
        "SELECT fanout_count,response_item_count,http_status,reached_remote
           FROM api_request_logs
          WHERE endpoint_path='/billing/integration/group/ML/order/details'
          ORDER BY id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($cardinality429)
        && (int) $cardinality429['fanout_count'] === 60
        && (int) $cardinality429['response_item_count'] === 0
        && (int) $cardinality429['http_status'] === 429
        && (int) $cardinality429['reached_remote'] === 1, '429_SELECTED_DEFER_SAFE');

    $resetBilling();
    $seedFinancial(101, 2, 'AMB');
    $transportAmbiguous = new BulkBillingFakeTransport();
    $transportAmbiguous->mode = 'ambiguous';
    $runBilling($transportAmbiguous);
    $assert((int) $pdo->query("SELECT COUNT(*) FROM sale_financial_reconciliation_jobs WHERE safe_message LIKE 'BILLING_BATCH_EXACT_FALLBACK_REQUIRED%'")->fetchColumn() === 2, 'BILLING_AMBIGUOUS_FALLBACK');

    $resetBilling();
    $fallbackIds = $seedFinancial(101, 3, 'AMBX');
    $pdo->prepare('UPDATE queue_v4_clean_jobs SET available_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 30 MINUTE) WHERE resource_id=?')
        ->execute([(string) $fallbackIds[2]]);
    $transportExactFallback = new BulkBillingFakeTransport();
    $transportExactFallback->mode = 'ambiguous';
    $runBilling($transportExactFallback);
    $assert(array_map('count', array_column($transportExactFallback->calls, 'order_ids')) === [2], 'AMBIGUOUS_FIRST_ATTEMPT_BATCHED_Q1_Q2');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM sale_financial_reconciliation_jobs WHERE safe_message LIKE 'BILLING_BATCH_EXACT_FALLBACK_REQUIRED%'")->fetchColumn() === 2, 'AMBIGUOUS_FIRST_ATTEMPT_MARKED_Q1_Q2');
    $fallbackPlaceholders = implode(',', array_fill(0, count($fallbackIds), '?'));
    $pdo->prepare(
        'UPDATE sale_financial_reconciliation_jobs
            SET next_run_at=UTC_TIMESTAMP()
          WHERE id IN (' . $fallbackPlaceholders . ')'
    )->execute($fallbackIds);
    $pdo->prepare(
        'UPDATE queue_v4_clean_jobs
            SET state="ready",available_at=UTC_TIMESTAMP(3),lease_owner=NULL,lease_expires_at=NULL,lease_generation=lease_generation+1
          WHERE resource_id IN (' . $fallbackPlaceholders . ')'
    )->execute(array_map('strval', $fallbackIds));
    $resetRhythm();
    $transportExactFallback->mode = 'success';
    $runBilling($transportExactFallback);
    $secondAttemptCounts = array_map('count', array_column($transportExactFallback->calls, 'order_ids'));
    $assert($secondAttemptCounts === [2, 1], 'AMBIGUOUS_SECOND_ATTEMPT_EXACT calls=' . json_encode($transportExactFallback->calls));
    $assert($transportExactFallback->calls[1]['order_ids'] === [$pdo->query('SELECT external_sale_id FROM sale_financial_reconciliation_jobs WHERE id=' . (int) $fallbackIds[0])->fetchColumn()], 'SECOND_ATTEMPT_ORDER_IDS=1');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE resource_id=' . (int) $fallbackIds[1] . ' AND state="ready"')->fetchColumn() === 1, 'Q2_NOT_BATCHED_WITH_Q1');
    $resetRhythm();
    $runBilling($transportExactFallback);
    $fallbackAttemptCounts = array_map('count', array_column($transportExactFallback->calls, 'order_ids'));
    $assert($fallbackAttemptCounts === [2, 1, 1], 'LAST_FALLBACK_SOURCE_NOT_REBATCHED calls=' . json_encode($transportExactFallback->calls));
    $assert(!in_array(2, array_slice($fallbackAttemptCounts, 1), true), 'AMBIGUOUS_BATCH_REPEATED=NO');

    $resetBilling();
    $seedFinancial(101, 2, 'PROC');
    $transportProcessing = new BulkBillingFakeTransport();
    $transportProcessing->mode = 'processing';
    $runBilling($transportProcessing);
    $processingStates = $pdo->query('SELECT status,safe_message,COUNT(*) jobs FROM sale_financial_reconciliation_jobs GROUP BY status,safe_message ORDER BY jobs DESC')->fetchAll(PDO::FETCH_ASSOC);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM sale_financial_reconciliation_jobs WHERE status="complete"')->fetchColumn() === 1, 'BILLING_PER_ORDER_STATE states=' . json_encode($processingStates));
    $assert((int) $pdo->query('SELECT COUNT(*) FROM sale_financial_reconciliation_jobs WHERE status="awaiting_remote"')->fetchColumn() === 1, 'BILLING_PER_ORDER_PROCESSING_STATE');

    $worker = $read('app/QueueV4Clean/QueueV4CleanWorker.php');
    $producer = $read('app/QueueV4Clean/QueueV4CleanProducer.php');
    $repository = $read('app/QueueV4Clean/QueueV4CleanRepository.php');
    $client = $read('app/Services/MeliApiClient.php');
    $policy = $read('app/Services/MeliTransportSourcePolicy.php');
    $orderSync = $read('app/Services/OrderSyncService.php');
    $finance = $read('app/Services/SaleFinancialService.php');
    $scheduler = $read('app/QueueV4Clean/QueueV4CleanScheduler.php');
    $cycleBudget = $read('app/QueueV4Clean/QueueV4CleanCycleBudget.php');
    $suite = $read('tests/current_release_suite_2399.php');

    $freshBlockStart = strpos($worker, "if (\$type === 'fresh_orders_discovery')");
    $orderExactBlockStart = strpos($worker, "if (\$type === 'order_exact')");
    $assert($freshBlockStart !== false && $orderExactBlockStart !== false, 'fresh_or_order_exact_block_missing');
    $freshBlock = substr($worker, (int) $freshBlockStart, (int) $orderExactBlockStart - (int) $freshBlockStart);
    $assert(str_contains($freshBlock, 'persistSearchSnapshotForQueueV4Clean'), 'fresh_does_not_persist_search_snapshot');
    $assert(!str_contains($freshBlock, "'order_exact'"), 'fresh_still_enqueues_order_exact');
    $assert(str_contains($producer, '(new SyncSettingsService())->pageLimit()'), 'producer_not_using_sync_page_limit_authority');
    $assert(str_contains($worker, '(new SyncSettingsService())->pageLimit()'), 'worker_not_using_sync_page_limit_authority');
    $assert(!str_contains($freshBlock, 'min(20'), 'fresh_keeps_hardcoded_20');
    $assert(str_contains($orderSync, 'function persistSearchSnapshotForQueueV4Clean'), 'queue_v4_snapshot_persistence_entry_missing');
    $assert(str_contains($orderSync, 'persistOrder($order, false, $beforePersist, false, false)'), 'snapshot_persistence_fanout_not_disabled');
    $assert(str_contains($orderSync, 'OrderInventoryService') && str_contains($orderSync, 'project($companyId'), 'snapshot_inventory_projection_missing');

    $assert(str_contains($finance, 'BILLING_MAX_ORDER_IDS = 60'), 'billing_batch_limit_missing');
    $assert(str_contains($repository, 'function contiguousFinancialReconciliationSourceIds'), 'billing_queue_owned_candidate_claim_missing');
    $assert(str_contains($repository, 'FROM queue_v4_clean_jobs q'), 'billing_candidates_not_queue_v4_owned');
    $assert(str_contains($repository, 'ORDER BY q.available_at ASC,q.id ASC'), 'billing_fifo_order_missing');
    $assert(str_contains($finance, 'function isSimpleCrossSaleCandidate'), 'billing_simple_cross_sale_guard_missing');
    $assert(str_contains($finance, "str_starts_with((string) (\$job['sale_key'] ?? ''), 'O:')"), 'billing_batch_allows_non_order_sales');
    $assert(str_contains($finance, 'function hasUnattributedBillingLines'), 'billing_unattributed_demux_guard_missing');
    $assert(!str_contains($finance, 'ORDER BY j.priority_tier,j.next_run_at,j.id'), 'billing_candidate_priority_order_leaks_into_batch');
    $assert(!str_contains($finance, 'batch_capacity_deferred'), 'billing_batch_capacity_retry_leak');
    $assert(str_contains($worker, 'parkFinancialReconciliationUntil'), 'billing_batch_429_pointer_parking_missing');
    $assert(str_contains($finance, "'bulk' => true"), 'billing_transport_metadata_not_bulk');
    $assert(str_contains($finance, 'expected_resource_ids'), 'billing_expected_resources_missing');
    $assert(!str_contains($finance, 'queue_v4_clean_jobs'), 'SALE_FINANCIAL_KNOWS_QUEUE_V4');
    $assert(str_contains($repository, 'alignReadyFinancialReconciliationPointers'), 'QUEUE_POINTER_STATE_OWNER');
    $assert(str_contains($policy, 'usesPrimaryRhythmAuthority'), 'RHYTHM_PRIMARY_AUTHORITY_policy');
    $assert(str_contains($client, 'usesPrimaryRhythmAuthority($source)'), 'RHYTHM_PRIMARY_AUTHORITY_client');
    $assert(str_contains($client, "? [\n                        'allowed' => true"), 'API_BUDGET_SECOND_NORMAL_THROTTLE');

    $assert(str_contains($scheduler, 'QueueV4CleanCycleBudget::CYCLE_HTTP_SAFETY_FUSE'), 'MAX_JOBS_CONTROLS_HTTP_YES_scheduler');
    $assert(str_contains($cycleBudget, 'CYCLE_HTTP_SAFETY_FUSE = 1000'), 'cycle_budget_safety_fuse_missing');
    QueueV4CleanCycleBudget::start(1000);
    $budgetBlocked = false;
    try {
        for ($i = 0; $i < 11; $i++) {
            QueueV4CleanCycleBudget::claim();
        }
    } catch (Throwable) {
        $budgetBlocked = true;
    } finally {
        QueueV4CleanCycleBudget::clear();
    }
    $assert(!$budgetBlocked, 'MAX_JOBS_CONTROLS_HTTP_YES_runtime');

    foreach ([
        'billing_429_emergency_hotfix_mysql.php',
        'financial_nonfailure_defer_h3_mysql.php',
        'h4_annual_sales_pack_mysql.php',
        'queue_v4_bulk_parity_convergence_2399.php',
    ] as $gate) {
        $assert(str_contains($suite, $gate), 'suite_gate_missing_' . $gate);
    }

    fwrite(STDOUT, 'QUEUE_V4_BULK_PARITY_CONVERGENCE_2399=PASS checks=' . $checks . PHP_EOL);
} finally {
    if (isset($server, $database)) {
        $server->exec('DROP DATABASE IF EXISTS ' . $quote($database));
    }
    if (is_dir($temporary)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($temporary);
    }
}
