<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\ApiHealthSafeMessageService;
use App\Services\ApiRequestOutcomeClassifier;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$safe = (new ApiHealthSafeMessageService())->present(
    "SQLSTATE[42S22]: Unknown column token_secret in C:\\private\\erp\\Service.php:91",
    'SQLSTATE[42S22]'
);
$assert(!str_contains(strtoupper($safe['safe_message']), 'SQLSTATE'), 'SQLSTATE llegó al mensaje web.');
$assert(!str_contains($safe['safe_message'], 'C:\\private'), 'Una ruta privada llegó al mensaje web.');
$assert($safe['diagnostic_id'] !== null, 'Un fallo técnico debe producir referencia diagnóstica.');
$assert($safe['normalized_error_code'] === 'database_error', 'El código SQLSTATE no fue normalizado antes de persistirlo.');

$policy = ApiRequestOutcomeClassifier::classify(
    'GET', '/orders/search', null, true, 'Presupuesto preventivo agotado', ['type' => 'api_budget_exhausted']
);
$assert($policy['outcome_class'] === 'policy_delay' && $policy['actionable'] === 0, 'Una espera preventiva no debe ser incidente accionable.');
$expected = ApiRequestOutcomeClassifier::classify('GET', '/items/MLA1/description', 404, false, 'not found');
$assert($expected['outcome_class'] === 'expected_absence' && $expected['incident_key'] === null, 'Una ausencia esperada no debe abrir incidente.');
$first = ApiRequestOutcomeClassifier::classify('GET', '/orders/1', 500, false, 'Timeout at 12:00:01');
$second = ApiRequestOutcomeClassifier::classify('GET', '/orders/2', 500, false, 'Timeout at 12:00:59');
$assert($first['incident_key'] === $second['incident_key'], 'La clave estable se fragmentó por ID o timestamp del mensaje.');

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$health = (string) file_get_contents($root . '/app/Services/ApiHealthService.php');
$migration = (string) file_get_contents($root . '/database/migrations/204_api_health_incident_truth_2_28_24.sql');
$assert(str_contains($controller, 'ApiIncidentAcknowledgementService'), 'El controlador todavía reconoce incidentes globalmente.');
$assert(str_contains($controller, "'protocol' => \$incidentPage['protocol']"), 'El JSON no declara el protocolo real de la página de incidentes.');
$assert(str_contains($health, 'acknowledged_through_at>=l.created_at'), 'Un reconocimiento no tiene frontera temporal reabrible.');
$assert(str_contains($health, 'ApiHealthAccessScope'), 'Salud API no aplica el alcance de telemetría explícito.');
$assert(!str_contains($migration, 'UPDATE api_request_logs l'), 'La actualización intenta bloquear api_request_logs con backfill masivo.');
$assert(str_contains($migration, 'information_schema.statistics'), 'La migración no protege índices de forma portable e idempotente.');

echo "PASS api_health_incident_truth_22824\n";
