<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\CronBacklogSnapshotService;
use App\Services\CronTaskLaneSelector;
use App\Services\CronWorkAvailabilityService;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$check(CronBacklogSnapshotService::trend(100, 102) === 'growing', '100-10+12=102 debe mostrarse creciendo, no drenando.');
$check(CronBacklogSnapshotService::trend(100, 90) === 'draining', 'Una reducción real debe mostrarse drenando.');
$check(CronBacklogSnapshotService::trend(100, 100) === 'stable', 'Un backlog inmóvil debe mostrarse estable.');

$selector = new CronTaskLaneSelector();
$selected = $selector->select([
    ['key' => 'notification_spool', 'lane' => 'local', 'api' => false],
    ['key' => 'notification_fallback', 'lane' => 'urgent', 'api' => true],
    ['key' => 'manual_campaign', 'lane' => 'directed', 'api' => true],
], 3, 1);
$check(array_column($selected, 'key') === ['manual_campaign', 'notification_spool'], 'El spool no debe consumir el turno dirigido.');

$finished = ['notification_fallback' => null, 'manual_campaign' => null, 'order_enrichment' => null];
$counts = array_fill_keys(array_keys($finished), 0);
for ($cycle = 0; $cycle < 12; $cycle++) {
    $round = $selector->select([
        ['key' => 'notification_fallback', 'lane' => 'urgent', 'api' => true, '_last_finished_at' => $finished['notification_fallback']],
        ['key' => 'manual_campaign', 'lane' => 'directed', 'api' => true, '_last_finished_at' => $finished['manual_campaign']],
        ['key' => 'order_enrichment', 'lane' => 'normal', 'api' => true, '_last_finished_at' => $finished['order_enrichment']],
    ], 1, 1);
    $key = (string) ($round[0]['key'] ?? '');
    if (isset($counts[$key])) {
        $counts[$key]++;
        $finished[$key] = gmdate('Y-m-d H:i:s', 1785510000 + $cycle);
    }
}
$check(min($counts) >= 3, 'La reserva dirigida no debe provocar starvation de otros carriles cuando solo hay una plaza.');

$availability = new ReflectionClass(CronWorkAvailabilityService::class);
$normalize = $availability->getMethod('normalize');
$normalizedUnknown = $normalize->invoke($availability->newInstanceWithoutConstructor(), [
    'known' => false,
    'work_count' => 0,
]);
$check(($normalizedUnknown['measurement_state'] ?? '') === 'unavailable', 'Una lectura fallida no debe convertirse en vacío autoritativo.');
$normalizedPartial = $normalize->invoke($availability->newInstanceWithoutConstructor(), [
    'known' => true,
    'measurement_state' => 'partial',
    'work_count' => 20,
    'eligible_count' => 20,
    'total_pending' => 20,
]);
$check(($normalizedPartial['measurement_state'] ?? '') === 'partial', 'Una medición solo elegible debe conservar cobertura parcial.');
$known = $availability->getMethod('known');
$measuredTotal = $known->invoke($availability->newInstanceWithoutConstructor(), 20, 100, null, 'complete');
$check(
    ($measuredTotal['total_pending'] ?? 0) === 100
    && ($measuredTotal['eligible_count'] ?? 0) === 20
    && ($measuredTotal['waiting_schedule'] ?? 0) === 80,
    'Una cola con 100 totales y 20 elegibles debe conservar 80 esperando programación.'
);
$measuredStates = $known->invoke($availability->newInstanceWithoutConstructor(), 20, 100, null, 'complete', 5, 7);
$check(
    ($measuredStates['waiting_schedule'] ?? 0) === 68
    && ($measuredStates['running_count'] ?? 0) === 5
    && ($measuredStates['attention_count'] ?? 0) === 7,
    'Programados, en ejecución y con intervención deben ser categorías separadas.'
);

$root = dirname(__DIR__);
$taskState = (string) file_get_contents($root . '/app/Services/CronTaskStateService.php');
$runs = (string) file_get_contents($root . '/app/Services/WorkQueueRunService.php');
$cron = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
$overview = (string) file_get_contents($root . '/app/Services/CronOperationalReadService.php');
$health = (string) file_get_contents($root . '/app/Services/ApiHealthOverviewService.php');
$migration = (string) file_get_contents($root . '/database/migrations/201_cron_measured_backlog_fair_scheduler_2_28_21.sql');

$check(str_contains($taskState, 'state.measurement_state=observed.measurement_state'), 'Cron debe conservar la cobertura real de cada medición.');
$check(str_contains($taskState, "if (empty(\$candidate['known']))"), 'Una cola no medida no debe ejecutarse como si estuviera vacía o lista.');
$check(!str_contains($runs, 'backlog_before-?'), 'backlog_after no debe inferirse restando completados.');
$check(str_contains($cron, '$planner->remeasure($key)'), 'El backlog posterior debe medirse de nuevo por la misma autoridad del ciclo.');
$check(str_contains($runs, 'attempted_remote_calls=?') && str_contains($runs, 'blocked_remote_calls=?'), 'Cada tarea debe persistir intentos y bloqueos remotos.');
$check(str_contains($cron, '$candidateCount = $planner->candidateCount()') && str_contains($cron, '$selectedCount++'), 'Candidatos y selecciones reales deben tener contadores separados.');
$check(str_contains($overview, 'backlog_delta') && str_contains($overview, "'growing'"), 'La UI debe publicar delta y crecimiento real.');
$check(str_contains($health, '$service->incidentOverview(') && !str_contains($health, '$service->incidentStatusCounts('), 'Salud API debe agregar incidentes en una sola lectura agrupada.');
$availabilitySource = (string) file_get_contents($root . '/app/Services/CronWorkAvailabilityService.php');
$check(str_contains($availabilitySource, 'FROM manual_campaign_items i'), 'La campaña debe medirse por trabajos, no como un único contenedor.');
$check(str_contains($availabilitySource, '"pending","waiting","retry","running","failed"'), 'El total de campaña debe incluir todos los trabajos todavía abiertos o con revisión.');
foreach (['candidate_count', 'measurement_state', 'coverage', 'running_count', 'attention_count', 'attempted_remote_calls', 'system_cron_backlog_snapshots'] as $needle) {
    $check(str_contains($migration, $needle), 'La migración 201 debe incluir ' . $needle . '.');
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "cron_measured_backlog_fair_scheduler_22821: OK\n");
