<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "ASSERTION FAILED: {$message}\n");
        exit(1);
    }
};

$migration = (string) file_get_contents($root . '/database/migrations/266_cron_v3_fifo_arrival_queue_2_33_0.sql');
$repository = (string) file_get_contents($root . '/app/Services/CronV3WorkRepository.php');
$enqueuer = (string) file_get_contents($root . '/app/Services/CronV3Enqueuer.php');
$adapter = (string) file_get_contents($root . '/app/Services/CronV3LegacyQueueAdapter.php');
$runner = (string) file_get_contents($root . '/app/Services/CronV3Runner.php');
$finalizer = (string) file_get_contents($root . '/app/Services/CronV3LegacySourceFinalizer.php');
$readService = (string) file_get_contents($root . '/app/Services/CronV3OperationalReadService.php');

foreach ([
    "waiting_capability",
    "waiting_identity",
    "waiting_rate",
    "waiting_budget",
    "waiting_api",
    "cron_v3_parked_work_events",
    "idx_cron_v3_work_fifo_claim",
] as $needle) {
    $assert(str_contains($migration, $needle), 'Migration 266 must add FIFO/parking contract: ' . $needle);
}

$assert(str_contains($repository, 'fifoOrderSql') && str_contains($repository, 'arrival_seq'), 'V3 claims must use the stable FIFO arrival sequence when available.');
$assert(!str_contains($repository, 'fairness_'), 'V3 repository must not claim by dynamic fairness scoring.');
$assert(str_contains($repository, 'private function storageStatus'), 'Repository must map automatic deferrals to storage statuses.');
foreach (['waiting_rate', 'waiting_budget', 'waiting_api'] as $status) {
    $assert(str_contains($repository, $status), 'Repository must persist automatic parking status: ' . $status);
}

$assert(str_contains($enqueuer, 'enqueueWithStatus'), 'Enqueuer must support explicit parking statuses.');
$assert(str_contains($enqueuer, 'recordParkingEvent'), 'Enqueuer must record parking events without blocking enqueue.');

$assert(str_contains($adapter, 'identityRepairEnvelope'), 'Legacy notification fallback must park identity gaps locally.');
$assert(str_contains($adapter, 'ownsFinalWorkType'), 'Legacy import must verify final V3 ownership before enqueueing executable work.');
$assert(str_contains($adapter, 'cron_v3_parking'), 'Legacy import must mark parked envelopes explicitly.');
$assert(str_contains($adapter, 'remote_resource_id REGEXP'), 'Legacy SQL must validate numeric resource identifiers before casting.');
$assert(!str_contains($adapter, 'w.resource_type IN ("order","question","claim") AND w.remote_resource_id IS NOT NULL'), 'Notification fallback import must not skip identity-missing rows forever.');

$assert(str_contains($runner, 'CronV3LegacySourceFinalizer'), 'Runner must reconcile legacy source after fenced V3 finalize.');
$assert(str_contains($runner, 'recordResult('), 'Runner must still record rate/circuit effects after fenced finalize.');
$assert(str_contains($finalizer, 'recordPendingReconciliation'), 'Legacy cleanup failures must become local reconciliation, not remote uncertainty.');
$assert(str_contains($readService, "'parked'"), 'Operational read model must expose parked work.');
$assert(str_contains($readService, "'waiting_identity'"), 'Operational read model must expose identity parking.');

echo "cron_v3_fifo_arrival_queue_2330: OK\n";
