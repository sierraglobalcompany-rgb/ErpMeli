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

$policies = new WorkResolutionPolicyRegistry();
foreach (['cron_deadline_deferred', 'waiting_budget', 'waiting_rhythm', 'api_manual_pause', 'lock_busy'] as $code) {
    $policy = $policies->resolve(['source_status' => 'error', 'normalized_error_code' => $code, 'reached_remote' => 0]);
    $check(!empty($policy['automatic']), $code . ' quedó clasificado como intervención.');
    $check(($policy['tone'] ?? '') !== 'red', $code . ' todavía aparece en rojo.');
    $check(($policy['actions'] ?? ['unexpected']) === [], $code . ' ofrece una acción manual innecesaria.');
}

$legacy = $policies->resolve(['source_status' => 'error', 'reached_remote' => null]);
$check(($legacy['key'] ?? '') === 'legacy_needs_diagnosis', 'El error histórico sin evidencia no se reclasifica como legacy_needs_diagnosis.');
$check(($legacy['actions'][0]['key'] ?? '') === 'diagnose_local', 'El error histórico no ofrece diagnóstico local como única acción primaria.');

$notTransported = $policies->resolve(['source_status' => 'error', 'reached_remote' => 0]);
$check(($notTransported['actions'][0]['key'] ?? '') === 'retry', 'Un error sin transporte confirmado no ofrece reintento exacto.');
$transported = $policies->resolve(['source_status' => 'error', 'reached_remote' => 1]);
$check(($transported['actions'][0]['key'] ?? '') === 'hold_uncertain', 'Un error con transporte no conserva el bloqueo incierto.');

$service = (string) file_get_contents($root . '/app/Services/ExactWorkRemediationService.php');
$queueService = (string) file_get_contents($root . '/app/Services/WorkQueueRunService.php');
$projection = (string) file_get_contents($root . '/app/Services/WorkQueueProjectionService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$routes = (string) file_get_contents($root . '/public/index.php');
$history = (string) file_get_contents($root . '/app/Views/settings/automation_history.php');
$runView = (string) file_get_contents($root . '/app/Views/settings/automation_run.php');

foreach (['notification_fallback', 'orders_sync', 'sales_audit', 'order_enrichment', 'financial_recalc', 'sale_financial_reconciliation'] as $queue) {
    $check(str_contains($service, "'" . $queue . "'"), 'Falta el adaptador exacto para ' . $queue . '.');
}
$check(!str_contains($service, 'MeliApiClient') && !str_contains($service, 'curl_') && !str_contains($service, 'processDue('),
    'La remediación exacta contiene transporte o procesamiento de cola.');
$check(str_contains($service, 'if (($source[\'reached_remote\'] ?? null) !== 0'),
    'El reintento no exige ausencia de transporte confirmada.');
$check(str_contains($service, 'AND a.company_id=?') && str_contains($service, 'meli_account_id=?')
    && str_contains($service, 'source_generation'), 'Falta fencing por empresa, cuenta, estado o generación.');

$check(str_contains($routes, "get('/settings/cron/run'")
    && str_contains($routes, "get('/settings/cron/run.json'")
    && str_contains($controller, 'public function cronRunJson'), 'Faltan las rutas HTML/JSON completas del ciclo.');
$check(str_contains($controller, '$requestedPerPage = (int) ($_GET[\'per_page\'] ?? 50)'),
    'El historial accede a per_page sin normalizar primero su ausencia.');
$check(str_contains($history, 'Ver y resolver') && str_contains($history, 'Aplazado automáticamente'),
    'El historial no separa errores resolubles de esperas automáticas.');
$check(str_contains($runView, 'Función y recurso') && str_contains($runView, 'Diagnóstico')
    && str_contains($runView, 'Próxima oportunidad'), 'El detalle del ciclo omite información accionable.');
$check(str_contains($queueService, "'real_error' => \$failed && !\$automatic"), 'Una espera automática todavía puede convertirse en fila roja.');
$check(str_contains($projection, 'COALESCE(p.normalized_error_code') && str_contains($projection, 'cron_deadline_deferred'),
    'La cola de intervención no excluye las esperas automáticas.');

$legacyMethod = strstr($controller, 'public function automationRemediate');
$check(is_string($legacyMethod) && !str_contains(substr($legacyMethod, 0, 900), 'WorkRemediationService'),
    'El POST heredado todavía puede reprocesar un grupo completo.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "PASS cron_action_center_22830\n");
