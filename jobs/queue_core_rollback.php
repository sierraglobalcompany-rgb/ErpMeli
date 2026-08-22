<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\QueueCoreRollbackService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

$rawArguments = $_SERVER['argv'] ?? [];
$arguments = is_array($rawArguments) ? array_map('strval', $rawArguments) : [];
if (!in_array('--prepare', $arguments, true)) {
    // The remaining diagnostic is intentionally read-only.
} else {
    fwrite(STDERR, "LEGACY_TOOL_BLOCKED component=queue_core_rollback remote=false http=0\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap.php';
$option = static function (string $name, ?string $default = null) use ($arguments): ?string {
    $prefix = '--' . $name . '=';
    foreach ($arguments as $argument) {
        if (str_starts_with((string) $argument, $prefix)) {
            return trim(substr((string) $argument, strlen($prefix)));
        }
    }
    return $default;
};

try {
    Database::useProfile('cli');
    $service = new QueueCoreRollbackService(Database::connectionFresh());
    $result = $service->preflight();
    fwrite(STDOUT, json_encode(['status' => 'read_only'] + $result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit($result['ok'] ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'status' => 'rollback_blocked',
        'error_class' => strtolower((new ReflectionClass($error))->getShortName()),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(2);
}
