<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\CronExecutionPlanner;
use App\Services\MeliEndpointRegistry;

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static fn (string $path): string => (string) file_get_contents(
    $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path)
);

$coordination = $read('app/Services/ExecutionCoordinationService.php');
$taskState = $read('app/Services/CronTaskStateService.php');
$cron = $read('jobs/process_sync_queue.php');
$campaign = $read('app/Services/ResumableCampaignWorkerService.php');
$backlog = $read('app/Services/CronBacklogSnapshotService.php');
$producer = $read('app/Services/CronProducerMetricService.php');
$migration = $read('database/migrations/215_cron_capacity_producer_contract_2_28_35.sql');

$check(str_contains($coordination, 'reservedQueueKeysReadOnly'), 'Falta la lectura de reservas sin mutaciones.');
$check(str_contains($coordination, "PHP_SAPI !== 'cli'"), 'La liberación de sesiones no quedó cercada a CLI.');
$check(substr_count($taskState, 'reservedQueueKeysReadOnly(') >= 2, 'due/preview no usan la lectura pura.');
$check(str_contains($cron, 'releaseAbandonedCli()'), 'El lanzador CLI no ejecuta la reconciliación explícita.');
$check(str_contains($cron, 'CronCapacityPlan::build') && str_contains($cron, 'maxClaims()'), 'Cron no consume su plan de capacidad.');

$reflection = new ReflectionClass(CronExecutionPlanner::class);
$planner = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('definitions')->setValue($planner, [
    'campaign' => ['key' => 'campaign', 'lane' => 'directed', 'api' => true],
    'urgent' => ['key' => 'urgent', 'lane' => 'urgent', 'api' => true],
    'normal' => ['key' => 'normal', 'lane' => 'normal', 'api' => true],
    'local' => ['key' => 'local', 'lane' => 'local', 'api' => false],
]);
$reflection->getProperty('exhausted')->setValue($planner, []);
$reflection->getProperty('claimsByKey')->setValue($planner, []);
$reflection->getProperty('maxClaims')->setValue($planner, 8);
$reflection->getProperty('laneOrder')->setValue($planner, ['directed', 'urgent', 'normal', 'local']);
$reflection->getProperty('laneCursor')->setValue($planner, 0);
$nextPool = $reflection->getMethod('nextPool');
$advance = $reflection->getMethod('advanceLane');
$expected = ['campaign', 'urgent', 'normal', 'local'];
foreach ($expected as $key) {
    $pool = $nextPool->invoke($planner);
    $check(array_keys($pool) === [$key], 'Round-robin no eligió el carril esperado: ' . $key);
    $advance->invoke($planner, $pool);
    $exhausted = $reflection->getProperty('exhausted')->getValue($planner);
    $exhausted[$key] = true;
    $reflection->getProperty('exhausted')->setValue($planner, $exhausted);
}

$check(MeliEndpointRegistry::contractKey('GET', '/questions/123') === 'question_exact', 'Pregunta exacta no normaliza a contrato estable.');
$check(MeliEndpointRegistry::contractKey('GET', '/questions/987') === 'question_exact', 'IDs distintos fragmentan el presupuesto de preguntas.');
$check(MeliEndpointRegistry::contractKey('GET', '/orders/billing-info/123/MLA') === 'order_billing_info', 'Billing info no normaliza por contrato.');

$check(str_contains($campaign, 'operationReserveCache') && str_contains($campaign, 'candidate_scan_limit'), 'La campaña conserva N+1 o un escaneo no acotado.');
$check(str_contains($campaign, 'last_scheduler_planned_at') && str_contains($campaign, 'last_scheduler_claimed_at'), 'Campaña no separa planeado, candidato y claim.');
$check(!str_contains($backlog, '$netEntries'), 'Backlog todavía inventa entradas por diferencia algebraica.');
$check(str_contains($producer, 'system_cron_producer_metrics'), 'Falta métrica persistida del productor.');
$check(str_contains($migration, 'system_cron_capacity_plans') && str_contains($migration, 'system_cron_producer_metrics'), 'Migración 215 incompleta.');
$check(str_contains($migration, 'INSERT IGNORE INTO app_settings'), 'Migración 215 sobrescribe defaults del operador.');

if ($failures !== []) {
    fwrite(STDERR, "FAIL cron_capacity_planner_truth_22835\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "PASS cron_capacity_planner_truth_22835\n");
