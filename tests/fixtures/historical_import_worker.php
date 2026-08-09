<?php

declare(strict_types=1);

use App\QueueCore\HistoricalAdmissionPolicy;
use App\QueueCore\HistoricalBacklogImporter;
use App\QueueCore\HistoricalBacklogSourceRegistry;
use App\QueueCore\QueueCoreRepository;

require dirname(__DIR__, 2) . '/bootstrap.php';

$dsn = (string) (getenv('QUEUE_CORE_TEST_DSN') ?: '');
$user = (string) (getenv('QUEUE_CORE_TEST_USER') ?: '');
$pass = (string) (getenv('QUEUE_CORE_TEST_PASS') ?: '');
$startAt = (int) ($argv[1] ?? 0);
while ((int) floor(microtime(true) * 1000) < $startAt) {
    usleep(1000);
}
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("SET time_zone='+00:00'");
$result = (new HistoricalBacklogImporter(
    $pdo,
    new QueueCoreRepository($pdo),
    new HistoricalBacklogSourceRegistry(),
    new HistoricalAdmissionPolicy(10),
))->run('notification_orders', 1, 1, 50);
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);
