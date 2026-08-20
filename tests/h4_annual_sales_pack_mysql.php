<?php

declare(strict_types=1);

use App\Core\Crypto;
use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanDispatchFence;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\ApiExecutionMetadataContext;
use App\Services\AppSettingsService;
use App\Services\MeliApiClient;
use App\Services\MeliHttpTransportInterface;
use App\Services\MeliTransportSourcePolicy;
use App\Services\OrderSyncService;
use App\Services\SchemaInspectorService;

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: H4 exige un DSN MariaDB desechable sin base seleccionada.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_h4_pack_' . bin2hex(random_bytes(5));
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-h4-pack-' . bin2hex(random_bytes(5));
mkdir($temporary . DIRECTORY_SEPARATOR . 'storage', 0770, true);
mkdir($temporary . DIRECTORY_SEPARATOR . 'private', 0700, true);

putenv('APP_KEY=h4-pack-local-test-only');
putenv('ML_WRITE_ENABLED=false');
putenv('CRON_V3_ENABLED=false');
putenv('CRON_V3_SHADOW_ENABLED=false');
putenv('ERP_PRIVATE_PATH=' . $temporary . DIRECTORY_SEPARATOR . 'private');
$_ENV['APP_KEY'] = 'h4-pack-local-test-only';
$_ENV['ML_WRITE_ENABLED'] = 'false';
define('ERP_SHARED_ROOT', $temporary);
define('ERP_INSTALLATION_ROOT', $temporary);
define('ERP_RELEASE_ROOT', $root);

$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$version = (string) $server->query('SELECT VERSION()')->fetchColumn();
if (stripos($version, 'mariadb') === false) {
    fwrite(STDERR, "ERROR: H4 exige MariaDB.\n");
    exit(2);
}
$server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

final class H4OrderTransport implements MeliHttpTransportInterface
{
    /** @var array<string,array<string,mixed>> */
    private array $orders = [];
    public int $physicalCalls = 0;

    /** @param array<string,mixed> $order */
    public function addOrder(array $order): void
    {
        $this->orders[(string) $order['id']] = $order;
    }

    public function request(
        string $method,
        string $url,
        array $data,
        array $headers,
        bool $form,
        array $timeouts,
    ): array {
        $context = ApiExecutionMetadataContext::current();
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        MeliTransportSourcePolicy::assertAllowed((string) ($context['source'] ?? ''), $method, $path);
        QueueV4CleanDispatchFence::immediatelyBeforeCurl($method, $path);
        $this->physicalCalls++;
        QueueV4CleanDispatchFence::responseKnown(200);

        $externalOrderId = basename($path);
        if (!isset($this->orders[$externalOrderId])) {
            throw new RuntimeException('h4_fixture_order_missing:' . $externalOrderId);
        }

        return [
            'status' => 200,
            'body' => $this->orders[$externalOrderId],
            'headers' => [],
            'curl_error' => '',
            'duration_ms' => 1,
            'wire_bytes' => 128,
            'decoded_bytes' => 512,
        ];
    }
}

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $target = $path . DIRECTORY_SEPARATOR . $entry;
        is_dir($target) ? $removeTree($target) : unlink($target);
    }
    rmdir($path);
};

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET SESSION time_zone='+00:00'");
    Database::setConnection($pdo);

    $quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';
    $contract = json_decode(
        (string) file_get_contents($root . '/resources/release/queue-v4-canonical-db-contract-2.38.9.json'),
        true,
        64,
        JSON_THROW_ON_ERROR,
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
        $indexGroups = [];
        foreach ($definition['indexes'] as $index) {
            $indexGroups[(string) $index['INDEX_NAME']][] = $index;
        }
        foreach ($indexGroups as $name => $parts) {
            usort($parts, static fn (array $a, array $b): int => (int) $a['SEQ_IN_INDEX'] <=> (int) $b['SEQ_IN_INDEX']);
            $indexed = array_map(
                static fn (array $part): string => $quote((string) $part['COLUMN_NAME'])
                    . ($part['SUB_PART'] !== null ? '(' . (int) $part['SUB_PART'] . ')' : ''),
                $parts,
            );
            $prefix = $name === 'PRIMARY'
                ? 'PRIMARY KEY'
                : ((int) $parts[0]['NON_UNIQUE'] === 0 ? 'UNIQUE KEY ' . $quote($name) : 'KEY ' . $quote($name));
            $columns[] = $prefix . ' (' . implode(',', $indexed) . ')';
        }
        $pdo->exec(
            'CREATE TABLE ' . $quote((string) $table) . ' (' . implode(',', $columns) . ') ENGINE='
            . $definition['engine'] . ' DEFAULT CHARSET=utf8mb4 COLLATE=' . $definition['collation'],
        );
    }

    $pdo->exec('CREATE TABLE companies(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        status TINYINT NOT NULL DEFAULT 1,
        deleted_at DATETIME NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE internal_products(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        internal_sku VARCHAR(80) NULL,
        name VARCHAR(160) NULL,
        unit VARCHAR(40) NULL,
        status VARCHAR(20) NOT NULL DEFAULT "active",
        deleted_at DATETIME NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_items(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        external_item_id VARCHAR(80) NOT NULL,
        title VARCHAR(160) NULL,
        seller_sku VARCHAR(80) NULL,
        UNIQUE KEY uq_item(meli_account_id,external_item_id)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE product_meli_links(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        internal_product_id BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        meli_item_id BIGINT UNSIGNED NOT NULL,
        meli_variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        conversion_factor DECIMAL(18,4) NOT NULL DEFAULT 1,
        status VARCHAR(20) NOT NULL DEFAULT "active"
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_orders(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        external_order_id VARCHAR(80) NOT NULL,
        external_pack_id VARCHAR(80) NULL,
        date_created DATETIME NULL,
        date_closed DATETIME NULL,
        status VARCHAR(40) NULL,
        status_detail VARCHAR(100) NULL,
        total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        currency_id VARCHAR(10) NULL,
        buyer_id VARCHAR(80) NULL,
        buyer_nickname VARCHAR(120) NULL,
        external_shipping_id VARCHAR(80) NULL,
        tags_json LONGTEXT NULL,
        raw_json LONGTEXT NULL,
        raw_path VARCHAR(255) NULL,
        synced_at DATETIME NULL,
        queue_snapshot_version CHAR(64) NULL,
        queue_snapshot_at DATETIME(3) NULL,
        UNIQUE KEY uq_order_account_external(meli_account_id,external_order_id)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_order_items(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        meli_order_id BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        external_item_id VARCHAR(80) NOT NULL,
        external_variation_id VARCHAR(80) NULL,
        title VARCHAR(255) NULL,
        seller_sku VARCHAR(120) NULL,
        quantity DECIMAL(18,4) NOT NULL DEFAULT 0,
        unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
        full_unit_price DECIMAL(18,2) NULL,
        sale_fee DECIMAL(18,2) NOT NULL DEFAULT 0,
        listing_type_id VARCHAR(80) NULL,
        raw_json LONGTEXT NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_payments(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        meli_order_id BIGINT UNSIGNED NOT NULL,
        external_payment_id VARCHAR(80) NOT NULL,
        status VARCHAR(40) NULL,
        status_detail VARCHAR(100) NULL,
        payment_method_id VARCHAR(80) NULL,
        payment_type VARCHAR(80) NULL,
        transaction_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        shipping_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
        coupon_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        total_paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
        marketplace_fee DECIMAL(18,2) NOT NULL DEFAULT 0,
        date_approved DATETIME NULL,
        raw_json LONGTEXT NULL,
        raw_path VARCHAR(255) NULL,
        synced_at DATETIME NULL,
        UNIQUE KEY uq_payment_account_external(meli_account_id,external_payment_id)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_packs(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        external_pack_id VARCHAR(80) NOT NULL,
        external_shipment_id VARCHAR(80) NULL,
        buyer_json LONGTEXT NULL,
        status VARCHAR(40) NULL,
        raw_json LONGTEXT NULL,
        raw_path VARCHAR(255) NULL,
        synced_at DATETIME NULL,
        expected_orders_count INT UNSIGNED NULL,
        linked_orders_count INT UNSIGNED NULL,
        expected_orders_json LONGTEXT NULL,
        orders_fingerprint CHAR(64) NULL,
        integrity_status VARCHAR(40) NULL,
        integrity_message VARCHAR(255) NULL,
        verified_at DATETIME NULL,
        UNIQUE KEY uq_pack_account_external(meli_account_id,external_pack_id)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_pack_orders(
        meli_pack_id BIGINT UNSIGNED NOT NULL,
        meli_order_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY(meli_pack_id,meli_order_id)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE sale_financial_reconciliation_jobs(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NULL,
        meli_account_id BIGINT UNSIGNED NULL,
        status VARCHAR(24) NOT NULL DEFAULT "pending"
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_billing_capture_runs(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NULL,
        meli_account_id BIGINT UNSIGNED NULL,
        http_status INT NULL
    ) ENGINE=InnoDB');

    foreach ($contract['tables'] as $table => $definition) {
        $groups = [];
        foreach ($definition['foreign_keys'] as $foreignKey) {
            $groups[(string) $foreignKey['CONSTRAINT_NAME']][] = $foreignKey;
        }
        foreach ($groups as $name => $parts) {
            usort($parts, static fn (array $a, array $b): int => (int) $a['ORDINAL_POSITION'] <=> (int) $b['ORDINAL_POSITION']);
            $local = array_map(static fn (array $row): string => $quote((string) $row['COLUMN_NAME']), $parts);
            $remote = array_map(static fn (array $row): string => $quote((string) $row['REFERENCED_COLUMN_NAME']), $parts);
            $first = $parts[0];
            $pdo->exec(
                'ALTER TABLE ' . $quote((string) $table)
                . ' ADD CONSTRAINT ' . $quote($name)
                . ' FOREIGN KEY (' . implode(',', $local) . ') REFERENCES '
                . $quote((string) $first['REFERENCED_TABLE_NAME']) . ' (' . implode(',', $remote) . ')'
                . ((string) $first['MATCH_OPTION'] !== 'NONE' ? ' MATCH ' . $first['MATCH_OPTION'] : '')
                . ' ON UPDATE ' . $first['UPDATE_RULE']
                . ' ON DELETE ' . $first['DELETE_RULE'],
            );
        }
    }

    $pdo->exec('CREATE TABLE api_budget_windows(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        scope VARCHAR(40) NOT NULL,scope_key VARCHAR(255) NOT NULL,
        meli_account_id BIGINT UNSIGNED NULL,endpoint_path VARCHAR(255) NULL,job_type VARCHAR(80) NULL,
        window_started_at DATETIME NOT NULL,window_seconds INT UNSIGNED NOT NULL DEFAULT 900,
        request_limit INT UNSIGNED NOT NULL DEFAULT 0,request_count INT UNSIGNED NOT NULL DEFAULT 0,
        error_400_count INT UNSIGNED NOT NULL DEFAULT 0,error_401_count INT UNSIGNED NOT NULL DEFAULT 0,
        error_403_count INT UNSIGNED NOT NULL DEFAULT 0,error_429_count INT UNSIGNED NOT NULL DEFAULT 0,
        error_5xx_count INT UNSIGNED NOT NULL DEFAULT 0,last_request_at DATETIME NULL,cooldown_until DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_api_budget_window(scope_key,window_started_at,window_seconds)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_rhythm_states(
        scope_key VARCHAR(64) PRIMARY KEY,generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
        calls_in_block INT UNSIGNED NOT NULL DEFAULT 0,block_started_at DATETIME(3) NULL,
        next_allowed_at DATETIME(3) NULL,block_pause_until DATETIME(3) NULL,
        last_dispatched_at DATETIME(3) NULL,
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_remote_permits(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,permit_token CHAR(40) NOT NULL,
        owner_token CHAR(32) NOT NULL,generation BIGINT UNSIGNED NOT NULL,run_token VARCHAR(100) NULL,
        work_key VARCHAR(120) NULL,company_id BIGINT UNSIGNED NULL,meli_account_id BIGINT UNSIGNED NULL,
        endpoint_key VARCHAR(120) NOT NULL,job_type VARCHAR(80) NOT NULL,method VARCHAR(10) NOT NULL,
        status ENUM("reserved","dispatched","completed","released","expired") NOT NULL DEFAULT "reserved",
        requested_interval_ms INT UNSIGNED NOT NULL,effective_interval_ms INT UNSIGNED NOT NULL,
        blocking_scope VARCHAR(80) NULL,http_status SMALLINT UNSIGNED NULL,created_at DATETIME(3) NOT NULL,
        dispatched_at DATETIME(3) NULL,completed_at DATETIME(3) NULL,released_at DATETIME(3) NULL,
        expires_at DATETIME(3) NOT NULL,updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        UNIQUE KEY uq_api_remote_permit_token(permit_token),KEY idx_api_remote_permit_active(status,expires_at)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE api_rhythm_penalties(
        scope_key VARCHAR(180) PRIMARY KEY,reduced_limit_per_minute SMALLINT UNSIGNED NOT NULL,
        blocked_until DATETIME(3) NULL,reduced_until DATETIME(3) NOT NULL,reason VARCHAR(80) NOT NULL,
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
    ) ENGINE=InnoDB');

    $pdo->exec("INSERT INTO companies(name,status) VALUES ('Empresa H4',1),('Otra Empresa',1)");
    $companyId = 1;
    $otherCompanyId = 2;
    $account = $pdo->prepare(
        'INSERT INTO meli_accounts(company_id,account_name,meli_user_id,site_id,status)
         VALUES (?,"Cuenta H4",?,"MCO","conectado")'
    );
    $account->execute([$companyId, '900001']);
    $accountId = (int) $pdo->lastInsertId();
    $account->execute([$otherCompanyId, '900002']);
    $otherAccountId = (int) $pdo->lastInsertId();
    $token = $pdo->prepare(
        'INSERT INTO meli_tokens
         (meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,refresh_version)
         VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 6 HOUR),1)'
    );
    $token->execute([$accountId, Crypto::encrypt('h4-access'), Crypto::encrypt('h4-refresh')]);
    $token->execute([$otherAccountId, Crypto::encrypt('h4-access-other'), Crypto::encrypt('h4-refresh-other')]);

    $settings = new AppSettingsService();
    foreach ([
        'api.guard.enabled' => '0',
        'api.rhythm.profile' => 'maximum',
        'api.rhythm.target_http_per_minute' => '40',
        'api.rhythm.current_adaptive_limit' => '40',
        'api.rhythm.minimum_interval_ms' => '1',
        'api.rhythm.rolling_window_seconds' => '60',
        'api.rhythm.adaptive_enabled' => '0',
        'api.rhythm.orders_search_requests_per_15m' => '90',
        'api.rhythm.shared_429_backoff_seconds' => '60',
        'api.rhythm.shared_429_jitter_seconds' => '0',
    ] as $key => $value) {
        $settings->set($key, $value, 'h4_test');
    }
    AppSettingsService::clearCache();
    SchemaInspectorService::clearCache();

    $pdo->exec(
        "INSERT INTO queue_v4_clean_control
            (control_key,engine_state,readiness_state,scheduler_enabled)
         VALUES ('primary','ACTIVE','CERTIFIED',1)"
    );

    $transport = new H4OrderTransport();
    $repository = new QueueV4CleanRepository($pdo);
    $syncFactory = static fn (int $id): OrderSyncService => new OrderSyncService($id, new MeliApiClient($id, $transport));
    $worker = new QueueV4CleanWorker($pdo, $repository, null, $syncFactory);

    $order = static function (string $id, string $packId): array {
        return [
            'id' => $id,
            'date_created' => '2026-08-20T00:00:00.000-05:00',
            'last_updated' => '2026-08-20T00:01:00.000-05:00',
            'status' => 'confirmed',
            'status_detail' => 'fixture',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'currency_id' => 'COP',
            'pack_id' => $packId,
            'shipping' => ['id' => '50' . $id],
            'buyer' => ['id' => 700000 + (int) $id, 'nickname' => 'fixture'],
            'order_items' => [],
            'payments' => [],
            'tags' => [],
        ];
    };
    $expectedHash = static fn (array $ids): string => hash('sha256', implode('|', array_values($ids)));
    $insertLocalOrder = static function (int $account, string $externalOrderId, string $externalPackId) use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO meli_orders
                (meli_account_id,external_order_id,external_pack_id,status,status_detail,total_amount,paid_amount,currency_id,synced_at)
             VALUES (?,?,?,"confirmed","fixture",0,0,"COP",UTC_TIMESTAMP())'
        );
        $stmt->execute([$account, $externalOrderId, $externalPackId]);
        return (int) $pdo->lastInsertId();
    };
    $seedPack = static function (
        int $account,
        string $externalPackId,
        ?array $expected,
        string $status = 'partial',
        ?string $fingerprint = null
    ) use ($pdo): int {
        $expectedJson = $expected === null ? null : json_encode($expected, JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare(
            'INSERT INTO meli_packs
                (meli_account_id,external_pack_id,status,expected_orders_count,linked_orders_count,
                 expected_orders_json,orders_fingerprint,integrity_status,integrity_message,verified_at,synced_at)
             VALUES (?,?,"provisional",?,?,?, ?, ?, "fixture", NULL, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            $account,
            $externalPackId,
            $expected === null ? null : count($expected),
            0,
            $expectedJson,
            $fingerprint,
            $status,
        ]);
        return (int) $pdo->lastInsertId();
    };
    $link = static function (int $packId, int $orderId) use ($pdo): void {
        $pdo->prepare('INSERT IGNORE INTO meli_pack_orders(meli_pack_id,meli_order_id) VALUES (?,?)')
            ->execute([$packId, $orderId]);
    };
    $packRow = static function (int $packId) use ($pdo): array {
        $stmt = $pdo->prepare('SELECT * FROM meli_packs WHERE id=?');
        $stmt->execute([$packId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    };
    $runOrderExact = static function (string $externalOrderId) use (
        $accountId,
        $companyId,
        $repository,
        $worker,
        $pdo
    ): array {
        $beforeJobs = (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn();
        $beforeFinance = (int) $pdo->query('SELECT COUNT(*) FROM sale_financial_reconciliation_jobs')->fetchColumn();
        $beforeBilling = (int) $pdo->query('SELECT COUNT(*) FROM meli_billing_capture_runs')->fetchColumn();
        $jobId = $repository->enqueue(
            $companyId,
            $accountId,
            'order_exact',
            $externalOrderId,
            'h4:order:' . $externalOrderId,
            ['order_id' => $externalOrderId],
            3,
        );
        QueueV4CleanCycleBudget::start(5);
        try {
            $summary = $worker->run('test', 1, 10);
        } finally {
            QueueV4CleanCycleBudget::clear();
        }
        $afterJobs = (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn();
        $afterFinance = (int) $pdo->query('SELECT COUNT(*) FROM sale_financial_reconciliation_jobs')->fetchColumn();
        $afterBilling = (int) $pdo->query('SELECT COUNT(*) FROM meli_billing_capture_runs')->fetchColumn();
        return [
            'job_id' => $jobId,
            'summary' => $summary,
            'job_delta' => $afterJobs - $beforeJobs,
            'finance_delta' => $afterFinance - $beforeFinance,
            'billing_delta' => $afterBilling - $beforeBilling,
        ];
    };

    // PACK_EXPECTS_TWO: the second exact order closes a pre-existing partial pack.
    $transport->addOrder($order('9002', 'PACK-H4-COMPLETE'));
    $packId = $seedPack($accountId, 'PACK-H4-COMPLETE', ['9001', '9002'], 'partial', $expectedHash(['9001', '9002']));
    $link($packId, $insertLocalOrder($accountId, '9001', 'PACK-H4-COMPLETE'));
    $result = $runOrderExact('9002');
    $pack = $packRow($packId);
    $assert($result['summary'] === ['claimed' => 1, 'completed' => 1, 'deferred' => 0], 'queue_v4_entrypoint_not_completed');
    $assert((int) $result['job_delta'] === 1, 'refresh_created_extra_queue_job');
    $assert((int) $result['finance_delta'] === 0, 'finance_attempt_mutated');
    $assert((int) $result['billing_delta'] === 0, 'billing_capture_mutated');
    $assert((string) $pack['integrity_status'] === 'complete', 'pack_expects_two_not_complete');
    $assert((int) $pack['linked_orders_count'] === 2, 'pack_expects_two_linked_count_invalid');
    $assert((string) $pack['orders_fingerprint'] === $expectedHash(['9001', '9002']), 'fingerprint_expected_set_not_preserved_complete');
    $assert((string) $pack['verified_at'] !== '', 'complete_verified_at_missing');

    // PACK_STILL_MISSING: same path stays partial when one expected order is still absent.
    $transport->addOrder($order('9012', 'PACK-H4-PARTIAL'));
    $partialPackId = $seedPack($accountId, 'PACK-H4-PARTIAL', ['9011', '9012', '9013'], 'partial', $expectedHash(['9011', '9012', '9013']));
    $link($partialPackId, $insertLocalOrder($accountId, '9011', 'PACK-H4-PARTIAL'));
    $runOrderExact('9012');
    $partialPack = $packRow($partialPackId);
    $assert((string) $partialPack['integrity_status'] === 'partial', 'missing_pack_not_partial');
    $assert((int) $partialPack['linked_orders_count'] === 2, 'missing_pack_linked_count_invalid');
    $assert((string) $partialPack['orders_fingerprint'] === $expectedHash(['9011', '9012', '9013']), 'fingerprint_expected_set_not_preserved_partial');
    $assert((string) $partialPack['verified_at'] !== '', 'partial_verified_at_missing');

    // EXPECTATION_ABSENT: only linked count is refreshed; status/fingerprint stay untouched.
    $transport->addOrder($order('9022', 'PACK-H4-ABSENT'));
    $absentPackId = $seedPack($accountId, 'PACK-H4-ABSENT', null, 'provisional', null);
    $link($absentPackId, $insertLocalOrder($accountId, '9021', 'PACK-H4-ABSENT'));
    $runOrderExact('9022');
    $absentPack = $packRow($absentPackId);
    $assert((int) $absentPack['linked_orders_count'] === 2, 'absent_expected_linked_count_invalid');
    $assert((string) $absentPack['integrity_status'] === 'provisional', 'absent_expected_status_changed');
    $assert($absentPack['orders_fingerprint'] === null, 'absent_expected_fingerprint_changed');

    // EXPECTATION_INVALID: invalid JSON also fails safe with count-only refresh.
    $transport->addOrder($order('9032', 'PACK-H4-INVALID'));
    $invalidPackId = $seedPack($accountId, 'PACK-H4-INVALID', null, 'needs_review', 'keep-me');
    $pdo->prepare('UPDATE meli_packs SET expected_orders_json=? WHERE id=?')
        ->execute(['{not valid json', $invalidPackId]);
    $link($invalidPackId, $insertLocalOrder($accountId, '9031', 'PACK-H4-INVALID'));
    $runOrderExact('9032');
    $invalidPack = $packRow($invalidPackId);
    $assert((int) $invalidPack['linked_orders_count'] === 2, 'invalid_expected_linked_count_invalid');
    $assert((string) $invalidPack['integrity_status'] === 'needs_review', 'invalid_expected_status_changed');
    $assert((string) $invalidPack['orders_fingerprint'] === 'keep-me', 'invalid_expected_fingerprint_changed');

    // EXTRA_LINKED_ORDER: extra linked order with the same pack keeps integrity partial.
    $transport->addOrder($order('9042', 'PACK-H4-EXTRA'));
    $extraPackId = $seedPack($accountId, 'PACK-H4-EXTRA', ['9041', '9042'], 'partial', $expectedHash(['9041', '9042']));
    $link($extraPackId, $insertLocalOrder($accountId, '9041', 'PACK-H4-EXTRA'));
    $link($extraPackId, $insertLocalOrder($accountId, '9049', 'PACK-H4-EXTRA'));
    $runOrderExact('9042');
    $extraPack = $packRow($extraPackId);
    $assert((string) $extraPack['integrity_status'] === 'partial', 'extra_linked_order_not_partial');
    $assert((int) $extraPack['linked_orders_count'] === 3, 'extra_linked_count_invalid');
    $assert((string) $extraPack['orders_fingerprint'] === $expectedHash(['9041', '9042']), 'extra_fingerprint_not_expected');

    // TENANT_COLLISION_SAME_EXTERNAL_PACK: another tenant sharing external pack id is untouched.
    $transport->addOrder($order('9052', 'PACK-H4-TENANT'));
    $tenantPackId = $seedPack($accountId, 'PACK-H4-TENANT', ['9051', '9052'], 'partial', $expectedHash(['9051', '9052']));
    $otherPackId = $seedPack($otherAccountId, 'PACK-H4-TENANT', ['9051', '9052'], 'complete', $expectedHash(['9051', '9052']));
    $link($tenantPackId, $insertLocalOrder($accountId, '9051', 'PACK-H4-TENANT'));
    $link($otherPackId, $insertLocalOrder($otherAccountId, '9051', 'PACK-H4-TENANT'));
    $runOrderExact('9052');
    $tenantPack = $packRow($tenantPackId);
    $otherPack = $packRow($otherPackId);
    $assert((string) $tenantPack['integrity_status'] === 'complete', 'tenant_pack_not_completed');
    $assert((int) $tenantPack['linked_orders_count'] === 2, 'tenant_pack_linked_invalid');
    $assert((string) $otherPack['integrity_status'] === 'complete', 'other_tenant_status_changed');
    $assert((int) $otherPack['linked_orders_count'] === 0, 'other_tenant_linked_count_changed');

    // REPEATED_ORDER: replaying the same exact order does not duplicate links.
    $runOrderExact('9052');
    $tenantPackReplay = $packRow($tenantPackId);
    $assert((string) $tenantPackReplay['integrity_status'] === 'complete', 'repeated_order_status_changed');
    $assert((int) $tenantPackReplay['linked_orders_count'] === 2, 'repeated_order_duplicated_link');

    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type<>'order_exact'")->fetchColumn() === 0, 'non_order_queue_created');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM sale_financial_reconciliation_jobs')->fetchColumn() === 0, 'financial_jobs_created');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM meli_billing_capture_runs')->fetchColumn() === 0, 'billing_capture_created');
    $assert($transport->physicalCalls === 7, 'fake_transport_call_count_invalid');

    fwrite(STDOUT, "PACK_EXPECTS_TWO=PASS\n");
    fwrite(STDOUT, "PACK_STILL_MISSING=PASS\n");
    fwrite(STDOUT, "EXPECTATION_ABSENT=PASS\n");
    fwrite(STDOUT, "EXPECTATION_INVALID=PASS\n");
    fwrite(STDOUT, "EXTRA_LINKED_ORDER=PASS\n");
    fwrite(STDOUT, "TENANT_COLLISION_SAME_EXTERNAL_PACK=PASS\n");
    fwrite(STDOUT, "REPEATED_ORDER=PASS\n");
    fwrite(STDOUT, "FINGERPRINT_EXPECTED_SET_PRESERVED=PASS\n");
    fwrite(STDOUT, "QUEUE_V4_ENTRYPOINT_PRESERVED=PASS\n");
    fwrite(STDOUT, "NO_QUEUE_CREATED_BY_REFRESH=PASS\n");
    fwrite(STDOUT, "NO_FINANCE_ATTEMPT_MUTATION=PASS\n");
    fwrite(STDOUT, "NO_BILLING_CAPTURE=PASS\n");
    fwrite(STDOUT, 'H4_ANNUAL_SALES_PACK_MYSQL=PASS checks=' . $checks . " real_http=0\n");
} finally {
    $server->exec("DROP DATABASE IF EXISTS `{$database}`");
    $removeTree($temporary);
}
