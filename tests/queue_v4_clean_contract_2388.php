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

$assert(trim($read('VERSION')) === '2.38.8', 'version_authority_invalid');
$assert(glob($root . '/database/migrations/298_*.sql') === [], 'migration_298_present');
$database = $read('app/QueueV4Clean/QueueV4CleanDatabaseContract.php');
$assert(str_contains($database, 'queue-v4-canonical-db-contract-2.38.8.json'),
    'database_contract_authority_invalid');
$authority = json_decode(
    $read('resources/release/queue-v4-canonical-db-contract-2.38.8.json'),
    true,
    64,
    JSON_THROW_ON_ERROR,
);
$assert(isset($authority['tables']['schema_migrations']), 'schema_migrations_authority_missing');
foreach (['queue_v4_clean_jobs', 'queue_v4_clean_attempts', 'oauth_refresh_operations',
    'sync_sales_audit_runs', 'sync_sales_audit_jobs', 'queue_v4_clean_transport_events'] as $table) {
    $assert(isset($authority['tables'][$table]), 'database_authority_table_missing:' . $table);
}

$scheduler = $read('app/QueueV4Clean/QueueV4CleanScheduler.php');
$repair = $read('app/Services/SalesAuditExactRepairService.php');
$transport = $read('app/Services/MeliTransportSourcePolicy.php');
$ownership = $read('app/QueueCore/QueueCoreOwnershipGuard.php');
$assert(str_contains($scheduler, 'QueueV4CleanCycleBudget::start(min(10, max(1, $maxJobs)))'),
    'shared_http_budget_missing');
$assert(str_contains($scheduler, 'SalesAuditExactRepairService())->processDue(1)')
    && str_contains($scheduler, '- $repairClaimed'), 'exact_repair_scheduler_budget_missing');
$repairStage = strpos($scheduler, '(new SalesAuditExactRepairService())');
$assert(strpos($scheduler, 'QueueV4CleanSalesAuditStage') < $repairStage
    && $repairStage < strpos($scheduler, 'new QueueV4CleanProducer'),
    'exact_repair_stage_order_invalid');
$assert(str_contains($repair, 'j.source_kind="exact"')
    && str_contains($repair, 'syncOrderByIdForQueueV4Clean')
    && !str_contains($scheduler, 'SalesRepairService'), 'legacy_repair_authority_reintroduced');
$assert(str_contains($repair, 'catch (OAuthRefreshRequiredException $error)')
    && str_contains($repair, 'GREATEST(i.attempts-?,0)'), 'oauth_nonfailure_deferral_missing');
$assert(str_contains($transport, 'QUEUE_V4_SALES_REPAIR')
    && str_contains($transport, "preg_match('#^/orders/[0-9]+$#D'"), 'repair_transport_capability_missing');
$assert(str_contains($ownership, 'MeliTransportSourcePolicy::QUEUE_V4_SALES_REPAIR'),
    'queue_v4_repair_ownership_missing');

$private = $read('app/Core/PrivatePathAuthority.php');
$escrow = $read('app/Services/QueueOAuthDurableRecoveryStore.php');
$assert(str_contains($private, 'assertNoLexicalSymlink') && str_contains($private, 'assertDirectoryIdentity'),
    'private_path_2386_regressed');
$assert(str_contains($escrow, 'assertRegularFileIdentity') && str_contains($escrow, 'new PrivatePathAuthority()'),
    'durable_escrow_2386_regressed');

echo 'QUEUE_V4_CLEAN_CONTRACT_2388=PASS checks=' . $checks
    . ' schema=297 migration298=absent exact_repair_limit=1 legacy=0' . PHP_EOL;
