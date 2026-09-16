<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_transport_fixture.inc.php';

use App\QueueV4Clean\QueueV4CleanProducer;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\Services\CronAdmissionService;
use App\Work\Adapters\QueueV4CanonicalWorkStore;
use App\Work\WorkContractVersion;
use App\Work\WorkEnvelope;

$runDir = (string) ($argv[1] ?? '');
$index = (int) ($argv[2] ?? -1);
$mode = (string) ($argv[3] ?? '');
$companyId = (int) ($argv[4] ?? 0);
$accountId = (int) ($argv[5] ?? 0);
$sourceId = (int) ($argv[6] ?? 0);
$packId = (string) ($argv[7] ?? '');

if ($runDir === '' || $index < 0 || $mode === '' || $companyId < 1 || $accountId < 1 || $sourceId < 1 || $packId === '') {
    fwrite(STDERR, "invalid_mixed_participant_args\n");
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
    'mode' => $mode,
    'company_id' => $companyId,
    'meli_account_id' => $accountId,
    'source_id' => $sourceId,
    'pack_id' => $packId,
    'connection_id_before' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(),
    'ready_at' => microtime(true),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

$started = microtime(true);
$attempts = [];
$receipt = null;
$error = null;
$connectionId = 0;
$exit = 1;

$maxAttempts = 8;
for ($try = 1; $try <= $maxAttempts; $try++) {
    try {
        $pdo->beginTransaction();
        $connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        if ($mode === 'service') {
            $receipt = (new CronAdmissionService($pdo))->submit(
                'order_enrichment_pack',
                $companyId,
                $accountId,
                $sourceId,
                'source:' . $sourceId,
                ['pack_id' => $packId],
            );
        } elseif ($mode === 'adapter_capability') {
            $receipt = (new QueueV4CanonicalWorkStore($pdo))->admit(new WorkEnvelope(
                WorkContractVersion::CURRENT,
                $companyId,
                $accountId,
                'order_enrichment_pack',
                (string) $sourceId,
                'source:' . $sourceId,
                ['pack_id' => $packId],
            ))->toLegacyCronAdmissionReceipt();
        } elseif ($mode === 'adapter_domain') {
            $receipt = (new QueueV4CanonicalWorkStore($pdo))->admit(new WorkEnvelope(
                WorkContractVersion::CURRENT,
                $companyId,
                $accountId,
                'domain_exact',
                (string) $sourceId,
                'domain:order_enrichment_pack:source:' . $sourceId,
                ['capability' => 'order_enrichment_pack', 'source_id' => $sourceId, 'payload' => ['pack_id' => $packId]],
            ))->toLegacyCronAdmissionReceipt();
        } elseif ($mode === 'service_rollback') {
            $receipt = (new CronAdmissionService($pdo))->submit(
                'order_enrichment_pack',
                $companyId,
                $accountId,
                $sourceId,
                'source:' . $sourceId,
                ['pack_id' => $packId],
            );
            $pdo->rollBack();
            $attempts[] = ['try' => $try, 'result' => 'rolled_back_by_test', 'receipt' => $receipt];
            $exit = 0;
            $error = null;
            break;
        } elseif ($mode === 'producer') {
            $pdo->commit();
            $receipt = (new QueueV4CleanProducer($pdo, new QueueV4CleanRepository($pdo)))->produce(60);
            $attempts[] = ['try' => $try, 'result' => 'producer_completed'];
            $exit = 0;
            $error = null;
            break;
        } else {
            throw new RuntimeException('unknown_mixed_participant_mode:' . $mode);
        }
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
        $attempts[] = [
            'try' => $try,
            'result' => 'rolled_back',
            'retryable' => $retryable,
            'error' => $exception::class . ':' . $message,
        ];
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
    'mode' => $mode,
    'company_id' => $companyId,
    'meli_account_id' => $accountId,
    'source_id' => $sourceId,
    'pack_id' => $packId,
    'started_at' => $started,
    'finished_at' => microtime(true),
    'attempts' => $attempts,
    'receipt' => $receipt,
    'error' => $error,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

exit($exit);
