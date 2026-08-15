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
$run = static function (array $command, string $cwd, ?array $environment = null): array {
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        $environment,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('test_process_unavailable');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
};
$remove = static function (string $path) use (&$remove): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $remove($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
};

$temporary = sys_get_temp_dir() . '/erp-meli-2390-legacy-cut-' . bin2hex(random_bytes(6));
$release = $temporary . '/releases/2.39.0';
@mkdir($temporary . '/launcher', 0700, true);
@mkdir($temporary . '/shared/storage/cache', 0700, true);
@mkdir($release . '/jobs', 0700, true);
@mkdir($release . '/resources', 0700, true);

try {
    copy($root . '/launcher/cron.php', $temporary . '/launcher/cron.php');
    file_put_contents(
        $temporary . '/shared/current-release.json',
        json_encode([
            'path' => 'releases/2.39.0',
            'release_id' => 'erp-meli-2.39.0-test',
        ], JSON_THROW_ON_ERROR),
    );
    file_put_contents(
        $release . '/jobs/queue_v4_clean.php',
        "<?php declare(strict_types=1); echo 'QUEUE_V4_DISPATCH_ALLOWED'.PHP_EOL;\n",
    );

    $launcher = $temporary . '/launcher/cron.php';
    $missing = $run([PHP_BINARY, $launcher], $temporary);
    $assert($missing['exit'] === 2, 'launcher_missing_job_exit_invalid');
    $assert(trim($missing['stderr']) === 'CRON_JOB_REQUIRED', 'launcher_missing_job_message_invalid');

    foreach (['process_sync_queue.php', 'cron_v3_remote.php', 'cron_v4.php', '../queue_v4_clean.php'] as $legacy) {
        $blocked = $run([PHP_BINARY, $launcher, $legacy], $temporary);
        $assert($blocked['exit'] === 2, 'launcher_legacy_exit_invalid:' . $legacy);
        $assert(trim($blocked['stderr']) === 'LEGACY_AUTOMATION_BLOCKED', 'launcher_legacy_message_invalid:' . $legacy);
        $assert($blocked['stdout'] === '', 'launcher_legacy_stdout_not_empty:' . $legacy);
    }

    $allowed = $run([PHP_BINARY, $launcher, 'queue_v4_clean.php'], $temporary);
    $assert($allowed['exit'] === 0, 'launcher_queue_v4_exit_invalid');
    $assert(trim($allowed['stdout']) === 'QUEUE_V4_DISPATCH_ALLOWED', 'launcher_queue_v4_not_dispatched');
    $assert($allowed['stderr'] === '', 'launcher_queue_v4_stderr_not_empty');

    foreach (['_cron_entry_state.php', '_automation_emergency_stop.php', '_prebootstrap_runtime_paths.php'] as $dependency) {
        copy($root . '/jobs/' . $dependency, $release . '/jobs/' . $dependency);
    }
    copy($root . '/jobs/process_sync_queue.php', $release . '/jobs/process_sync_queue.php');
    file_put_contents(
        $release . '/resources/runtime-manifest.json',
        json_encode(['version' => '2.39.0', 'build_id' => 'legacy-cut-test'], JSON_THROW_ON_ERROR),
    );
    $normalEnvironment = array_merge($_ENV, [
        'CRON_V3_ENABLED' => 'true',
        'CRON_V3_SHADOW_ENABLED' => 'false',
        'ERP_QUEUE_ENGINE' => 'v3',
    ]);
    $normal = $run([PHP_BINARY, $release . '/jobs/process_sync_queue.php'], $release, $normalEnvironment);
    $assert($normal['exit'] === 0, 'process_sync_queue_retired_exit_invalid');
    $assert(str_contains($normal['stdout'], 'reason=LEGACY_AUTOMATION_RETIRED'), 'process_sync_queue_not_retired');
    $assert(str_contains($normal['stdout'], 'remote=false http=0'), 'process_sync_queue_remote_authority_missing');
    $assert(!str_contains($normal['stdout'], 'ERP_CRON_START'), 'process_sync_queue_workers_started');

    $launcherSource = (string) file_get_contents($root . '/launcher/cron.php');
    $legacySource = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    $retentionSource = (string) file_get_contents($root . '/app/Services/TechnicalRetentionCliService.php');
    $assert(!str_contains($launcherSource, "?? 'process_sync_queue.php'")
        && str_contains($launcherSource, "hash_equals('queue_v4_clean.php', \$requestedJob)"), 'launcher_allowlist_not_exact');
    $assert(!str_contains($launcherSource, 'queue_engine_control')
        && !str_contains($launcherSource, 'queue_v4_clean_control'), 'launcher_db_authority_lookup_present');
    $assert(!str_contains($legacySource, 'QueueCoreOwnershipGuard::v4OwnsWebhook')
        && !str_contains($legacySource, 'CronV3OperationalModeService'), 'legacy_runtime_fallback_guard_present');
    $assert(!str_contains($legacySource, 'CRON_V3_ENABLED')
        && !str_contains($legacySource, 'CRON_V3_SHADOW_ENABLED'), 'legacy_config_fallback_present');
    $retiredAt = strpos($legacySource, 'reason=LEGACY_AUTOMATION_RETIRED');
    $queuesAt = strpos($legacySource, "stage(\$bootstrapId, 'preparing_queues')");
    $capacityAt = strpos($legacySource, 'CronCapacityPlan::build');
    $assert($retiredAt !== false && $queuesAt !== false && $retiredAt < $queuesAt, 'legacy_retirement_after_queue_prepare');
    $assert($capacityAt !== false && $retiredAt < $capacityAt, 'legacy_retirement_after_capacity_plan');
    $assert(strpos($legacySource, 'if ($doctorMode)') < $retiredAt, 'doctor_not_preserved');
    $assert(strpos($legacySource, 'if ($qaReplayMode)') < $retiredAt
        && str_contains($legacySource, 'ERP_FAKE_MELI_TRANSPORT'), 'qa_replay_not_preserved');
    $assert(strpos($legacySource, 'if ($retentionStepMode)') > $retiredAt
        && str_contains($legacySource, '!$retentionStepMode'), 'retention_step_not_exempt');
    $assert(str_contains($retentionSource, 'Nunca consulta Mercado Libre')
        && !str_contains($retentionSource, 'MeliApiClient'), 'retention_step_not_local_only');
    $assert(str_contains($legacySource, "if (defined('ERP_LOCAL_MAINTENANCE_ONLY'))")
        && str_contains($legacySource, "!defined('ERP_LOCAL_MAINTENANCE_ONLY')"), 'local_maintenance_not_preserved');
    $assert(!str_contains($legacySource, 'AutomationGateway')
        && !str_contains($legacySource, 'AutomationCapabilityRegistry'), 'automation_boundary_added_early');

    echo 'LEGACY_CRON_FAIL_CLOSED_2390=PASS checks=' . $checks
        . ' launcher_allowed=1 remote_http=0 business_dml=0' . PHP_EOL;
} finally {
    $remove($temporary);
}
