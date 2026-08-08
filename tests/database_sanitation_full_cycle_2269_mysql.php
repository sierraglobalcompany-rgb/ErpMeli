<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\Core\Env;
use App\Services\BackupCenterService;
use App\Services\DatabaseMaintenanceService;

@set_time_limit(0);

$pdo = Database::connection();
$service = new DatabaseMaintenanceService();
$started = microtime(true);
$stage = 'inicio';

try {
    $stage = 'validación local';
    if ((string) Env::get('APP_ENV', '') !== 'local') {
        throw new RuntimeException('El saneamiento integral solo puede certificarse en localhost.');
    }
    $userId = (int) $pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('El clon no contiene un administrador para la prueba.');
    }
    $stage = 'análisis';
    $analysis = $service->analyze($userId);
    $sessionId = (int) ($analysis['id'] ?? 0);
    if ($sessionId < 1) {
        throw new RuntimeException('El análisis no creó una sesión de saneamiento.');
    }
    $stage = 'copia exacta';
    $backupCenter = new BackupCenterService();
    $backup = $backupCenter->enqueue($userId, 'manual', 'database_sanitation', $sessionId);
    $backupId = (int) ($backup['id'] ?? 0);
    $backupPublicId = (string) ($backup['public_id'] ?? '');
    if ($backupId < 1) {
        throw new RuntimeException('No fue posible crear la copia exacta para saneamiento.');
    }
    for ($attempt = 0; $attempt < 5000; $attempt++) {
        $backupStep = $backupCenter->processInteractiveStep($backupId, $userId);
        if (($backupStep['result'] ?? '') === 'completed') {
            break;
        }
        if (in_array((string) ($backupStep['result'] ?? ''), ['locked', 'deferred'], true)) {
            usleep(250000);
            continue;
        }
        if (($backupStep['result'] ?? '') !== 'partial') {
            throw new RuntimeException(
                'La copia exacta dejó de avanzar: ' . (string) ($backupStep['message'] ?? 'sin detalle')
            );
        }
    }
    $stage = 'vincular copia';
    $service->bindVerifiedBackup($sessionId, $backupId, $userId);
    $token = bin2hex(random_bytes(32));
    $stage = 'iniciar saneamiento';
    $service->start($sessionId, $userId, $token, null);
    $pausedAndResumed = false;
    $cycles = 0;
    while ($cycles++ < 5000) {
        $stage = 'micro-lote ' . $cycles;
        $step = $service->runInteractiveStep($sessionId, $userId, $token);
        $state = $service->session($sessionId, $userId);
        if (!$pausedAndResumed && $cycles === 5) {
            $paused = $service->pause($sessionId, $userId);
            if ((string) ($paused['status'] ?? '') !== 'paused') {
                throw new RuntimeException('La pausa no esperó el cierre del micro-lote.');
            }
            if ($service->runInteractiveStep($sessionId, $userId, $token) !== null) {
                throw new RuntimeException('La sesión pausada inició otro lote.');
            }
            $service->start($sessionId, $userId, $token, null);
            $pausedAndResumed = true;
            continue;
        }
        if (in_array((string) ($state['status'] ?? ''), ['completed', 'finished'], true)) {
            break;
        }
        if ((string) ($state['status'] ?? '') === 'failed') {
            throw new RuntimeException(
                'El saneamiento falló: ' . (string) ($state['safe_message'] ?? '')
            );
        }
        if ($step === null && (string) ($state['status'] ?? '') !== 'running') {
            throw new RuntimeException('El saneamiento quedó detenido en un estado no terminal.');
        }
    }
    $final = $service->session($sessionId, $userId);
    if (!in_array((string) ($final['status'] ?? ''), ['completed', 'finished'], true)) {
        throw new RuntimeException('El saneamiento no terminó dentro del límite de ciclos.');
    }
    $counters = is_array($final['counters'] ?? null) ? $final['counters'] : [];
    $integrityBefore = is_array($final['integrity_before'] ?? null)
        ? $final['integrity_before']
        : [];
    $integrityAfter = is_array($final['integrity_after'] ?? null)
        ? $final['integrity_after']
        : [];
    if (
        !is_array($integrityBefore)
        || !is_array($integrityAfter)
        || $integrityBefore !== $integrityAfter
    ) {
        throw new RuntimeException('La huella comercial cambió durante el saneamiento.');
    }
    echo json_encode([
        'status' => 'PASS',
        'session_id' => $sessionId,
        'cycles' => $cycles,
        'rows_reviewed' => (int) ($counters['reviewed'] ?? $counters['rows_reviewed'] ?? 0),
        'rows_archived' => (int) ($counters['archived'] ?? $counters['rows_archived'] ?? 0),
        'rows_deleted' => (int) ($counters['deleted'] ?? $counters['rows_deleted'] ?? 0),
        'payloads_externalized' => (int) (
            $counters['payloads'] ?? $counters['payloads_externalized'] ?? 0
        ),
        'bytes_released' => (int) ($counters['bytes_released'] ?? 0),
        'seconds' => round(microtime(true) - $started, 3),
        'pause_resume' => $pausedAndResumed,
        'commercial_integrity' => true,
        'remote_transport' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Etapa: ' . $stage . PHP_EOL);
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    fwrite(STDERR, $error->getTraceAsString() . PHP_EOL);
    exit(1);
}
