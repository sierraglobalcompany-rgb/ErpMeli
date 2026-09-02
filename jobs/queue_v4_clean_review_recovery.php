<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanReviewService;

require dirname(__DIR__) . '/bootstrap.php';

Database::useProfile('cli');
$options = getopt('', ['company:', 'account:', 'job:', 'next-safe-at:', 'execute']);
$execute = array_key_exists('execute', $options);
$pdo = Database::connectionFresh();

try {
    $service = new QueueV4CleanReviewService($pdo);
    if (!$execute) {
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        echo json_encode([
            'ok' => true,
            'mode' => 'read_only',
            'review' => $service->summary(),
            'recovery_executed' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
        exit(0);
    }
    $result = $service->recoverExact(
        (int) ($options['company'] ?? 0),
        (int) ($options['account'] ?? 0),
        (int) ($options['job'] ?? 0),
        (string) ($options['next-safe-at'] ?? ''),
    );
    echo json_encode($result + ['mode' => 'execute_exact'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => preg_replace('/[^a-z0-9_:-]+/i', '_', $error->getMessage()) ?: 'recovery_failed',
        'recovery_executed' => false,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
