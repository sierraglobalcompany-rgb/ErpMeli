<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$base = 'f91cd534d271b964db1ad9e682260475eca96820';
$version = '2.36.0';
$minimumMigration = '292_queue_core_authoritative_convergence_b2_1.sql';

$command = sprintf(
    'git -C %s diff --name-only %s HEAD',
    escapeshellarg($root),
    escapeshellarg($base),
);
$output = shell_exec($command);
if (!is_string($output)) {
    fwrite(STDERR, "Unable to enumerate the Git release delta.\n");
    exit(1);
}
$paths = preg_split('/\R/', trim($output)) ?: [];
$paths = array_values(array_filter(array_map(
    static fn (string $path): string => str_replace('\\', '/', trim($path)),
    $paths,
), static fn (string $path): bool => $path !== ''));
$paths[] = 'jobs/cron_probe.php';
$paths[] = 'jobs/process_sync_queue.php';
$paths = array_values(array_unique(array_filter($paths, static function (string $path): bool {
    if (in_array($path, ['jobs/cron_probe.php', 'jobs/process_sync_queue.php'], true)) {
        return true;
    }
    return preg_match('#^(app|jobs|public|launcher|database/migrations)/#', $path) === 1
        && preg_match('/\.(php|sql|js|css)$/', $path) === 1;
})));
sort($paths, SORT_STRING);

$components = [];
foreach ($paths as $path) {
    $absolute = $root . '/' . $path;
    if (!is_file($absolute)) {
        fwrite(STDERR, "Missing release component: {$path}\n");
        exit(1);
    }
    $bytes = file_get_contents($absolute);
    if (!is_string($bytes)) {
        fwrite(STDERR, "Unreadable release component: {$path}\n");
        exit(1);
    }
    $key = match ($path) {
        'jobs/cron_probe.php' => 'cron_probe',
        'jobs/process_sync_queue.php' => 'process_sync_queue',
        default => 'runtime_' . trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($path)), '_'),
    };
    if (isset($components[$key])) {
        fwrite(STDERR, "Duplicate manifest component key: {$key}\n");
        exit(1);
    }
    $canonical = str_replace(["\r\n", "\r"], "\n", $bytes);
    $components[$key] = [
        'path' => $path,
        'sha256' => hash('sha256', $bytes),
        'sha256_lf' => hash('sha256', $canonical),
        'text' => true,
    ];
}

$manifest = [
    'product' => 'erp-meli',
    'version' => $version,
    'build_id' => 'erp-meli-2.36.0-cron-v4-readonly-rc1-20260809',
    'built_at' => '2026-08-09T00:00:00Z',
    'minimum_migration' => $minimumMigration,
    'components' => $components,
];
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($root . '/resources/runtime-manifest.json', $json, LOCK_EX) !== strlen($json)) {
    fwrite(STDERR, "Unable to publish runtime manifest.\n");
    exit(1);
}
fwrite(STDOUT, 'Manifest components: ' . count($components) . "\n");
