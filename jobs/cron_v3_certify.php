<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

require __DIR__ . '/_bootstrap.php';

\App\Core\Database::useProfile('diagnostic');

$cycles = 60;
$fake = false;
foreach (is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [] as $arg) {
    if (str_starts_with((string) $arg, '--cycles=')) {
        $cycles = max(1, min(120, (int) substr((string) $arg, 9)));
    }
    if ($arg === '--fake-transport') {
        $fake = true;
    }
}

if (!$fake) {
    echo json_encode([
        'ok' => false,
        'reason' => 'fake_transport_required',
        'message' => 'La certificación local exige --fake-transport para garantizar cero llamadas reales.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(2);
}

$snapshot = (new \App\Services\CronV3OperationalSnapshotService())->snapshot();
echo json_encode([
    'ok' => ($snapshot['snapshot_state'] ?? '') !== 'unavailable',
    'mode' => 'certification_dry_run',
    'cycles_requested' => $cycles,
    'fake_transport' => true,
    'read_only' => true,
    'snapshot_state' => $snapshot['snapshot_state'] ?? 'unavailable',
    'totals' => $snapshot['totals'] ?? [],
    'message' => 'Gate preparado. La ejecución de 60 ciclos reales se mantiene como paso de QA con DSN local restaurado.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit(($snapshot['snapshot_state'] ?? '') === 'unavailable' ? 2 : 0);
