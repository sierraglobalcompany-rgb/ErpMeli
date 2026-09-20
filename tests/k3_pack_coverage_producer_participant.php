<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

use App\QueueV4Clean\QueueV4CleanProducer;
use App\QueueV4Clean\QueueV4CleanRepository;

$runDir = (string) ($argv[1] ?? '');
$index = (int) ($argv[2] ?? -1);
if ($runDir === '' || $index < 0 || !is_dir($runDir)) {
    fwrite(STDERR, "k3_participant_args_invalid\n");
    exit(2);
}

$ready = $runDir . DIRECTORY_SEPARATOR . 'ready-' . $index . '.json';
$result = $runDir . DIRECTORY_SEPARATOR . 'result-' . $index . '.json';
file_put_contents($ready, json_encode([
    'index' => $index,
    'pid' => getmypid(),
    'connection_id' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(),
    'ready_at' => microtime(true),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

$started = microtime(true);
$created = null;
$error = null;
try {
    $producer = new QueueV4CleanProducer($pdo, new QueueV4CleanRepository($pdo));
    $method = new ReflectionMethod($producer, 'schedulePackExactDiscovery');
    $created = (int) $method->invoke($producer, [
        ['company_id' => 7100, 'meli_account_id' => 7101],
        ['company_id' => 7200, 'meli_account_id' => 7201],
        ['company_id' => 7200, 'meli_account_id' => 7202],
    ]);
} catch (Throwable $exception) {
    $error = $exception::class . ':' . $exception->getMessage();
}

file_put_contents($result, json_encode([
    'index' => $index,
    'pid' => getmypid(),
    'created' => $created,
    'error' => $error,
    'started_at' => $started,
    'finished_at' => microtime(true),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
exit($error === null ? 0 : 1);
