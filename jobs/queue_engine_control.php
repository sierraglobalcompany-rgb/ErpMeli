<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

$arguments = is_array($_SERVER['argv'] ?? null) ? array_map('strval', $_SERVER['argv']) : [];
$option = static function (string $name) use ($arguments): ?string {
    $prefix = '--' . $name . '=';
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, $prefix)) {
            return trim(substr($argument, strlen($prefix)));
        }
    }
    return null;
};
$desired = $option('set');
$readiness = $option('readiness');
if ($readiness !== null || $desired !== null) {
    echo json_encode([
        'ok' => false,
        'status' => 'legacy_engine_activation_retired',
        'component' => 'queue_engine_control',
        'remote' => false,
        'http' => 0,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    // A retired activation is an acknowledged no-op, not a failed runtime
    // command. Keeping exit 0 preserves fail-closed callers without giving
    // automation an error path it could retry.
    exit(0);
}

require dirname(__DIR__) . '/bootstrap.php';

$result = (new \App\QueueCore\QueueEngineControlCli())->run(
    is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : []
);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(!empty($result['ok']) ? 0 : 2);
