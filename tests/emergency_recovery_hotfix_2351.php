<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\CronV3Cli;
use App\Services\EmergencyControlService;

$root = dirname(__DIR__);
$failures = [];
$passed = 0;
$total = 0;
$check = static function (bool $condition, string $message) use (&$failures, &$passed, &$total): void {
    $total++;
    if ($condition) {
        $passed++;
        return;
    }
    $failures[] = $message;
};

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-emergency-hotfix-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700, true);
$private = $temporary . DIRECTORY_SEPARATOR . 'private';
mkdir($private, 0700, true);
$previousPrivate = getenv('ERP_EMERGENCY_STORAGE_DIR');
putenv('ERP_EMERGENCY_STORAGE_DIR=' . $private);
$_ENV['ERP_EMERGENCY_STORAGE_DIR'] = $private;

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    @rmdir($path);
};

try {
    $control = new EmergencyControlService($temporary);
    $control->stopAll('test', 'Prueba fail closed');
    $check($control->automationStopped() && $control->apiStopped(), 'stop_all no dejó ambas autoridades detenidas.');

    @unlink($temporary . DIRECTORY_SEPARATOR . EmergencyControlService::AUTOMATION_MARKER);
    try {
        $control->startApiWithoutCanary('test', 'No debe abrir');
        $check(false, 'API se habilitó sin detener automatización.');
    } catch (RuntimeException $error) {
        $check(str_contains($error->getMessage(), 'EMERGENCY_AUTOMATION_NOT_STOPPED'), 'Falta código de precondición de automatización.');
    }

    $control->stopAutomation('test', 'Preparar lecturas');
    $control->stopApi('test', 'Preparar lecturas');
    $control->startApiWithoutCanary('test', 'Lecturas controladas');
    $check(!$control->apiStopped() && $control->automationStopped(), 'Habilitar API no preservó automatización detenida.');

    $control->stopApi('test', 'Preparar canario');
    $control->prepareApiStart('test', 'Canario local');
    $status = $control->status();
    $check(($status['api'] ?? '') === 'canary' && $control->automationStopped(), 'Preparar canario no conservó automatización detenida.');

    $serviceSource = (string) file_get_contents($root . '/app/Services/EmergencyControlService.php');
    $kernelSource = (string) file_get_contents($root . '/app/Recovery/EmergencyControlKernel.php');
    $cliSource = (string) file_get_contents($root . '/app/Services/CronV3Cli.php');
    $clientSource = (string) file_get_contents($root . '/app/Services/MeliApiClient.php');
    $transportSource = (string) file_get_contents($root . '/app/Services/CurlMeliHttpTransport.php');
    $journalSource = (string) file_get_contents($root . '/app/Services/ExecutionJournalService.php');
    $jsSource = (string) file_get_contents($root . '/public/assets/emergency-control.js');
    $assetSource = (string) file_get_contents($root . '/asset.php');

    $stopAllStart = strpos($serviceSource, 'public function stopAll');
    $stopAutomation = strpos($serviceSource, '$this->stopAutomation', $stopAllStart ?: 0);
    $stopApi = strpos($serviceSource, '$this->stopApi', $stopAllStart ?: 0);
    $check($stopAllStart !== false && $stopAutomation !== false && $stopApi !== false && $stopAutomation < $stopApi,
        'stop_all no aplica automation antes de API.');

    $guard = strpos($cliSource, "status' => 'SKIPPED_AUTOMATION_STOPPED'");
    $lock = strpos($cliSource, "job_try_lock('cron_v3_' . \$lane)");
    $database = strpos($cliSource, "Database::useProfile('cli')");
    $legacy = strpos($cliSource, '$legacyImport =');
    $producer = strpos($cliSource, '$maintenanceProducer =');
    $runner = strpos($cliSource, '->run($lane, $remainingRuntime, $maxItems, $shadow)');
    $check($guard !== false && $guard < $lock && $guard < $database && $guard < $legacy && $guard < $producer && $guard < $runner,
        'Guard de automatización no está antes de todo efecto V3.');

    $previousEnabled = getenv('CRON_V3_ENABLED');
    putenv('CRON_V3_ENABLED=true');
    $sourceAutomationMarker = $root . DIRECTORY_SEPARATOR . EmergencyControlService::AUTOMATION_MARKER;
    $savedSourceAutomation = is_file($sourceAutomationMarker) ? file_get_contents($sourceAutomationMarker) : null;
    file_put_contents($sourceAutomationMarker, '{"active":true}');
    foreach (['local', 'remote'] as $lane) {
        ob_start();
        $exit = CronV3Cli::run($lane, [$lane === 'local' ? 'cron_v3_local.php' : 'cron_v3_remote.php']);
        $payload = json_decode(trim((string) ob_get_clean()), true);
        $check($exit === 0 && is_array($payload)
            && ($payload['status'] ?? '') === 'SKIPPED_AUTOMATION_STOPPED'
            && (int) ($payload['http_calls'] ?? -1) === 0
            && (int) ($payload['source_mutations'] ?? -1) === 0,
            'Launcher ' . $lane . ' no produjo skip de cero efectos.');
    }
    if (is_string($savedSourceAutomation)) {
        file_put_contents($sourceAutomationMarker, $savedSourceAutomation);
    } else {
        @unlink($sourceAutomationMarker);
    }
    if ($previousEnabled === false) {
        putenv('CRON_V3_ENABLED');
    } else {
        putenv('CRON_V3_ENABLED=' . $previousEnabled);
    }

    $raceGuard = strpos($clientSource, '$cronV3RemoteContext && (new EmergencyControlService())->automationStopped()');
    $transport = strpos($clientSource, '$this->transport->request(');
    $check($raceGuard !== false && $transport !== false && $raceGuard < $transport,
        'La segunda barrera no está antes del transporte físico.');
    $check(str_contains($clientSource, '$budget->releaseReservation($budgetReservation)')
        && str_contains($clientSource, '$rhythm->cancelBeforeTransport($rhythmPermit)')
        && str_contains($clientSource, '$executionJournal->dispatchCancelledBeforeRemote(')
        && str_contains($clientSource, 'if (!$dispatchBoundaryCrossed)'),
        'La barrera previa a HTTP no libera consistentemente las reservas internas.');
    $check(str_contains($transportSource, '$emergency->assertTransportAllowed();')
        && str_contains($transportSource, 'completeCanaryTransport(')
        && strpos($transportSource, '$emergency->assertTransportAllowed();') < strpos($transportSource, 'curl_init()'),
        'CurlMeliHttpTransport no aplica el contrato canario antes de cURL y después del resultado.');
    $check(str_contains($journalSource, 'state IN ("budget_reserved","remote_dispatched")')
        && str_contains($journalSource, 'state="local_started",budget_reserved=0,reached_remote=0'),
        'El diario no compensa la reserva previa al transporte mediante CAS.');

    $check(str_contains($kernelSource, 'type="hidden" name="action" value="start_api_without_canary"')
        && str_contains($kernelSource, 'type="hidden" name="action" value="' . "' . \$this->escape(\$apiAction) . '"),
        'Las acciones API no viajan como hidden action nativo.');
    $check(!str_contains($jsSource, 'event.submitter'),
        'El JS todavía depende del submitter.');
    $check(str_contains($jsSource, "pendingForm.dataset.confirmed = '1'")
        && str_contains($jsSource, "typeof pendingForm.requestSubmit === 'function'")
        && str_contains($jsSource, 'pendingForm.requestSubmit()')
        && str_contains($jsSource, 'HTMLFormElement.prototype.submit.call(pendingForm)'),
        'El modal no conserva validación nativa con fallback seguro.');
    $check(str_contains($kernelSource, "hash_file('sha256', \$scriptPath)")
        && str_contains($kernelSource, "&amp;v=' . rawurlencode(\$scriptVersion)"),
        'El asset de emergencia no tiene fingerprint.');
    $check(str_contains($assetSource, "\$relativeAsset === 'emergency-control.js'")
        && str_contains($assetSource, 'no-cache, max-age=0, must-revalidate'),
        'El asset de emergencia conserva caché immutable sin revalidación.');
    $check(str_contains($serviceSource, 'diagnoseApiReactivation()')
        && str_contains($serviceSource, "'manual_pause_state'")
        && str_contains($serviceSource, "'circuit_state'")
        && str_contains($serviceSource, "'automation'"),
        'Diagnóstico local de reactivación incompleto.');
    $check(str_contains($serviceSource, 'No se pudo comprobar la autoridad schema_migrations.')
        && str_contains($serviceSource, 'No se pudo comprobar la autoridad meli_accounts.')
        && str_contains($serviceSource, 'No se pudo comprobar la autoridad meli_tokens.')
        && str_contains($serviceSource, 'No se pudo comprobar la autoridad api_manual_pauses.')
        && str_contains($serviceSource, 'No se pudo comprobar la autoridad api_circuit_breakers.')
        && str_contains($serviceSource, '$mandatoryChecks'),
        'Los checks DB del diagnóstico no están aislados o UNKNOWN no bloquea.' );
    $check(str_contains($kernelSource, 'EMERGENCY_ACTION_MISSING'), 'Falta diagnóstico para action ausente.');
    $check(str_contains($kernelSource, 'EMERGENCY_ACTION_UNKNOWN'), 'Falta diagnóstico para action desconocida.');
    $check(str_contains($kernelSource, 'EMERGENCY_PRECONDITION_UNKNOWN')
        && str_contains($kernelSource, "(\$check['status'] ?? '') === 'UNKNOWN'"),
        'READINESS_UNKNOWN_IS_ACTIONABLE no conserva detalle y código para UNKNOWN.');
    $check(str_contains($kernelSource, 'EMERGENCY_CSRF_INVALID'), 'Falta diagnóstico CSRF específico.');
    $check(str_contains($serviceSource, 'EMERGENCY_MARKER_WRITE_FAILED'), 'Falta diagnóstico de escritura de marcador.');
    $check(str_contains($serviceSource, 'assertAutomationStoppedForApiEnable()'), 'No existe precondición servidor-side para API.');
    $check(substr_count($serviceSource, '$this->assertAutomationStoppedForApiEnable();') >= 3,
        'Alguna transición de API omite la precondición de automatización.');
    $check(str_contains($serviceSource, 'Retirar un freeze nunca debe retirar PAUSE_ERP_AUTOMATION'),
        'Procesamiento local todavía está acoplado a automatización.');
    $check(str_contains($kernelSource, "if (!is_string(\$_SESSION['emergency_csrf'] ?? null))"),
        'El token CSRF no es estable entre pestañas de la misma sesión.');
    $check(!str_contains($kernelSource, "Env::bool('ML_WRITE_ENABLED', true)"),
        'El hotfix cambió el default seguro de ML_WRITE_ENABLED.');
    if ($failures !== []) {
        fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
        exit(1);
    }
    echo 'PASS emergency_recovery_hotfix_2351 ' . $passed . '/' . $total . PHP_EOL;
} finally {
    if ($previousPrivate === false) {
        putenv('ERP_EMERGENCY_STORAGE_DIR');
        unset($_ENV['ERP_EMERGENCY_STORAGE_DIR']);
    } else {
        putenv('ERP_EMERGENCY_STORAGE_DIR=' . $previousPrivate);
        $_ENV['ERP_EMERGENCY_STORAGE_DIR'] = $previousPrivate;
    }
    $removeTree($temporary);
}
