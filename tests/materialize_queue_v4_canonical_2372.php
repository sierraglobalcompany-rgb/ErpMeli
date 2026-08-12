<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\Migrator;

require dirname(__DIR__) . '/bootstrap.php';
restore_exception_handler();

$migrationPath = (string) (getenv('QUEUE_V4_CANONICAL_MIGRATIONS') ?: '');
if ($migrationPath === '' || !is_dir($migrationPath)) {
    fwrite(STDERR, "QUEUE_V4_CANONICAL_MIGRATIONS is required\n");
    exit(2);
}

try {
    $pdo = Database::connectionFresh();
    $results = (new Migrator($pdo, $migrationPath))->run();
    $count = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    $required = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=?');
    $required->execute(['294_queue_v4_clean_greenfield_2_37_0.sql']);
    $columns = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations'
         ORDER BY ORDINAL_POSITION"
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($count !== 294 || (int) $required->fetchColumn() !== 1 || $columns !== ['version', 'applied_at']) {
        throw new RuntimeException('canonical_schema_materialization_invalid');
    }
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'applied_results' => count($results),
        'schema_migrations_count' => $count,
        'schema_migrations_columns' => $columns,
        'required_queue_v4_migration_present' => true,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
