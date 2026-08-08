<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\ApiBudgetService;
use App\Services\CronHealthService;
use App\Services\CronTaskLaneSelector;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$selector = new CronTaskLaneSelector();

// Backlogs grandes no cambian el contrato: el límite remoto es global y el
// trabajo que lleva más tiempo sin terminar rota durante ciclos sucesivos.
$finished = ['notification_fallback' => null, 'manual_campaign' => null, 'order_enrichment' => null];
$counts = array_fill_keys(array_keys($finished), 0);
for ($cycle = 0; $cycle < 15; $cycle++) {
    $items = [
        ['key' => 'notification_fallback', 'lane' => 'urgent', 'api' => true, 'work_count' => 2500, '_last_finished_at' => $finished['notification_fallback']],
        ['key' => 'manual_campaign', 'lane' => 'directed', 'api' => true, 'work_count' => 649, '_last_finished_at' => $finished['manual_campaign']],
        ['key' => 'order_enrichment', 'lane' => 'normal', 'api' => true, 'work_count' => 1000, '_last_finished_at' => $finished['order_enrichment']],
    ];
    $selected = $selector->select($items, 3, 1);
    $check(count($selected) === 1, 'El cap API global permitió más de una tarea remota.');
    $key = (string) ($selected[0]['key'] ?? '');
    $counts[$key]++;
    $finished[$key] = gmdate('Y-m-d H:i:s', 1785510000 + $cycle);
}
$check(min($counts) >= 4, 'La rotación de 15 ciclos dejó una cola API sin servicio suficiente.');

// Con maxTasks=1 el spool y API deben alternarse según la última finalización;
// el spool no puede monopolizar el único puesto ni quedar abandonado.
$singleA = $selector->select([
    ['key' => 'notification_spool', 'lane' => 'local', 'api' => false, '_last_finished_at' => null],
    ['key' => 'manual_campaign', 'lane' => 'directed', 'api' => true, '_last_finished_at' => null],
], 1, 1);
$check(array_column($singleA, 'key') === ['notification_spool'], 'El primer ciclo de una sola plaza debe admitir el spool nunca atendido.');
$singleB = $selector->select([
    ['key' => 'notification_spool', 'lane' => 'local', 'api' => false, '_last_finished_at' => '2026-07-31 15:00:00'],
    ['key' => 'manual_campaign', 'lane' => 'directed', 'api' => true, '_last_finished_at' => null],
], 1, 1);
$check(array_column($singleB, 'key') === ['manual_campaign'], 'El segundo ciclo debe entregar la plaza a la API nunca atendida.');
$two = $selector->select([
    ['key' => 'notification_spool', 'lane' => 'local', 'api' => false],
    ['key' => 'notification_fallback', 'lane' => 'urgent', 'api' => true],
    ['key' => 'manual_campaign', 'lane' => 'directed', 'api' => true],
], 2, 1);
$check(count($two) === 2 && count(array_filter($two, static fn(array $item): bool => !empty($item['api']))) === 1, 'Límite 2 debe admitir spool y exactamente una API.');

// Configuración reducida real: el spool ocupa una de dos plazas y solo queda
// un turno remoto. Ese turno debe rotar entre urgencia, campaña y normal; la
// prioridad fija no puede dejar dos carriles a la deriva.
$tightFinished = ['notification_fallback' => null, 'manual_campaign' => null, 'order_enrichment' => null];
$tightCounts = array_fill_keys(array_keys($tightFinished), 0);
for ($cycle = 0; $cycle < 10; $cycle++) {
    $tight = $selector->select([
        ['key' => 'notification_spool', 'lane' => 'local', 'api' => false, '_last_finished_at' => gmdate('Y-m-d H:i:s', 1785510000 + $cycle)],
        ['key' => 'notification_fallback', 'lane' => 'urgent', 'api' => true, '_last_finished_at' => $tightFinished['notification_fallback']],
        ['key' => 'manual_campaign', 'lane' => 'directed', 'api' => true, '_last_finished_at' => $tightFinished['manual_campaign']],
        ['key' => 'order_enrichment', 'lane' => 'normal', 'api' => true, '_last_finished_at' => $tightFinished['order_enrichment']],
    ], 2, 2);
    $remote = array_values(array_filter($tight, static fn (array $item): bool => !empty($item['api'])));
    $check(count($remote) === 1, 'La ventana reducida debe entregar exactamente un turno remoto por ciclo.');
    if (isset($remote[0]['key'], $tightCounts[(string) $remote[0]['key']])) {
        $key = (string) $remote[0]['key'];
        $tightCounts[$key]++;
        $tightFinished[$key] = gmdate('Y-m-d H:i:s', 1785511000 + $cycle);
    }
}
$check(min($tightCounts) >= 3, 'Diez ciclos reducidos dejaron un carril remoto sin rotación justa.');

// Una espera real o backlog observado no puede certificarse como cola vacía.
$health = new ReflectionClass(CronHealthService::class);
$isEmpty = $health->getMethod('isEmptySummary');
$healthInstance = $health->newInstanceWithoutConstructor();
$check($isEmpty->invoke($healthInstance, ['end_reason' => 'queue_empty']) === true, 'queue_empty sin trabajo debe permanecer vacío.');
$check($isEmpty->invoke($healthInstance, ['selected' => 1, 'deferred' => 1, 'end_reason' => 'waiting_budget']) === false, 'Una tarea diferida fue clasificada como empty.');
$check($isEmpty->invoke($healthInstance, ['work_availability' => [['known' => true, 'work_count' => 2500]], 'end_reason' => 'deadline_reached']) === false, 'Backlog pendiente fue clasificado como empty.');

// DATETIME de MariaDB representa UTC incluso cuando PHP presenta Bogotá.
$previousTimezone = date_default_timezone_get();
date_default_timezone_set('America/Bogota');
$budget = new ReflectionClass(ApiBudgetService::class);
$utcTimestamp = $budget->getMethod('utcTimestamp');
$budgetInstance = $budget->newInstanceWithoutConstructor();
$check($utcTimestamp->invoke($budgetInstance, '2026-07-31 15:39:56') === 1785512396, 'ApiBudget interpretó cooldown UTC como hora Bogotá.');
date_default_timezone_set($previousTimezone);

$taskState = (string) file_get_contents(dirname(__DIR__) . '/app/Services/CronTaskStateService.php');
$cron = (string) file_get_contents(dirname(__DIR__) . '/jobs/process_sync_queue.php');
$planner = (string) file_get_contents(dirname(__DIR__) . '/app/Services/CronExecutionPlanner.php');
$availability = (string) file_get_contents(dirname(__DIR__) . '/app/Services/CronWorkAvailabilityService.php');
$check(str_contains($taskState, 'bool $allowEarlyDirected = false') && str_contains($taskState, 'OR :allow_early=1'), 'Claim dirigido futuro no está alineado con due().');
$check(str_contains($planner, '$lane === \'directed\''), 'El planificador no autoriza el claim temprano exclusivamente al carril dirigido.');
foreach (['attempted_remote_calls=', 'blocked_remote_calls='] as $field) {
    $check(str_contains($cron, $field), 'La salida Hostinger no incluye ' . $field);
}
$check(!str_contains($availability, "'questions' => fn (): array => \$this->unknown()")
    && !str_contains($availability, "'recurring_sync' => fn (): array => \$this->unknown()"),
    'Preguntas o programación recurrente todavía consumen slots con disponibilidad desconocida.');

// El lock ocupado y un storage roto son condiciones distintas. Un archivo se
// usa como raíz imposible para reproducir el fallo de storage sin permisos ni
// modificar el storage real del ERP.
$brokenRoot = sys_get_temp_dir() . '/erp-cron-broken-' . bin2hex(random_bytes(5));
file_put_contents($brokenRoot, 'not-a-directory');
define('ERP_SHARED_ROOT', $brokenRoot);
require_once dirname(__DIR__) . '/jobs/_cron_entry_state.php';
$brokenLock = cron_entry_early_lock();
$check(($brokenLock['status'] ?? '') === 'storage_unavailable', 'Storage roto fue confundido con lock ocupado.');
@unlink($brokenRoot);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, array_map(static fn(string $failure): string => 'FAIL ' . $failure, $failures)) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "PASS cron_adversarial_22812\n");
