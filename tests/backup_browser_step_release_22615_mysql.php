<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\AppPaths;
use App\Core\Database;
use App\Services\BackupArchiveV3Service;
use App\Services\BackupCenterService;

$pdo = Database::connection();
$center = new BackupCenterService();
$markerPaths = [
    AppPaths::storage('cache/backup-maintenance-request.json'),
    AppPaths::storage('cache/database-snapshot-active.json'),
    AppPaths::storage('cache/local-maintenance-state.json'),
];
$markerState = [];
foreach ($markerPaths as $path) {
    $markerState[$path] = is_file($path) ? file_get_contents($path) : null;
    @unlink($path);
}

$backupId = null;

try {
    $active = $pdo->query(
        "SELECT id,status FROM system_backup_archives
          WHERE deleted_at IS NULL
            AND status IN ('prepared','queued','creating','verifying','ready_pending_release','cancel_requested','deleting')
          ORDER BY id LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (is_array($active)) {
        throw new RuntimeException(
            'La prueba requiere una base sin copias activas; quedó activa la copia #'
            . (string) $active['id'] . ' (' . (string) $active['status'] . ').'
        );
    }

    $userId = (int) $pdo->query(
        "SELECT id FROM users WHERE role='admin' AND status=1 AND is_temporary=0 ORDER BY id LIMIT 1"
    )->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('La prueba requiere un administrador permanente.');
    }

    $created = $center->enqueue($userId, 'manual', 'general', null);
    $backupId = (int) $created['id'];
    $result = $center->processInteractiveStep($backupId, $userId);
    if ((string) ($result['result'] ?? '') !== 'partial') {
        throw new RuntimeException(
            'El primer micro-paso debía quedar parcial, resultado: '
            . json_encode($result, JSON_UNESCAPED_SLASHES)
        );
    }

    $stmt = $pdo->prepare(
        "SELECT status,lease_owner,lease_expires_at,checkpoint_json
           FROM system_backup_jobs
          WHERE backup_id=? AND job_type='create'
          LIMIT 1"
    );
    $stmt->execute([$backupId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (
        !is_array($job)
        || (string) $job['status'] !== 'pending'
        || $job['lease_owner'] !== null
        || $job['lease_expires_at'] !== null
    ) {
        throw new RuntimeException(
            'El micro-paso parcial no liberó el lease: '
            . json_encode($job, JSON_UNESCAPED_SLASHES)
        );
    }

    echo json_encode([
        'status' => 'PASS',
        'backup_id' => $backupId,
        'partial_step' => 'released',
        'remote_transport' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($backupId !== null) {
        $stmt = $pdo->prepare(
            "SELECT checkpoint_json FROM system_backup_jobs WHERE backup_id=? AND job_type='create' LIMIT 1"
        );
        $stmt->execute([$backupId]);
        $checkpoint = json_decode((string) $stmt->fetchColumn(), true);
        if (is_array($checkpoint)) {
            (new BackupArchiveV3Service())->cleanup($checkpoint);
        }
        $pdo->prepare('DELETE FROM system_backup_archives WHERE id=?')->execute([$backupId]);
    }
    foreach ($markerState as $path => $contents) {
        if (is_string($contents)) {
            file_put_contents($path, $contents, LOCK_EX);
        } else {
            @unlink($path);
        }
    }
}
