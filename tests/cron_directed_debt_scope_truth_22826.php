<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\CronBacklogSnapshotService;
use App\Services\CronTaskLaneSelector;

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$selector = new CronTaskLaneSelector();
$selected = $selector->select([
    ['key' => 'notification_spool', 'api' => false, 'lane' => 'local', '_last_finished_at' => '2026-01-01 00:00:00'],
    ['key' => 'orders_sync', 'api' => true, 'lane' => 'urgent', '_last_finished_at' => '2026-01-01 00:00:00'],
    ['key' => 'order_enrichment', 'api' => true, 'lane' => 'normal', '_last_finished_at' => '2026-01-01 00:00:00'],
    ['key' => 'manual_campaign', 'api' => true, 'lane' => 'directed', '_last_finished_at' => '2026-08-01 00:00:00', '_directed_missed_cycles' => 1],
], 1, 1);
$check(array_column($selected, 'key') === ['manual_campaign'], 'Una campaña con deuda debe ganar incluso con maxTasks=1.');

$withoutDebt = $selector->select([
    ['key' => 'notification_spool', 'api' => false, 'lane' => 'local', '_last_finished_at' => '2026-01-01 00:00:00'],
    ['key' => 'manual_campaign', 'api' => true, 'lane' => 'directed', '_last_finished_at' => '2026-08-01 00:00:00', '_directed_missed_cycles' => 0],
], 1, 1);
$check(array_column($withoutDebt, 'key') === ['notification_spool'], 'Sin deuda debe conservarse la rotación persistente normal.');

$check(!CronBacklogSnapshotService::countsTowardBacklog('operational_maintenance'), 'Mantenimiento operativo no es backlog comercial.');
$check(!CronBacklogSnapshotService::countsTowardBacklog('monthly_report_maintenance'), 'Reportes diarios no son backlog comercial.');
$check(CronBacklogSnapshotService::countsTowardBacklog('manual_campaign'), 'La campaña sí debe contar en el backlog.');

$taskState = (string) file_get_contents($root . '/app/Services/CronTaskStateService.php');
$worker = (string) file_get_contents($root . '/app/Services/ResumableCampaignWorkerService.php');
$readModel = (string) file_get_contents($root . '/app/Services/CronOperationalReadService.php');
$access = (string) file_get_contents($root . '/app/Services/CronOperationalAccessScope.php');
$migration = (string) file_get_contents($root . '/database/migrations/206_cron_directed_debt_scope_truth_2_28_26.sql');

$check(str_contains($taskState, 'directed_missed_cycles=LEAST(65535,directed_missed_cycles+1)'), 'El runtime debe persistir deuda cuando la campaña no inicia.');
$check(str_contains($taskState, 'directed_missed_cycles=0'), 'El inicio real debe saldar la deuda dirigida.');
$check(!str_contains($worker, '\$reason = \'window_complete\';'), 'Una campaña nueva no puede cerrar con causa genérica window_complete.');
$check(str_contains($worker, "'required_window_ms'"), 'La trazabilidad debe publicar ventana requerida.');
$check(str_contains($readModel, "'snapshot_state' => \$this->snapshotState"), 'La caché de tareas debe conservar el estado del snapshot.');
$check(str_contains($readModel, 'CronOperationalAccessScope())->assertGlobal()'), 'Los read models globales de Cron deben exigir scope explícito.');
$check(str_contains($access, "empty(\$scope['application'])"), 'El rol admin por sí solo no debe conceder telemetría global.');
$check(str_contains($migration, 'directed_missed_cycles') && str_contains($migration, "'2.28.26'"), 'La migración 206 debe versionar deuda dirigida.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "PASS cron_directed_debt_scope_truth_22826\n");
