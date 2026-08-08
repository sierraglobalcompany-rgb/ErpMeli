<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

require __DIR__ . '/_bootstrap.php';

exit(\App\Services\CronV3Cli::run('local', is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : []));
