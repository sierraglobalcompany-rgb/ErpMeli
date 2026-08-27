<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\SaleFinancialService;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$timestamp = static function (string $value): int {
    $parsed = strtotime($value . ' UTC');
    if ($parsed === false) {
        throw new RuntimeException('invalid_timestamp:' . $value);
    }

    return $parsed;
};

$worker = (new ReflectionClass(QueueV4CleanWorker::class))->newInstanceWithoutConstructor();
$workerHelper = new ReflectionMethod(QueueV4CleanWorker::class, 'financialReconciliationNextSafeAt');
$workerOutcome = new ReflectionMethod(QueueV4CleanWorker::class, 'domainOutcome');
$service = (new ReflectionClass(SaleFinancialService::class))->newInstanceWithoutConstructor();
$serviceHelper = new ReflectionMethod(SaleFinancialService::class, 'financialReconciliationNextSafeAt');

$before = time();
$workerStale = (string) $workerHelper->invoke($worker, '2026-01-01 00:00:00');
$workerEmpty = (string) $workerHelper->invoke($worker, '');
$serviceStale = (string) $serviceHelper->invoke($service, '2026-01-01 00:00:00');
$future120 = gmdate('Y-m-d H:i:s', time() + 120);
$future600 = gmdate('Y-m-d H:i:s', time() + 600);
$future1800 = gmdate('Y-m-d H:i:s', time() + 1800);
$workerFuture120 = (string) $workerHelper->invoke($worker, $future120);
$workerFuture600 = (string) $workerHelper->invoke($worker, $future600);
$workerFuture1800 = (string) $workerHelper->invoke($worker, $future1800);
$after = time();

$assert($timestamp($workerStale) >= $before + 890, 'worker_stale_source_recheck_too_short');
$assert($timestamp($workerStale) <= $after + 910, 'worker_stale_source_recheck_too_long');
$assert($timestamp($workerEmpty) >= $before + 890, 'worker_empty_source_recheck_too_short');
$assert($timestamp($serviceStale) >= $before + 890, 'sale_financial_stale_source_recheck_too_short');
$assert(abs($timestamp($workerFuture120) - $timestamp($future120)) <= 1, 'future_120_source_next_run_not_preserved');
$assert(abs($timestamp($workerFuture600) - $timestamp($future600)) <= 1, 'future_600_source_next_run_not_preserved');
$assert(abs($timestamp($workerFuture1800) - $timestamp($future1800)) <= 1, 'future_1800_source_next_run_not_preserved');

$outcome = $workerOutcome->invoke($worker, 'financial_reconciliation', [
    'status' => 'retry',
    'next_run_at' => '2026-01-01 00:00:00',
]);
$assert(is_array($outcome), 'domain_outcome_not_array');
$assert(($outcome['state'] ?? '') === 'waiting', 'domain_outcome_state_not_waiting');
$assert(($outcome['classification'] ?? '') === 'domain_source_waiting:financial_reconciliation', 'domain_outcome_classification_changed');
$assert(isset($outcome['next_safe_at']) && $timestamp((string) $outcome['next_safe_at']) >= $before + 890, 'domain_outcome_stale_recheck_too_short');

$recalcOutcome = $workerOutcome->invoke($worker, 'financial_recalc', [
    'status' => 'retry',
    'next_run_at' => null,
]);
$assert(is_array($recalcOutcome), 'recalc_outcome_not_array');
$assert(($recalcOutcome['state'] ?? '') === 'waiting', 'recalc_outcome_state_not_waiting');
$assert(($recalcOutcome['classification'] ?? '') === 'domain_source_waiting:financial_recalc', 'recalc_outcome_classification_changed');
$assert(!array_key_exists('next_safe_at', $recalcOutcome), 'financial_recalc_received_900_floor');

echo 'PASS queue_v4_domain_source_stale_recheck_2401 checks=' . $checks . PHP_EOL;
