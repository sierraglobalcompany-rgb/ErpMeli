<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$inventoryPath = $root . '/qa/calls-final/call-path-inventory.csv';

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

$assert(is_file($inventoryPath), 'inventory_csv_exists');
$rows = [];
if (is_file($inventoryPath)) {
    $handle = fopen($inventoryPath, 'rb');
    $headers = $handle !== false ? fgetcsv($handle, null, ',', '"', '\\') : false;
    while ($handle !== false && ($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
        if ($headers === false) {
            continue;
        }
        $rows[] = array_combine($headers, $row);
    }
    if ($handle !== false) {
        fclose($handle);
    }
}

$byPath = [];
foreach ($rows as $row) {
    if (is_array($row) && isset($row['runtime_path'])) {
        $byPath[(string) $row['runtime_path']] = $row;
    }
}

$assert(isset($byPath['app/Services/MeliApiClient.php']), 'meli_api_gateway_inventory_row_exists');
$assert(($byPath['app/Services/MeliApiClient.php']['classification'] ?? '') === 'BUDGETED_MELI_API_GATEWAY', 'meli_api_gateway_budgeted');
$assert(isset($byPath['app/Services/CurlMeliHttpTransport.php']), 'meli_transport_inventory_row_exists');
$assert(($byPath['app/Services/CurlMeliHttpTransport.php']['classification'] ?? '') === 'ONLY_MELI_WIRE_BOUNDARY', 'meli_transport_only_wire_boundary');
$assert(isset($byPath['app/Services/UpdateRemoteService.php']), 'update_remote_inventory_row_exists');
$assert(($byPath['app/Services/UpdateRemoteService.php']['classification'] ?? '') === 'NON_MELI_UPDATE_REMOTE', 'update_remote_not_meli_capacity');
$assert(isset($byPath['jobs/queue_v4_clean.php']), 'queue_v4_cli_inventory_row_exists');
$assert(str_contains((string) ($byPath['jobs/queue_v4_clean.php']['notes'] ?? ''), 'max-jobs'), 'queue_v4_cli_notes_legacy_jobs_removed');

$scanRoots = [$root . '/app', $root . '/jobs', $root . '/public'];
$curlFiles = [];
$forbiddenFiles = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file): bool {
            $path = str_replace('\\', '/', $file->getPathname());
            return str_contains($path, '/app/')
                || str_contains($path, '/jobs/')
                || str_contains($path, '/public/')
                || $file->isDir();
        }
    )
);
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $contents = (string) file_get_contents($file->getPathname());
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (preg_match('/\bcurl_(?:init|exec)\s*\(/', $contents) === 1) {
        $curlFiles[$relative] = true;
    }
    if (preg_match('/\b(?:curl_multi_exec|stream_socket_client|fsockopen|GuzzleHttp|new\s+Client)\b/', $contents) === 1) {
        $forbiddenFiles[] = $relative;
    }
}

$expectedCurlFiles = [
    'app/Services/CurlMeliHttpTransport.php' => true,
    'app/Services/UpdateRemoteService.php' => true,
];
$assert(array_keys($curlFiles) === array_keys($expectedCurlFiles), 'runtime_curl_files_match_inventory:' . json_encode(array_keys($curlFiles), JSON_UNESCAPED_SLASHES));
$assert($forbiddenFiles === [], 'no_hidden_http_clients:' . json_encode($forbiddenFiles, JSON_UNESCAPED_SLASHES));

foreach (array_keys($curlFiles) as $path) {
    $assert(isset($byPath[$path]), 'curl_file_has_inventory_row:' . $path);
    $assert(($byPath[$path]['classification'] ?? '') !== 'UNBUDGETED_MELI_WIRE', 'curl_file_not_unbudgeted:' . $path);
}

$queueCli = (string) file_get_contents($root . '/jobs/queue_v4_clean.php');
$assert(str_contains($queueCli, 'legacy_capacity_argument_removed'), 'legacy_max_jobs_rejected_by_cli');
$assert(!str_contains($queueCli, 'LEGACY_MAX_JOBS_OVERRIDE'), 'legacy_max_jobs_override_removed_from_cli');

if ($failures !== []) {
    fwrite(STDERR, "FAIL\n" . implode("\n", $failures) . "\n");
    exit(1);
}

echo "PASS calls_final_call_path_inventory\n";
