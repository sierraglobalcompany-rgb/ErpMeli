<?php
declare(strict_types=1);

$db = (string) getenv('R0_H3_DB_NAME') ?: '';
$port = (string) getenv('R0_H3_DB_PORT') ?: '';
$manifestPath = (string) getenv('R0_H3_LAB_MANIFEST');
if ($db === '' || $port === '' || $manifestPath === '' || !is_file($manifestPath)) {
    fwrite(STDERR, "r0_h3_lab_manifest_required\n");
    exit(90);
}
if (!preg_match('/^erp_meli_r0_h3_[a-z0-9_]{8,64}$/', $db) || $port !== '33191') {
    fwrite(STDERR, "r0_h3_lab_identity_invalid\n");
    exit(91);
}
$manifest = json_decode((string) file_get_contents($manifestPath), true);
if (!is_array($manifest)
    || ($manifest['owner'] ?? '') !== 'r0_h3_local_lab'
    || ($manifest['host'] ?? '') !== '127.0.0.1'
    || (string) ($manifest['port'] ?? '') !== $port
    || ($manifest['database'] ?? '') !== $db
    || !preg_match('/^[a-f0-9]{16,64}$/', (string) ($manifest['run_id'] ?? ''))) {
    fwrite(STDERR, "r0_h3_lab_manifest_invalid\n");
    exit(92);
}

$pdoRoot = new PDO('mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$exists = $pdoRoot->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=? LIMIT 1');
$exists->execute([$db]);
if ($exists->fetchColumn() !== false) {
    $marker = $pdoRoot->prepare(
        'SELECT 1
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA=? AND TABLE_NAME="r0_h3_lab_owner"
          LIMIT 1'
    );
    $marker->execute([$db]);
    if ($marker->fetchColumn() === false) {
        fwrite(STDERR, "r0_h3_lab_database_not_owned\n");
        exit(93);
    }
    $owner = $pdoRoot->prepare(
        'SELECT run_id FROM `' . str_replace('`', '``', $db) . '`.r0_h3_lab_owner
          WHERE owner="r0_h3_local_lab" LIMIT 1'
    );
    $owner->execute();
    if ((string) $owner->fetchColumn() !== (string) $manifest['run_id']) {
        fwrite(STDERR, "r0_h3_lab_owner_mismatch\n");
        exit(94);
    }
}
$pdoRoot->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $db) . '`');
$pdoRoot->exec('CREATE DATABASE `' . str_replace('`', '``', $db) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

require __DIR__ . '/r0_h3_bootstrap.php';

$result = (new App\Services\Migrator($pdo, $repoRoot . '/database/migrations'))->run(301);
$pdo->exec('CREATE TABLE IF NOT EXISTS r0_h3_lab_owner (owner VARCHAR(64) NOT NULL PRIMARY KEY, run_id VARCHAR(64) NOT NULL, created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$pdo->prepare('INSERT INTO r0_h3_lab_owner(owner,run_id) VALUES("r0_h3_local_lab",?) ON DUPLICATE KEY UPDATE run_id=VALUES(run_id)')
    ->execute([(string) $manifest['run_id']]);
echo json_encode(['database' => $db, 'migration' => $result], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
