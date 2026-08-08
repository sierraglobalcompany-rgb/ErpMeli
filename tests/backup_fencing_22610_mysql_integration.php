<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/jobs/_prebootstrap_runtime_paths.php';

use App\Core\AppPaths;
use App\Core\Database;
use App\Core\Env;
use App\Services\BackupArchiveV3Service;
use App\Services\BackupCenterService;
use App\Services\DatabaseMaintenanceService;
use App\Services\LocalMaintenanceCoordinator;

$pdo = Database::connection();
$center = new BackupCenterService();
$coordinator = new LocalMaintenanceCoordinator();
$createdBackups = [];
$createdSessions = [];
$createdFiles = [];
$markers = [
    AppPaths::storage('cache/backup-maintenance-request.json'),
    AppPaths::storage('cache/database-snapshot-active.json'),
    AppPaths::storage('cache/local-maintenance-state.json'),
];
$savedMarkers = [];
foreach ($markers as $marker) {
    $savedMarkers[$marker] = is_file($marker) ? file_get_contents($marker) : null;
    @unlink($marker);
}

/** @return string */
function fencingUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
        . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-'
        . substr($hex, 20);
}

/**
 * @return array{id:int,public_id:string}
 */
function fencingBackup(
    PDO $pdo,
    int $userId,
    string $archiveStatus,
    string $jobStatus,
    ?string $checkpoint = null,
    string $contextType = 'general',
    ?int $contextId = null,
    string $executionMode = 'cli'
): array {
    $publicId = fencingUuid();
    $insert = $pdo->prepare(
        "INSERT INTO system_backup_archives
            (public_id,requested_by,purpose,status,erp_version,format_version,
             context_type,context_id,execution_mode)
         VALUES (:public_id,:user_id,'manual',:status,'2.26.10',3,:context_type,:context_id,:execution_mode)"
    );
    $insert->execute([
        'public_id' => $publicId,
        'user_id' => $userId,
        'status' => $archiveStatus,
        'context_type' => $contextType,
        'context_id' => $contextId,
        'execution_mode' => $executionMode,
    ]);
    $backupId = (int) $pdo->lastInsertId();
    $job = $pdo->prepare(
        "INSERT INTO system_backup_jobs
            (backup_id,job_type,status,lease_generation,checkpoint_json)
         VALUES (:backup_id,'create',:status,4,:checkpoint)"
    );
    $job->execute([
        'backup_id' => $backupId,
        'status' => $jobStatus,
        'checkpoint' => $checkpoint,
    ]);
    return ['id' => $backupId, 'public_id' => $publicId];
}

try {
    $userId = (int) $pdo->query(
        "SELECT id FROM users
          WHERE role='admin' AND status=1 AND is_temporary=0
          ORDER BY id LIMIT 1"
    )->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('La prueba requiere un administrador permanente.');
    }

    $expired = fencingBackup(
        $pdo,
        $userId,
        'creating',
        'pending',
        json_encode(['format' => 3, 'stage' => 'dump', 'chunks' => []], JSON_THROW_ON_ERROR)
    );
    $createdBackups[] = $expired['id'];
    $coordinator->publishBackup(
        $expired['id'],
        $expired['public_id'],
        'pending',
        '',
        4,
        'create',
        true
    );
    $markerPath = AppPaths::storage('cache/local-maintenance-state.json');
    $payload = json_decode((string) file_get_contents($markerPath), true);
    if (!is_array($payload)) {
        throw new RuntimeException('No se publicó el coordinador de prueba.');
    }
    $payload['issued_at'] = time() - 604700;
    $payload['updated_at'] = $payload['issued_at'];
    $payload['expires_at'] = time() - 5;
    unset($payload['signature']);
    ksort($payload);
    $key = hash(
        'sha256',
        'local-maintenance-coordinator|' . (string) Env::get('APP_KEY', ''),
        true
    );
    $payload['signature'] = hash_hmac(
        'sha256',
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        $key
    );
    file_put_contents(
        $markerPath,
        json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        LOCK_EX
    );
    $inspection = LocalMaintenanceCoordinator::inspectBeforeBootstrap(
        AppPaths::sharedRoot(),
        AppPaths::installationRoot()
    );
    $runtime = erp_prebootstrap_runtime_state();
    if (
        $inspection['valid']
        || $inspection['reason'] !== 'coordinator_expired'
        || erp_prebootstrap_runtime_mode($runtime) !== 'local_maintenance'
    ) {
        throw new RuntimeException('El coordinador vencido no abrió recuperación local segura.');
    }
    $reconciled = $coordinator->reconcileBackup($pdo);
    $renewed = LocalMaintenanceCoordinator::inspectBeforeBootstrap(
        AppPaths::sharedRoot(),
        AppPaths::installationRoot()
    );
    if (
        (string) $reconciled['state'] !== 'runnable'
        || !$renewed['valid']
        || (int) $renewed['backup_id'] !== $expired['id']
    ) {
        throw new RuntimeException('El coordinador vencido no se reconstruyó desde MariaDB.');
    }

    $alteredPayload = json_decode((string) file_get_contents($markerPath), true);
    if (!is_array($alteredPayload)) {
        throw new RuntimeException('No se pudo preparar el marcador alterado.');
    }
    $alteredPayload['generation'] = (int) ($alteredPayload['generation'] ?? 0) + 99;
    file_put_contents(
        $markerPath,
        json_encode($alteredPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        LOCK_EX
    );
    $alteredResult = $coordinator->reconcileBackup($pdo);
    $alteredInspection = LocalMaintenanceCoordinator::inspectBeforeBootstrap(
        AppPaths::sharedRoot(),
        AppPaths::installationRoot()
    );
    if (
        (string) $alteredResult['state'] !== 'blocked'
        || (string) $alteredResult['reason'] !== 'coordinator_guided_repair_required'
        || $alteredInspection['valid']
    ) {
        throw new RuntimeException(
            'Un marcador alterado fue renovado sin reparación administrativa.'
        );
    }

    foreach ($markers as $marker) {
        @unlink($marker);
    }
    $cancelled = fencingBackup(
        $pdo,
        $userId,
        'creating',
        'cancelled',
        json_encode(['format' => 3, 'stage' => 'dump', 'chunks' => []], JSON_THROW_ON_ERROR)
    );
    $createdBackups[] = $cancelled['id'];
    $recoverRejected = false;
    try {
        $center->recover($cancelled['id'], $userId);
    } catch (RuntimeException $error) {
        $recoverRejected = str_contains($error->getMessage(), 'trabajo ejecutable');
    }
    if (!$recoverRejected || is_file($markerPath)) {
        throw new RuntimeException('Recover anunció éxito para un job cancelado.');
    }

    $orphan = fencingBackup($pdo, $userId, 'queued', 'pending');
    $createdBackups[] = $orphan['id'];
    $safeId = substr(
        preg_replace('/[^a-f0-9]/i', '', $orphan['public_id']),
        0,
        24
    );
    $orphanPath = AppPaths::backups()
        . '/erp-v3-' . $safeId . '-g4-deadbeefcafe-chunk-00000001.bin';
    file_put_contents($orphanPath, random_bytes(96), LOCK_EX);
    $createdFiles[] = $orphanPath;
    $requested = $center->delete($orphan['id'], $userId);
    if (
        (string) $requested['status'] !== 'cancel_requested'
        || !is_file($orphanPath)
    ) {
        throw new RuntimeException('El fragmento huérfano fue tratado como una solicitud vacía.');
    }
    $cleaned = $center->processRequested();
    if ((string) ($cleaned['result'] ?? '') !== 'completed' || is_file($orphanPath)) {
        throw new RuntimeException('El cleanup cercado no retiró el fragmento huérfano.');
    }

    $generationA = AppPaths::backups()
        . '/erp-v3-' . $safeId . '-g7-aaaaaaaaaaaa-chunk-00000001.bin';
    $generationB = AppPaths::backups()
        . '/erp-v3-' . $safeId . '-g8-bbbbbbbbbbbb-chunk-00000001.bin';
    file_put_contents($generationA, random_bytes(64), LOCK_EX);
    file_put_contents($generationB, random_bytes(64), LOCK_EX);
    $createdFiles[] = $generationA;
    $createdFiles[] = $generationB;
    (new BackupArchiveV3Service())->cleanupExecutionArtifacts(
        $orphan['public_id'],
        'g7-aaaaaaaaaaaa'
    );
    if (is_file($generationA) || !is_file($generationB)) {
        throw new RuntimeException(
            'La limpieza de una generación alteró archivos de otro worker.'
        );
    }

    foreach ($markers as $marker) {
        @unlink($marker);
    }
    $global = fencingBackup($pdo, $userId, 'ready', 'completed');
    $createdBackups[] = $global['id'];
    $pdo->prepare(
        "UPDATE system_backup_archives
            SET verified_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 MINUTE),
                completed_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 MINUTE)
          WHERE id=:id"
    )->execute(['id' => $global['id']]);
    $analysis = (new DatabaseMaintenanceService())->analyze($userId);
    $createdSessions[] = (int) $analysis['id'];
    if (
        (int) ($analysis['backup_id'] ?? 0) !== 0
        || (int) ($analysis['plan']['backup_binding']['id'] ?? 0) !== 0
    ) {
        throw new RuntimeException('El análisis adoptó una copia global anterior.');
    }

    echo json_encode([
        'status' => 'PASS',
        'expired_marker_recovered' => true,
        'tampered_marker_requires_guided_repair' => true,
        'cancelled_job_rejected' => true,
        'orphan_fragment_cleaned' => true,
        'generation_cleanup_fenced' => true,
        'global_backup_not_bound' => true,
        'remote_transport' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    foreach ($createdFiles as $file) {
        @unlink($file);
    }
    foreach (array_reverse($createdSessions) as $sessionId) {
        $pdo->prepare(
            'DELETE FROM database_maintenance_sessions WHERE id=:id'
        )->execute(['id' => $sessionId]);
    }
    foreach (array_reverse($createdBackups) as $backupId) {
        $pdo->prepare(
            'DELETE FROM system_backup_archives WHERE id=:id'
        )->execute(['id' => $backupId]);
    }
    foreach ($savedMarkers as $path => $contents) {
        if (is_string($contents)) {
            file_put_contents($path, $contents, LOCK_EX);
        } else {
            @unlink($path);
        }
    }
}
