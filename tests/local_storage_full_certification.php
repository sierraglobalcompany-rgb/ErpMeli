<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

$payloadTotal = 0;
for ($iteration = 1; $iteration <= 120; $iteration++) {
    $result = (new App\Services\RemotePayloadMigrationService())->migrateBatch(500);
    $payloadTotal += (int) $result['processed'];
    echo 'payload iteration=' . $iteration
        . ' processed=' . (int) $result['processed']
        . ' skipped=' . (int) $result['skipped']
        . ' errors=' . (int) $result['errors']
        . ' total=' . $payloadTotal . PHP_EOL;
    if ((int) $result['processed'] === 0 && (int) $result['errors'] === 0) {
        break;
    }
    if ((int) $result['errors'] > 0) {
        throw new RuntimeException('La externalización local encontró errores.');
    }
}

$retentionDeleted = 0;
$retentionArchives = 0;
for ($iteration = 1; $iteration <= 40; $iteration++) {
    $result = (new App\Services\RetentionPolicyService())->run(5000);
    $retentionDeleted += (int) $result['deleted'];
    $retentionArchives += (int) $result['archives'];
    echo 'retention iteration=' . $iteration
        . ' deleted=' . (int) $result['deleted']
        . ' archives=' . (int) $result['archives']
        . ' errors=' . (int) $result['errors'] . PHP_EOL;
    if ((int) $result['errors'] > 0) {
        throw new RuntimeException('La retención local encontró errores.');
    }
    if ((int) $result['deleted'] === 0 && (int) $result['archives'] === 0) {
        break;
    }
}

echo 'local_storage_certified payloads=' . $payloadTotal
    . ' deleted=' . $retentionDeleted
    . ' archives=' . $retentionArchives . PHP_EOL;
