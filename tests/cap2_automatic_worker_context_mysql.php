<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';

use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;

putenv('APP_ENV=test');
putenv('ML_WRITE_ENABLED=false');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=33079');
putenv('DB_USER=root');
putenv('DB_PASS=');
putenv('DB_NAME=erp_meli_k1d_test_cap2_worker_context_' . bin2hex(random_bytes(4)));

$harness = K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo = $harness->pdo();
    $worker = new QueueV4CleanWorker($pdo, new QueueV4CleanRepository($pdo));

    QueueV4CleanCycleBudget::clear();
    $zeroCalls = $worker->run('test', 0, 45);
    k1b_assert($zeroCalls['run_id'] === 0 && $zeroCalls['max_calls'] === 0, 'zero_call_early_exit_needs_no_outer_context');
    $zeroRuntime = $worker->run('test', 150, 0);
    k1b_assert($zeroRuntime['run_id'] === 0 && $zeroRuntime['max_calls'] === 100,
        'max_calls_normalized_before_runtime_early_exit');

    $missing = null;
    try {
        $worker->run('test', 1, 45);
    } catch (RuntimeException $error) {
        $missing = $error->getMessage();
    }
    k1b_assert($missing === 'queue_v4_clean_cycle_budget_context_missing',
        'missing_outer_budget_rejected_before_repository_reads');

    QueueV4CleanCycleBudget::start(10);
    $mismatch = null;
    try {
        $worker->run('test', 5, 45);
    } catch (RuntimeException $error) {
        $mismatch = $error->getMessage();
    } finally {
        QueueV4CleanCycleBudget::clear();
    }
    k1b_assert($mismatch === 'queue_v4_clean_cycle_budget_exceeds_authorized_calls',
        'outer_remaining_above_worker_authority_rejected_before_repository_reads');

    echo "STATUS=PASS CAP2_AUTOMATIC_WORKER_CONTEXT_MYSQL\nREAL_MELI_HTTP=0\nREAL_EMAIL_SENT=0\n";
} finally {
    QueueV4CleanCycleBudget::clear();
    $harness->cleanup();
}
