<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\Migrator;

require dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

foreach ((new Migrator(Database::connection(), dirname(__DIR__) . '/database/migrations'))->run() as $result) {
    echo $result['status'] . ' ' . $result['version'] . "\n";
}
