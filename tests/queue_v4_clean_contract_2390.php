<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$assert(trim($read('VERSION')) === '2.39.0', 'version_authority_invalid');
$migrations = glob($root . '/database/migrations/298_*.sql') ?: [];
$assert(count($migrations) === 1
    && basename($migrations[0]) === '298_queue_v4_sales_repair_transport_authority_2_38_9.sql',
    'migration_298_authority_invalid');
$migration = $read('database/migrations/298_queue_v4_sales_repair_transport_authority_2_38_9.sql');
$assert(str_contains($migration, "ENUM('queue','oauth','sales_audit','sales_repair')")
    && !str_contains($migration, 'CREATE TABLE') && !str_contains($migration, 'ADD COLUMN'),
    'migration_298_scope_expanded');

$database = $read('app/QueueV4Clean/QueueV4CleanDatabaseContract.php');
$readiness = $read('app/QueueV4Clean/QueueV4CleanReadinessService.php');
$assert(str_contains($database, 'queue-v4-canonical-db-contract-2.38.9.json'), 'database_contract_authority_invalid');
$assert(str_contains($readiness, "298_queue_v4_sales_repair_transport_authority_2_38_9.sql")
    && str_contains($readiness, "migration_298_missing"), 'readiness_migration_gate_invalid');
$authority = json_decode(
    $read('resources/release/queue-v4-canonical-db-contract-2.38.9.json'),
    true,
    64,
    JSON_THROW_ON_ERROR,
);
$transportColumns = (array) ($authority['tables']['queue_v4_clean_transport_events']['columns'] ?? []);
$source = array_values(array_filter(
    $transportColumns,
    static fn(array $column): bool => ($column['COLUMN_NAME'] ?? '') === 'source_kind',
));
$assert(count($source) === 1
    && ($source[0]['COLUMN_TYPE'] ?? '') === "enum('queue','oauth','sales_audit','sales_repair')",
    'database_contract_sales_repair_enum_invalid');

$policy = $read('app/Services/MeliTransportSourcePolicy.php');
$repair = $read('app/Services/SalesAuditExactRepairService.php');
$fence = $read('app/QueueV4Clean/QueueV4CleanDispatchFence.php');
$journal = $read('app/QueueV4Clean/QueueV4CleanTransportJournal.php');
$curl = $read('app/Services/CurlMeliHttpTransport.php');
$client = $read('app/Services/MeliApiClient.php');
$assert(str_contains($policy, '|| $source === self::QUEUE_V4_SALES_REPAIR'), 'sales_repair_read_fence_missing');
$assert(str_contains($repair, "'sales_repair_job_id'")
    && str_contains($repair, "'sales_repair_item_id'")
    && str_contains($repair, "'sales_repair_lease_owner'")
    && str_contains($repair, "'sales_repair_lease_generation'"), 'repair_transport_metadata_incomplete');
$assert(substr_count($repair, 'QueueV4CleanCycleBudget::claim()') === 0
    && substr_count($fence, 'QueueV4CleanCycleBudget::claim()') === 1, 'cycle_budget_authority_duplicated');
$assert(str_contains($fence, 'self::startSalesRepair($meta, $method, $path)')
    && str_contains($fence, "source_kind='sales_repair'")
    && str_contains($journal, "'sales_repair'"), 'durable_sales_repair_journal_missing');
$uncertain = strpos($repair, 'catch (RemoteResultUncertainException $error)');
$generic = strpos($repair, 'catch (Throwable $error)', $uncertain ?: 0);
$assert($uncertain !== false && $generic !== false && $uncertain < $generic,
    'remote_uncertain_explicit_handler_missing');
$assert(str_contains($repair, "=== 'NOT_DISPATCHED'")
    && str_contains($repair, "'waiting_budget'")
    && str_contains($repair, "'La consulta fue iniciada"), 'remote_uncertain_state_split_missing');
$assert(substr_count($curl, 'QueueV4CleanDispatchFence::immediatelyBeforeCurl') === 1
    && str_contains($client, 'QueueV4CleanDispatchFence::state($meta)'), 'existing_transport_path_regressed');
$assert(!is_file($root . '/app/QueueV4Clean/SalesRepairDispatchFence.php'), 'parallel_fence_class_created');

echo 'QUEUE_V4_CLEAN_CONTRACT_2390=PASS checks=' . $checks
    . ' schema=298 budget_claims=1 new_tables=0 new_columns=0' . PHP_EOL;


