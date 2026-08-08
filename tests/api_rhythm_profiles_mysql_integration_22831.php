<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\ApiRhythmDeferredException;
use App\Services\ApiRhythmPolicyService;
use App\Services\AppSettingsService;
use App\Services\HttpRetryAfterParser;

foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER'] as $required) {
    if (trim((string) getenv($required)) === '') {
        fwrite(STDERR, "ERROR: {$required} es obligatorio.\n");
        exit(2);
    }
}
putenv('APP_ENV=local');
putenv('ML_WRITE_ENABLED=false');

require dirname(__DIR__) . '/vendor/autoload.php';

function profileAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param array<string,string> $overrides */
function configureProfile(PDO $pdo, int $target, array $overrides = []): void
{
    $pdo->exec('DELETE FROM api_remote_permits');
    $pdo->exec('DELETE FROM api_rhythm_states');
    $pdo->exec('DELETE FROM api_rhythm_penalties');
    $profile = [10 => 'conservative', 20 => 'balanced', 30 => 'fast', 40 => 'maximum'][$target];
    $settings = new AppSettingsService();
    foreach (array_merge([
        'api.rhythm.profile' => $profile,
        'api.rhythm.target_http_per_minute' => (string) $target,
        'api.rhythm.current_adaptive_limit' => (string) $target,
        'api.rhythm.current_level_started_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        'api.rhythm.minimum_interval_ms' => '1000',
        'api.rhythm.rolling_window_seconds' => '60',
        'api.rhythm.short_wait_ceiling_ms' => '1500',
        'api.rhythm.adaptive_enabled' => '0',
    ], $overrides) as $key => $value) {
        $settings->set($key, $value, 'api_rhythm');
    }
    AppSettingsService::clearCache();
}

function releaseMinimumInterval(PDO $pdo): void
{
    $pdo->exec("UPDATE api_rhythm_states SET next_allowed_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND)");
}

$pdo = Database::connectionFresh();
$accountIds = array_map('intval', $pdo->query('SELECT id FROM meli_accounts ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
profileAssert(count($accountIds) === 2, 'La certificación necesita dos cuentas para validar aislamiento.');
[$accountOne, $accountTwo] = $accountIds;

foreach ([10, 20, 30, 40] as $target) {
    configureProfile($pdo, $target);
    $service = new ApiRhythmPolicyService();
    $preview = $service->preview($accountOne);
    profileAssert((int) $preview['requested_rpm'] === $target, "El preview no expone {$target} HTTP/min.");
    profileAssert((int) $preview['adaptive_rpm'] === $target, "Adaptive disabled no respeta {$target} HTTP/min.");

    for ($i = 0; $i < $target; $i++) {
        $permit = $service->reserve($accountOne, 'GET', '/orders/' . ($i + 1), ['job_type' => 'orders_sync']);
        profileAssert($service->dispatched($permit), "El transporte " . ($i + 1) . " no obtuvo permiso en {$target}/min.");
        profileAssert($service->finalizeKnownResult($permit, 200), 'No se cerró el permiso conocido.');
        releaseMinimumInterval($pdo);
    }
    $blocked = false;
    try {
        $service->reserve($accountOne, 'GET', '/orders/overflow', ['job_type' => 'orders_sync']);
    } catch (ApiRhythmDeferredException $error) {
        $blocked = $error->blockingScope === 'rhythm_global_window';
    }
    profileAssert($blocked, "El transporte N+1 superó el techo rodante {$target}/min.");
    $dispatched = (int) $pdo->query('SELECT COUNT(*) FROM api_remote_permits WHERE dispatched_at IS NOT NULL')->fetchColumn();
    profileAssert($dispatched === $target, "La ventana {$target}/min persistió {$dispatched} transportes.");
}

// Un 429 reduce solo la cuenta y endpoint afectados; otra cuenta conserva su
// capacidad aun cuando use el mismo contrato de endpoint.
configureProfile($pdo, 40);
$service = new ApiRhythmPolicyService();
$permit = $service->reserve($accountOne, 'GET', '/orders/first', ['job_type' => 'orders_sync']);
profileAssert($service->dispatched($permit), 'El permiso previo al 429 no fue despachado.');
$service->finalizeKnownResult($permit, 429, 30);
releaseMinimumInterval($pdo);
$otherAccountPermit = $service->reserve($accountTwo, 'GET', '/orders/second', ['job_type' => 'orders_sync']);
profileAssert($service->dispatched($otherAccountPermit), 'El 429 de una cuenta contaminó otra cuenta.');
$service->finalizeKnownResult($otherAccountPermit, 200);
releaseMinimumInterval($pdo);
$blocked = false;
try {
    $service->reserve($accountOne, 'GET', '/orders/third', ['job_type' => 'orders_sync']);
} catch (ApiRhythmDeferredException $error) {
    $blocked = $error->blockingScope === 'retry_after';
}
profileAssert($blocked, 'HTTP 429 no aplicó Retry-After al alcance afectado.');
profileAssert((int) $pdo->query('SELECT COUNT(*) FROM api_rhythm_penalties')->fetchColumn() === 2,
    'La reducción debe persistir cuenta y endpoint por separado.');

$now = time();
$httpDate = gmdate('D, d M Y H:i:s \G\M\T', $now + 37);
profileAssert(HttpRetryAfterParser::seconds($httpDate, $now) === 37, 'Retry-After HTTP-date no se interpretó exactamente.');
profileAssert(HttpRetryAfterParser::seconds('19', $now) === 19, 'Retry-After numérico no se conservó.');

// Dos procesos cruzan la misma barrera. Solo uno puede despachar mientras el
// permiso global del ganador continúa vigente.
configureProfile($pdo, 40);
$barrier = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-rhythm-barrier-' . bin2hex(random_bytes(6));
$worker = __DIR__ . '/api_rhythm_concurrent_worker_22831.php';
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$processes = [];
for ($i = 0; $i < 2; $i++) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, $worker, (string) $accountOne, $barrier], $descriptors, $pipes, dirname(__DIR__));
    profileAssert(is_resource($process), 'No se pudo iniciar el worker concurrente.');
    $processes[] = [$process, $pipes];
}
touch($barrier);
$results = [];
foreach ($processes as [$process, $pipes]) {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    profileAssert($exit === 0, 'Worker concurrente falló: ' . trim($stderr));
    $decoded = json_decode(trim($stdout), true);
    profileAssert(is_array($decoded), 'Worker concurrente devolvió salida inválida: ' . trim($stdout));
    $results[] = $decoded;
}
@unlink($barrier);
$winners = count(array_filter($results, static fn(array $result): bool => !empty($result['dispatched'])));
profileAssert($winners === 1, "Dos procesos concurrentes produjeron {$winners} transportes en vez de uno.");
profileAssert((int) $pdo->query('SELECT COUNT(*) FROM api_remote_permits WHERE dispatched_at IS NOT NULL')->fetchColumn() === 1,
    'La base no certifica un único transporte concurrente.');

// La rampa exige 30 minutos continuos en el nivel actual y resultados que el
// ERP certificó completos. Un único 206 parcial impide superar el 99 %.
$pdo->exec('DELETE FROM api_request_logs');
$pdo->exec('DELETE FROM api_circuit_breakers');
configureProfile($pdo, 20, [
    'api.rhythm.current_adaptive_limit' => '10',
    'api.rhythm.current_level_started_at' => gmdate('Y-m-d H:i:s', time() - 31 * 60),
    'api.rhythm.last_ramp_evaluation_at' => gmdate('Y-m-d H:i:s', time() - 31 * 60),
    'api.rhythm.ramp_evaluation_minutes' => '30',
    'api.rhythm.adaptive_enabled' => '1',
]);
$insertLog = $pdo->prepare(
    "INSERT INTO api_request_logs
     (meli_account_id,company_id,request_id,method,endpoint_path,http_status,duration_ms,
      outcome_class,reached_remote,response_item_count,response_count_state,created_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())"
);
for ($i = 0; $i < 60; $i++) {
    $partial = $i === 59;
    $insertLog->execute([
        $accountOne, 1, 'RHYTHM-PARTIAL-' . $i, 'GET', '/orders/search', $partial ? 206 : 200, 250,
        'success', 1, 1, $partial ? 'partial' : 'complete',
    ]);
}
AppSettingsService::clearCache();
$partialRampService = new ApiRhythmPolicyService();
$partialRampPermit = $partialRampService->reserve($accountOne, 'GET', '/orders/ramp-partial', ['job_type' => 'orders_sync']);
$partialRampService->release($partialRampPermit);
AppSettingsService::clearCache();
profileAssert((int) (new ApiRhythmPolicyService())->preview($accountOne)['adaptive_rpm'] === 10,
    'Una respuesta HTTP 206 parcial autorizó indebidamente la rampa.');

$pdo->exec('DELETE FROM api_request_logs');
$settings = new AppSettingsService();
$settings->set('api.rhythm.current_adaptive_limit', '10', 'api_rhythm');
$settings->set('api.rhythm.current_level_started_at', gmdate('Y-m-d H:i:s', time() - 31 * 60), 'api_rhythm');
$settings->set('api.rhythm.last_ramp_evaluation_at', gmdate('Y-m-d H:i:s', time() - 31 * 60), 'api_rhythm');
for ($i = 0; $i < 60; $i++) {
    $insertLog->execute([
        $accountOne, 1, 'RHYTHM-COMPLETE-' . $i, 'GET', '/orders/search', 200, 250,
        'success', 1, 1, 'complete',
    ]);
}
AppSettingsService::clearCache();
$completeRampService = new ApiRhythmPolicyService();
$completeRampPermit = $completeRampService->reserve($accountOne, 'GET', '/orders/ramp-complete', ['job_type' => 'orders_sync']);
$completeRampService->release($completeRampPermit);
AppSettingsService::clearCache();
profileAssert((int) (new ApiRhythmPolicyService())->preview($accountOne)['adaptive_rpm'] === 15,
    'Sesenta resultados completos y estables no elevaron un solo nivel de rampa.');

// Si la autoridad de penalizaciones no puede leerse, la salida falla cerrada
// con una espera recuperable, no como permiso silencioso.
configureProfile($pdo, 10);
$pdo->exec('RENAME TABLE api_rhythm_penalties TO api_rhythm_penalties_unavailable');
try {
    $blocked = false;
    try {
        (new ApiRhythmPolicyService())->reserve($accountOne, 'GET', '/orders/protected', ['job_type' => 'orders_sync']);
    } catch (ApiRhythmDeferredException $error) {
        $blocked = $error->blockingScope === 'rhythm_penalty_state_unavailable';
    }
    profileAssert($blocked, 'Un fallo de lectura de penalizaciones autorizó una salida HTTP.');
} finally {
    $pdo->exec('RENAME TABLE api_rhythm_penalties_unavailable TO api_rhythm_penalties');
}

fwrite(STDOUT, "PASS api_rhythm_profiles_mysql_integration_22831\n");
