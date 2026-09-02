<?php

declare(strict_types=1);

require_once __DIR__ . '/k1b_bootstrap.php';

$root = realpath(__DIR__ . '/..');
k1b_assert(is_string($root), 'repo_root_exists');

$needles = [
    'QueueV4CleanWorker',
    'QueueV4CleanRepository',
    'QueueV4CleanScheduler',
    'queue_v4_clean_jobs',
    'queue_v4_clean_leases',
    'queue_core_execution_leases',
    'QueueExecutionLeaseService',
    '--max-jobs',
    'max_jobs',
    'job_count',
];
$allowedPrefixes = [
    'app/QueueV4Clean/',
    'app/QueueCore/',
    'app/Work/Adapters/',
    'jobs/queue_v4_clean.php',
    'tests/',
    'docs/',
    'resources/runtime-manifest.json',
    'resources/release/',
];
$businessLegacyAllowed = [
    'app/Services/CronAdmissionService.php',
    'app/Services/CronOperationalReadService.php',
    'app/Controllers/SettingsController.php',
    'app/Views/settings/manual_processing.php',
    'app/Views/settings/cron.php',
    'app/Views/settings/cron_shell.php',
    'app/Services/ApiHealthOverviewService.php',
    'app/Services/ManualCampaignPreviewService.php',
    'app/Services/ManualProcessingService.php',
    'app/Services/ManualSingleStepService.php',
    'app/Services/QueueCoreDeploymentGateService.php',
    'app/Services/QueueCoreRollbackService.php',
    'app/Services/QueueV4DiagnosticBundleService.php',
    'app/Services/V4ReadinessBootstrapService.php',
];

$newOutsideAllowed = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (!preg_match('/\.(php|md|json|txt)$/', $path)) {
        continue;
    }
    $text = file_get_contents($file->getPathname());
    if (!is_string($text)) {
        continue;
    }
    $hit = false;
    foreach ($needles as $needle) {
        if (str_contains($text, $needle)) {
            $hit = true;
            break;
        }
    }
    if (!$hit) {
        continue;
    }
    $allowed = false;
    foreach ($allowedPrefixes as $prefix) {
        if (str_starts_with($path, $prefix)) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed && !in_array($path, $businessLegacyAllowed, true)) {
        $newOutsideAllowed[] = $path;
    }
}

k1b_assert($newOutsideAllowed === [], 'no_new_v4_coupling_outside_allowed_boundary:' . implode(',', $newOutsideAllowed));
echo "K1B_V4_COUPLING_STATIC=PASS\n";
