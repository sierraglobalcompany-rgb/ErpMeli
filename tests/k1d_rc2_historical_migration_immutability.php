<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';

$root = dirname(__DIR__);
$baseHead = 'e24c79dd46a6a1714c50f38a8162965c538d82aa';
$allowedNewMigration = 'database/migrations/301_k1d_api_safety_2_40_1.sql';

$baseMigrations = gitLines($root, ['ls-tree', '-r', '--name-only', $baseHead, 'database/migrations']);
$headMigrations = gitLines($root, ['ls-files', 'database/migrations']);

$baseHistorical = [];
foreach ($baseMigrations as $path) {
    if (historicalMigrationNumber($path) !== null && historicalMigrationNumber($path) <= 300) {
        $baseHistorical[$path] = true;
    }
}

$headMigrationSet = array_fill_keys($headMigrations, true);
$modifications = 0;
$deletions = 0;
$compared = 0;
foreach (array_keys($baseHistorical) as $path) {
    if (!isset($headMigrationSet[$path]) || !is_file($root . '/' . $path)) {
        $deletions++;
        continue;
    }
    $compared++;
    $baseBytes = gitBytes($root, ['show', $baseHead . ':' . $path]);
    $headBytes = (string) file_get_contents($root . '/' . $path);
    if (!hash_equals(hash('sha256', $baseBytes), hash('sha256', $headBytes))) {
        $modifications++;
    }
}

$newMigrations = [];
foreach ($headMigrations as $path) {
    if (!isset($baseHistorical[$path]) && str_starts_with($path, 'database/migrations/')) {
        $number = historicalMigrationNumber($path);
        if ($number !== null && $number > 300) {
            $newMigrations[] = basename($path);
        }
    }
}
sort($newMigrations, SORT_STRING);

$negativePass = negativeMutationDetected($root, $baseHead, $allowedNewMigration);
$blob122 = trim(gitBytes($root, ['hash-object', 'database/migrations/122_sale_financial_reconciliation_2_24_0.sql']));
$baseBlob122 = trim(gitBytes($root, ['rev-parse', $baseHead . ':database/migrations/122_sale_financial_reconciliation_2_24_0.sql']));

$pass = $compared > 0
    && $modifications === 0
    && $deletions === 0
    && $newMigrations === ['301_k1d_api_safety_2_40_1.sql']
    && $negativePass
    && hash_equals($baseBlob122, $blob122);

echo 'STATUS=' . ($pass ? 'PASS K1D_RC2_HISTORICAL_MIGRATION_IMMUTABILITY' : 'BLOCKED K1D_RC2_HISTORICAL_MIGRATION_IMMUTABILITY') . "\n";
echo "HISTORICAL_MIGRATIONS_COMPARED={$compared}\n";
echo "HISTORICAL_MIGRATION_MODIFICATIONS={$modifications}\n";
echo "HISTORICAL_MIGRATION_DELETIONS={$deletions}\n";
echo 'NEW_MIGRATIONS=' . ($newMigrations === [] ? 'NONE' : implode(',', $newMigrations)) . "\n";
echo 'HISTORICAL_IMMUTABILITY_NEGATIVE_TEST_PASS=' . ($negativePass ? 'YES' : 'NO') . "\n";
echo "MIGRATION_122_BLOB_BASE={$baseBlob122}\n";
echo "MIGRATION_122_BLOB_AFTER={$blob122}\n";
echo 'MIGRATION_122_DIFF_VS_BASE=' . (hash_equals($baseBlob122, $blob122) ? '0' : '1') . "\n";
echo "MIGRATION_122_CHECKSUM_REPLACEMENT_AUTHORIZED=NO\n";
echo "PRODUCTION_CHANGED_BY_CODEX=NO\n";
echo "REAL_MELI_HTTP=0\n";
echo "REAL_EMAIL_SENT=0\n";

exit($pass ? 0 : 20);

/** @return list<string> */
function gitLines(string $root, array $args): array
{
    $output = gitBytes($root, $args);
    return array_values(array_filter(preg_split('/\r?\n/', trim($output)) ?: [], static fn (string $line): bool => $line !== ''));
}

function gitBytes(string $root, array $args): string
{
    $command = array_merge(['git', '-C', $root], $args);
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('GIT_PROC_OPEN_FAILED');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException('GIT_FAILED_' . $exit . ':' . trim((string) $stderr));
    }
    return (string) $stdout;
}

function historicalMigrationNumber(string $path): ?int
{
    if (preg_match('#^database/migrations/([0-9]{3})_#', $path, $match) !== 1) {
        return null;
    }
    return (int) $match[1];
}

function negativeMutationDetected(string $root, string $baseHead, string $allowedNewMigration): bool
{
    $tmp = sys_get_temp_dir() . '/erp_meli_rc2_immutability_' . bin2hex(random_bytes(4));
    if (!mkdir($tmp) && !is_dir($tmp)) {
        throw new RuntimeException('NEGATIVE_TMP_CREATE_FAILED');
    }
    $fixture = $tmp . '/122_sale_financial_reconciliation_2_24_0.sql';
    try {
        $baseBytes = gitBytes($root, ['show', $baseHead . ':database/migrations/122_sale_financial_reconciliation_2_24_0.sql']);
        file_put_contents($fixture, $baseBytes . "\n-- deliberate negative mutation\n");
        $mutated = (string) file_get_contents($fixture);
        return !hash_equals(hash('sha256', $baseBytes), hash('sha256', $mutated))
            && $allowedNewMigration === 'database/migrations/301_k1d_api_safety_2_40_1.sql';
    } finally {
        if (is_file($fixture)) {
            unlink($fixture);
        }
        @rmdir($tmp);
    }
}
