<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$migration = $read('database/migrations/294_queue_v4_clean_greenfield_2_37_0.sql');
$architecture = $read('docs/QUEUE_V4_CLEAN_ARCHITECTURE.md');
$view = $read('app/Views/settings/cron_shell.php');
$routes = $read('public/index.php');
$controller = $read('app/Controllers/SettingsController.php');
$worker = $read('app/QueueV4Clean/QueueV4CleanWorker.php');
$producer = $read('app/QueueV4Clean/QueueV4CleanProducer.php');
$readiness = $read('app/QueueV4Clean/QueueV4CleanReadinessService.php');
$repository = $read('app/QueueV4Clean/QueueV4CleanRepository.php');
$control = $read('app/QueueV4Clean/QueueV4CleanControlService.php');
$databaseContract = $read('app/QueueV4Clean/QueueV4CleanDatabaseContract.php');
$appJs = $read('public/assets/app.js');

$assert(trim($read('VERSION')) === '2.38.0', 'VERSION is not 2.38.0');
$assert(str_contains($architecture, '## REUSE') && str_contains($architecture, '## REPLACE') && str_contains($architecture, '## LEGACY_IGNORE'), 'architecture classification incomplete');
foreach (['control', 'readiness_runs', 'readiness_accounts', 'jobs', 'attempts', 'runs', 'leases', 'checkpoints'] as $table) {
    $assert(str_contains($migration, 'queue_v4_clean_' . $table), 'new table missing: ' . $table);
}
$assert(!str_contains($migration, 'INSERT INTO queue_core_') && !str_contains($migration, 'UPDATE queue_core_'), 'migration imports or mutates Queue Core');
$assert(!str_contains($migration, 'INSERT INTO cron_v3_work') && !str_contains($migration, 'UPDATE cron_v3_work'), 'migration imports or mutates V3 work');
$assert(str_contains($migration, "('cron_v3.enabled','0'") && str_contains($migration, "('cron_v3.shadow_enabled','0'"), 'legacy disable authority absent');

$moduleFiles = glob($root . '/app/QueueV4Clean/*.php') ?: [];
$assert(count($moduleFiles) >= 7, 'Queue V4 Clean module incomplete');
foreach ($moduleFiles as $file) {
    $source = (string) file_get_contents($file);
    $assert(!preg_match('/\b(queue_core_jobs|queue_core_attempts|queue_core_dispatch_journal|queue_engine_control|cron_v3_work_items)\b/i', $source), 'new module reads legacy operational state: ' . basename($file));
    $assert(!str_contains(strtolower($source), 'storage/raw'), 'new module touches raw storage: ' . basename($file));
}

foreach (['NOT_READY', 'READY_TO_TEST', 'TESTING', 'CERTIFIED', 'FAILED'] as $state) {
    $assert(str_contains($readiness . $migration, $state), 'readiness state missing: ' . $state);
}
foreach (['partial_arm', 'recovery_required', 'uncertain_recovery_required', 'ready_for_context', 'readiness_context_hash'] as $legacyState) {
    $assert(!str_contains($readiness, $legacyState), 'legacy readiness state leaked: ' . $legacyState);
}
$assert(str_contains($readiness, "get('/users/me')"), 'readiness direct identity GET missing');
$assert(str_contains($readiness, "'queue_jobs_created' => 0"), 'readiness zero-job contract missing');
$assert(str_contains($readiness, "GET_LOCK('erp_meli_queue_v4_clean_readiness',0)"), 'readiness concurrency lock missing');
$assert(str_contains($readiness, 'schema_migrations WHERE version=?'), 'canonical migration column is not used');
$assert(!str_contains($readiness, 'WHERE migration='), 'obsolete migration column leaked');
$assert(str_contains($readiness, 'QueueV4CleanDatabaseContract'), 'physical database contract gate missing');
$assert(str_contains($readiness, "'295_inventory_warehouse_v1_2_38_0.sql'"), 'migration 295 readiness gate missing');
$assert(str_contains($databaseContract, 'database_version_family') && str_contains($databaseContract, 'information_schema.COLUMNS'), 'database contract is incomplete');
$assert(substr_count($databaseContract, '$this->metadata(') === 4, 'database contract metadata queries are not exactly four');
$assert(!str_contains($databaseContract, 'BINARY TABLE_SCHEMA') && !str_contains($databaseContract, 'BINARY TABLE_NAME'), 'database contract retained unindexed BINARY filters');
$assert(str_contains($databaseContract, 'TABLE_NAME IN ('), 'database contract is not batched by exact table set');
$assert(str_contains($databaseContract, 'hash_equals($database, $schema)'), 'database contract does not enforce exact schema identity in PHP');
$assert(str_contains($databaseContract, 'queue-v4-canonical-db-contract-2.38.0.json'), '2.38.0 database contract authority missing');
$databaseAuthority = json_decode($read('resources/release/queue-v4-canonical-db-contract-2.38.0.json'), true, 64, JSON_THROW_ON_ERROR);
$assert(count((array) ($databaseAuthority['tables'] ?? [])) === 16, 'database contract table count invalid');
foreach (['inventory_warehouses', 'inventory_balances', 'inventory_movements', 'inventory_reviews'] as $table) {
    $assert(isset($databaseAuthority['tables'][$table]), 'inventory table missing from database contract: ' . $table);
}
$assert(str_contains($appJs, 'El backend Queue V4 agotó el tiempo de respuesta.'), 'Queue V4 timeout feedback missing');
$assert(str_contains($appJs, "includes('application/json')"), 'Queue V4 content-type gate missing');
$assert(str_contains($readiness, "failure_class='interrupted'"), 'interrupted readiness restart missing');
$assert(!str_contains($readiness, '/orders/search'), 'readiness uses operational search');
$assert(str_contains($repository, "ORDER BY available_at ASC,id ASC"), 'FIFO order is not exact');
$assert(str_contains($worker . $repository, "state='ready'"), 'ready FIFO state missing');
$assert(str_contains($worker, "max(1, min(3, \$maxJobs))"), 'worker max-jobs bound missing');
$assert(str_contains($worker, "'source' => 'queue_v4_clean'"), 'worker source authority missing');
$assert(!str_contains($worker, 'historical'), 'worker contains historical importer');
$assert(str_contains($producer, 'queue_v4_clean_readiness_accounts'), 'producer is not bound to certified accounts');
$assert(str_contains($producer, 'queue_v4_clean_certified_account_set_invalid'), 'producer exact certified account cardinality gate missing');
$assert(str_contains($producer, "producer_key='inventory_order_refresh'"), 'inventory lifecycle refresh checkpoint missing');
$assert(str_contains($producer, 'INTERVAL 15 MINUTE'), 'inventory lifecycle refresh is not bounded');
$assert(str_contains($producer, 'reversal.reversal_of_movement_id=issue.id'), 'inventory lifecycle refresh does not stop after reversal');
$assert(str_contains($producer, 'inventory_pending_floor') && str_contains($producer, 'inventory_pending_cursor'), 'inventory crash-gap floor/cursor authority missing');
$assert(str_contains($producer, 'ensurePendingProjectionAuthority($accounts)'), 'active-upgrade pending floor bootstrap missing');
$assert(str_contains($producer, 'inventory_refresh_turn'), 'inventory refresh source alternation missing');
$assert(str_contains($repository, 'state IN ("completed","review")'), 'inventory review job cannot be revived safely');
$assert(str_contains($control, 'initializePendingAuthority') && str_contains($control, 'inventory_pending_floor'), 'activation does not capture inventory cutover floor');
$assert(strpos($control, 'initializePendingAuthority') < strpos($control, "engine_state='ACTIVE'"), 'inventory floor is not captured before activation');
$assert(str_contains($control, "engine_state='ACTIVE',scheduler_enabled=1"), 'activation does not enable internal scheduler atomically');
$assert(str_contains($control, 'activationIssues()'), 'activation does not revalidate current readiness authority');
$assert(str_contains($appJs, "(snapshot?.issues || []).length === 0"), 'activation UI ignores current readiness issues');

foreach (['Comprobar y certificar', 'Activar', 'Detener'] as $button) {
    $assert(substr_count($view, '>' . $button . '</button>') === 1, 'UI action missing/duplicated: ' . $button);
}
$assert(!str_contains($view, 'generation') && !str_contains($view, 'context hash') && !str_contains($view, 'recovery authority'), 'legacy complexity visible in UI');
$assert(str_contains($view, 'Queue V4') && str_contains($view, 'Legado no consultado'), 'new UI identity missing');
foreach (['queue-v4.json', 'queue-v4/readiness', 'queue-v4/activate', 'queue-v4/stop'] as $route) {
    $assert(str_contains($routes, $route), 'route missing: ' . $route);
}
$assert(str_contains($controller, 'AdministrativeReauthenticationService') && str_contains($controller, 'assertSameOrigin') && str_contains($controller, 'Csrf::validate'), 'administrative mutation protections missing');
$assert(str_contains($controller, 'assertLegacyCronMutationDisabled'), 'legacy admin mutation gate missing');

$forbidden = ['POST /', 'PUT /', 'DELETE /'];
foreach ($forbidden as $needle) {
    $assert(!str_contains($architecture, $needle), 'remote mutation documented: ' . $needle);
}
$assert(str_contains($architecture, '`ML_WRITE_ENABLED=false`'), 'write guard architecture missing');

echo json_encode([
    'ok' => true,
    'checks' => $checks,
    'greenfield_v4' => true,
    'legacy_runtime_used' => false,
    'readiness_states' => ['NOT_READY', 'READY_TO_TEST', 'TESTING', 'CERTIFIED', 'FAILED'],
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
