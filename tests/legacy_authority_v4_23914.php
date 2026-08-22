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
$run = static function (array $command) use ($root): array {
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('legacy_authority_process_unavailable');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
};

$legacy = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
$entryAt = strpos($legacy, "require __DIR__ . '/_cron_entry_state.php'");
$retiredAt = strpos($legacy, 'reason=LEGACY_AUTOMATION_RETIRED');
$assert($entryAt !== false && $retiredAt !== false && $retiredAt < $entryAt,
    'legacy_retirement_must_precede_entry_state');
$assert(strpos($legacy, '$legacyFlags') !== false && strpos($legacy, 'LEGACY_AUTOMATION_BLOCKED') !== false,
    'legacy_mutation_flags_not_blocked');

$normal = $run([PHP_BINARY, $root . '/jobs/process_sync_queue.php']);
$assert($normal['exit'] === 0 && str_contains($normal['stdout'], 'reason=LEGACY_AUTOMATION_RETIRED'),
    'legacy_normal_invocation_not_fail_closed');
foreach (['--retention-step', '--record', '--persist', '--execute', '--set=disabled', '--rollback', '--prepare'] as $flag) {
    $blocked = $run([PHP_BINARY, $root . '/jobs/process_sync_queue.php', $flag]);
    $assert($blocked['exit'] === 2 && str_contains($blocked['stderr'], 'LEGACY_AUTOMATION_BLOCKED'),
        'legacy_flag_not_blocked:' . $flag);
}

$health = (string) file_get_contents($root . '/app/Services/CronHealthService.php');
$assert(str_contains($health, 'queue_v4_clean.php --runtime=45 --max-jobs=3'), 'v4_command_not_recommended');
$assert(!str_contains($health, 'launcher/cron.php process_sync_queue.php'), 'legacy_command_still_recommended');

$inventory = (string) file_get_contents($root . '/app/Services/RuntimeProcessInventoryService.php');
$assert(str_contains($inventory, "if (\$name === 'queue_v4_clean.php')")
    && str_contains($inventory, "'retired_blocked'"), 'runtime_inventory_not_behavioral');
$assert(!str_contains($inventory, "['process_sync_queue.php', 'cron_v3_local.php', 'cron_v3_remote.php'], true)) {\n                \$status = 'active_launcher'"),
    'runtime_inventory_legacy_still_active');

$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$testAt = strpos($controller, 'public function testCron(): void');
$testSection = $testAt === false ? '' : substr($controller, $testAt, 5600);
$assert(str_contains($testSection, "'/jobs/queue_v4_clean.php'")
    && str_contains($testSection, 'queue_v4_read_only'), 'web_preflight_not_v4_read_only');
$assert(!str_contains($testSection, "begin('process_sync_queue'"), 'web_preflight_creates_legacy_health');

foreach ([
    'jobs/queue_core_convergence.php' => 'LEGACY_TOOL_BLOCKED',
    'jobs/queue_core_health_snapshot.php' => 'LEGACY_TOOL_BLOCKED',
    'jobs/queue_core_preflight.php' => 'LEGACY_TOOL_BLOCKED',
    'jobs/queue_core_rollback.php' => 'LEGACY_TOOL_BLOCKED',
    'jobs/queue_core_release_certify.php' => 'LEGACY_TOOL_BLOCKED',
    'jobs/queue_engine_control.php' => 'legacy_engine_activation_retired',
] as $path => $needle) {
    $assert(str_contains((string) file_get_contents($root . '/' . $path), $needle), 'legacy_tool_not_guarded:' . $path);
}

fwrite(STDOUT, 'LEGACY_AUTHORITY_V4_23914=PASS checks=' . $checks . ' real_meli_http=0' . PHP_EOL);
