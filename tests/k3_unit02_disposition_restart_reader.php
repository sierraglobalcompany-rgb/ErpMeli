<?php

declare(strict_types=1);

require __DIR__ . '/k3_unit02_disposition_fixture.inc.php';

use App\QueueV4Clean\PackDiscoveryOccupancyPolicy;

$pdo->beginTransaction();
try {
    $metrics = (new PackDiscoveryOccupancyPolicy($pdo))->measureForAccounts(k3u2_scope());
    $pdo->commit();
    echo json_encode($metrics, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $error::class . ':' . $error->getMessage() . PHP_EOL);
    exit(1);
}
