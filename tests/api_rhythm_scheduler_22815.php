<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\ApiBudgetExhaustedException;
use App\Services\ApiRhythmDeferredException;
use App\Services\CronTaskLaneSelector;
use App\Services\CronWorkOutcome;
use App\Services\RemoteResultUncertainException;
use App\Services\SyncErrorClassifier;

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$waiting = new ApiRhythmDeferredException(
    'Esperando ritmo.',
    '2026-08-01 10:00:00',
    'rhythm_interval'
);
$check($waiting instanceof ApiBudgetExhaustedException, 'La espera de ritmo no conserva compatibilidad con colas existentes.');
$check($waiting->reachedRemote === false, 'Una reserva aplazada no puede afirmar transporte remoto.');

$normalized = CronWorkOutcome::normalize([
    'stop_reason' => 'api_rhythm',
    'next_safe_at' => '2026-08-01 10:00:00',
], 0, 0);
$check(($normalized['status'] ?? '') === 'waiting_rhythm', 'El ritmo fue convertido en cola vacía o fallo.');
$check(CronWorkOutcome::operationalState('waiting_rhythm', 'api_rhythm') === 'waiting_rhythm', 'El estado operacional perdió waiting_rhythm.');
$check(CronWorkOutcome::operationalState('waiting_deadline', 'time_budget') === 'waiting_deadline', 'El deadline seguro no conserva su estado tipado.');

$selected = (new CronTaskLaneSelector())->select([
    ['key' => 'notification_fallback', 'lane' => 'urgent', 'api' => true],
    ['key' => 'manual_campaign', 'lane' => 'directed', 'api' => true],
    ['key' => 'order_enrichment', 'lane' => 'normal', 'api' => true],
    ['key' => 'notification_spool', 'lane' => 'local', 'api' => false],
], 4, 4);
$check(count($selected) === 4, 'El selector todavía limita artificialmente las definiciones API a una por ciclo.');
$check(count(array_filter($selected, static fn (array $row): bool => !empty($row['api']))) === 3, 'El selector no admite rondas entre colas remotas.');

$classified = SyncErrorClassifier::classify(new RemoteResultUncertainException('REQ-1', 200));
$check(($classified['type'] ?? '') === 'remote_result_uncertain', 'Un resultado remoto incierto se volvería a clasificar como error genérico.');

$client = (string) file_get_contents($root . '/app/Services/MeliApiClient.php');
$rhythm = (string) file_get_contents($root . '/app/Services/ApiRhythmPolicyService.php');
$batchPolicy = (string) file_get_contents($root . '/app/Services/CronBatchPolicyService.php');
$cron = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
$reservePosition = strpos($client, '$rhythm->reserve');
$budgetPosition = strpos($client, '$budget->reserve');
$transportPosition = strpos($client, '$this->transport->request');
$dispatchPosition = strpos($client, '$rhythm->dispatched');
$check($reservePosition !== false && $budgetPosition !== false && $reservePosition < $budgetPosition, 'El presupuesto se reserva antes del permiso de ritmo.');
$check($transportPosition !== false && $dispatchPosition !== false && $dispatchPosition < $transportPosition, 'El permiso no cambia a dispatched inmediatamente antes del transporte.');
$check(substr_count($client, '$rhythm->dispatched(') === 1, 'El cliente vuelve a confirmar dispatched después de recibir la respuesta HTTP.');
$check(str_contains($client, '$rhythm->finalizeKnownResult($rhythmPermit'), 'Una respuesta HTTP conocida no tiene cleanup local tolerante a fallos.');
$check(str_contains($client, '$budget->releaseReservation($budgetReservation)') && str_contains($client, '$rhythm->release($rhythmPermit)'), 'Un transporte no iniciado no devuelve todas sus reservas.');
$check(str_contains($client, 'RemoteResultUncertainException'), 'La pérdida de fencing puede provocar un reintento remoto silencioso.');
$check(
    str_contains($rhythm, "WHERE status IN ('reserved','dispatched') AND expires_at>UTC_TIMESTAMP(3)"),
    'Dos procesos podrían reservar o despachar simultáneamente el permiso global.'
);
$check(str_contains($rhythm, "SET status='expired'"), 'Los permisos vencidos no se cercan explícitamente.');

foreach (['cron.notification_batch_limit', 'cron.order_enrichment_batch_limit', 'cron.pack_reconciliation_batch_limit', 'cron.financial_reconciliation_batch_limit'] as $setting) {
    $check(str_contains($batchPolicy, $setting), 'La política única no contiene el micro-lote configurable ' . $setting);
}
$check(str_contains($cron, "CronBatchPolicyService(\$settings)"), 'El CLI no consume la autoridad única de lotes.');
$check(substr_count($cron, "\$batchPolicy->limit('") >= 10, 'Los callbacks continúan duplicando límites fuera de la autoridad única.');
$check(!str_contains($cron, "settings->int('cron.max_api_tasks_per_run'"), 'El selector CLI todavía usa el límite obsoleto de tareas API.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL {$failure}\n");
    }
    exit(1);
}

echo "PASS api_rhythm_scheduler_22815\n";
