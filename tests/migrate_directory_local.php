<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$path = trim((string) ($_SERVER['argv'][1] ?? ''));
if ($path === '' || !is_dir($path)) {
    fwrite(STDERR, "Indique un directorio local de migraciones.\n");
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

$migrator = new \App\Services\Migrator(\App\Core\Database::connection(), $path);
foreach ($migrator->run() as $result) {
    echo $result['status'] . ' ' . $result['version'] . PHP_EOL;
}
