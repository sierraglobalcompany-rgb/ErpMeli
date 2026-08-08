<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\CronTaskLaneSelector;
use App\Services\HttpRetryAfterParser;
use App\Services\NotificationCoalescerService;
use App\Services\NotificationWorkItemService;
use App\Services\SystemDatabaseUtcClock;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__);
$clock = new SystemDatabaseUtcClock();
$check(
    $clock->timestamp('2026-07-31 15:39:56.000') === 1785512396,
    'Una fecha de MariaDB debe interpretarse como UTC, no como hora Bogotá.'
);
$check(
    $clock->toBogota('2026-07-31 15:39:56.000') === '31/07/2026 10:39:56',
    'La presentación debe convertir UTC a America/Bogota exactamente una vez.'
);
$previousTimezone = date_default_timezone_get();
date_default_timezone_set('America/Bogota');
$check(
    $clock->timestamp('2026-07-31 15:39:56.000') === 1785512396,
    'La zona horaria de PHP no debe cambiar la elegibilidad de un DATETIME UTC.'
);
date_default_timezone_set($previousTimezone);

$deadlineChecks = [
    [NotificationCoalescerService::class, 0.5, false],
    [NotificationCoalescerService::class, 4.0, true],
    [NotificationWorkItemService::class, 0.5, false],
    [NotificationWorkItemService::class, 4.0, true],
];
foreach ($deadlineChecks as [$class, $seconds, $expected]) {
    $reflection = new ReflectionClass($class);
    $instance = $reflection->newInstanceWithoutConstructor();
    $method = $reflection->getMethod('canStartResource');
    $actual = $method->invoke($instance, microtime(true) + $seconds);
    $check($actual === $expected, $class . ' debe respetar la reserva segura del deadline del orquestador.');
}

$selected = (new CronTaskLaneSelector())->select([
    ['key' => 'notification_fallback', 'lane' => 'urgent', 'api' => true, 'work_count' => 1000, '_last_finished_at' => '2026-07-31 15:00:00'],
    ['key' => 'manual_campaign', 'lane' => 'directed', 'api' => true, 'work_count' => 649, '_last_finished_at' => null],
    ['key' => 'order_enrichment', 'lane' => 'normal', 'api' => true, 'work_count' => 628, '_last_finished_at' => '2026-07-31 14:00:00'],
], 1, 1);
$keys = array_column($selected, 'key');
$check(
    $keys === ['manual_campaign'],
    'El límite API global debe seleccionar una sola tarea remota y priorizar la que nunca terminó.'
);

$withSpool = (new CronTaskLaneSelector())->select([
    ['key' => 'notification_spool', 'lane' => 'local', 'api' => false, 'work_count' => 6401],
    ['key' => 'notification_fallback', 'lane' => 'urgent', 'api' => true, 'work_count' => 1520],
    ['key' => 'manual_campaign', 'lane' => 'directed', 'api' => true, 'work_count' => 649],
], 3, 1);
$check(
    array_column($withSpool, 'key') === ['manual_campaign', 'notification_spool'],
    'La campaña obtiene su reserva antes del spool y el límite remoto global sigue siendo uno.'
);

$antiStarvation = (new CronTaskLaneSelector())->select([
    ['key' => 'notification_fallback', 'lane' => 'urgent', 'api' => true, '_last_finished_at' => '2026-07-31 15:20:00'],
    ['key' => 'manual_campaign', 'lane' => 'directed', 'api' => true, '_last_finished_at' => '2026-07-31 15:10:00'],
    ['key' => 'order_enrichment', 'lane' => 'normal', 'api' => true, '_last_finished_at' => null],
], 3, 1);
$check(
    array_column($antiStarvation, 'key') === ['order_enrichment'],
    'Una tarea API normal que nunca terminó debe recibir el turno antes de carriles atendidos recientemente.'
);

$retryNow = 1785510000;
$check(HttpRetryAfterParser::seconds('120', $retryNow) === 120, 'Retry-After debe aceptar delta-seconds.');
$check(
    HttpRetryAfterParser::seconds(gmdate('D, d M Y H:i:s \\G\\M\\T', $retryNow + 75), $retryNow) === 75,
    'Retry-After debe aceptar una fecha HTTP RFC 7231.'
);
$check(HttpRetryAfterParser::seconds('invalid', $retryNow) === null, 'Retry-After inválido no debe inventar una espera.');

$campaign = (string) file_get_contents($root . '/app/Services/ManualCampaignService.php');
$worker = (string) file_get_contents($root . '/app/Services/ResumableCampaignWorkerService.php');
$taskState = (string) file_get_contents($root . '/app/Services/CronTaskStateService.php');
$workProjection = (string) file_get_contents($root . '/app/Services/WorkQueueProjectionService.php');
$workAdapter = (string) file_get_contents($root . '/app/Services/SqlWorkQueueAdapter.php');
$eligibility = (string) file_get_contents($root . '/app/Services/WorkEligibilityService.php');
$campaignRhythm = (string) file_get_contents($root . '/app/Services/ManualCampaignRhythmService.php');
$campaignPreview = (string) file_get_contents($root . '/app/Services/ManualCampaignPreviewService.php');
$notificationCoalescer = (string) file_get_contents($root . '/app/Services/NotificationCoalescerService.php');
$notificationWork = (string) file_get_contents($root . '/app/Services/NotificationWorkItemService.php');
$notificationBackfill = (string) file_get_contents($root . '/app/Services/NotificationBackfillService.php');
$notificationLegacy = (string) file_get_contents($root . '/app/Services/NotificationLegacyNormalizationService.php');
$notificationReconciliation = (string) file_get_contents($root . '/app/Services/NotificationReconciliationService.php');
$cron = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
$routes = (string) file_get_contents($root . '/public/index.php');
$view = (string) file_get_contents($root . '/app/Views/settings/manual_processing_session_list.php');
$migration = (string) file_get_contents($root . '/database/migrations/190_cron_execution_truth_2_28_10.sql');

$check(
    str_contains($campaign, 'i.next_eligible_at<=UTC_TIMESTAMP(3)')
        && str_contains($campaign, 'ORDER BY (i.next_eligible_at IS NULL OR i.next_eligible_at<=UTC_TIMESTAMP(3)) DESC'),
    'Un ítem futuro no debe bloquear el siguiente ítem elegible.'
);
$check(!str_contains($worker, 'ManualCampaignService())->pause('), 'Un error aislado no debe pausar toda la campaña.');
$check(
    str_contains($worker, 'OR EXISTS (') && str_contains($worker, 'hasDueItem'),
    'La fecha global de campaña no debe ocultar ítems vencidos realmente elegibles.'
);
$check(
    str_contains($taskState, '$directedReadyKeys') && str_contains($taskState, 'status="ready" AND task_key IN'),
    'El selector persistente debe respetar el carril dirigido aun si su fecha global quedó futura.'
);
$check(
    str_contains($taskState, 'status IN ("ready","deferred")')
        && str_contains($taskState, 'public function started(string $taskKey, string $runToken, bool $allowEarlyDirected = false): bool'),
    'Due y claim deben excluir running/error y adquirir el estado mediante una transición condicional.'
);
$check(
    !str_contains($workProjection, "strtotime((string) \$item['created_at_source'])")
        && !str_contains($workAdapter, 'strtotime((string) $next)')
        && !str_contains($eligibility, 'strtotime((string) $value)')
        && !str_contains($campaignRhythm, "strtotime((string) \$campaign['started_at'])")
        && !str_contains($campaignPreview, "strtotime((string) \$record['expires_at'])"),
    'Las fechas DATETIME de colas y campañas deben interpretarse explícitamente como UTC.'
);
$check(
    !str_contains($notificationBackfill, 'strtotime(')
        && !str_contains($notificationLegacy, 'strtotime(')
        && !str_contains($notificationReconciliation, 'strtotime('),
    'Backfill, normalización y reconciliación de notificaciones deben comparar fechas UTC sin depender del timezone PHP.'
);
$check(
    str_contains($notificationCoalescer, 'effectiveDeadline($deadline)')
        && str_contains($notificationCoalescer, 'canStartResource($deadline)')
        && str_contains($notificationWork, 'effectiveDeadline($deadline, $timeBudget)')
        && str_contains($notificationWork, 'canStartResource($context->deadline)'),
    'Notificaciones deben heredar el deadline del orquestador antes de reservar un recurso.'
);
foreach (['selected=', 'started=', 'inspected=', 'deferred=', 'remote_calls=', 'checkpoint_approved=', 'completed=', 'not_started='] as $field) {
    $check(str_contains($cron, $field), 'La salida de Hostinger debe incluir ' . $field);
}
foreach (['/settings/cron/overview.json', '/settings/cron/tasks.json', '/settings/cron/run.json'] as $route) {
    $check(str_contains($routes, $route), 'Falta endpoint operativo ' . $route);
}
$check(!str_contains($view, 'fecha indicada'), 'La campaña no debe ocultar la fecha detrás de “fecha indicada”.');
foreach (['remote_call_count', 'checkpoint_approved_count', 'not_started_count', 'last_scheduler_run_token'] as $column) {
    $check(str_contains($migration, $column), 'La migración 190 debe persistir ' . $column);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "PASS cron_execution_truth_22810\n");
