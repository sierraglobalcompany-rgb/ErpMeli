<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\CronWorkOutcome;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

foreach (['waiting_deadline', 'waiting_rhythm', 'waiting_budget', 'waiting_api', 'waiting_schedule', 'waiting_lock', 'retry'] as $state) {
    $check(CronWorkOutcome::isWaiting($state), $state . ' debe permanecer como espera automática.');
    $check(!CronWorkOutcome::countsAsFailure($state), $state . ' no debe incrementar fallos.');
}
$check(CronWorkOutcome::requiresAction('repairable'), 'repairable debe ofrecer una acción exacta.');
$check(CronWorkOutcome::requiresAction('remote_result_uncertain'), 'Un resultado remoto incierto debe permanecer bloqueado.');
$check(CronWorkOutcome::countsAsFailure('action_required'), 'action_required debe contar como fallo real.');

$root = dirname(__DIR__);
$readModel = (string) file_get_contents($root . '/app/Services/CronOperationalReadService.php');
$campaign = (string) file_get_contents($root . '/app/Services/ManualCampaignService.php');
$health = (string) file_get_contents($root . '/app/Services/ApiHealthOverviewService.php');
$apiHealth = (string) file_get_contents($root . '/app/Services/ApiHealthService.php');
$guard = (string) file_get_contents($root . '/app/Services/ApiGuardService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$sectionController = (string) file_get_contents($root . '/app/Controllers/SettingsSectionController.php');
$javascript = (string) file_get_contents($root . '/public/assets/app.js');

$check(str_contains($readModel, 'nextTasksFromRuntimeSelector'), 'La próxima ejecución debe usar el selector real.');
$check(str_contains($readModel, 'started_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 180 SECOND)'), 'Un run abierto vencido no debe aparecer como actual.');
$check(str_contains($readModel, 'origin="scheduled_cli"'), 'El Centro Cron no debe mezclar ejecuciones manuales.');
$check(str_contains($campaign, "'launcher_recent'"), 'La campaña debe separar la señal del lanzador.');
$check(str_contains($campaign, "'campaign_selected_recently'"), 'La campaña debe separar su selección reciente.');
$check(str_contains($campaign, "'item_lease_live'"), 'La campaña debe exigir lease vigente para mostrar ejecución.');
$check(str_contains($guard, 'public function readAvailable()'), 'Circuitos debe distinguir vacío de lectura fallida.');
$check(str_contains($apiHealth, 'LEFT JOIN api_incident_acknowledgements'), 'Los reconocimientos deben agregarse sin N+1.');
$incidentsSection = explode('public function dataAvailable()', explode('public function incidents(', $apiHealth, 2)[1] ?? '', 2)[0] ?? '';
$check(!str_contains($incidentsSection, '$this->incidentAcknowledgement('), 'El listado de incidentes no debe consultar un reconocimiento por fila.');
$check(str_contains($health, "'scope' => 'global'"), 'La evidencia Cron debe declarar su alcance global.');
$check(str_contains($health, "'snapshot_state' => \$snapshotState") && str_contains($health, "'authoritative' => in_array"), 'Salud API debe publicar disponibilidad tipada.');
$check(str_contains($controller, "'authoritative' => in_array(\$snapshotState"), 'Los JSON de Cron deben declarar snapshots autoritativos solo cuando corresponda.');
$check(str_contains($controller, 'NotificationWorkItemService())->summary($accountIds)'), 'El resumen de notificaciones debe limitarse a cuentas autorizadas.');
$check(str_contains($sectionController, 'ApiManualPauseService())->summary($accountIds)'), 'Las pausas manuales visibles deben limitarse a cuentas autorizadas.');
$check(str_contains($javascript, 'new AbortController()') && str_contains($javascript, '8000'), 'El polling Cron debe cortar lecturas colgadas.');
$check(str_contains($javascript, "snapshotState === 'authoritative_empty'"), 'Un vacío autoritativo debe limpiar filas antiguas.');
$check(str_contains($javascript, 'campaignSelectedRecently') && str_contains($javascript, 'itemLeaseLive'), 'La campaña debe presentar por separado selección y lease activo.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "cron_truth_api_health_22818: OK\n");
