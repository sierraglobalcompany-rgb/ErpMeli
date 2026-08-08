<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Database;
use App\Services\RestoreService;

Database::useProfile('cli');
$pdo = Database::connection();
$backupId = (int) $pdo->query(
    "SELECT id FROM system_backup_archives WHERE status='ready' ORDER BY id DESC LIMIT 1"
)->fetchColumn();
$userId = (int) $pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn();
if ($backupId < 1 || $userId < 1) {
    throw new RuntimeException('Falta una copia o un administrador para la prueba.');
}

$service = new RestoreService();
$plan = $service->prepare($backupId, $userId, 'diagnostic_clone', [
    'host' => '127.0.0.1',
    'port' => '33316',
    'database' => 'erpmeli_restore_test',
    'user' => 'root',
    'password' => '',
]);

$last = [];
for ($attempt = 0; $attempt < 100; $attempt++) {
    $last = $service->processRequested();
    if (($last['result'] ?? '') === 'completed') {
        break;
    }
}
if (($last['result'] ?? '') !== 'completed') {
    throw new RuntimeException('La restauración no terminó dentro del límite de ciclos.');
}

$clone = new PDO(
    'mysql:host=127.0.0.1;port=33316;dbname=erpmeli_restore_test;charset=utf8mb4',
    'root',
    '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$orders = (int) $clone->query('SELECT COUNT(*) FROM meli_orders')->fetchColumn();
$tokens = (int) $clone->query('SELECT COUNT(*) FROM meli_tokens')->fetchColumn();
$connected = (int) $clone->query("SELECT COUNT(*) FROM meli_accounts WHERE status='conectado'")->fetchColumn();
if ($orders < 1 || $tokens !== 0 || $connected !== 0) {
    throw new RuntimeException('El clon diagnóstico no quedó sanitizado correctamente.');
}

echo json_encode([
    'ok' => true,
    'restore_id' => (int) $plan['id'],
    'orders' => $orders,
    'tokens' => $tokens,
    'connected_accounts' => $connected,
], JSON_THROW_ON_ERROR) . PHP_EOL;
