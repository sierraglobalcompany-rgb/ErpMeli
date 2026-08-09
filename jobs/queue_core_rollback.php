<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\QueueCoreRollbackService;

require dirname(__DIR__) . '/bootstrap.php';

$rawArguments = $_SERVER['argv'] ?? [];
$arguments = is_array($rawArguments) ? array_map('strval', $rawArguments) : [];
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
    if (!in_array('--prepare', $arguments, true)) {
        $result = $service->preflight();
        fwrite(STDOUT, json_encode(['status' => 'read_only'] + $result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
        exit($result['ok'] ? 0 : 2);
    }
    $expected = $option('expected-generation');
    if ($expected === null || !ctype_digit($expected)) {
        fwrite(STDOUT, "{\"ok\":false,\"status\":\"expected_generation_required\"}\n");
        exit(2);
    }
    $result = $service->prepare((int) $expected, (int) $option('limit', '50'));
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit($result['ok'] ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'status' => 'rollback_blocked',
        'error_class' => strtolower((new ReflectionClass($error))->getShortName()),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(2);
}
