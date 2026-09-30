<?php

declare(strict_types=1);

require __DIR__ . '/k3_unit02_disposition_fixture.inc.php';

use App\QueueV4Clean\PackDiscoveryUnit02DispositionService;

$evidencePath = (string) ($argv[1] ?? '');
$startPath = (string) ($argv[2] ?? '');
$readyPath = (string) ($argv[3] ?? '');
if ($evidencePath === '' || $startPath === '' || $readyPath === '') {
    fwrite(STDERR, "k3_unit02_participant_arguments_required\n");
    exit(2);
}
try {
    $decoded = json_decode((string) file_get_contents($evidencePath), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('k3_unit02_participant_evidence_invalid');
    }
    file_put_contents($readyPath, "READY\n", LOCK_EX);
    $deadline = microtime(true) + 15.0;
    while (!is_file($startPath) && microtime(true) < $deadline) {
        usleep(20000);
    }
    if (!is_file($startPath)) {
        throw new RuntimeException('k3_unit02_participant_start_timeout');
    }
    $result = (new PackDiscoveryUnit02DispositionService($pdo))->apply(
        $decoded,
        5001,
        'Felipe approved the unique non-renewable UNIT-02 occupancy disposition.',
        true,
    );
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error::class . ':' . $error->getMessage() . PHP_EOL);
    exit(1);
}
