<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

$runDir = (string) ($argv[1] ?? '');
$index = (int) ($argv[2] ?? -1);
$companyId = (int) ($argv[3] ?? 0);
$accountId = (int) ($argv[4] ?? 0);
$sourceId = (int) ($argv[5] ?? 0);
$packId = (string) ($argv[6] ?? '');

if ($runDir === '' || $index < 0 || $companyId < 1 || $accountId < 1 || $sourceId < 1 || $packId === '') {
    fwrite(STDERR, "invalid_participant_args\n");
    exit(2);
}

if (!is_dir($runDir)) {
    mkdir($runDir, 0777, true);
}

$readyPath = $runDir . DIRECTORY_SEPARATOR . 'ready-' . $index . '.json';
$resultPath = $runDir . DIRECTORY_SEPARATOR . 'result-' . $index . '.json';
file_put_contents($readyPath, json_encode([
    'pid' => getmypid(),
    'index' => $index,
    'source_id' => $sourceId,
    'connection_id_before' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(),
    'ready_at' => microtime(true),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

$started = microtime(true);
$attempts = [];
$receipt = null;
$connectionId = 0;
$error = null;
$exit = 1;
$maxAttempts = 8;
for ($try = 1; $try <= $maxAttempts; $try++) {
    try {
        $pdo->beginTransaction();
        $connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        $receipt = (new App\Services\CronAdmissionService($pdo))->submit(
            'order_enrichment_pack',
            $companyId,
            $accountId,
            $sourceId,
            'source:' . $sourceId,
            ['pack_id' => $packId],
        );
        $retryableReceiptReason = in_array((string) ($receipt['reason'] ?? ''), [
            'R0_OCCUPANCY_UNAVAILABLE',
            'R0_H3_AUTHORITY_UNAVAILABLE',
        ], true);
        if (($receipt['accepted'] ?? false) === false
            && $retryableReceiptReason
            && $try < $maxAttempts) {
            $pdo->rollBack();
            $attempts[] = ['try' => $try, 'result' => 'rolled_back_retryable_receipt', 'retryable' => true, 'receipt' => $receipt];
            usleep((100000 * $try) + (25000 * $index));
            continue;
        }
        $pdo->commit();
        $attempts[] = ['try' => $try, 'result' => 'committed'];
        $exit = 0;
        $error = null;
        break;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = $exception->getMessage();
        $retryable = $exception instanceof PDOException
            && (str_contains($message, '1213') || str_contains($message, '40001') || stripos($message, 'Deadlock') !== false);
        $attempts[] = ['try' => $try, 'result' => 'rolled_back', 'retryable' => $retryable, 'error' => $exception::class . ':' . $message];
        if (!$retryable || $try === $maxAttempts) {
            $error = $exception::class . ':' . $message;
            break;
        }
        usleep((100000 * $try) + (25000 * $index));
    }
}

file_put_contents($resultPath, json_encode([
    'pid' => getmypid(),
    'connection_id' => $connectionId,
    'index' => $index,
    'source_id' => $sourceId,
    'started_at' => $started,
    'finished_at' => microtime(true),
    'attempts' => $attempts,
    'receipt' => $receipt,
    'error' => $error,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

exit($exit);
