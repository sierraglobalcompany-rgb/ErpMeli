<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root . '/resources/release/production-legacy-quarantine-2.36.1.json';
$manifest = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
$entries = is_array($manifest['entries'] ?? null) ? $manifest['entries'] : [];
$expectedPaths = [
    'app/Services/StorageMaintenanceService.php',
    'CronV3Cli.php',
    'EmergencyControlKernel.php',
    'EmergencyControlService.php',
    'ExecutionJournalService.php',
    'MeliApiClient.php',
];
$failures = [];
$seenPaths = [];
$seenHashes = [];

foreach ($entries as $entry) {
    $entryPath = (string) ($entry['path'] ?? '');
    $hash = strtolower((string) ($entry['expected_sha256'] ?? ''));
    if ($entryPath === '' || str_contains($entryPath, '..') || str_starts_with($entryPath, '/')) {
        $failures[] = 'unsafe_path';
    }
    if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
        $failures[] = 'invalid_hash:' . $entryPath;
    }
    if (($entry['classification'] ?? '') !== 'PRODUCTION_ONLY_STALE_TO_QUARANTINE'
        || ($entry['must_be_unreachable_after_cutover'] ?? null) !== true
    ) {
        $failures[] = 'unsafe_contract:' . $entryPath;
    }
    $destination = (string) ($entry['rollback_destination'] ?? '');
    if (!str_starts_with($destination, 'operator-private/quarantine/prod-2.36.1/' . $hash . '/')) {
        $failures[] = 'rollback_destination_not_hash_bound:' . $entryPath;
    }
    if (isset($seenPaths[strtolower($entryPath)]) || isset($seenHashes[$hash])) {
        $failures[] = 'duplicate_entry:' . $entryPath;
    }
    $seenPaths[strtolower($entryPath)] = true;
    $seenHashes[$hash] = true;
}

$actualPaths = array_keys($seenPaths);
$expectedFolded = array_map('strtolower', $expectedPaths);
sort($actualPaths, SORT_STRING);
sort($expectedFolded, SORT_STRING);
if ($actualPaths !== $expectedFolded) {
    $failures[] = 'inventory_mismatch';
}
foreach ($expectedPaths as $entryPath) {
    if (is_file($root . '/' . $entryPath)) {
        $failures[] = 'quarantined_path_in_target:' . $entryPath;
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'PRODUCTION_QUARANTINE_MANIFEST_2361=FAIL ' . implode(',', $failures) . PHP_EOL);
    exit(1);
}

echo 'PRODUCTION_QUARANTINE_MANIFEST_2361=PASS ENTRIES=' . count($entries) . PHP_EOL;
