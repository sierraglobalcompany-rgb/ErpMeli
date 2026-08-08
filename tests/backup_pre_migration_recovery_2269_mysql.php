<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\AppPaths;
use App\Core\Database;
use App\Services\BackupArchiveV3Service;
use App\Services\BackupCenterService;
use App\Services\BackupMaintenanceRequestService;

$pdo = Database::connection();
$migrationInstalled = (int) $pdo->query(
    "SELECT COUNT(*) FROM schema_migrations
      WHERE version='157_backup_lifecycle_sanitation_bridge_2_26_9.sql'"
)->fetchColumn() > 0;
if ($migrationInstalled) {
    fwrite(STDERR, "La prueba requiere el esquema anterior a 157.\n");
    exit(1);
}

$paths = [
    AppPaths::storage('cache/backup-maintenance-request.json'),
    AppPaths::storage('cache/database-snapshot-active.json'),
    AppPaths::storage('cache/local-maintenance-state.json'),
];
$saved = [];
foreach ($paths as $path) {
    $saved[$path] = is_file($path) ? file_get_contents($path) : null;
    @unlink($path);
}
$backupId = 0;

try {
    $userId = (int) $pdo->query(
        "SELECT id FROM users
          WHERE role='admin' AND status=1 AND is_temporary=0
          ORDER BY id LIMIT 1"
    )->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('La prueba requiere un administrador permanente.');
    }
    $publicId = sprintf(
        '%08x-%04x-4%03x-8%03x-%012x',
        random_int(0, 0x7fffffff),
        random_int(0, 0xffff),
        random_int(0, 0xfff),
        random_int(0, 0xfff),
        random_int(0, 0x7fffffff)
    );
    $insert = $pdo->prepare(
        "INSERT INTO system_backup_archives
            (public_id,requested_by,purpose,status,erp_version,format_version)
         VALUES (:public_id,:user_id,'manual','queued','2.26.8',3)"
    );
    $insert->execute(['public_id' => $publicId, 'user_id' => $userId]);
    $backupId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO system_backup_jobs (backup_id,job_type,status)
         VALUES (:backup_id,'create','pending')"
    )->execute(['backup_id' => $backupId]);
    (new BackupArchiveV3Service())->activateFreeze($publicId);
    (new BackupMaintenanceRequestService())->publish($backupId, $publicId);

    $center = new BackupCenterService();
    $overview = $center->overview();
    if (
        (int) ($overview['active']['id'] ?? 0) !== $backupId
        || !$center->ownsControlMutation($backupId)
    ) {
        throw new RuntimeException('El esquema anterior no pudo presentar o autorizar la solicitud vacía.');
    }
    $result = $center->delete($backupId, $userId);
    if ((string) ($result['status'] ?? '') !== 'deleted') {
        throw new RuntimeException('La solicitud vacía anterior a 157 no fue cancelada.');
    }
    $state = $pdo->prepare(
        'SELECT status,deleted_at,storage_name FROM system_backup_archives WHERE id=:id'
    );
    $state->execute(['id' => $backupId]);
    $archive = $state->fetch(PDO::FETCH_ASSOC);
    $job = $pdo->prepare(
        'SELECT status,lease_generation FROM system_backup_jobs WHERE backup_id=:id'
    );
    $job->execute(['id' => $backupId]);
    $jobState = $job->fetch(PDO::FETCH_ASSOC);
    if (
        !is_array($archive)
        || (string) $archive['status'] !== 'deleted'
        || empty($archive['deleted_at'])
        || $archive['storage_name'] !== null
        || !is_array($jobState)
        || (string) $jobState['status'] !== 'failed'
        || (int) $jobState['lease_generation'] < 1
        || is_file($paths[0])
        || is_file($paths[1])
        || is_file($paths[2])
    ) {
        throw new RuntimeException('La cancelación anterior a 157 dejó estado, lease o marcadores inconsistentes.');
    }
    echo json_encode([
        'status' => 'PASS',
        'schema' => 'pre_157',
        'queued_without_file' => 'cancelled',
        'freeze_released' => true,
        'remote_transport' => false,
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($backupId > 0) {
        $pdo->prepare('DELETE FROM system_backup_archives WHERE id=?')->execute([$backupId]);
    }
    foreach ($saved as $path => $contents) {
        if (is_string($contents)) {
            file_put_contents($path, $contents, LOCK_EX);
        } else {
            @unlink($path);
        }
    }
}
