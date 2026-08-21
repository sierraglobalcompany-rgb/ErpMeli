<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;

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

    $worker = $read('app/QueueV4Clean/QueueV4CleanWorker.php');
    $producer = $read('app/QueueV4Clean/QueueV4CleanProducer.php');
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
    $assert(str_contains($finance, 'function claimAdditionalQueueOwnedBillingJobs'), 'billing_queue_owned_candidate_claim_missing');
    $assert(str_contains($finance, 'FROM queue_v4_clean_jobs q'), 'billing_candidates_not_queue_v4_owned');
    $assert(str_contains($finance, 'ORDER BY q.available_at ASC,q.id ASC'), 'billing_fifo_order_missing');
    $assert(str_contains($finance, 'function isSimpleCrossSaleCandidate'), 'billing_simple_cross_sale_guard_missing');
    $assert(str_contains($finance, "str_starts_with((string) (\$job['sale_key'] ?? ''), 'O:')"), 'billing_batch_allows_non_order_sales');
    $assert(str_contains($finance, 'function hasUnattributedBillingLines'), 'billing_unattributed_demux_guard_missing');
    $assert(!str_contains($finance, 'ORDER BY j.priority_tier,j.next_run_at,j.id'), 'billing_candidate_priority_order_leaks_into_batch');
    $assert(!str_contains($finance, 'batch_capacity_deferred'), 'billing_batch_capacity_retry_leak');
    $assert(str_contains($finance, 'deferAdditionalQueuePointers'), 'billing_batch_429_pointer_parking_missing');
    $assert(str_contains($finance, "'bulk' => true"), 'billing_transport_metadata_not_bulk');
    $assert(str_contains($finance, 'expected_resource_ids'), 'billing_expected_resources_missing');

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
