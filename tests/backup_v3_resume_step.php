<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\Services\BackupCenterService;

Database::useProfile('cli');
$center = new BackupCenterService();
$action = (string) ($argv[1] ?? 'step');
if ($action === 'enqueue') {
    $userId = (int) Database::connection()->query(
        "SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1"
    )->fetchColumn();
    if ($userId < 1) {
        throw new RuntimeException('No existe administrador local para la prueba.');
    }
    $queued = $center->enqueue($userId);
    $result = $center->processRequested();
    echo json_encode([
        'backup_id' => $queued['id'],
        'result' => $result['result'] ?? 'unknown',
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit;
}
$result = $center->processRequested();
echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
