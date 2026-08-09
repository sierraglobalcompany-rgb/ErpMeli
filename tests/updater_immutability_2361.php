<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$inventoryPath = $root . '/resources/release/updater-locked-file-inventory-2.36.1.json';
$inventory = json_decode((string) file_get_contents($inventoryPath), true, 32, JSON_THROW_ON_ERROR);
$base = (string) ($inventory['base_commit'] ?? '');
$files = is_array($inventory['files'] ?? null) ? $inventory['files'] : [];
$failures = [];

if ($base !== '0cb5ddab368d033e15bdb040b53bb8592248246c' || $files === []) {
    $failures[] = 'locked_inventory_invalid';
}

foreach ($files as $entry) {
    $path = (string) ($entry['path'] ?? '');
    $expected = strtolower((string) ($entry['base_sha256'] ?? ''));
    $expectedFinal = strtolower((string) ($entry['final_sha256'] ?? ''));
    $absolute = $root . '/' . $path;
    if ($path === '' || preg_match('/^[a-f0-9]{64}$/', $expected) !== 1 || !is_file($absolute)) {
        $failures[] = 'missing_or_invalid:' . $path;
        continue;
    }
    $actual = hash_file('sha256', $absolute);
    if (!hash_equals($expected, $expectedFinal)
        || !is_string($actual)
        || !hash_equals($expected, strtolower($actual))
    ) {
        $failures[] = 'hash_mismatch:' . $path;
    }
}

$paths = array_map(static fn (array $entry): string => (string) ($entry['path'] ?? ''), $files);
$command = 'git -C ' . escapeshellarg($root)
    . ' diff --exit-code --no-ext-diff ' . escapeshellarg($base)
    . ' -- ' . implode(' ', array_map('escapeshellarg', $paths)) . ' 2>&1';
$output = [];
$exit = 1;
exec($command, $output, $exit);
if ($exit !== 0) {
    $failures[] = 'git_diff_not_empty';
}

if ($failures !== []) {
    fwrite(STDERR, 'UPDATER_IMMUTABILITY_2361=FAIL ' . implode(',', $failures) . PHP_EOL);
    exit(1);
}

echo 'UPDATER_IMMUTABILITY_2361=PASS FILES=' . count($files) . PHP_EOL;
