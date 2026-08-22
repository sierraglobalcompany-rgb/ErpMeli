<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/app/Services/CronApiRiskSummaryService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$routes = (string) file_get_contents($root . '/public/index.php');
$view = (string) file_get_contents($root . '/app/Views/settings/cron_shell.php');
$javascript = (string) file_get_contents($root . '/public/assets/app.js');

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$check(str_contains($service, "'source' => 'api_request_logs_direct'"), 'La tarjeta debe leer telemetría directa, no incidentes materializados.');
$check(str_contains($service, 'l.reached_remote=1 AND l.http_status=429'), 'REMOTE_HTTP_429 debe exigir transporte remoto conocido.');
$check(str_contains($service, 'l.reached_remote=0 AND l.outcome_class="policy_delay"'), 'La pausa preventiva local debe permanecer separada del 429 remoto.');
$check(str_contains($service, "'LOCAL_RATE_LIMITED_PRETRANSPORT'"), 'La salida debe nombrar inequívocamente la pausa pretransporte.');
$check(str_contains($service, "'REMOTE_HTTP_429'"), 'La salida debe nombrar inequívocamente el 429 remoto.');
$check(str_contains($service, "LIMIT ' . self::TOP_LIMIT"), 'Las listas de riesgos deben ser acotadas.');
$check(str_contains($service, 'ApiRequestOutcomeClassifier::normalizePath'), 'Los endpoints deben normalizar identificadores externos.');
$check(str_contains($service, "hash('sha256', 'cron-risk-account:'"), 'Las cuentas deben exponerse como alias anonimizados.');
$check(str_contains($service, "'scope' => 'Billing únicamente'"), 'El estado del breaker debe declarar alcance Billing únicamente.');
$check(!str_contains($service, 'UPDATE ') && !str_contains($service, 'INSERT ') && !str_contains($service, 'DELETE '), 'La tarjeta debe seguir siendo solo lectura.');
$check(str_contains($controller, 'public function cronApiRisks(): void') && str_contains($controller, 'releaseReadOnlySession()'), 'El endpoint debe liberar sesión y ser de lectura.');
$check(str_contains($routes, "/settings/cron/api-risks.json"), 'Falta la ruta JSON de Riesgos API.');
$check(str_contains($view, 'Riesgos API · últimos 30 días') && str_contains($view, 'data-cron-api-risks'), 'Cron debe contener la tarjeta de riesgos progresiva.');
$check(str_contains($javascript, 'cache: \'no-store\'') && str_contains($javascript, 'timeoutMs = 8000'), 'La tarjeta debe evitar datos obsoletos y cortar una lectura lenta.');
$check(str_contains($javascript, 'Pausa preventiva local · sin HTTP remoto'), 'La interfaz no debe etiquetar una pausa local como HTTP 429.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "CRON_API_RISKS_2416=PASS real_meli_http=0\n");
