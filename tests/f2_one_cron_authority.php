<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$read = static function (string $path) use ($root): string {
    $source = (string) file_get_contents($root . '/' . $path);
    if ($source === '') {
        throw new RuntimeException('missing_or_empty:' . $path);
    }
    return $source;
};

$launcher = $read('launcher/cron.php');
$queueV4Job = $read('jobs/queue_v4_clean.php');
$legacyJob = $read('jobs/process_sync_queue.php');
$narrative = $read('app/Services/CronRunNarrativeService.php');
$entry = $read('app/Services/CronEntryStateService.php');
$prebootstrapEntry = $read('jobs/_cron_entry_state.php');
$bootstrapJournal = $read('app/Services/CronBootstrapJournalService.php');
$manualTtl = $read('app/Services/ManualCampaignReservationTtlService.php');
$inventory = $read('app/Services/RuntimeProcessInventoryService.php');
$repository = $read('app/QueueV4Clean/QueueV4CleanRepository.php');
$worker = $read('app/QueueV4Clean/QueueV4CleanWorker.php');

$assert(
    str_contains($launcher, "hash_equals('queue_v4_clean.php', \$requestedJob)")
        && !str_contains($launcher, "process_sync_queue.php', \$requestedJob"),
    'canonical_launcher_not_queue_v4_only'
);
$assert(str_contains($queueV4Job, 'QueueV4CleanScheduler'), 'queue_v4_job_not_scheduler');
$assert(str_contains($queueV4Job, "'runtime::'") && str_contains($queueV4Job, "'max-jobs::'"), 'queue_v4_cli_contract_missing');

$assert(str_contains($legacyJob, 'LEGACY_AUTOMATION_RETIRED'), 'process_sync_queue_not_retired');
$assert(!str_contains($legacyJob, 'CronWorkCoordinator'), 'process_sync_queue_still_loads_old_worker');
$assert(!str_contains($legacyJob, 'MeliApiClient'), 'process_sync_queue_can_reference_remote_client');

$assert(!str_contains($narrative, "latestAutomaticAnyBuild('process_sync_queue')"), 'narrative_reads_legacy_cron');
$assert(str_contains($narrative, 'latestAutomaticAnyBuild()'), 'narrative_not_using_canonical_default');
$assert(str_contains($entry, 'VALUES ("queue_v4_clean"'), 'cron_entry_state_component_not_v4');
$assert(str_contains($prebootstrapEntry, "'component' => 'queue_v4_clean'"), 'prebootstrap_entry_component_not_v4');
$assert(str_contains($bootstrapJournal, "latest(string \$component = 'queue_v4_clean')"), 'bootstrap_latest_default_not_v4');
$assert(str_contains($manualTtl, 'WHERE job_name="queue_v4_clean"'), 'manual_ttl_reads_legacy_cron');

$assert(str_contains($inventory, "\$name === 'queue_v4_clean.php'"), 'inventory_missing_active_launcher');
$assert(str_contains($inventory, "\$status = 'active_launcher'"), 'inventory_missing_active_status');
$assert(str_contains($inventory, "'process_sync_queue.php'") && str_contains($inventory, "\$status = 'retired_blocked'"), 'inventory_not_marking_legacy_retired');

$assert(str_contains($repository, 'financialSourceFuturePredicate'), 'f1_finance_future_predicate_missing');
$assert(str_contains($repository, 'integrity_status') && str_contains($repository, "sale_key LIKE 'P:%'"), 'f1b_pack_preclaim_exclusion_missing');
$assert(str_contains($worker, 'financialPackIntegrityComplete'), 'f1b_pack_worker_classification_missing');

echo 'F2_ONE_CRON_AUTHORITY=PASS checks=' . $checks
    . ' canonical_entrypoint=queue_v4_clean legacy_runtime_deleted=0 legacy_runtime_disconnected=1'
    . PHP_EOL;
