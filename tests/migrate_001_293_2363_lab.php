<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\Migrator;

require dirname(__DIR__) . '/bootstrap.php';
restore_exception_handler();

try {
    $results = (new Migrator(Database::connection(), dirname(__DIR__) . '/database/migrations'))->run();
    $pdo = Database::connection();
    $count = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    $users = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users'"
    )->fetchColumn();
    if ($count !== 293 || $users !== 1) {
        throw new RuntimeException('schema_materialization_incomplete');
    }
    fwrite(STDOUT, 'Schema 001-293: PASS applied=' . count($results) . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, 'Schema 001-293: FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

