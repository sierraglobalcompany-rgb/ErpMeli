<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$routes = (string) file_get_contents($root . '/public/index.php');
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$cron = (string) file_get_contents($root . '/app/Views/settings/cron_shell.php');
$rhythm = (string) file_get_contents($root . '/app/Views/settings/api_workload.php');
$health = (string) file_get_contents($root . '/app/Views/settings/api_health.php');
$healthService = (string) file_get_contents($root . '/app/Services/ApiHealthOverviewService.php');
$incidentService = (string) file_get_contents($root . '/app/Services/ApiHealthService.php');
$protection = (string) file_get_contents($root . '/app/Views/settings/api_health_protection.php');

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$check(str_contains($routes, "get('/settings/cron/rhythm'") && str_contains($routes, "post('/settings/cron/rhythm'"), 'Deben existir lectura y guardado del ritmo.');
$check(str_contains($routes, "get('/settings/cron/rhythm/preview.json'"), 'Debe existir el preview de ritmo.');
$check(str_contains($routes, "get('/settings/api-health/overview.json'") && str_contains($routes, "get('/settings/api-health/protection.json'"), 'Salud API debe tener read models independientes.');
$check(str_contains($controller, 'public function saveCronRhythm') && str_contains($controller, '$this->assertSameOrigin();'), 'El POST de ritmo debe validar origen.');
$check(str_contains($controller, "'recovery' => 40") && str_contains($controller, "'maximum' => 40"), 'El perfil legado de recuperación debe migrar al techo controlado de 40 HTTP/min.');
$check(str_contains($cron, '>Resumen<') && str_contains($cron, '>Ritmo<') && str_contains($cron, '>Colas<') && str_contains($cron, '>Historial<'), 'Cron debe tener cuatro vistas humanas.');
$check(str_contains($cron, '<th>Finalizados/h</th>') && str_contains($cron, '<th>Salidas HTTP/h</th>'), 'Colas debe separar throughput y transportes HTTP.');
$check(str_contains($rhythm, 'Ritmo efectivo estimado') && str_contains($rhythm, 'no una promesa'), 'Ritmo debe distinguir solicitado de efectivo.');
$check(str_contains($health, '>Mercado Libre<') && str_contains($health, '>Protección del ERP<') && str_contains($health, '>Automatización<'), 'Salud API debe separar sus tres estados.');
$check(str_contains($healthService, "'protection_event_count'") && str_contains($healthService, "'erp_waits'"), 'Las esperas preventivas deben salir de incidentes API.');
$check(str_contains($incidentService, 'l.outcome_class<>"policy_delay"'), 'Los listados de incidentes no deben mezclar policy_delay por defecto.');
$check(str_contains($protection, "\$window['scope'] ?? '') !== 'account'"), 'La capacidad visible por cuenta debe usar solo scope account.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "PASS cron_health_human_22816\n");
