<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\WorkResolutionPolicyRegistry;

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$historical = (string) file_get_contents($root . '/app/Services/HistoricalWorkReconciliationService.php');
$exact = (string) file_get_contents($root . '/app/Services/ExactWorkRemediationService.php');
$groups = (string) file_get_contents($root . '/app/Services/WorkRemediationService.php');
$projection = (string) file_get_contents($root . '/app/Services/WorkQueueProjectionService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$acknowledgement = (string) file_get_contents($root . '/app/Services/ApiIncidentAcknowledgementService.php');
$health = (string) file_get_contents($root . '/app/Services/ApiHealthService.php');
$attentionView = (string) file_get_contents($root . '/app/Views/settings/automation_attention.php');
$queueView = (string) file_get_contents($root . '/app/Views/settings/automation_queue.php');
$workView = (string) file_get_contents($root . '/app/Views/settings/automation_work.php');
$incidentList = (string) file_get_contents($root . '/app/Views/settings/api_health_incidents.php');
$incidentDetail = (string) file_get_contents($root . '/app/Views/settings/api_health_incident_show.php');
$migration = (string) file_get_contents($root . '/database/migrations/216_historical_intervention_scoped_ack_2_28_36.sql');

$check(!str_contains($historical, 'MeliApiClient') && !str_contains($historical, 'curl_')
    && !str_contains($historical, 'processDue('), 'El reconciliador histórico intenta transportar o procesar una cola.');
$check(str_contains($historical, 'company_id=? AND meli_account_id=? AND queue_key=? AND source_id=?'),
    'La lectura histórica no está aislada por empresa, cuenta y recurso exacto.');
$check(str_contains($historical, "'remote_result_uncertain'") && str_contains($historical, "'expected_absence'")
    && str_contains($historical, "'ready_for_exact_retry'"), 'Falta una salida segura del diagnóstico histórico.');
$check(str_contains($historical, "\$work['safe_error_message']"), 'El mensaje reconciliado no usa el campo seguro que consume la política.');

$check(str_contains($exact, 'HistoricalWorkReconciliationService') && str_contains($exact, 'reconcile($pdo, $source, $userId)'),
    'La acción exacta no ejecuta el reconciliador histórico local.');
$check(!str_contains($exact, 'processDue('), 'La intervención exacta intenta procesar una cola completa.');

$check(!str_contains($attentionView, '<form') && !str_contains($attentionView, '/attention/remediate'),
    'El centro de intervención conserva acciones masivas.');
$check(str_contains($attentionView, 'review_url') && str_contains($attentionView, 'No modifica ni reprograma trabajos'),
    'El centro de intervención no guía hacia trabajos exactos de solo lectura.');
$legacyMethod = strstr($controller, 'public function automationRemediate');
$check(is_string($legacyMethod) && str_contains(substr($legacyMethod, 0, 500), '410'),
    'El endpoint masivo heredado no quedó retirado con HTTP 410.');
$check(str_contains($controller, "\$_GET['resolution']") && str_contains($queueView, 'resolution'),
    'El centro no conserva el filtro de resolución exacta al abrir la cola.');
$check(str_contains($groups, 'h.company_id=p.company_id AND h.meli_account_id=p.meli_account_id'),
    'Los grupos históricos pueden mezclar cuentas o empresas.');
$check(str_contains($projection, 'h.company_id=p.company_id AND h.meli_account_id=p.meli_account_id'),
    'El filtro de trabajos históricos puede reutilizar evidencia de otro alcance.');

$check(str_contains($controller, "\$_POST['scope_key']") && str_contains($acknowledgement, 'acknowledgementKeys()'),
    'El reconocimiento de incidentes no exige un alcance autorizado exacto.');
$check(str_contains($acknowledgement, 'hash_equals($scopeKey'),
    'El reconocimiento puede alcanzar ocurrencias de otra cuenta.');
$check(str_contains($health, 'ack.scope_key=') && str_contains($health, 'scope_key'),
    'El estado revisado de Salud API no se calcula por scope exacto.');
$check(str_contains($incidentDetail, 'name="scope_key"') && str_contains($incidentDetail, 'Marcar este alcance como revisado'),
    'El detalle del incidente no ofrece reconocimiento independiente por alcance.');
$check(str_contains($incidentList, 'per_page') && str_contains($incidentList, '$pages')
    && str_contains($incidentList, '$total'), 'El catálogo de incidentes no expone total y paginación humana.');

$check(str_contains($workView, 'Salidas HTTP estimadas') && str_contains($workView, 'Reconciliada localmente'),
    'El detalle del trabajo mezcla unidades o no muestra la evidencia reconciliada.');

$check(str_contains($migration, 'UNIQUE KEY uq_work_historical_resource (company_id,meli_account_id,queue_key,source_id)'),
    'La tabla histórica no impone unicidad por scope y recurso.');
$check(str_contains($migration, "('cron.group_mutations_enabled','0'"),
    'La migración no deshabilita explícitamente mutaciones grupales.');
foreach (['orders', 'order_items', 'payments', 'shipments', 'packs', 'campaigns', 'oauth'] as $commercialTable) {
    $check(!preg_match('/(?:UPDATE|DELETE\s+FROM)\s+`?' . preg_quote($commercialTable, '/') . '`?/i', $migration),
        'La migración modifica datos comerciales en ' . $commercialTable . '.');
}

$policy = new WorkResolutionPolicyRegistry();
$retry = $policy->resolve([
    'source_status' => 'error',
    'normalized_error_code' => 'legacy_local_failure',
    'failure_class' => 'local',
    'reached_remote' => 0,
]);
$uncertain = $policy->resolve([
    'source_status' => 'error',
    'normalized_error_code' => 'remote_result_uncertain',
    'failure_class' => 'remote_result_uncertain',
    'reached_remote' => 1,
]);
$check(($retry['actions'][0]['key'] ?? '') === 'retry', 'Un fallo local reconciliado no habilita el reintento exacto.');
$check(($uncertain['actions'][0]['key'] ?? '') === 'hold_uncertain', 'Un resultado incierto no conserva el bloqueo preventivo.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "PASS historical_intervention_scope_22836\n");
