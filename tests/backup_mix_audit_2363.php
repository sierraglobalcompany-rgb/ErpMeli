<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$backup = $argv[1] ?? 'C:/codex/meli backup';
$output = $argv[2] ?? '';
if (!is_dir($backup)) {
    throw new RuntimeException('backup_root_missing');
}
require $root . '/app/Services/ManagedRuntimePublicationPolicy.php';

use App\Services\ManagedRuntimePublicationPolicy;

$forbidden = [
    str_replace('\\', '/', rtrim($backup, '/\\') . '/storage/raw'),
    str_replace('\\', '/', rtrim($backup, '/\\') . '/shared/storage/raw'),
];
$assertAllowed = static function (string $path) use ($forbidden): void {
    $normalized = str_replace('\\', '/', $path);
    foreach ($forbidden as $prefix) {
        if ($normalized === $prefix || str_starts_with($normalized, $prefix . '/')) {
            throw new RuntimeException('forbidden_raw_path_access:' . $normalized);
        }
    }
};

$counts = ['exact' => 0, 'lf_equivalent' => 0, 'different' => 0, 'missing' => 0];
$different = [];
$missing = [];
$packagePaths = [];
foreach (ManagedRuntimePublicationPolicy::packageEntries($root, 'prod-2.36.2') as $entry) {
    $path = (string) $entry['path'];
    $packagePaths[$path] = true;
    $candidate = rtrim($backup, '/\\') . '/' . str_replace('/', DIRECTORY_SEPARATOR, $path);
    $assertAllowed($candidate);
    if (!is_file($candidate) || is_link($candidate)) {
        $counts['missing']++;
        $missing[] = $path;
        continue;
    }
    $expected = ManagedRuntimePublicationPolicy::gitBlob($root, 'prod-2.36.2', $path);
    $actual = file_get_contents($candidate);
    if (!is_string($actual)) {
        throw new RuntimeException('backup_file_read_failed:' . $path);
    }
    if (hash_equals(hash('sha256', $expected), hash('sha256', $actual))) {
        $counts['exact']++;
        continue;
    }
    $expectedLf = str_replace(["\r\n", "\r"], "\n", $expected);
    $actualLf = str_replace(["\r\n", "\r"], "\n", $actual);
    if (hash_equals(hash('sha256', $expectedLf), hash('sha256', $actualLf))) {
        $counts['lf_equivalent']++;
        continue;
    }
    $counts['different']++;
    $different[] = [
        'path' => $path,
        'expected_sha256' => hash('sha256', $expected),
        'observed_sha256' => hash('sha256', $actual),
    ];
}

$safeRoots = ['app', 'bin', 'database', 'jobs', 'launcher', 'public', 'resources', 'stop'];
$extras = [];
foreach ($safeRoots as $safeRoot) {
    $directory = rtrim($backup, '/\\') . '/' . $safeRoot;
    $assertAllowed($directory);
    if (!is_dir($directory) || is_link($directory)) {
        continue;
    }
    $tree = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $entry): bool {
            if (!$entry->isDir()) {
                return true;
            }
            return !in_array(strtolower($entry->getFilename()), ['graphify-out', 'vendor', 'cache', 'logs', 'tmp', 'temp'], true);
        }
    );
    $iterator = new RecursiveIteratorIterator(
        $tree,
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) {
            continue;
        }
        $absolute = $file->getPathname();
        $assertAllowed($absolute);
        $relative = str_replace('\\', '/', substr($absolute, strlen(rtrim($backup, '/\\')) + 1));
        if (!isset($packagePaths[$relative]) && preg_match('/\.(?:php|sql|json|js|css|htaccess)$/i', $relative) === 1) {
            $extras[] = $relative;
        }
    }
}
sort($extras, SORT_STRING);
sort($missing, SORT_STRING);
usort($different, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

$report = [
    'schema' => 'erp-meli-backup-mix-audit-2363-v1',
    'backup_root' => str_replace('\\', '/', $backup),
    'authority_ref' => 'prod-2.36.2',
    'raw_storage_touched' => false,
    'package_files' => count($packagePaths),
    'counts' => $counts,
    'different' => $different,
    'missing' => $missing,
    'executable_extras' => $extras,
];
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if ($output !== '') {
    $parent = dirname($output);
    if (!is_dir($parent) && !mkdir($parent, 0770, true) && !is_dir($parent)) {
        throw new RuntimeException('audit_output_directory_failed');
    }
    if (file_put_contents($output, $json, LOCK_EX) !== strlen($json)) {
        throw new RuntimeException('audit_output_write_failed');
    }
}
fwrite(STDOUT, $json);
