<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanScheduler;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

$options = getopt('', ['runtime::', 'max-jobs::']);
$runtime = max(5, min(45, (int) ($options['runtime'] ?? 45)));
$maxJobs = max(1, min(3, (int) ($options['max-jobs'] ?? 3)));

try {
    Database::useProfile('cli');
    $result = (new QueueV4CleanScheduler(Database::connectionFresh()))->run($maxJobs, $runtime);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable) {
    fwrite(STDERR, "QUEUE_V4_CLEAN_FAILED\n");
    exit(1);
}
