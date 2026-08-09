<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use App\QueueCore\QueueClaim;
use App\QueueCore\QueueCoreRepository;
use App\QueueCore\SalePipelineCapabilityRepository;

$dsn = getenv('QUEUE_CORE_TEST_DSN') ?: '';
$user = getenv('QUEUE_CORE_TEST_USER') ?: '';
$pass = getenv('QUEUE_CORE_TEST_PASS') ?: '';
if ($dsn === '') {
    fwrite(STDERR, "QUEUE_CORE_TEST_DSN is required\n");
    exit(2);
}
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("SET time_zone='+00:00'");
App\Core\Database::setConnection($pdo);

$drop = [
    'queue_core_capability_dependencies','queue_core_pending_capabilities',
    'queue_core_dispatch_journal','queue_core_attempts','queue_core_events','queue_core_jobs',
    'queue_core_producer_checkpoints','queue_core_scheduler_state','queue_core_execution_leases',
    'queue_engine_control','meli_orders','meli_accounts',
];
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($drop as $table) {
    $pdo->exec("DROP TABLE IF EXISTS `$table`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE meli_accounts (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(40) NOT NULL
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE meli_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    external_order_id VARCHAR(80) NOT NULL,
    external_pack_id VARCHAR(80) NULL,
    external_shipping_id VARCHAR(80) NULL,
    synced_at DATETIME NULL,
    UNIQUE KEY uq_b2_order (meli_account_id,external_order_id)
) ENGINE=InnoDB");
$pdo->exec("INSERT INTO meli_accounts VALUES (1,10,'connected'),(2,20,'connected')");

$apply = static function (string $file) use ($pdo, $root): void {
    $sql = file_get_contents($root . '/database/migrations/' . $file);
    if (!is_string($sql)) {
        throw new RuntimeException('Migration missing: ' . $file);
    }
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
        $statement = trim($statement);
        $statement = preg_replace('/^--[^\n]*\n(?:--[^\n]*\n)*/', '', $statement) ?? $statement;
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
};
$migrations = [
    '280_queue_core_cron_v4_phase_b1.sql',
    '281_queue_core_reaudit1_fifo_fencing.sql',
    '282_queue_core_architecture_closeout_b1_2.sql',
    '283_queue_engine_control_oauth_supervisor_b1_4.sql',
    '284_queue_core_sales_pipeline_b2.sql',
];
foreach ($migrations as $migration) {
    $apply($migration);
}
foreach ($migrations as $migration) {
    $apply($migration);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$pdo->exec("INSERT INTO meli_orders
    (id,meli_account_id,external_order_id,external_pack_id,external_shipping_id,synced_at,queue_snapshot_version,queue_snapshot_at)
    VALUES (101,1,'70001','80001','90001',UTC_TIMESTAMP(),'snapshot-v1',UTC_TIMESTAMP(3))");
$insertCapability = $pdo->prepare(
    "INSERT INTO queue_core_pending_capabilities
      (company_id,meli_account_id,resource_type,resource_id,capability_key,state)
     VALUES (?,?,?,?,?,'pending_b2')"
);
$insertCapability->execute([10,1,'order','101','financial_projection']);
$financialCapability = (int) $pdo->lastInsertId();
$insertCapability->execute([10,1,'order','101','order_enrichment']);
$enrichmentCapability = (int) $pdo->lastInsertId();

$queue = new QueueCoreRepository($pdo);
$pipeline = new SalePipelineCapabilityRepository($pdo, $queue);
$summary = $pipeline->materializePending(10, 101);
$assert($summary['materialized'] === 2, 'Both sale capabilities must materialize.');
$assert((int) $pdo->query("SELECT COUNT(*) FROM queue_core_jobs")->fetchColumn() === 3,
    'Financial, pack and shipment jobs must be exact children.');
$assert((int) $pdo->query("SELECT COUNT(*) FROM queue_core_capability_dependencies")->fetchColumn() === 3,
    'Every exact child must have one durable dependency.');
$assert($pipeline->materializePending(10, 101)['materialized'] === 0,
    'Materialization must be idempotent.');

$jobRow = $pdo->query("SELECT * FROM queue_core_jobs WHERE work_type='financial_projection' LIMIT 1")->fetch();
$financialClaim = new QueueClaim(
    (int) $jobRow['id'], 10, 1, 'financial_projection', 'order', '101', 'local', 0,
    'running', 1, 5, 'fixture', 1, 'NOT_DISPATCHED',
    json_decode((string) $jobRow['payload_json'], true, 32, JSON_THROW_ON_ERROR),
    'queue_core_sale_pipeline', 'capability:' . $financialCapability
);
$assert($pipeline->completeDependency($financialClaim, $financialCapability),
    'Financial capability dependency must complete with tenant scope.');
$assert((string) $pdo->query("SELECT state FROM queue_core_pending_capabilities WHERE id=$financialCapability")->fetchColumn() === 'resolved',
    'A single local dependency must resolve its capability.');

$packRow = $pdo->query("SELECT * FROM queue_core_jobs WHERE work_type='pack_exact' LIMIT 1")->fetch();
$packClaim = new QueueClaim(
    (int) $packRow['id'], 10, 1, 'pack_exact', 'pack', '80001', 'normal', 0,
    'running', 1, 5, 'fixture', 1, 'DISPATCHED_RESULT_KNOWN',
    json_decode((string) $packRow['payload_json'], true, 32, JSON_THROW_ON_ERROR),
    'queue_core_sale_pipeline', 'capability:' . $enrichmentCapability
);
$spawned = $pipeline->appendShipmentDependency(
    $packClaim, $enrichmentCapability, 101, '90002', 'snapshot-v1'
);
$duplicate = $pipeline->appendShipmentDependency(
    $packClaim, $enrichmentCapability, 101, '90002', 'snapshot-v1'
);
$assert($spawned === $duplicate, 'Spawned shipment dependency must deduplicate.');
$assert((int) $pdo->query("SELECT required_dependencies FROM queue_core_pending_capabilities WHERE id=$enrichmentCapability")->fetchColumn() === 3,
    'A new shipment may increment the graph exactly once.');
$assert($pipeline->completeDependency($packClaim, $enrichmentCapability),
    'Pack container must complete independently from shipment children.');
$assert((int) $pdo->query("SELECT COUNT(*) FROM meli_orders")->fetchColumn() === 1,
    'A pack container must never duplicate a sale/order row.');

// A newer order snapshot reuses the capability identity but starts a new,
// fenced dependency generation. Old workers cannot complete the new graph.
$pdo->exec("UPDATE meli_orders SET queue_snapshot_version='snapshot-v2' WHERE id=101");
$pdo->exec("UPDATE queue_core_pending_capabilities
    SET state='pending_b2',input_version='snapshot-v2',lifecycle_generation=lifecycle_generation+1,
        required_dependencies=0,completed_dependencies=0,resolved_at=NULL
    WHERE id=$enrichmentCapability");
$assert($pipeline->materializePending(1, 101)['materialized'] === 1,
    'A new snapshot must materialize a new dependency generation.');
$assert((int) $pdo->query("SELECT COUNT(*) FROM queue_core_capability_dependencies
    WHERE capability_id=$enrichmentCapability AND lifecycle_generation=1")->fetchColumn() === 2,
    'The new snapshot must keep old evidence but create only its own dependencies.');
$assert(!$pipeline->completeDependency($packClaim, $enrichmentCapability),
    'An old generation must not resolve a newer order snapshot.');
$assert((int) $pdo->query("SELECT COUNT(*) FROM queue_core_jobs WHERE work_type='order_exact'")->fetchColumn() === 0,
    'The sale graph must not cycle back into order discovery/exact work.');

$crossScope = new QueueClaim(
    $financialClaim->id, 20, 2, 'financial_projection', 'order', '101', 'local', 0,
    'running', 1, 5, 'fixture', 1, 'NOT_DISPATCHED', $financialClaim->payload,
    'queue_core_sale_pipeline', 'capability:' . $financialCapability
);
$assert(!$pipeline->completeDependency($crossScope, $financialCapability),
    'Cross-company/account completion must fail closed.');

$insertCapability->execute([20,2,'order','101','financial_projection']);
$foreignCapability = (int) $pdo->lastInsertId();
$review = $pipeline->materializePending(1);
$assert($review['review'] === 1
    && (string) $pdo->query("SELECT state FROM queue_core_pending_capabilities WHERE id=$foreignCapability")->fetchColumn() === 'review',
    'A capability whose order is outside scope must become review, not cross tenant.');

$source = file_get_contents($root . '/app/Services/SaleFinancialStateService.php');
$assert(is_string($source)
    && str_contains($source, 'projectOrderForQueueCore')
    && !str_contains(substr($source, (int) strpos($source, 'projectOrderForQueueCore'), 900), 'CronV3ProducerService'),
    'Queue Core local financial projection must not fan out to Cron V3.');

echo "PASS queue_core_sales_pipeline_b2\n";
