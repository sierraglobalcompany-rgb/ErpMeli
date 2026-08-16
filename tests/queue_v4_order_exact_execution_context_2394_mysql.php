<?php

declare(strict_types=1);

use App\Core\Crypto;
use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanDatabaseContract;
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

$mode = strtolower(trim((string) ($argv[1] ?? 'post')));
if (!in_array($mode, ['pre', 'post'], true)) {
    fwrite(STDERR, "Usage: php queue_v4_order_exact_execution_context_2394_mysql.php [pre|post]\n");
    exit(2);
}

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: H1 exige un DSN MariaDB desechable sin base seleccionada.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_h1_order_exact_' . bin2hex(random_bytes(5));
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-h1-order-exact-' . bin2hex(random_bytes(5));
mkdir($temporary . DIRECTORY_SEPARATOR . 'storage', 0770, true);
mkdir($temporary . DIRECTORY_SEPARATOR . 'private', 0700, true);

putenv('APP_KEY=h1-order-exact-local-test-only');
putenv('ML_WRITE_ENABLED=false');
putenv('CRON_V3_ENABLED=false');
putenv('CRON_V3_SHADOW_ENABLED=false');
putenv('ERP_PRIVATE_PATH=' . $temporary . DIRECTORY_SEPARATOR . 'private');
$_ENV['APP_KEY'] = 'h1-order-exact-local-test-only';
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
    fwrite(STDERR, "ERROR: H1 exige MariaDB.\n");
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

final class H1OrderExactTransport implements MeliHttpTransportInterface
{
    /** @var array<string,mixed> */
    public array $context = [];
    /** @var array<string,mixed> */
    public array $physicalFenceState = [];
    public int $entered = 0;
    public int $physicalCalls = 0;
    public string $rootException = '';

    public function request(
        string $method,
        string $url,
        array $data,
        array $headers,
        bool $form,
        array $timeouts,
    ): array {
        $this->entered++;
        $this->context = ApiExecutionMetadataContext::current();
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        try {
            MeliTransportSourcePolicy::assertAllowed(
                (string) ($this->context['source'] ?? ''),
                $method,
                $path,
            );
        } catch (Throwable $error) {
            $this->rootException = $error->getMessage();
            throw $error;
        }

        QueueV4CleanDispatchFence::immediatelyBeforeCurl($method, $path);
        $this->physicalFenceState = QueueV4CleanDispatchFence::state($this->context);
        $this->physicalCalls++;
        QueueV4CleanDispatchFence::responseKnown(200);

        return [
            'status' => 200,
            'body' => [
                'id' => '23940001',
                'date_created' => '2026-08-16T09:00:00.000-05:00',
                'last_updated' => '2026-08-16T09:01:00.000-05:00',
                'status' => 'paid',
                'status_detail' => 'accredited',
                'total_amount' => 100000,
                'paid_amount' => 100000,
                'currency_id' => 'COP',
                'pack_id' => null,
                'shipping' => ['id' => '23949001'],
                'buyer' => ['id' => 900001, 'nickname' => 'fixture'],
                'order_items' => [[
                    'item' => [
                        'id' => 'MCO23940001',
                        'title' => 'Producto H1',
                        'variation_id' => null,
                        'seller_sku' => 'H1-FIXTURE',
                    ],
                    'quantity' => 1,
                    'unit_price' => 100000,
                    'full_unit_price' => 100000,
                ]],
                'payments' => [[
                    'id' => '23946001',
                    'status' => 'approved',
                    'transaction_amount' => 100000,
                    'date_approved' => '2026-08-16T09:00:30.000-05:00',
                    'payment_type' => 'account_money',
                    'payment_method_id' => 'account_money',
                ]],
            ],
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

    $pdo->exec((string) file_get_contents($root . '/database/migrations/299_queue_v4_domain_exact_admission_2_39_3.sql'));
    $pdo->prepare('INSERT INTO schema_migrations(version) VALUES (?)')->execute([
        '299_queue_v4_domain_exact_admission_2_39_3.sql',
    ]);
    $assert((new QueueV4CleanDatabaseContract($pdo))->issues() === [], 'queue_v4_contract_failed');

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

    $pdo->exec("INSERT INTO companies(name,status) VALUES ('Empresa H1',1)");
    $companyId = (int) $pdo->lastInsertId();
    $account = $pdo->prepare(
        'INSERT INTO meli_accounts(company_id,account_name,meli_user_id,site_id,status)
         VALUES (?,"Cuenta H1","900001","MCO","conectado")'
    );
    $account->execute([$companyId]);
    $accountId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO meli_tokens
         (meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,refresh_version)
         VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 6 HOUR),1)'
    )->execute([$accountId, Crypto::encrypt('h1-access'), Crypto::encrypt('h1-refresh')]);

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
        $settings->set($key, $value, 'h1_test');
    }
    AppSettingsService::clearCache();
    SchemaInspectorService::clearCache();

    $pdo->exec(
        "INSERT INTO queue_v4_clean_control
            (control_key,engine_state,readiness_state,scheduler_enabled)
         VALUES ('primary','ACTIVE','CERTIFIED',1)"
    );
    $repository = new QueueV4CleanRepository($pdo);
    $jobId = $repository->enqueue(
        $companyId,
        $accountId,
        'order_exact',
        '23940001',
        'h1:order:23940001',
        ['order_id' => '23940001'],
        3,
    );
    $transport = new H1OrderExactTransport();
    $syncFactory = static fn (int $id): OrderSyncService => new OrderSyncService(
        $id,
        new MeliApiClient($id, $transport),
    );

    QueueV4CleanCycleBudget::start(2);
    try {
        $result = (new QueueV4CleanWorker(
            $pdo,
            $repository,
            null,
            $syncFactory,
        ))->run('test', 1, 10);
    } finally {
        QueueV4CleanCycleBudget::clear();
    }

    $job = $pdo->query('SELECT * FROM queue_v4_clean_jobs WHERE id=' . $jobId)->fetch(PDO::FETCH_ASSOC);
    $attempt = $pdo->query(
        'SELECT * FROM queue_v4_clean_attempts WHERE job_id=' . $jobId . ' ORDER BY id DESC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($job) && is_array($attempt), 'queue_receipts_missing');

    if ($mode === 'pre') {
        $assert($transport->entered === 1, 'pre_transport_not_entered');
        $assert((string) ($transport->context['source'] ?? '') === '', 'pre_context_source_not_missing');
        $assert($transport->rootException === 'meli_transport_source_unknown', 'pre_root_exception_not_exact');
        $assert($transport->physicalCalls === 0, 'pre_real_transport_started');
        $assert($result === ['claimed' => 1, 'completed' => 0, 'deferred' => 1], 'pre_worker_summary_changed');
        $assert((string) $job['state'] === 'waiting', 'pre_job_not_waiting');
        $assert((string) $attempt['outcome'] === 'waiting', 'pre_attempt_not_waiting');
        $assert((string) $attempt['error_class'] === 'pre_transport_deferred', 'pre_classification_not_collapsed');
        $assert((int) $attempt['physical_http_calls'] === 0, 'pre_attempt_http_not_zero');
        fwrite(STDOUT, "PRE_FIX_REAL_PATH=PASS\n");
        fwrite(STDOUT, "PRE_FIX_CONTEXT_SOURCE=EMPTY\n");
        fwrite(STDOUT, "ROOT_EXCEPTION=meli_transport_source_unknown\n");
        fwrite(STDOUT, "PRE_FIX_WORKER_CLASSIFICATION=pre_transport_deferred\n");
    } else {
        $context = $transport->context;
        $assert((string) ($context['source'] ?? '') === 'queue_v4_clean', 'post_context_source_invalid');
        $assert((string) ($context['job_type'] ?? '') === 'order_exact', 'post_job_type_invalid');
        $assert((int) ($context['company_id'] ?? 0) === $companyId, 'post_company_invalid');
        $assert((int) ($context['account_id'] ?? 0) === $accountId, 'post_account_invalid');
        $assert((int) ($context['queue_v4_job_id'] ?? 0) === $jobId, 'post_job_id_invalid');
        $assert((int) ($context['queue_v4_attempt_id'] ?? 0) === (int) $attempt['id'], 'post_attempt_id_invalid');
        $assert(trim((string) ($context['queue_v4_lease_owner'] ?? '')) !== '', 'post_lease_owner_missing');
        $assert((int) ($context['queue_v4_lease_generation'] ?? 0) === (int) $attempt['lease_generation'], 'post_generation_invalid');
        $assert((string) ($transport->physicalFenceState['dispatch_state'] ?? '') === 'PHYSICAL_STARTED', 'post_physical_fence_missing');
        $assert($transport->physicalCalls === 1, 'post_fake_transport_count_invalid');
        $assert($result === ['claimed' => 1, 'completed' => 1, 'deferred' => 0], 'post_worker_summary_invalid:' . json_encode($result));
        $assert((string) $job['state'] === 'completed', 'post_job_not_completed');
        $assert((int) $job['attempt_count'] === 1, 'post_attempt_count_semantics_changed');
        $assert((string) $attempt['outcome'] === 'completed', 'post_attempt_not_completed');
        $assert((string) $attempt['dispatch_state'] === 'RESPONSE_KNOWN', 'post_response_not_known');
        $assert((int) $attempt['physical_http_calls'] === 1, 'post_attempt_http_invalid');
        $assert((int) $pdo->query(
            "SELECT COUNT(*) FROM meli_orders WHERE meli_account_id={$accountId} AND external_order_id='23940001'"
        )->fetchColumn() === 1, 'post_order_not_persisted');
        fwrite(STDOUT, "POST_FIX_CONTEXT_SOURCE=queue_v4_clean\n");
        fwrite(STDOUT, "POST_FIX_ORDER_EXACT_COMPLETED=YES\n");
        fwrite(STDOUT, "POST_FIX_ATTEMPT_COUNT=1\n");
        fwrite(STDOUT, "POST_FIX_PRETRANSPORT=0\n");
        fwrite(STDOUT, "POST_FIX_PHYSICAL_FENCE=PASS\n");
    }

    fwrite(STDOUT, 'QUEUE_V4_ORDER_EXACT_EXECUTION_CONTEXT_2394=PASS mode=' . $mode
        . ' checks=' . $checks . " real_http=0\n");
} finally {
    $server->exec("DROP DATABASE IF EXISTS `{$database}`");
    $removeTree($temporary);
}
