<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(2);
}

require __DIR__ . '/_bootstrap.php';

\App\Core\Database::useProfile('diagnostic');

$json = in_array('--json', is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [], true);
$doctor = new \App\Services\CronV3DoctorService();
$local = $doctor->snapshot('local');
$remote = $doctor->snapshot('remote');
$operational = (new \App\Services\CronV3OperationalSnapshotService())->snapshot();
$ok = !empty($local['ok']) && !empty($remote['ok']) && (($operational['snapshot_state'] ?? '') !== 'unavailable');
$payload = [
    'ok' => $ok,
    'read_only' => true,
    'local' => $local,
    'remote' => $remote,
    'operational' => [
        'state' => $operational['state'] ?? 'unavailable',
        'label' => $operational['state_label'] ?? 'No se pudo comprobar',
        'totals' => $operational['totals'] ?? [],
        'measured_at' => $operational['measured_at'] ?? null,
    ],
];

if ($json) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    echo 'CRON_V3_DOCTOR ok=' . ($ok ? 'true' : 'false')
        . ' state=' . (string) $payload['operational']['state']
        . ' read_only=true' . PHP_EOL;
}

exit($ok ? 0 : 2);
