<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\AppPaths;
use App\Core\Database;
use App\Services\BackupArchiveV3Service;
use App\Services\BackupCenterService;
use App\Services\BackupMaintenanceRequestService;
use App\Services\LocalMaintenanceCoordinator;
$pdo = Database::connection();
$center = new BackupCenterService();
$requestService = new BackupMaintenanceRequestService();
$markerPaths = [
    AppPaths::storage('cache/backup-maintenance-request.json'),
    AppPaths::storage('cache/database-snapshot-active.json'),
    AppPaths::storage('cache/local-maintenance-state.json'),
];
$markerState = [];
$createdIds = [];
$createdFiles = [];
foreach ($markerPaths as $path) {
    $markerState[$path] = is_file($path) ? file_get_contents($path) : null;
    @unlink($path);
}

/** @return array{id:int,public_id:string} */
function backupLifecycleFixture(
    PDO $pdo,
    int $userId,
    string $status,
    string $jobStatus,
    ?string $leaseOwner = null,
    ?string $leaseExpiresAt = null,
    ?string $storageName = null
): array {
    $publicId = sprintf(
        '%s-%s-4%s-%s-%s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(2)),
        substr(bin2hex(random_bytes(2)), 1),
        substr('89ab', random_int(0, 3), 1) . substr(bin2hex(random_bytes(2)), 1),
        bin2hex(random_bytes(6))
    );
    $insert = $pdo->prepare(
        "INSERT INTO system_backup_archives
            (public_id,requested_by,purpose,status,erp_version,format_version,storage_name)
         VALUES (:public_id,:user_id,'manual',:status,'2.26.9',3,:storage_name)"
    );
    $insert->execute([
        'public_id' => $publicId,
        'user_id' => $userId,
        'status' => $status,
        'storage_name' => $storageName,
    ]);
    $id = (int) $pdo->lastInsertId();
    $job = $pdo->prepare(
        "INSERT INTO system_backup_jobs
            (backup_id,job_type,status,lease_owner,lease_generation,lease_expires_at)
         VALUES (:backup_id,'create',:status,:owner,7,:expires)"
    );
    $job->execute([
        'backup_id' => $id,
        'status' => $jobStatus,
        'owner' => $leaseOwner,
        'expires' => $leaseExpiresAt,
    ]);
    return ['id' => $id, 'public_id' => $publicId];
}

try {
    $userId = (int) $pdo->query(
        "SELECT id FROM users WHERE role='admin' AND status=1 AND is_temporary=0 ORDER BY id LIMIT 1"
    )->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('La prueba requiere un administrador permanente.');
    }

    $queued = backupLifecycleFixture($pdo, $userId, 'queued', 'pending');
    $createdIds[] = $queued['id'];
    (new BackupArchiveV3Service())->activateFreeze($queued['public_id']);
    $requestService->publish($queued['id'], $queued['public_id']);
    if (!$center->ownsControlMutation($queued['id'])) {
        throw new RuntimeException('El control estricto no reconoció la copia propietaria del freeze.');
    }
    $requested = $center->delete($queued['id'], $userId);
    if ($requested['status'] !== 'deleted') {
        throw new RuntimeException('La solicitud queued vacía no se canceló inmediatamente.');
    }
    $queuedState = $pdo->prepare(
        'SELECT status,deleted_at,storage_name FROM system_backup_archives WHERE id=?'
    );
    $queuedState->execute([$queued['id']]);
    $queuedRow = $queuedState->fetch(PDO::FETCH_ASSOC);
    if (
        !is_array($queuedRow)
        || $queuedRow['status'] !== 'deleted'
        || empty($queuedRow['deleted_at'])
        || $queuedRow['storage_name'] !== null
        || is_file($markerPaths[0])
        || is_file($markerPaths[1])
        || is_file($markerPaths[2])
    ) {
        throw new RuntimeException('La limpieza queued dejó base, request o freeze inconsistentes.');
    }

    $recoverable = backupLifecycleFixture($pdo, $userId, 'creating', 'pending');
    $createdIds[] = $recoverable['id'];
    $pdo->prepare("UPDATE system_backup_archives SET execution_mode='cli' WHERE id=?")
        ->execute([$recoverable['id']]);
    $pdo->prepare(
        "UPDATE system_backup_jobs
            SET checkpoint_json=:checkpoint
          WHERE backup_id=:backup_id AND job_type='create'"
    )->execute([
        'checkpoint' => json_encode([
            'format' => 3,
            'stage' => 'dump',
            'chunks' => [],
        ], JSON_THROW_ON_ERROR),
        'backup_id' => $recoverable['id'],
    ]);
    (new BackupArchiveV3Service())->activateFreeze($recoverable['public_id']);
    @unlink($markerPaths[0]);
    if (!$center->ownsControlMutation($recoverable['id'])) {
        throw new RuntimeException('No se pudo recuperar una señal ausente del snapshot propietario.');
    }
    $center->recover($recoverable['id'], $userId);
    $signal = LocalMaintenanceCoordinator::inspectBeforeBootstrap(
        AppPaths::sharedRoot(),
        AppPaths::installationRoot()
    );
    if (!$signal['valid'] || $signal['backup_id'] !== $recoverable['id']) {
        throw new RuntimeException('La recuperación no repuso la señal firmada exacta.');
    }
    $center->delete($recoverable['id'], $userId);
    $center->processRequested();

    $live = backupLifecycleFixture(
        $pdo,
        $userId,
        'creating',
        'running',
        'qa-old-worker',
        gmdate('Y-m-d H:i:s', time() + 120)
    );
    $createdIds[] = $live['id'];
    $pdo->prepare("UPDATE system_backup_archives SET execution_mode='cli' WHERE id=?")
        ->execute([$live['id']]);
    (new BackupArchiveV3Service())->activateFreeze($live['public_id']);
    (new LocalMaintenanceCoordinator())->publishBackup(
        $live['id'],
        $live['public_id'],
        'running',
        'qa-old-worker',
        1,
        'create',
        true
    );
    $center->delete($live['id'], $userId);
    $fenced = $pdo->prepare(
        "SELECT status,lease_generation FROM system_backup_jobs
          WHERE backup_id=? AND job_type='create'"
    );
    $fenced->execute([$live['id']]);
    $fencedRow = $fenced->fetch(PDO::FETCH_ASSOC);
    if (
        !is_array($fencedRow)
        || $fencedRow['status'] !== 'cancel_requested'
        || (int) $fencedRow['lease_generation'] !== 8
    ) {
        throw new RuntimeException('El worker anterior no quedó cercado.');
    }
    $waiting = $center->processRequested();
    if ((string) ($waiting['result'] ?? '') !== 'deferred') {
        throw new RuntimeException(
            'La limpieza no respetó el lease previo: '
            . json_encode($waiting, JSON_UNESCAPED_SLASHES)
        );
    }
    $pdo->prepare(
        "UPDATE system_backup_jobs SET available_at=UTC_TIMESTAMP(3)
          WHERE backup_id=? AND job_type='cleanup'"
    )->execute([$live['id']]);
    $done = $center->processRequested();
    if ((string) ($done['result'] ?? '') !== 'completed') {
        throw new RuntimeException('La limpieza no continuó después del cercado.');
    }

    $fileName = 'qa-backup-lifecycle-' . bin2hex(random_bytes(5)) . '.erpbackup';
    $filePath = AppPaths::backups() . '/' . $fileName;
    if (!is_dir(AppPaths::backups())) {
        mkdir(AppPaths::backups(), 0700, true);
    }
    file_put_contents($filePath, 'encrypted-fixture', LOCK_EX);
    $createdFiles[] = $filePath;
    $ready = backupLifecycleFixture($pdo, $userId, 'ready', 'completed', null, null, $fileName);
    $createdIds[] = $ready['id'];
    $pdo->prepare("UPDATE system_backup_archives SET execution_mode='cli' WHERE id=?")
        ->execute([$ready['id']]);
    $center->delete($ready['id'], $userId);
    $center->processRequested();
    if (is_file($filePath)) {
        throw new RuntimeException('El archivo cifrado ready no fue eliminado por cleanup.');
    }

    echo json_encode([
        'status' => 'PASS',
        'queued_without_storage' => 'deleted',
        'missing_signal' => 'recovered',
        'old_worker' => 'fenced',
        'live_lease' => 'respected',
        'ready_file' => 'deleted',
        'remote_transport' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    foreach ($createdFiles as $file) {
        @unlink($file);
    }
    foreach (array_reverse($createdIds) as $id) {
        $pdo->prepare('DELETE FROM system_backup_archives WHERE id=?')->execute([$id]);
    }
    foreach ($markerState as $path => $contents) {
        if (is_string($contents)) {
            file_put_contents($path, $contents, LOCK_EX);
        } else {
            @unlink($path);
        }
    }
}
