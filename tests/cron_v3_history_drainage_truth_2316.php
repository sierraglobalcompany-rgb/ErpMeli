<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$runService = (string) file_get_contents($root . '/app/Services/WorkQueueRunService.php');
$historyView = (string) file_get_contents($root . '/app/Views/settings/automation_history.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "ASSERTION FAILED: {$message}\n");
        exit(1);
    }
};

$assert(str_contains($controller, '$legacyNeedsDiagnosis'), 'History page must calculate legacy diagnosis grouping.');
$assert(str_contains($controller, '$row[\'safe_error_message\']'), 'History JSON must include safe_error_message in legacy grouping haystack.');
$assert(str_contains($runService, "'legacy_needs_diagnosis' => \$legacyNeedsDiagnosis"), 'Run item presenter must expose legacy_needs_diagnosis flag.');
$assert(str_contains($runService, "'real_error' => \$failed && !\$automatic && !\$legacyNeedsDiagnosis"), 'Legacy missing-diagnostic rows must not be repeated as real red errors.');
$assert(str_contains($historyView, 'eventos legacy agrupados'), 'History page must show grouped legacy intervention notice.');
$assert(str_contains($historyView, 'settings/cron/queue?group=attention&amp;resolution=legacy_needs_diagnosis'), 'History page must link grouped legacy diagnostics through the attention resolution filter.');

echo "cron_v3_history_drainage_truth_2316: OK\n";
