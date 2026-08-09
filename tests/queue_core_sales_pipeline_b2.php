<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/bootstrap.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
});

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
    'queue_core_capability_edges','queue_core_capability_dependencies','queue_core_pending_capabilities',
    'queue_core_dispatch_journal','queue_core_attempts','queue_core_events','queue_core_jobs',
    'queue_core_producer_checkpoints','queue_core_scheduler_state','queue_core_execution_leases',
    'queue_engine_control','meli_pack_orders','meli_packs','meli_orders','meli_accounts',
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
    UNIQUE KEY uq_b21_order (meli_account_id,external_order_id)
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE meli_packs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL,
    external_pack_id VARCHAR(80) NOT NULL,
    external_shipment_id VARCHAR(80) NULL,
    raw_json LONGTEXT NULL,
    synced_at DATETIME NULL,
    UNIQUE KEY uq_b21_pack (meli_account_id,external_pack_id)
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE meli_pack_orders (
    meli_pack_id BIGINT UNSIGNED NOT NULL,
    meli_order_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY(meli_pack_id,meli_order_id)
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
    '290_queue_core_sales_dependency_graph_b2_1.sql',
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
$claim = static function (array $row): QueueClaim {
    return new QueueClaim(
        (int) $row['id'], (int) $row['company_id'], (int) $row['meli_account_id'],
        (string) $row['work_type'], (string) $row['resource_type'],
        $row['resource_id'] !== null ? (string) $row['resource_id'] : null,
        (string) $row['lane'], (int) $row['priority'], 'running', 1,
        (int) $row['max_attempts'], 'fixture', 1, 'DISPATCHED_RESULT_KNOWN',
        json_decode((string) $row['payload_json'], true, 32, JSON_THROW_ON_ERROR),
        (string) $row['source'], $row['source_ref'] !== null ? (string) $row['source_ref'] : null
    );
};
$job = static function (PDO $pdo, string $type, string $resourceId): array {
    $statement = $pdo->prepare(
        'SELECT * FROM queue_core_jobs WHERE work_type=? AND resource_id=? ORDER BY id DESC LIMIT 1'
    );
    $statement->execute([$type, $resourceId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Expected Queue Core job was not materialized: ' . $type . '/' . $resourceId);
    }
    return $row;
};
$terminal = static function (PDO $pdo, int $jobId): void {
    $pdo->prepare(
        "UPDATE queue_core_jobs SET state='completed',completed_at=UTC_TIMESTAMP(3),
             lease_owner=NULL,lease_expires_at=NULL WHERE id=?"
    )->execute([$jobId]);
};
$insertCapability = $pdo->prepare(
    "INSERT INTO queue_core_pending_capabilities
      (company_id,meli_account_id,resource_type,resource_id,capability_key,state,input_version)
     VALUES (?,?,?,?,?,'pending_b2',?)"
);

// Financial work waits until the required pack/shipment graph is complete.
$pdo->exec("INSERT INTO meli_orders
    (id,meli_account_id,external_order_id,external_pack_id,external_shipping_id,synced_at,queue_snapshot_version,queue_snapshot_at)
    VALUES (101,1,'70001','80001','90001',UTC_TIMESTAMP(),'snapshot-v1',UTC_TIMESTAMP(3))");
$insertCapability->execute([10,1,'order','101','financial_projection','snapshot-v1']);
$financialCapability = (int) $pdo->lastInsertId();
$insertCapability->execute([10,1,'order','101','order_enrichment','snapshot-v1']);
$enrichmentCapability = (int) $pdo->lastInsertId();

$queue = new QueueCoreRepository($pdo);
$pipeline = new SalePipelineCapabilityRepository($pdo, $queue);
$summary = $pipeline->materializePending(10, 101);
$assert($summary['waiting_dependency'] === 1 && $summary['materialized'] === 1,
    'Financial work did not wait for required logistics.');
$assert((int) $pdo->query("SELECT COUNT(*) FROM queue_core_jobs")->fetchColumn() === 2,
    'Only exact pack/shipment children should exist before logistics completes.');
$assert((int) $pdo->query("SELECT COUNT(*) FROM queue_core_capability_edges")->fetchColumn() === 1,
    'The logistics -> financial edge was not persisted atomically.');
$assert((string) $pdo->query("SELECT state FROM queue_core_pending_capabilities WHERE id=$financialCapability")->fetchColumn() === 'waiting_dependency',
    'Financial capability was executable before logistics.');

$packRow = $job($pdo, 'pack_exact', '80001');
$shipmentRow = $job($pdo, 'shipment_exact', '90001');
$packClaim = $claim($packRow);
$shipmentClaim = $claim($shipmentRow);
$assert($pipeline->completeDependency($packClaim, $enrichmentCapability),
    'Pack dependency did not close.');
$terminal($pdo, (int) $packRow['id']);
$assert($pipeline->completeDependency($shipmentClaim, $enrichmentCapability),
    'Shipment dependency did not close.');
$terminal($pdo, (int) $shipmentRow['id']);
$assert((string) $pdo->query("SELECT state FROM queue_core_pending_capabilities WHERE id=$enrichmentCapability")->fetchColumn() === 'resolved',
    'Logistics capability did not resolve.');
$assert((string) $pdo->query("SELECT state FROM queue_core_pending_capabilities WHERE id=$financialCapability")->fetchColumn() === 'pending_b2',
    'Financial capability was not woken by completed logistics.');
$assert($pipeline->materializePending(2, 101)['materialized'] === 1,
    'Financial work was not materialized after logistics.');
$financialRow = $job($pdo, 'financial_projection', '101');
$assert($pipeline->completeDependency($claim($financialRow), $financialCapability),
    'Financial dependency did not complete.');
$terminal($pdo, (int) $financialRow['id']);

// A pack webhook always produces exact shipment work. Repeating the same
// observation is idempotent; changing logistics reopens one durable version.
$pdo->exec("INSERT INTO meli_orders
    (id,meli_account_id,external_order_id,external_pack_id,external_shipping_id,synced_at,queue_snapshot_version,queue_snapshot_at)
    VALUES (102,1,'70002','80002',NULL,UTC_TIMESTAMP(),'order-102-v1',UTC_TIMESTAMP(3))");
$pdo->exec("INSERT INTO meli_packs(id,meli_account_id,external_pack_id,external_shipment_id,raw_json,synced_at)
    VALUES (202,1,'80002','90002','{\"id\":80002,\"shipment\":{\"id\":90002}}',UTC_TIMESTAMP())");
$pdo->exec("INSERT INTO meli_pack_orders VALUES(202,102)");
$webhookJobV1 = $pipeline->materializeWebhookPackShipment(10,1,'80002','90002','pack-v1');
$generationsV1 = $pdo->query("SELECT capability_key,lifecycle_generation,input_version
    FROM queue_core_pending_capabilities WHERE resource_id='102' ORDER BY capability_key")
    ->fetchAll(PDO::FETCH_ASSOC);
$jobsAfterV1 = (int) $pdo->query("SELECT COUNT(*) FROM queue_core_jobs")->fetchColumn();
$duplicateV1 = $pipeline->materializeWebhookPackShipment(10,1,'80002','90002','pack-v1');
$generationsRepeat = $pdo->query("SELECT capability_key,lifecycle_generation,input_version
    FROM queue_core_pending_capabilities WHERE resource_id='102' ORDER BY capability_key")
    ->fetchAll(PDO::FETCH_ASSOC);
$assert($webhookJobV1 === $duplicateV1 && $generationsV1 === $generationsRepeat
    && (int) $pdo->query("SELECT COUNT(*) FROM queue_core_jobs")->fetchColumn() === $jobsAfterV1,
    'Repeated pack webhook duplicated shipment/revenue lifecycle.');

$shipmentV1 = $job($pdo, 'shipment_exact', '90002');
$enrichment102 = (int) $pdo->query("SELECT id FROM queue_core_pending_capabilities
    WHERE resource_id='102' AND capability_key='order_enrichment'")->fetchColumn();
$financial102 = (int) $pdo->query("SELECT id FROM queue_core_pending_capabilities
    WHERE resource_id='102' AND capability_key='financial_projection'")->fetchColumn();
$assert($pipeline->completeDependency($claim($shipmentV1), $enrichment102),
    'Webhook shipment dependency did not complete.');
$terminal($pdo, (int) $shipmentV1['id']);
$assert($pipeline->materializePending(2, 102)['materialized'] === 1,
    'Webhook logistics did not release financial projection.');
$financialV1 = $job($pdo, 'financial_projection', '102');
$terminal($pdo, (int) $financialV1['id']);
$assert($pipeline->completeDependency($claim($financialV1), $financial102),
    'First financial webhook version did not resolve.');

$webhookJobV2 = $pipeline->materializeWebhookPackShipment(10,1,'80002','90002','pack-v2');
$generationsV2 = $pdo->query("SELECT capability_key,lifecycle_generation,input_version
    FROM queue_core_pending_capabilities WHERE resource_id='102' ORDER BY capability_key")
    ->fetchAll(PDO::FETCH_ASSOC);
$jobsBeforeRepeatV2 = (int) $pdo->query("SELECT COUNT(*) FROM queue_core_jobs")->fetchColumn();
$assert($pipeline->materializeWebhookPackShipment(10,1,'80002','90002','pack-v2') === $webhookJobV2
    && $generationsV2 === $pdo->query("SELECT capability_key,lifecycle_generation,input_version
       FROM queue_core_pending_capabilities WHERE resource_id='102' ORDER BY capability_key")
       ->fetchAll(PDO::FETCH_ASSOC)
    && (int) $pdo->query("SELECT COUNT(*) FROM queue_core_jobs")->fetchColumn() === $jobsBeforeRepeatV2,
    'One logistics input version reopened more than once.');
$shipmentV2 = $job($pdo, 'shipment_exact', '90002');
$assert((int) $shipmentV2['id'] === $webhookJobV2,
    'Changed logistics did not create its own exact shipment input version.');
$assert($pipeline->completeAllDependencies($claim($shipmentV2)),
    'Changed logistics dependency did not complete.');
$terminal($pdo, (int) $shipmentV2['id']);
$assert($pipeline->materializePending(2, 102)['materialized'] === 1,
    'Changed logistics did not reopen financial work.');
$assert((int) $pdo->query("SELECT COUNT(*) FROM queue_core_jobs
    WHERE work_type='financial_projection' AND resource_id='102'")->fetchColumn() === 2,
    'Financial projection was not exactly once per logistics input version.');

// A corrupt reverse edge is terminal review evidence, never a requeue loop.
$pdo->exec("INSERT INTO meli_orders
    (id,meli_account_id,external_order_id,external_pack_id,external_shipping_id,synced_at,queue_snapshot_version,queue_snapshot_at)
    VALUES (103,1,'70003','80003','90003',UTC_TIMESTAMP(),'snapshot-cycle',UTC_TIMESTAMP(3))");
$insertCapability->execute([10,1,'order','103','financial_projection','snapshot-cycle']);
$cycleFinancial = (int) $pdo->lastInsertId();
$insertCapability->execute([10,1,'order','103','order_enrichment','snapshot-cycle']);
$cycleLogistics = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO queue_core_capability_edges
    (company_id,meli_account_id,prerequisite_capability_id,prerequisite_generation,
     dependent_capability_id,dependent_generation,edge_key,input_version,state)
    VALUES(10,1,?,0,?,0,'corrupt_reverse','cycle','pending')")
    ->execute([$cycleFinancial,$cycleLogistics]);
$cycle = $pipeline->materializePending(1, 103);
$assert($cycle['review'] === 1
    && (string) $pdo->query("SELECT state FROM queue_core_pending_capabilities WHERE id=$cycleFinancial")->fetchColumn() === 'review'
    && (int) $pdo->query("SELECT COUNT(*) FROM queue_core_jobs WHERE resource_id='103'")->fetchColumn() === 0,
    'Dependency cycle did not stop in review.');

// Failure before the transactional graph commit leaves no cross-scope rows.
$beforeJobs = (int) $pdo->query('SELECT COUNT(*) FROM queue_core_jobs')->fetchColumn();
$beforeCapabilities = (int) $pdo->query('SELECT COUNT(*) FROM queue_core_pending_capabilities')->fetchColumn();
$blocked = false;
try {
    $pipeline->materializeWebhookPackShipment(20,2,'80002','90002','foreign');
} catch (Throwable) {
    $blocked = true;
}
$assert($blocked
    && (int) $pdo->query('SELECT COUNT(*) FROM queue_core_jobs')->fetchColumn() === $beforeJobs
    && (int) $pdo->query('SELECT COUNT(*) FROM queue_core_pending_capabilities')->fetchColumn() === $beforeCapabilities,
    'Cross-scope/crash path exposed a partial graph.');
$assert((int) $pdo->query('SELECT COUNT(*) FROM meli_orders')->fetchColumn() === 3,
    'Pack processing duplicated a sale/order row.');

$gateway = file_get_contents($root . '/app/QueueCore/MeliWebhookExactGateway.php');
$assert(is_string($gateway)
    && str_contains($gateway, 'materializeWebhookPackShipment')
    && strpos($gateway, 'materializeWebhookPackShipment') > strpos($gateway, "'pack' => \$sync->syncPackByIdForQueueCore"),
    'Pack webhook is not wired to exact shipment publication.');

echo "PASS queue_core_sales_pipeline_b2\n";
