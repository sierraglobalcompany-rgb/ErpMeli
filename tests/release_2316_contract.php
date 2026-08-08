<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);
$version = trim((string) file_get_contents($root . '/VERSION'));
if ($version !== '2.31.6') {
    echo "release_2316_contract_skipped_for_cumulative_" . $version . "\n";
    return;
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "ASSERTION FAILED: {$message}\n");
        exit(1);
    }
};

$assert($version === '2.31.6', 'VERSION must be 2.31.6');
$assert(($manifest['version'] ?? '') === '2.31.6', 'manifest version must be 2.31.6');
$assert(($manifest['minimum_migration'] ?? '') === '264_cron_v3_history_drainage_v3_truth_2_31_6.sql', 'manifest minimum migration must be 264');
$assert(($manifest['build_id'] ?? '') === 'erp-meli-2.31.6-v3-coherente-salud-api-20260804', 'manifest build id must match 2.31.6');

foreach ([
    'migration_262_cron_v3_visual_truth_api_health',
    'migration_263_cron_v3_queue_capability_owners',
    'migration_264_cron_v3_history_drainage_v3_truth',
    'frontend_app_js',
    'api_health_js',
    'api_health_shell',
    'cron_shell',
] as $component) {
    $path = $manifest['components'][$component]['path'] ?? null;
    $sha = $manifest['components'][$component]['sha256'] ?? null;
    $assert(is_string($path) && is_file($root . '/' . $path), "{$component} path must exist");
    $assert(is_string($sha) && hash_file('sha256', $root . '/' . $path) === $sha, "{$component} hash must match manifest");
}

echo "release_2316_contract: OK\n";

