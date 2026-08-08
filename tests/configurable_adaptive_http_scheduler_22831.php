<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\CronExecutionPlanner;
use App\Services\CronExecutionWindow;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__);
$rhythm = (string) file_get_contents($root . '/app/Services/ApiRhythmPolicyService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$cron = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
$migration = (string) file_get_contents($root . '/database/migrations/211_configurable_adaptive_http_scheduler_2_28_31.sql');

foreach ([
    "'conservative' => 10",
    "'balanced' => 20",
    "'fast' => 30",
    "'maximum' => 40",
] as $profile) {
    $check(str_contains($rhythm, $profile), 'Falta el perfil backend ' . $profile);
    $check(str_contains($controller, $profile), 'El POST no admite el perfil ' . $profile);
}
$check(str_contains($rhythm, 'rollingWindowBlock('), 'El permiso no aplica una ventana rodante real.');
$check(str_contains($rhythm, 'dispatched_at>DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ? SECOND)'), 'La ventana rodante no cuenta transportes iniciados.');
$check(str_contains($rhythm, 'known_responses') && str_contains($rhythm, '$known >= 60'), 'La rampa sube sin evidencia remota suficiente.');
$check(str_contains($rhythm, '$rateLimited === 0') && str_contains($rhythm, '$uncertain === 0'), 'La rampa ignora 429 o resultados inciertos.');
$check(str_contains($rhythm, '$oauthFailures === 0'), 'La rampa ignora la estabilidad del intercambio OAuth.');
$check(str_contains($rhythm, "outcome_class='success'")
    && str_contains($rhythm, "response_count_state='complete'")
    && str_contains($rhythm, 'http_status<>206'),
    'La rampa admite respuestas no aceptadas, parciales o HTTP 206.');
$check(str_contains($rhythm, 'current_level_started_at')
    && str_contains($controller, 'api.rhythm.current_level_started_at'),
    'La rampa hereda evidencia de un perfil o nivel anterior.');
$check(str_contains($rhythm, '10 => 32'), 'El perfil conservador no deja margen real para la campaña dirigida tras el bootstrap.');
$check(str_contains($rhythm, "endpoint_key=? AND meli_account_id=?"), 'La penalización de endpoint mezcla cuentas distintas.');
$check(str_contains($rhythm, 'observedCapacity($accountId)'), 'El preview no publica capacidad observada con scope de cuenta.');
$check(str_contains($rhythm, 'recordRateLimitPenalty'), 'HTTP 429 no reduce el alcance afectado.');
$check(str_contains($rhythm, 'rhythm_penalty_state_unavailable'), 'Un fallo al leer penalizaciones no difiere de forma segura.');
$check(str_contains($cron, 'CronDeadlineContext::window()'), 'Cron no reutiliza su ventana única.');
$check(str_contains($cron, 'CronLaneBudgetService::fromWindow'), 'Los carriles todavía reinician el reloj.');
$check(str_contains($migration, 'INSERT IGNORE INTO app_settings'), 'La migración puede sobrescribir preferencias existentes.');
$check(str_contains($migration, 'api_rhythm_penalties'), 'Falta persistencia de reducción por cuenta/endpoint.');
$check(str_contains($migration, 'api.rhythm.current_level_started_at'), 'Falta persistir el inicio estable del nivel adaptativo.');

$now = microtime(true);
$window = new CronExecutionWindow(40, 30, $now);
$check(abs($window->startedAt() - $now) < .0001, 'La ventana cambió el inicio recibido.');
$check(abs($window->acceptUntil() - ($now + 30)) < .0001, 'accept_until no proviene del reloj único.');
$check(abs($window->deadline() - ($now + 40)) < .0001, 'deadline no proviene del reloj único.');
$check(abs($window->closeAt() - ($now + 30)) < .0001, 'close_at debe iniciar el cierre seguro, no repetir el deadline.');

$conservativeWindow = new CronExecutionWindow(32, 22, $now - 3);
$check($conservativeWindow->canStartRemote(17), 'El perfil 10/min no conserva margen después del bootstrap para el carril dirigido.');

$reflection = new ReflectionClass(CronExecutionPlanner::class);
$planner = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('exhausted')->setValue($planner, ['manual_campaign' => true]);
$reflection->getProperty('definitions')->setValue($planner, [
    'manual_campaign' => ['key' => 'manual_campaign', 'lane' => 'directed'],
    'notification_fallback' => ['key' => 'notification_fallback', 'lane' => 'urgent'],
]);
$reflection->getProperty('claimsByKey')->setValue($planner, []);
$reflection->getProperty('laneOrder')->setValue($planner, ['directed', 'urgent', 'normal', 'local']);
$reflection->getProperty('laneCursor')->setValue($planner, 0);
$reflection->getProperty('claimCount')->setValue($planner, 0);
$reflection->getProperty('maxClaims')->setValue($planner, 4);
$nextPool = $reflection->getMethod('nextPool')->invoke($planner);
$check(array_keys($nextPool) === ['notification_fallback'], 'Un deadline de campaña no debe cerrar colas urgentes que sí caben.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "PASS configurable_adaptive_http_scheduler_22831\n");
