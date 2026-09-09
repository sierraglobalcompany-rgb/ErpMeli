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

$tracked = [];
$process = proc_open(
    'git ls-files app jobs public',
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    $root
);
if (is_resource($process)) {
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) === 0) {
        foreach (preg_split('/\R/', trim((string) $stdout)) ?: [] as $path) {
            if ($path !== '' && preg_match('/\.(?:php|js)$/', $path) === 1) {
                $tracked[] = $path;
            }
        }
    }
}
$assert($tracked !== [], 'git_ls_files_runtime_scan_not_empty');

$curlFiles = [];
$forbiddenFiles = [];
$unbudgetedMeliWire = [];
$meliWireBoundary = [];
foreach ($tracked as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        continue;
    }
    $contents = (string) file_get_contents($path);
    $hasCurl = preg_match('/\bcurl_(?:init|exec)\s*\(/', $contents) === 1;
    $hasForbiddenHttpClient = preg_match('/\b(?:curl_multi_exec|stream_socket_client|fsockopen|GuzzleHttp|new\s+Client)\b/', $contents) === 1;
    $hasPhpHttpStream = preg_match('/\b(?:file_get_contents|fopen)\s*\([^;\n]*(?:https?:\/\/)/i', $contents) === 1;
    $hasAbsoluteBrowserMeli = preg_match('/\b(?:fetch|XMLHttpRequest)\b[\s\S]{0,200}https?:\/\/(?:api\.)?mercadolibre\./i', $contents) === 1;
    $hasMeliApiBase = preg_match('/api\.mercadolibre\.com|MELI_API_BASE/i', $contents) === 1;
    if ($hasCurl) {
        $curlFiles[$relative] = true;
    }
    if ($hasForbiddenHttpClient || $hasPhpHttpStream || $hasAbsoluteBrowserMeli) {
        $forbiddenFiles[] = $relative;
    }
    if ($relative === 'app/Services/CurlMeliHttpTransport.php' && $hasCurl) {
        $meliWireBoundary[] = $relative;
    } elseif ($hasCurl && $hasMeliApiBase) {
        $unbudgetedMeliWire[] = $relative;
    }
}

$expectedCurlFiles = [
    'app/Services/CurlMeliHttpTransport.php' => true,
    'app/Services/UpdateRemoteService.php' => true,
];
$assert(array_keys($curlFiles) === array_keys($expectedCurlFiles), 'runtime_curl_files_match_inventory:' . json_encode(array_keys($curlFiles), JSON_UNESCAPED_SLASHES));
$assert($forbiddenFiles === [], 'no_hidden_http_clients:' . json_encode($forbiddenFiles, JSON_UNESCAPED_SLASHES));
$assert($unbudgetedMeliWire === [], 'unbudgeted_meli_wire_paths_zero:' . json_encode($unbudgetedMeliWire, JSON_UNESCAPED_SLASHES));
$assert($meliWireBoundary === ['app/Services/CurlMeliHttpTransport.php'], 'only_server_meli_wire_boundary:' . json_encode($meliWireBoundary, JSON_UNESCAPED_SLASHES));

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
echo "UNBUDGETED_MELI_WIRE_PATHS=0\n";
echo "ONLY_SERVER_MELI_WIRE_BOUNDARY=CurlMeliHttpTransport.php\n";
echo "UPDATE_REMOTE_CLASSIFIED_NON_MELI=YES\n";
