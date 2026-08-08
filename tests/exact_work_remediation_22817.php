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
$deadline = $policies->resolve([
    'source_status' => 'error',
    'normalized_error_code' => 'unknown',
    'safe_error_message' => 'El cron alcanzó su límite seguro antes de iniciar otra consulta API.',
    'reached_remote' => 0,
]);
$check(($deadline['key'] ?? '') === 'waiting_deadline', 'El deadline previo a HTTP no se clasifica como espera automática.');
$check(($deadline['automatic'] ?? false) === true, 'El deadline previo a HTTP exige intervención manual.');
$check(($deadline['reachedRemote'] ?? null) === false, 'El deadline previo a HTTP no conserva remote=false.');
$check(($deadline['actions'] ?? ['unexpected']) === [], 'El deadline previo a HTTP ofrece acciones innecesarias.');

$repairable = $policies->resolve([
    'source_status' => 'error',
    'normalized_error_code' => 'invalid_resource_identity',
    'failure_class' => 'missing_resource_identity',
    'reached_remote' => 0,
]);
$check(($repairable['key'] ?? '') === 'repairable', 'Una identidad inválida no queda reparable.');
$check(($repairable['actions'][0]['key'] ?? '') === 'repair_identity_and_retry', 'Falta la reparación local exacta.');

$uncertain = $policies->resolve([
    'source_status' => 'error',
    'normalized_error_code' => 'remote_result_uncertain',
    'failure_class' => 'remote_result_uncertain',
    'reached_remote' => 1,
]);
$uncertainActions = array_column((array) ($uncertain['actions'] ?? []), 'key');
$check($uncertainActions === ['hold_uncertain'], 'Un resultado remoto incierto no ofrece la decisión persistente de mantenerlo bloqueado.');

$service = (string) file_get_contents($root . '/app/Services/ExactWorkRemediationService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$routes = (string) file_get_contents($root . '/public/index.php');
$workView = (string) file_get_contents($root . '/app/Views/settings/automation_work.php');
$campaignView = (string) file_get_contents($root . '/app/Views/settings/manual_processing_session.php');
$script = (string) file_get_contents($root . '/public/assets/app.js');

$check(!str_contains($service, 'MeliApiClient'), 'La remediación web referencia transporte Mercado Libre.');
$check(!str_contains($service, '->processDue('), 'La remediación web procesa una cola en lugar de un recurso exacto.');
$check(!str_contains($service, 'curl_'), 'La remediación web abre transporte cURL.');
$check(str_contains($service, 'WHERE j.id=? AND j.meli_account_id=? AND j.status=? AND j.lease_generation=?'), 'La mutación no está cercada por recurso, cuenta, estado y generación.');
$check(str_contains($service, 'a.company_id=?'), 'La mutación no cerca la empresa de la cuenta.');
$check(str_contains($service, 'LIMIT 1 FOR UPDATE'), 'La decisión exacta no bloquea el recurso dentro de la transacción.');
$check(str_contains($service, 'idempotency_key'), 'La decisión exacta no conserva idempotencia.');
$check(str_contains($service, 'hash_equals($expectedKey, $idempotencyKey)'), 'El servicio confía en una clave de idempotencia no vinculada a la acción.');
$check(str_contains($service, "(string) (\$previous['new_status'] ?? '')")
    && str_contains($service, "(int) (\$previous['resulting_generation'] ?? -2)"),
    'Un replay no revalida el estado y la generación resultantes del recurso.');
$check(str_contains($service, 'campaignReplayMatches('), 'Un replay de campaña no comprueba el estado exacto del ítem.');
$check(str_contains($service, 'foreach ($source !== null ? $campaigns : [] as $campaign)'), 'Una cola sin adaptador todavía recibe acciones de campaña rotas.');
$check(str_contains($service, "'diagnose_local' => \$this->diagnoseLocal(\$pdo, \$source, \$userId)")
    && str_contains($service, 'HistoricalWorkReconciliationService'),
    'Falta el diagnóstico local exacto, scoped y auditable.');
$check(str_contains($service, 'reconcileSourceCampaignItems')
    && str_contains($service, 'CASE WHEN status="completed" THEN completed_units ELSE 0 END'),
    'Resolver el trabajo fuente no reconcilia la campaña o vuelve a inflar unidades completadas.');

$check(str_contains($routes, "post('/settings/cron/work/remediate'"), 'Falta el endpoint exacto de intervención.');
$method = strstr($controller, 'public function automationWorkRemediate');
$check(is_string($method)
    && str_contains(substr($method, 0, 1800), 'requireAdminPermanent')
    && str_contains(substr($method, 0, 1800), 'Csrf::validate')
    && str_contains(substr($method, 0, 1800), 'assertSameOrigin'),
    'El endpoint exacto no exige administrador, CSRF y mismo origen.');

$check(str_contains($workView, 'Qué pasó')
    && str_contains($workView, 'Qué hará el ERP')
    && str_contains($workView, 'Qué puede hacer ahora'),
    'El detalle no presenta la resolución en tres pasos humanos.');
$check(str_contains($workView, 'expected_generation') && str_contains($workView, 'idempotency_key')
    && str_contains($workView, 'action_nonce'), 'El formulario omite fencing, nonce o idempotencia.');
$check(str_contains($workView, "(string) \$resolution['expected_status']")
    && str_contains($workView, "(string) \$resolution['expected_generation']"),
    'La clave del formulario no incorpora estado y generación esperados.');
$check(str_contains($campaignView, 'Cron continúa con los demás') && str_contains($campaignView, 'processing_paused'), 'La campaña sigue presentando un error aislado como pausa global.');
$check(str_contains($script, 'continues_with_attention') && str_contains($script, 'processing_paused'), 'El polling vuelve a introducir el falso estado pausado.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "PASS exact_work_remediation_22817\n");
