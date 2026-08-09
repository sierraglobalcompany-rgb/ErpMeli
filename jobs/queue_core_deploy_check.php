<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\QueueCoreDeploymentGateService;

require dirname(__DIR__) . '/bootstrap.php';

$rawArguments = $_SERVER['argv'] ?? [];
$arguments = is_array($rawArguments) ? array_map('strval', $rawArguments) : [];
$option = static function (string $name) use ($arguments): ?string {
    $prefix = '--' . $name . '=';
    foreach ($arguments as $argument) {
        if (str_starts_with((string) $argument, $prefix)) {
            return trim(substr((string) $argument, strlen($prefix)));
        }
    }
    return null;
};

try {
    Database::useProfile('cli');
    $result = (new QueueCoreDeploymentGateService(Database::connectionFresh()))->inspect(
        $option('backup'),
        $option('sha256'),
    );
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit($result['ok'] ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDOUT, json_encode([
        'ok' => false,
        'issues' => ['preflight_unavailable'],
        'error_class' => strtolower((new ReflectionClass($error))->getShortName()),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(2);
}
