<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\Services\BackupCenterService;

Database::useProfile('cli');
$pdo = Database::connection();
$userId = (int) $pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn();
if ($userId < 1) {
    throw new RuntimeException('El clon no contiene un administrador para la prueba.');
}

$center = new BackupCenterService();
$queued = $center->enqueue($userId, 'manual', 'general', null, 'cli');
$result = [];
for ($attempt = 0; $attempt < 2000; $attempt++) {
    $result = $center->processRequested();
    if (($result['result'] ?? '') === 'completed') {
        break;
    }
    if (($result['result'] ?? '') !== 'partial') {
        throw new RuntimeException('La copia dejó de avanzar de forma reanudable.');
    }
}
$overview = $center->overview();
$latest = is_array($overview['latest'] ?? null) ? $overview['latest'] : [];

if ((string) ($result['result'] ?? '') !== 'completed' || (string) ($latest['status'] ?? '') !== 'ready') {
    throw new RuntimeException('La copia no llegó al estado verificado.');
}
if ((int) ($latest['table_count'] ?? 0) < 1 || (int) ($latest['row_count'] ?? 0) < 1) {
    throw new RuntimeException('La copia no contiene los conteos esperados.');
}

echo json_encode([
    'ok' => true,
    'backup_id' => (int) $queued['id'],
    'tables' => (int) $latest['table_count'],
    'rows' => (int) $latest['row_count'],
], JSON_THROW_ON_ERROR) . PHP_EOL;
