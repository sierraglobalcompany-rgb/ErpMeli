<?php

declare(strict_types=1);

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: se requiere un DSN MySQL/MariaDB sin base seleccionada.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

$database = 'erp_collation_' . bin2hex(random_bytes(6));
$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

try {
    $version = strtolower((string) $server->query('SELECT VERSION()')->fetchColumn());
    $isMariaDb = str_contains($version, 'mariadb');
    $databaseCollation = $isMariaDb ? 'utf8mb4_uca1400_ai_ci' : 'utf8mb4_0900_ai_ci';
    $connectionCollation = $isMariaDb ? 'utf8mb4_unicode_ci' : 'utf8mb4_0900_as_ci';
    $server->exec(
        'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE ' . $databaseCollation
    );
    $pdo = new PDO(
        preg_replace('/;?charset=[^;]+/i', '', $dsn) . ';dbname=' . $database . ';charset=utf8mb4',
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec('SET NAMES utf8mb4 COLLATE ' . $connectionCollation);
    $pdo->exec('CREATE TABLE runtime_probe (id BIGINT UNSIGNED PRIMARY KEY, note VARCHAR(40))');

    $gateway = new \App\Services\InformationSchemaGateway($pdo);
    if (!$gateway->hasTable('runtime_probe')
        || !$gateway->hasColumn('runtime_probe', 'note')
        || $gateway->database() !== $database) {
        throw new RuntimeException('El gateway no pudo inspeccionar el esquema con collations mixtas.');
    }
    $snapshot = $gateway->schemaSnapshot();
    if (!isset($snapshot['runtime_probe']['columns']['note'])) {
        throw new RuntimeException('El snapshot binario perdió columnas del esquema.');
    }
    $batch = $gateway->columnNamesForTables(['runtime_probe', 'missing_probe']);
    if (array_keys($batch['runtime_probe'] ?? []) !== ['id', 'note']
        || ($batch['missing_probe'] ?? []) !== []) {
        throw new RuntimeException('La inspección por lote no respetó tablas presentes y ausentes.');
    }
    $inspector = new \App\Services\SchemaInspectorService($gateway);
    $missing = $inspector->missingRequirements([
        'runtime_probe' => ['id', 'note'],
        'missing_probe' => ['id'],
    ]);
    if ($missing !== ['missing_probe']) {
        throw new RuntimeException('El contrato por lote no clasificó correctamente el esquema real.');
    }
    fwrite(
        STDOUT,
        'OK: information_schema compatible con collations mixtas en '
        . ($isMariaDb ? 'MariaDB' : 'MySQL') . ".\n"
    );
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
