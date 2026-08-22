<?php

declare(strict_types=1);

// Queue V4 is the only production automation entrypoint. This legacy name is
// retained exclusively for read-only diagnosis and fake-transport QA.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$args = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
$blockedFlags = [
    '--retention-step',
    '--record',
    '--persist',
    '--execute',
    '--set',
    '--rollback',
    '--prepare',
    '--manual',
];
foreach ($args as $argument) {
    foreach ($blockedFlags as $flag) {
        if ($argument === $flag || str_starts_with((string) $argument, $flag . '=')) {
            fwrite(
                STDERR,
                'LEGACY_AUTOMATION_BLOCKED component=process_sync_queue flag='
                . preg_replace('/[^A-Za-z0-9_.-]/', '_', $flag) . " remote=false http=0\n"
            );
            exit(2);
        }
    }
}

$allowedArguments = ['--doctor', '--json', '--qa-replay', '--fake-transport'];
foreach (array_slice($args, 1) as $argument) {
    $argument = (string) $argument;
    if (in_array($argument, $allowedArguments, true)
        || preg_match('/^--cycles=[1-9][0-9]*$/', $argument) === 1) {
        continue;
    }
    fwrite(STDERR, "LEGACY_AUTOMATION_BLOCKED component=process_sync_queue flag=unknown remote=false http=0\n");
    exit(2);
}

$doctorMode = in_array('--doctor', $args, true);
$qaReplayMode = in_array('--qa-replay', $args, true);
$jsonOutput = in_array('--json', $args, true);

if (($doctorMode && $qaReplayMode) || (!$qaReplayMode && array_filter(
    $args,
    static fn (mixed $argument): bool => str_starts_with((string) $argument, '--cycles=')
))) {
    fwrite(STDERR, "LEGACY_AUTOMATION_BLOCKED component=process_sync_queue flag=mode remote=false http=0\n");
    exit(2);
}

if ($doctorMode) {
    require __DIR__ . '/_bootstrap.php';
    $doctor = new \App\Services\CronDoctorService();
    if ($jsonOutput) {
        echo json_encode(
            $doctor->snapshot('legacy-doctor'),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ) . PHP_EOL;
    } else {
        echo $doctor->textReport();
    }
    exit(0);
}

if ($qaReplayMode) {
    require __DIR__ . '/_bootstrap.php';
    $cycles = 60;
    foreach ($args as $arg) {
        if (str_starts_with((string) $arg, '--cycles=')) {
            $cycles = max(1, min(240, (int) substr((string) $arg, 9)));
        }
    }
    $fakeTransport = in_array('--fake-transport', $args, true)
        || filter_var((string) getenv('ERP_FAKE_MELI_TRANSPORT'), FILTER_VALIDATE_BOOL);
    echo json_encode([
        'ok' => $fakeTransport,
        'mode' => 'qa-replay',
        'cycles_requested' => $cycles,
        'fake_transport' => $fakeTransport,
        'read_only_preflight' => (new \App\Services\CronDoctorService())->snapshot('legacy-qa-preflight'),
        'message' => $fakeTransport
            ? 'Preflight listo. Use únicamente el arnés QA dedicado con transporte Mercado Libre falso.'
            : 'QA replay bloqueado: active --fake-transport o ERP_FAKE_MELI_TRANSPORT=true.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($fakeTransport ? 0 : 2);
}

// Normal invocation must not load bootstrap, PDO, locks, health rows or HTTP.
echo 'ERP_CRON_SKIP component=process_sync_queue reason=LEGACY_AUTOMATION_RETIRED remote=false http=0' . PHP_EOL;
exit(0);
