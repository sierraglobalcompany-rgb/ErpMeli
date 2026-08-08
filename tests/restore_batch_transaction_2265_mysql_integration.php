<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;
use App\Services\RestoreService;

Database::useProfile('cli');
$pdo = Database::connection();
$pdo->exec(
    'CREATE TEMPORARY TABLE restore_batch_2265_test (
        id INT NOT NULL PRIMARY KEY,
        value_text VARCHAR(40) NOT NULL
    ) ENGINE=InnoDB'
);

$method = new ReflectionMethod(RestoreService::class, 'executeRestoreStatements');
$service = new RestoreService();
$method->invoke($service, $pdo, [
    "INSERT INTO restore_batch_2265_test (id,value_text) VALUES (1,'uno')",
    "INSERT INTO restore_batch_2265_test (id,value_text) VALUES (1,'uno')",
]);
if ((int) $pdo->query('SELECT COUNT(*) FROM restore_batch_2265_test')->fetchColumn() !== 1) {
    throw new RuntimeException('Repetir un lote confirmado duplicó datos.');
}

try {
    $method->invoke($service, $pdo, [
        "INSERT INTO restore_batch_2265_test (id,value_text) VALUES (2,'dos')",
        "INSERT INTO restore_batch_2265_test (missing_column) VALUES ('fallo')",
    ]);
    throw new RuntimeException('El lote inválido no fue rechazado.');
} catch (ReflectionException $error) {
    throw $error;
} catch (Throwable) {
    // Se espera la excepción SQL; la transacción completa debe retroceder.
}
if (
    (int) $pdo->query(
        'SELECT COUNT(*) FROM restore_batch_2265_test WHERE id=2'
    )->fetchColumn() !== 0
) {
    throw new RuntimeException('Un lote fallido dejó una escritura parcial.');
}

echo json_encode([
    'status' => 'PASS',
    'transactional_batch' => true,
    'idempotent_retry' => true,
    'remote_transport' => false,
], JSON_THROW_ON_ERROR) . PHP_EOL;
