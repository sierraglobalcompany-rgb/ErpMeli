<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

require __DIR__ . '/_bootstrap.php';

\App\Core\Database::useProfile('diagnostic');

$snapshot = (new \App\Services\CronV3OperationalSnapshotService())->snapshot();
echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit(($snapshot['snapshot_state'] ?? '') === 'unavailable' ? 2 : 0);
