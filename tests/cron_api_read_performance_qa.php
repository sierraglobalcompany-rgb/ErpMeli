<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$cronRead = (string) file_get_contents($root . '/app/Services/CronOperationalReadService.php');
$pause = (string) file_get_contents($root . '/app/Services/ApiManualPauseService.php');
$guard = (string) file_get_contents($root . '/app/Services/ApiGuardService.php');
$overview = (string) file_get_contents($root . '/app/Services/ApiHealthOverviewService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$appJs = (string) file_get_contents($root . '/public/assets/app.js');

$check(
    str_contains($cronRead, "'cron-operational-overview'")
        && str_contains($cronRead, "'cron-operational-tasks'")
        && str_contains($cronRead, 'private function readCacheKey()'),
    'Las lecturas sondeadas de Cron deben reutilizar un modelo corto aislado por usuario.'
);
$check(
    !str_contains($appJs, 'Promise.all([fetchOverview(), fetchTasks()])')
        && str_contains($appJs, "document.querySelector('[data-queue-v4-clean]')")
        && !str_contains($appJs, 'data-cron-v3'),
    'La página Cron debe leer sólo Queue V4 y no reactivar agregaciones V3 simultáneas.'
);
$check(
    !str_contains($pause, '$this->expireFinished();'),
    'Consultar pausas no puede actualizar filas expiradas desde una petición GET.'
);
$openCircuits = strstr($guard, 'public function openCircuits');
$check(
    is_string($openCircuits)
        && !str_contains(substr($openCircuits, 0, 1800), 'closeExpiredForAccounts(')
        && str_contains(substr($openCircuits, 0, 1800), 'blocked_until>UTC_TIMESTAMP()'),
    'Listar circuitos debe filtrar vencidos sin ejecutar una mutación.'
);
$exportStart = strpos($controller, 'public function exportApiHealth');
$diagnosticsStart = strpos($controller, 'public function diagnostics', $exportStart === false ? 0 : $exportStart);
$exportBlock = $exportStart !== false && $diagnosticsStart !== false
    ? substr($controller, $exportStart, $diagnosticsStart - $exportStart)
    : '';
$check(
    $exportBlock !== ''
        && !str_contains($exportBlock, 'recordAdminAction')
        && str_contains($exportBlock, 'releaseReadOnlySession'),
    'Exportar Salud API por GET debe ser una lectura pura y liberar la sesión.'
);
$check(
    strpos($overview, '$dataAvailable = $service->dataAvailable();')
        > strpos($overview, '$activity = $service->recentSuccessfulActivity'),
    'La disponibilidad debe evaluarse después de todas las consultas de Salud API.'
);
$check(
    substr_count($controller, "'message' => 'No se pudo actualizar") >= 3,
    'Los JSON parciales de Cron deben responder con una causa humana sin borrar el estado anterior.'
);
$statusStart = strpos($controller, 'public function apiHealthIncidentsStatus');
$protectionStart = strpos($controller, 'public function apiHealthProtection', $statusStart === false ? 0 : $statusStart);
$statusBlock = $statusStart !== false && $protectionStart !== false
    ? substr($controller, $statusStart, $protectionStart - $statusStart)
    : '';
$check(
    str_contains($statusBlock, 'releaseReadOnlySession'),
    'El JSON de incidentes debe liberar el lock de sesión antes de consultar tablas grandes.'
);
foreach ([
    'recoverKnownNotificationErrors',
    'createNotificationRecoveryCanary',
    'startNotificationCollationRecovery',
    'pauseNotificationCollationRecovery',
    'rescheduleOverdue',
    'testCron',
    'closeApiCircuit',
    'pauseApiAccount',
    'pauseApi',
    'resumeApi',
    'acknowledgeApiIncident',
    'reactivateApiAccount',
] as $method) {
    $start = strpos($controller, 'public function ' . $method . '(');
    $next = $start === false ? false : strpos($controller, "\n    public function ", $start + 20);
    $block = $start !== false ? substr($controller, $start, $next === false ? null : $next - $start) : '';
    $check(
        $block !== '' && str_contains($block, 'assertSameOrigin()'),
        'La acción sensible ' . $method . ' debe validar el origen además de CSRF.'
    );
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "PASS cron_api_read_performance_qa\n");
