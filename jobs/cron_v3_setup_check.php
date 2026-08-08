<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

require __DIR__ . '/_bootstrap.php';

\App\Core\Database::useProfile('diagnostic');

$json = in_array('--json', is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [], true);
$snapshot = (new \App\Services\CronV3SetupAssistantService())->snapshot();

if ($json) {
    echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    echo 'CRON_V3_SETUP state=' . (string) ($snapshot['state'] ?? 'unknown')
        . ' read_only=true ml_write_enabled=' . (!empty($snapshot['ml_write_enabled']) ? 'true' : 'false') . PHP_EOL;
    foreach ((array) ($snapshot['blocking'] ?? []) as $item) {
        echo 'BLOCK ' . (string) $item . PHP_EOL;
    }
    if ((array) ($snapshot['blocking'] ?? []) === []) {
        echo 'OK cron_v3_setup' . PHP_EOL;
    }
}

exit(!empty($snapshot['ok']) ? 0 : 2);
