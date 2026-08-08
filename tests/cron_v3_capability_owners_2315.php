<?php
declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/app/Services/CronV3CapabilityMatrixService.php');
$allowed = ['v3_active', 'v3_local_only', 'waiting_capability', 'review_unsupported', 'legacy_readonly_backlog'];

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "ASSERTION FAILED: {$message}\n");
        exit(1);
    }
};

$assert(str_contains($source, 'private const CAPABILITIES'), 'Capability matrix must be explicit and code-owned.');
$assert(str_contains($source, 'transferableWorkTypes'), 'Matrix must expose transferable work types.');
$assert(str_contains($source, 'blockedWorkTypes'), 'Matrix must expose blocked work types.');

foreach ($allowed as $state) {
    $assert(str_contains($source, "'state' => '{$state}'"), "Matrix must use state {$state} where applicable.");
}

preg_match_all("/'([a-z0-9_]+)'\\s*=>\\s*\\[\\s*'label'\\s*=>\\s*'([^']+)'/m", $source, $matches, PREG_SET_ORDER);
$assert(count($matches) >= 20, 'Matrix must cover the visible Cron/V3 queue families.');

foreach ($matches as $match) {
    $queue = $match[1];
    $blockStart = strpos($source, "'{$queue}' => [");
    $blockEnd = strpos($source, "],\n        '", (int) $blockStart + 1);
    $block = substr($source, (int) $blockStart, $blockEnd === false ? null : $blockEnd - (int) $blockStart);
    $assert(str_contains($block, "'state' =>"), "{$queue} must declare a capability state.");
    $assert(str_contains($block, "'reason' =>"), "{$queue} must declare a human reason.");
    if (!str_contains($block, "'state' => 'legacy_readonly_backlog'")) {
        $assert(str_contains($block, "'work_types' => ["), "{$queue} must declare exact work type contract.");
    }
}

echo "cron_v3_capability_owners_2315: OK\n";
