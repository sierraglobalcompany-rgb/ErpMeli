<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/Services/MeliTransportSourcePolicy.php';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$expectedVersion = trim((string) (getenv('ERP_QUEUE_V4_CONTRACT_VERSION') ?: '2.39.3'));
$assert(trim($read('VERSION')) === $expectedVersion, 'version_authority_invalid');

$migrationFiles = glob($root . '/database/migrations/299_*.sql') ?: [];
$assert(count($migrationFiles) === 1, 'migration299_count_invalid');
$migration = $read('database/migrations/299_queue_v4_domain_exact_admission_2_39_3.sql');
$assert(substr_count($migration, 'ALTER TABLE queue_v4_clean_jobs') === 1, 'migration299_table_scope_invalid');
$assert(str_contains($migration, "ENUM('fresh_orders_discovery','order_exact','domain_exact')"), 'migration299_enum_invalid');
$assert(!str_contains($migration, 'CREATE TABLE') && !str_contains($migration, 'ADD COLUMN') && !str_contains($migration, 'ADD KEY'), 'migration299_scope_expanded');

$database = $read('app/QueueV4Clean/QueueV4CleanDatabaseContract.php');
$assert(str_contains($database, 'queue-v4-canonical-db-contract-2.39.3.json'), 'schema299_contract_not_selected');
$old = json_decode($read('resources/release/queue-v4-canonical-db-contract-2.38.9.json'), true, 64, JSON_THROW_ON_ERROR);
$new = json_decode($read('resources/release/queue-v4-canonical-db-contract-2.39.3.json'), true, 64, JSON_THROW_ON_ERROR);
$oldType = $old['tables']['queue_v4_clean_jobs']['columns'][3]['COLUMN_TYPE'] ?? '';
$newType = $new['tables']['queue_v4_clean_jobs']['columns'][3]['COLUMN_TYPE'] ?? '';
$assert($oldType === "enum('fresh_orders_discovery','order_exact')", 'historical_contract_changed');
$assert($newType === "enum('fresh_orders_discovery','order_exact','domain_exact')", 'schema299_contract_enum_invalid');
$new['tables']['queue_v4_clean_jobs']['columns'][3]['COLUMN_TYPE'] = $oldType;
$assert($new === $old, 'schema299_contract_contains_unrelated_drift');

$admission = $read('app/Services/CronAdmissionService.php');
$worker = $read('app/QueueV4Clean/QueueV4CleanWorker.php');
$scheduler = $read('app/QueueV4Clean/QueueV4CleanScheduler.php');
$repository = $read('app/QueueV4Clean/QueueV4CleanRepository.php');
$policy = $read('app/Services/MeliTransportSourcePolicy.php');
$fence = $read('app/QueueV4Clean/QueueV4CleanDispatchFence.php');
$assert(str_contains($admission, 'final class CronAdmissionService')
    && !str_contains($admission, 'beginTransaction')
    && !str_contains($admission, '->commit(')
    && !str_contains($admission, '->rollBack('), 'admission_transaction_ownership_invalid');
$assert(str_contains($admission, "'financial_recalc'")
    && str_contains($admission, "'financial_reconciliation'")
    && str_contains($admission, "\"domain_exact\""), 'admission_capability_contract_invalid');
$assert(str_contains($admission, "'domain_exact'")
    && str_contains($admission, 'ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)')
    && !str_contains($admission, 'state=IF'), 'domain_duplicate_reactivation_detected');
$assert(!str_contains($worker, 'domainSourceExists(')
    && str_contains($worker, 'domain_source_tenant_mismatch')
    && str_contains($worker, 'domain_source_missing')
    && str_contains($worker, 'WHERE id=? AND company_id=? LIMIT 1'), 'domain_tenant_classification_invalid');
$assert(str_contains($worker, "if (\$type === 'domain_exact')")
    && str_contains($worker, 'handleDomainExact('), 'domain_worker_missing');
$assert(!str_contains($scheduler, 'domain_exact'), 'parallel_domain_scheduler_stage_detected');
$assert(str_contains($repository, 'ORDER BY available_at ASC,id ASC'), 'fifo_changed');
$assert(str_contains($repository, "['fresh_orders_discovery', 'order_exact']"), 'legacy_enqueue_semantics_changed');
$assert(str_contains($policy, 'QUEUE_V4_DOMAIN_EXACT')
    && str_contains($policy, '/billing/integration/group/ML/order/details'), 'domain_transport_policy_missing');
$assert(str_contains($fence, "'billing_orders'")
    && substr_count($fence, 'QueueV4CleanCycleBudget::claim()') === 1, 'domain_transport_budget_not_shared');
$assert(!is_file($root . '/app/QueueV4Clean/QueueV4CleanDomainHandler.php'), 'parallel_domain_handler_class_created');

$allowed = static function (string $source, string $method, string $path): bool {
    try {
        \App\Services\MeliTransportSourcePolicy::assertAllowed($source, $method, $path);
        return true;
    } catch (RuntimeException) {
        return false;
    }
};
$domain = \App\Services\MeliTransportSourcePolicy::QUEUE_V4_DOMAIN_EXACT;
$assert($allowed($domain, 'GET', '/billing/integration/group/ML/order/details'), 'domain_billing_get_denied');
$assert(!$allowed($domain, 'POST', '/billing/integration/group/ML/order/details')
    && !$allowed($domain, 'GET', '/orders/search')
    && !$allowed($domain, 'GET', '/orders/1001')
    && !$allowed($domain, 'GET', '/items/1001'), 'domain_transport_scope_expanded');
$assert(!$allowed('queue_v4_clean', 'GET', '/billing/integration/group/ML/order/details')
    && !$allowed(\App\Services\MeliTransportSourcePolicy::QUEUE_V4_SALES_AUDIT, 'GET', '/billing/integration/group/ML/order/details')
    && !$allowed(\App\Services\MeliTransportSourcePolicy::QUEUE_V4_SALES_REPAIR, 'GET', '/billing/integration/group/ML/order/details'), 'legacy_queue_source_gained_billing');
$assert(\App\Services\MeliTransportSourcePolicy::blocksRedirects($domain), 'domain_redirects_not_blocked');

echo 'QUEUE_V4_CLEAN_CONTRACT_' . str_replace('.', '', $expectedVersion) . '=PASS checks=' . $checks
    . ' schema=299 budget_claims=1 new_tables=0 new_columns=0' . PHP_EOL;
