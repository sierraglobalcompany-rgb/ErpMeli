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
$readiness = $read('app/QueueV4Clean/QueueV4CleanReadinessService.php');
$repository = $read('app/QueueV4Clean/QueueV4CleanRepository.php');

$assert(trim($read('VERSION')) === '2.37.0', 'VERSION is not 2.37.0');
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
$assert(!str_contains($readiness, '/orders/search'), 'readiness uses operational search');
$assert(str_contains($repository, "ORDER BY available_at ASC,id ASC"), 'FIFO order is not exact');
$assert(str_contains($worker . $repository, "state='ready'"), 'ready FIFO state missing');
$assert(str_contains($worker, "max(1, min(3, \$maxJobs))"), 'worker max-jobs bound missing');
$assert(str_contains($worker, "'source' => 'queue_v4_clean'"), 'worker source authority missing');
$assert(!str_contains($worker, 'historical'), 'worker contains historical importer');

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
