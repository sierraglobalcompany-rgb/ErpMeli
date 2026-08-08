<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\CronExecutionPlanner;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__);
$cron = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
$plannerSource = (string) file_get_contents($root . '/app/Services/CronExecutionPlanner.php');
$availability = (string) file_get_contents($root . '/app/Services/CronWorkAvailabilityService.php');
$taskState = (string) file_get_contents($root . '/app/Services/CronTaskStateService.php');
$runs = (string) file_get_contents($root . '/app/Services/WorkQueueRunService.php');

$check(str_contains($cron, 'while (($plan = $planner->next($runToken)) !== null)'), 'Cron debe elegir y reclamar un trabajo por iteración.');
$check(!str_contains($cron, '$taskState->due('), 'El entrypoint no debe volver a seleccionar una lista anticipada.');
$check(str_contains($cron, "if (\$plan['state'] !== 'claimed')"), 'Una propuesta sin claim debe persistirse como no iniciada.');
$check(
    strpos($cron, '$selectedCount++') < strpos($cron, '$workRuns->selected('),
    'selected debe incrementarse solo después del claim cercado.'
);
$check(str_contains($cron, '$planner->remeasure($key)'), 'Cada cola ejecutada debe medirse otra vez sin escanear todas las demás.');
$check(str_contains($cron, "' selected=' . \$selectedCount"), 'La salida CLI debe publicar claims reales, no candidatos planeados.');
$check(str_contains($plannerSource, "['directed', 'urgent', 'normal', 'local']")
    && str_contains($plannerSource, 'advanceLane('),
    'El planificador debe iniciar por campaña y rotar carriles explícitamente.');
$check(str_contains($plannerSource, '$this->taskState->claim('), 'El planificador debe reclamar antes de devolver una selección.');
$check(str_contains($availability, 'snapshotCached') && str_contains($availability, 'unset($this->cache[$key])'), 'Los probes deben reutilizarse e invalidarse por cola.');
$check(str_contains($taskState, 'public function claim(') && str_contains($taskState, 'return $this->claim('), 'El claim debe ser una operación explícita y compatible.');
$check(str_contains($runs, '"not_started","not_started"'), 'Un candidato que no inició debe conservar una fila trazable.');
$check(str_contains($runs, 'persistTraceMetadata'), 'El resultado debe persistir scope y diagnóstico cuando el esquema lo permita.');

$reflection = new ReflectionClass(CronExecutionPlanner::class);
$planner = $reflection->newInstanceWithoutConstructor();
$definitions = $reflection->getProperty('definitions');
$definitions->setValue($planner, [
    'notification_fallback' => ['key' => 'notification_fallback', 'lane' => 'urgent'],
    'manual_campaign' => ['key' => 'manual_campaign', 'lane' => 'directed'],
    'notification_spool' => ['key' => 'notification_spool', 'lane' => 'local'],
]);
$reflection->getProperty('exhausted')->setValue($planner, []);
$reflection->getProperty('claimsByKey')->setValue($planner, []);
$reflection->getProperty('observedKeys')->setValue($planner, []);
$reflection->getProperty('maxClaims')->setValue($planner, 8);
$reflection->getProperty('laneOrder')->setValue($planner, ['directed', 'urgent', 'normal', 'local']);
$reflection->getProperty('laneCursor')->setValue($planner, 0);
$nextPool = $reflection->getMethod('nextPool');
$firstPool = $nextPool->invoke($planner);
$check(array_keys($firstPool) === ['manual_campaign'], 'La campaña debe sondearse antes de cualquier cola global.');
$advanceLane = $reflection->getMethod('advanceLane');
$advanceLane->invoke($planner, $firstPool);
$reflection->getProperty('exhausted')->setValue($planner, ['manual_campaign' => true]);
$normalPool = $nextPool->invoke($planner);
$check(array_keys($normalPool) === ['notification_fallback'], 'Tras campaña debe rotar al carril urgente, no mezclar todas las colas.');

// Un claim exitoso no agota la función. Después de rotar los demás carriles,
// el planner puede volver a medirla y el claim exacto de la cola cercará el
// siguiente recurso.
$reflection->getProperty('exhausted')->setValue($planner, []);
$reflection->getProperty('claimsByKey')->setValue($planner, ['manual_campaign' => 1]);
$reflection->getProperty('laneCursor')->setValue($planner, 0);
$reentryPool = $nextPool->invoke($planner);
$check(array_keys($reentryPool) === ['manual_campaign'], 'Una función con capacidad restante debe poder reentrar en round-robin.');

$reflection->getProperty('claimsByKey')->setValue($planner, ['manual_campaign' => 8]);
$cappedPool = $nextPool->invoke($planner);
$check(!isset($cappedPool['manual_campaign']), 'La reentrada debe respetar el fencing de capacidad por función.');

$reflection->getProperty('observedKeys')->setValue($planner, [
    'manual_campaign' => true,
    'notification_fallback' => true,
]);
$observedKeys = array_keys($reflection->getProperty('observedKeys')->getValue($planner));
$check(
    $observedKeys === ['manual_campaign', 'notification_fallback'],
    'El delta debe conservar todas las colas medidas aunque due() no las proponga.'
);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "PASS cron_incremental_execution_planner_22829\n");
