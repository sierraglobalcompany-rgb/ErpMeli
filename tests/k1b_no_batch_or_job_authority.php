<?php

declare(strict_types=1);

require_once __DIR__ . '/k1b_bootstrap.php';

$files = [
    __DIR__ . '/../app/Work/WorkContractVersion.php',
    __DIR__ . '/../app/Work/Adapters/QueueV4CanonicalWorkStore.php',
    __DIR__ . '/../app/Work/Adapters/QueueCoreDrainAuthority.php',
    __DIR__ . '/../app/Work/Adapters/QueueV4CurrentDrainer.php',
    __DIR__ . '/../jobs/queue_v4_clean.php',
];
$text = '';
foreach ($files as $file) {
    $content = file_get_contents($file);
    k1b_assert(is_string($content), 'read_' . basename($file));
    $text .= "\n" . $content;
}

k1b_assert(str_contains($text, 'PHYSICAL_API_CALL'), 'control_unit_physical_api_call');
k1b_assert(str_contains(file_get_contents(__DIR__ . '/../jobs/queue_v4_clean.php'), 'max-calls'), 'max_calls_remains_canonical');
k1b_assert(str_contains(file_get_contents(__DIR__ . '/../jobs/queue_v4_clean.php'), 'max-jobs'), 'legacy_max_jobs_compat_still_supported');
k1b_assert(!str_contains($text, 'QueueV5') && !str_contains($text, 'queue_v5'), 'no_queue_v5');
k1b_assert(!str_contains($text, 'BATCH_AUTHORITY') && !str_contains($text, 'JOB_COUNT_AUTHORITY'), 'no_batch_or_job_count_authority');

echo "K1B_NO_BATCH_OR_JOB_AUTHORITY=PASS\n";
