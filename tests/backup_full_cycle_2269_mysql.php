<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\AppPaths;
use App\Core\Database;
use App\Core\Env;
use App\Services\BackupArchiveService;
use App\Services\BackupCenterService;
use App\Services\DatabaseMaintenanceService;
use App\Services\LocalMaintenanceCoordinator;

$pdo = Database::connection();
$center = new BackupCenterService();
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
$started = microtime(true);
$backupId = 0;
$sessionId = 0;

try {
    if ((string) Env::get('APP_ENV', '') !== 'local') {
        throw new RuntimeException('La certificación completa solo puede ejecutarse en localhost.');
    }
    if ($center->active() !== null) {
        throw new RuntimeException('La certificación requiere que no exista otra copia activa.');
    }
    $userId = (int) $pdo->query(
        "SELECT id FROM users
          WHERE role='admin' AND status=1 AND is_temporary=0
          ORDER BY id LIMIT 1"
    )->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('La certificación requiere un administrador permanente.');
    }
    foreach ($center->overview()['archives'] as $previousArchive) {
        if (
            (string) ($previousArchive['status'] ?? '') !== 'ready'
            || (string) ($previousArchive['purpose'] ?? '') !== 'manual'
        ) {
            continue;
        }
        $center->delete((int) $previousArchive['id'], $userId);
        for ($cleanupCycle = 0; $cleanupCycle < 10; $cleanupCycle++) {
            (new LocalMaintenanceCoordinator())->reconcileBackup($pdo);
            $cleanupResult = $center->processRequested();
            (new LocalMaintenanceCoordinator())->reconcileBackup($pdo);
            if ((string) ($cleanupResult['result'] ?? '') === 'completed') {
                break;
            }
        }
    }
    $maintenance = new DatabaseMaintenanceService();
    $analysis = $maintenance->analyze($userId);
    $sessionId = (int) ($analysis['id'] ?? 0);
    if ($sessionId < 1) {
        throw new RuntimeException('No fue posible crear el análisis previo.');
    }
    $created = $center->enqueue(
        $userId,
        'manual',
        'database_sanitation',
        $sessionId
    );
    $backupId = (int) $created['id'];
    $coordinator = new LocalMaintenanceCoordinator();
    $lostSignalRecovered = false;
    $releasePhaseObserved = false;
    $cycles = 0;
    while ($cycles++ < 2000) {
        $reconciliation = $coordinator->reconcileBackup($pdo);
        if (!in_array(
            (string) ($reconciliation['state'] ?? ''),
            ['runnable', 'terminal_reconciled'],
            true
        )) {
            throw new RuntimeException(
                'El coordinador bloqueó el ciclo: '
                . (string) ($reconciliation['reason'] ?? 'unknown')
            );
        }
        if ((string) $reconciliation['state'] === 'terminal_reconciled') {
            break;
        }
        $result = $center->processRequested();
        $state = $pdo->prepare(
            'SELECT status,storage_name,size_bytes,checksum_sha256,manifest_sha256
               FROM system_backup_archives WHERE id=:id'
        );
        $state->execute(['id' => $backupId]);
        $archive = $state->fetch(PDO::FETCH_ASSOC);
        if (!is_array($archive)) {
            throw new RuntimeException('La copia desapareció durante su ejecución.');
        }
        if ((string) $archive['status'] === 'ready_pending_release') {
            $releasePhaseObserved = true;
            if ((string) ($result['result'] ?? '') !== 'partial') {
                throw new RuntimeException('La fase de liberación no quedó reanudable.');
            }
        }
        if (!$lostSignalRecovered && $cycles > 1 && (string) $archive['status'] === 'creating') {
            @unlink($markers[2]);
            $recovered = $coordinator->reconcileBackup($pdo);
            if (
                (string) ($recovered['state'] ?? '') !== 'runnable'
                || !is_file($markers[2])
            ) {
                throw new RuntimeException('La señal perdida no se reconstruyó desde el checkpoint.');
            }
            $lostSignalRecovered = true;
        }
        $post = $coordinator->reconcileBackup($pdo);
        if ((string) $archive['status'] === 'ready') {
            if (!in_array(
                (string) ($post['state'] ?? ''),
                ['terminal_reconciled', 'idle'],
                true
            )) {
                throw new RuntimeException('La copia lista no liberó su coordinación.');
            }
            break;
        }
    }
    $archive = $center->archive($backupId);
    if ((string) $archive['status'] !== 'ready') {
        throw new RuntimeException('La copia no terminó dentro del límite de ciclos.');
    }
    if (!$lostSignalRecovered || !$releasePhaseObserved) {
        throw new RuntimeException('La certificación no recorrió recuperación y liberación en dos fases.');
    }
    $path = $center->pathForArchive($archive);
    $verified = (new BackupArchiveService())->verify($path);
    if (
        !hash_equals((string) $archive['checksum_sha256'], (string) hash_file('sha256', $path))
        || !hash_equals(
            (string) $archive['manifest_sha256'],
            (string) $verified['manifest_checksum']
        )
    ) {
        throw new RuntimeException('La copia completa no coincide con su evidencia.');
    }
    $bound = $pdo->prepare(
        'SELECT backup_id,plan_json FROM database_maintenance_sessions WHERE id=:id'
    );
    $bound->execute(['id' => $sessionId]);
    $session = $bound->fetch(PDO::FETCH_ASSOC);
    $plan = is_array($session)
        ? json_decode((string) $session['plan_json'], true)
        : null;
    if (
        !is_array($session)
        || (int) $session['backup_id'] !== $backupId
        || (int) ($plan['backup_binding']['id'] ?? 0) !== $backupId
    ) {
        throw new RuntimeException('La copia no quedó fijada a su análisis.');
    }
    foreach ($markers as $marker) {
        if (is_file($marker)) {
            throw new RuntimeException('La copia lista dejó una protección física activa.');
        }
    }
    $chunkPrefix = AppPaths::backups() . '/erp-v3-'
        . substr(preg_replace('/[^a-f0-9]/i', '', (string) $archive['public_id']), 0, 24)
        . '-*';
    $leftovers = array_values(array_filter(
        glob($chunkPrefix) ?: [],
        static fn (string $candidate): bool =>
            basename($candidate) !== basename((string) $archive['storage_name'])
    ));
    if ($leftovers !== []) {
        throw new RuntimeException('La copia lista dejó fragmentos temporales.');
    }
    echo json_encode([
        'status' => 'PASS',
        'backup_id' => $backupId,
        'maintenance_session_id' => $sessionId,
        'cycles' => $cycles,
        'tables' => (int) $archive['table_count'],
        'rows' => (int) $archive['row_count'],
        'bytes' => (int) $archive['size_bytes'],
        'seconds' => round(microtime(true) - $started, 3),
        'lost_signal_recovered' => true,
        'two_phase_release' => true,
        'sanitation_binding' => true,
        'remote_transport' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    foreach ($savedMarkers as $path => $contents) {
        if (is_string($contents)) {
            file_put_contents($path, $contents, LOCK_EX);
        } else {
            @unlink($path);
        }
    }
}
