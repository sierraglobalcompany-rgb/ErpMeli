<?php
declare(strict_types=1);

// Reuse the affected scheduler's existing stage-isolation fixture unchanged.
// The scheduler, repository SQL, capacity policy and CycleBudget are real;
// external stages and its global-authority fixture are doubles. Global lease
// concurrency is separately covered by k1d_rc1_drainer_multiprocess.php.
$mode = $argv[1] ?? 'adapter';
if (!in_array($mode, ['direct','adapter'], true)) { throw new RuntimeException('invalid_fixture_mode'); }
$source = file_get_contents(__DIR__ . '/cap2_transport_scheduler.php');
$old = '(new App\\QueueV4Clean\\QueueV4CleanScheduler($pdo))->run(5,45)';
if (substr_count($source, $old) !== 2) { throw new RuntimeException('scheduler_fixture_authority_changed'); }
if ($mode === 'adapter') {
    $source = str_replace($old, "(new App\\Work\\Adapters\\QueueV4CurrentDrainer(\$pdo))->drain('cron_v4',5,45)->metadata", $source);
}
eval(substr($source, 5));
echo 'K4A_REAL_SCHEDULER_MODE=' . $mode . " PASS\n";
