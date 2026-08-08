<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\ApiRhythmDeferredException;
use App\Services\ApiRhythmPolicyService;
use App\Services\AppSettingsService;
use App\Services\CronLaneBudgetService;

if (trim((string) getenv('ERP_RHYTHM_TEST_DSN')) === '') {
    fwrite(STDERR, "ERROR: ERP_RHYTHM_TEST_DSN es obligatorio.\n");
    exit(2);
}

putenv('DB_HOST=' . (string) getenv('ERP_RHYTHM_TEST_HOST'));
putenv('DB_PORT=' . (string) getenv('ERP_RHYTHM_TEST_PORT'));
putenv('DB_NAME=' . (string) getenv('ERP_RHYTHM_TEST_DB'));
putenv('DB_USER=' . (string) getenv('ERP_RHYTHM_TEST_USER'));
putenv('DB_PASS=' . (string) getenv('ERP_RHYTHM_TEST_PASS'));
putenv('APP_ENV=local');
putenv('ML_WRITE_ENABLED=false');

require dirname(__DIR__) . '/vendor/autoload.php';

/** @param mixed $condition */
function rhythmAssert($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = Database::connectionFresh();
$accountId = (int) $pdo->query('SELECT id FROM meli_accounts ORDER BY id LIMIT 1')->fetchColumn();
rhythmAssert($accountId > 0, 'El clon debe contener una cuenta local.');

$pdo->exec("DELETE FROM api_remote_permits; DELETE FROM api_rhythm_states");
$settings = [
    'api.rhythm.profile' => 'conservative',
    'api.rhythm.target_http_per_minute' => '10',
    'api.rhythm.current_adaptive_limit' => '10',
    'api.rhythm.minimum_interval_ms' => '1000',
    'api.rhythm.rolling_window_seconds' => '60',
    'api.rhythm.adaptive_enabled' => '0',
    'api.rhythm.calls_per_block' => '2',
    'api.rhythm.interval_ms' => '1000',
    'api.rhythm.block_pause_ms' => '20000',
    'api.rhythm.short_wait_ceiling_ms' => '0',
];
$upsert = $pdo->prepare(
    'INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group)
     VALUES (?, ?, 0, "api_rhythm")
     ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'
);
foreach ($settings as $key => $value) {
    $upsert->execute([$key, $value]);
}

$service = new ApiRhythmPolicyService();
$meta = ['job_type' => 'orders_sync', 'source_work_id' => 'qa-rhythm'];
$first = $service->reserve($accountId, 'GET', '/orders/1', $meta);
rhythmAssert(($first['permit_token'] ?? '') !== '', 'Debe reservar el primer permiso.');

$busy = false;
try {
    $service->reserve($accountId, 'GET', '/orders/2', $meta);
} catch (ApiRhythmDeferredException $error) {
    $busy = $error->blockingScope === 'rhythm_permit_busy';
}
rhythmAssert($busy, 'Dos procesos no pueden reservar el mismo turno global.');

$service->release($first);
$second = $service->reserve($accountId, 'GET', '/orders/2', $meta);
rhythmAssert($service->dispatched($second), 'El permiso vigente debe confirmar transporte.');
$service->completed($second, 200);
rhythmAssert((int) $pdo->query("SELECT calls_in_block FROM api_rhythm_states WHERE scope_key='global'")->fetchColumn() === 1,
    'Solo el transporte confirmado debe contar en el bloque.');

$interval = false;
try {
    $service->reserve($accountId, 'GET', '/orders/3', $meta);
} catch (ApiRhythmDeferredException $error) {
    $interval = $error->blockingScope === 'rhythm_interval';
}
rhythmAssert($interval, 'El intervalo debe persistir entre solicitudes.');

$pdo->exec("UPDATE api_rhythm_states SET next_allowed_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND)");
$third = $service->reserve($accountId, 'GET', '/orders/3', $meta);
rhythmAssert($service->dispatched($third), 'El segundo transporte debe iniciar tras el intervalo.');
$service->completed($third, 200);

rhythmAssert($pdo->query("SELECT block_pause_until IS NULL FROM api_rhythm_states WHERE scope_key='global'")->fetchColumn() == 1,
    'El contrato nuevo no debe crear pausas de bloque heredadas; la ventana rodante es la autoridad.');

// Reproduce el defecto 2.28.22: al vencer la pausa, la autoridad incrementaba
// generation pero reserve() devolvía todavía la generación anterior.
$pdo->exec(
    "UPDATE api_rhythm_states
     SET generation=7,calls_in_block=2,next_allowed_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND),
         block_pause_until=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND)
     WHERE scope_key='global'"
);
$rollover = $service->reserve($accountId, 'GET', '/orders/rollover', $meta);
$stateGeneration = (int) $pdo->query(
    "SELECT generation FROM api_rhythm_states WHERE scope_key='global'"
)->fetchColumn();
rhythmAssert((int) ($rollover['generation'] ?? 0) === 8 && $stateGeneration === 8,
    'El permiso posterior a una pausa vencida debe usar la nueva generación.');
rhythmAssert($service->isCurrent($rollover), 'El permiso nuevo debe estar vigente antes de iniciar HTTP.');
rhythmAssert($service->dispatched($rollover), 'El rollover legítimo no puede convertirse en resultado remoto incierto.');
$service->completed($rollover, 200);

$pdo->exec("UPDATE api_rhythm_states SET calls_in_block=0,next_allowed_at=NULL,block_pause_until=NULL,generation=generation+1");
$expired = $service->reserve($accountId, 'GET', '/orders/5', $meta);
$pdo->exec("UPDATE api_rhythm_states SET generation=generation+1 WHERE scope_key='global'");
rhythmAssert(!$service->isCurrent($expired), 'Un permiso con generación vencida debe detectarse antes del transporte.');
rhythmAssert(!$service->dispatched($expired), 'Un permiso con generación vencida no puede confirmar transporte.');

$pdo->exec("DELETE FROM api_remote_permits; UPDATE api_rhythm_states SET calls_in_block=0,next_allowed_at=NULL,block_pause_until=NULL");
$preHttp = $service->reserve($accountId, 'GET', '/orders/pre-http', $meta);
rhythmAssert($service->dispatched($preHttp), 'El permiso pre-HTTP debe pasar a dispatched.');
$activeBusy = false;
try {
    $service->reserve($accountId, 'GET', '/orders/concurrent', $meta);
} catch (ApiRhythmDeferredException $error) {
    $activeBusy = $error->blockingScope === 'rhythm_permit_busy';
}
rhythmAssert($activeBusy, 'Un permiso dispatched vigente debe impedir otra reserva concurrente.');
$service->cancelBeforeTransport($preHttp);
$preHttpStmt = $pdo->prepare('SELECT status,blocking_scope FROM api_remote_permits WHERE permit_token=?');
$preHttpStmt->execute([(string) $preHttp['permit_token']]);
$preHttpRow = $preHttpStmt->fetch(PDO::FETCH_ASSOC) ?: [];
rhythmAssert((string) ($preHttpRow['status'] ?? '') === 'released'
    && (string) ($preHttpRow['blocking_scope'] ?? '') === 'cancelled_before_transport'
    && (int) $pdo->query("SELECT calls_in_block FROM api_rhythm_states WHERE scope_key='global'")->fetchColumn() === 0,
    'Cancelar antes de HTTP debe liberar el permiso y devolver la unidad del bloque.');

// Una respuesta HTTP ya conocida no puede convertirse en incierta porque el
// registro técnico cambió de estado antes del cleanup posterior.
$pdo->exec("DELETE FROM api_remote_permits; UPDATE api_rhythm_states SET calls_in_block=0,next_allowed_at=NULL,block_pause_until=NULL");
$known = $service->reserve($accountId, 'GET', '/orders/known-result', $meta);
rhythmAssert($service->dispatched($known), 'El permiso debe quedar dispatched inmediatamente antes de HTTP.');
$expire = $pdo->prepare('UPDATE api_remote_permits SET status="expired" WHERE permit_token=?');
$expire->execute([(string) $known['permit_token']]);
rhythmAssert(!$service->finalizeKnownResult($known, 200), 'El fallback debe informar que reconcilió un cleanup vencido.');
$knownStmt = $pdo->prepare('SELECT status,http_status,blocking_scope FROM api_remote_permits WHERE permit_token=?');
$knownStmt->execute([(string) $known['permit_token']]);
$knownRow = $knownStmt->fetch(PDO::FETCH_ASSOC) ?: [];
rhythmAssert((string) ($knownRow['status'] ?? '') === 'expired'
    && (int) ($knownRow['http_status'] ?? 0) === 200
    && (string) ($knownRow['blocking_scope'] ?? '') === 'known_result_reconciliation',
    'El cleanup debe conservar el HTTP conocido sin confirmar una generación nueva.');

foreach ([
    'cron.directed_lane_seconds' => '15',
    'cron.directed_operation_reserve_seconds' => '15',
    'cron.directed_lane_guard_ms' => '1500',
    'cron.directed_min_start_seconds' => '4',
    'cron.safe_close_seconds' => '10',
] as $key => $value) {
    $upsert->execute([$key, $value]);
}
$shortLane = new CronLaneBudgetService(microtime(true) - 16.0, 40, new AppSettingsService());
rhythmAssert($shortLane->canStart('directed'), 'Una ventana real cercana a 14 segundos debe permitir reclamar y dejar que el candidato exacto decida si cabe.');
$fullLane = new CronLaneBudgetService(microtime(true), 40, new AppSettingsService());
rhythmAssert($fullLane->canStart('directed'), 'Una ejecución nueva debe reservar la ventana dirigida completa.');
$directedWindow = $fullLane->deadline('directed') - microtime(true);
rhythmAssert($directedWindow >= 16.5, 'La ventana dirigida efectiva debe ser cercana a 17 segundos.');

$pdo->exec("DELETE FROM api_remote_permits; DELETE FROM api_rhythm_states");
echo "PASS api_rhythm_mysql_integration_22815\n";
