<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';

putenv('APP_ENV=test');
putenv('ML_WRITE_ENABLED=false');
putenv('DB_HOST=' . (getenv('DB_HOST') ?: '127.0.0.1'));
putenv('DB_PORT=' . (getenv('DB_PORT') ?: '3306'));
putenv('DB_USER=' . (getenv('DB_USER') ?: 'root'));
putenv('DB_PASS=' . (string) getenv('DB_PASS'));

$root = dirname(__DIR__);
$baseHead = 'e24c79dd46a6a1714c50f38a8162965c538d82aa';
$targetHead = trim(gitBytes($root, ['rev-parse', 'HEAD']));
$targetTree = trim(gitBytes($root, ['rev-parse', 'HEAD^{tree}']));
$migration122 = '122_sale_financial_reconciliation_2_24_0.sql';
$migration301 = '301_k1d_api_safety_2_40_1.sql';
$baseRoot = '';
$targetRoot = '';
$runner = '';
$upgradeDb = null;
$freshDb = null;
$metrics = [
    'baseChecksum122' => '',
    'targetChecksum122' => '',
    'historicalChecksumDrift' => 1,
    'migration122SqlReexecuted' => 'UNKNOWN',
    'upgradePass' => false,
    'freshPass' => false,
    'compatPass' => false,
    'rerunPass' => false,
    'businessPreserved' => false,
    'newMigrationsOnRerun' => -1,
    'failure' => '',
];

try {
    $tmp = sys_get_temp_dir() . '/erp_meli_rc2_upgrade_' . bin2hex(random_bytes(4));
    if (!mkdir($tmp, 0777, true) && !is_dir($tmp)) {
        throw new RuntimeException('TMP_CREATE_FAILED');
    }
    $baseRoot = $tmp . '/base';
    $targetRoot = $tmp . '/target';
    $runner = $tmp . '/run_migrator.php';
    writeRunner($runner);

    gitWorktreeAdd($root, $baseRoot, $baseHead);
    gitWorktreeAdd($root, $targetRoot, $targetHead);

    $baseBlob122 = trim(gitBytes($root, ['rev-parse', $baseHead . ':database/migrations/' . $migration122]));
    $targetBlob122 = trim(gitBytes($targetRoot, ['rev-parse', 'HEAD:database/migrations/' . $migration122]));
    if (!hash_equals($baseBlob122, $targetBlob122)) {
        throw new RuntimeException('MIGRATION_122_TARGET_BLOB_DIFFERS_FROM_BASE');
    }

    putenv('DB_NAME=erp_meli_k1d_test_rc2_upgrade_' . strtolower(bin2hex(random_bytes(4))));
    $upgradeDb = K1dSafeTestDatabase::createFromEnvironment();
    $pdo = $upgradeDb->pdo();

    runMigratorChild($runner, $baseRoot, $upgradeDb->dbName, '300', 'utf8mb4_general_ci');
    $metrics['baseChecksum122'] = checksumFor($pdo, $migration122);
    $beforeSql122 = sqlStartedEvents($pdo, $migration122);
    $pdo->exec("CREATE TABLE IF NOT EXISTS k1d_rc2_business_marker (id INT NOT NULL PRIMARY KEY, marker VARCHAR(30) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT INTO k1d_rc2_business_marker (id,marker) VALUES (1,'preserved')");

    runMigratorChild($runner, $targetRoot, $upgradeDb->dbName, '1', 'utf8mb4_unicode_ci');
    $afterSql122 = sqlStartedEvents($pdo, $migration122);
    $metrics['targetChecksum122'] = checksumFor($pdo, $migration122);
    $metrics['historicalChecksumDrift'] = driftCount($pdo);
    $metrics['migration122SqlReexecuted'] = $afterSql122 === $beforeSql122 ? 'NO' : 'YES';
    $metrics['upgradePass'] = migrationApplied($pdo, $migration301);
    $metrics['businessPreserved'] = (string) $pdo->query('SELECT marker FROM k1d_rc2_business_marker WHERE id=1')->fetchColumn() === 'preserved';

    $countBeforeRerun = migrationCount($pdo);
    runMigratorChild($runner, $targetRoot, $upgradeDb->dbName, '1', 'utf8mb4_unicode_ci');
    $countAfterRerun = migrationCount($pdo);
    $metrics['newMigrationsOnRerun'] = $countAfterRerun - $countBeforeRerun;
    $metrics['rerunPass'] = $metrics['newMigrationsOnRerun'] === 0 && migrationApplied($pdo, $migration301);

    putenv('DB_NAME=erp_meli_k1d_test_rc2_fresh_' . strtolower(bin2hex(random_bytes(4))));
    $freshDb = K1dSafeTestDatabase::createFromEnvironment();
    $freshPdo = $freshDb->pdo();
    runMigratorChild($runner, $targetRoot, $freshDb->dbName, '301', 'utf8mb4_unicode_ci');
    $metrics['freshPass'] = migrationApplied($freshPdo, $migration301);
    $metrics['compatPass'] = migrationApplied($freshPdo, $migration122)
        && hash_equals(hash_file('sha256', $targetRoot . '/database/migrations/' . $migration122) ?: '', checksumFor($freshPdo, $migration122));
} catch (Throwable $error) {
    $metrics['failure'] = get_class($error) . ':' . preg_replace('/\s+/', ' ', $error->getMessage());
} finally {
    if ($freshDb instanceof K1dSafeTestDatabase) {
        $freshDb->cleanup();
    }
    if ($upgradeDb instanceof K1dSafeTestDatabase) {
        $upgradeDb->cleanup();
    }
    if ($baseRoot !== '') {
        gitWorktreeRemove($root, $baseRoot);
    }
    if ($targetRoot !== '') {
        gitWorktreeRemove($root, $targetRoot);
    }
    if ($runner !== '' && is_file($runner)) {
        unlink($runner);
    }
    if (isset($tmp) && is_dir($tmp)) {
        @rmdir($tmp);
    }
}

$expectedMigration122Checksum = hash_file('sha256', $root . '/database/migrations/' . $migration122) ?: '';
$pass = hash_equals($expectedMigration122Checksum, $metrics['baseChecksum122'])
    && hash_equals($metrics['baseChecksum122'], $metrics['targetChecksum122'])
    && $metrics['historicalChecksumDrift'] === 0
    && $metrics['migration122SqlReexecuted'] === 'NO'
    && $metrics['upgradePass']
    && $metrics['freshPass']
    && $metrics['compatPass']
    && $metrics['rerunPass']
    && $metrics['businessPreserved'];

echo 'STATUS=' . ($pass ? 'PASS K1D_RC2_REAL_BASE300_TO_TARGET301_UPGRADE' : 'BLOCKED K1D_RC2_REAL_BASE300_TO_TARGET301_UPGRADE') . "\n";
echo "BASE_300_MIGRATOR={$baseHead}:App\\Services\\Migrator::run\n";
echo "TARGET_301_MIGRATOR={$targetHead}:App\\Services\\Migrator::run\n";
echo "TARGET_TREE={$targetTree}\n";
echo 'BASE_300_CHECKSUM_122=' . $metrics['baseChecksum122'] . "\n";
echo 'TARGET_ACTIVE_CHECKSUM_122=' . $metrics['targetChecksum122'] . "\n";
echo 'HISTORICAL_CHECKSUM_DRIFT=' . $metrics['historicalChecksumDrift'] . "\n";
echo 'MIGRATION_122_SQL_REEXECUTED=' . $metrics['migration122SqlReexecuted'] . "\n";
echo 'MIGRATION_301_UPGRADE_FROM_BASE_300_PASS=' . ($metrics['upgradePass'] ? 'YES' : 'NO') . "\n";
echo 'MIGRATION_301_FRESH_PASS=' . ($metrics['freshPass'] ? 'YES' : 'NO') . "\n";
echo 'MIGRATION_122_COMPAT_EXECUTION_PASS=' . ($metrics['compatPass'] ? 'YES' : 'NO') . "\n";
echo 'MIGRATION_301_RERUN_PASS=' . ($metrics['rerunPass'] ? 'YES' : 'NO') . "\n";
echo 'NEW_MIGRATIONS_ON_RERUN=' . $metrics['newMigrationsOnRerun'] . "\n";
echo 'BUSINESS_DATA_PRESERVED=' . ($metrics['businessPreserved'] ? 'YES' : 'NO') . "\n";
if ($metrics['failure'] !== '') {
    echo 'FAILURE=' . $metrics['failure'] . "\n";
}
echo "PRODUCTION_CHANGED_BY_CODEX=NO\n";
echo "REAL_MELI_HTTP=0\n";
echo "REAL_EMAIL_SENT=0\n";

exit($pass ? 0 : 20);

function writeRunner(string $path): void
{
    $code = <<<'PHP'
<?php
declare(strict_types=1);

$root = $argv[1] ?? '';
$dbName = $argv[2] ?? '';
$limit = isset($argv[3]) ? (int) $argv[3] : 1;
$collation = $argv[4] ?? 'utf8mb4_unicode_ci';
if ($root === '' || $dbName === '') {
    fwrite(STDERR, "RUNNER_ARGS_MISSING\n");
    exit(64);
}

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = rtrim($root, '/\\') . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', $dbName),
    (string) getenv('DB_USER'),
    (string) getenv('DB_PASS'),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
    ]
);
$pdo->exec('SET NAMES utf8mb4 COLLATE ' . $collation);
$pdo->exec("SET SESSION time_zone='+00:00'");
if (class_exists('App\\Core\\Database')) {
    App\Core\Database::useProfile('migration');
    App\Core\Database::setConnection($pdo);
}
(new App\Services\Migrator($pdo, rtrim($root, '/\\') . '/database/migrations'))->run($limit);
echo "RUNNER_STATUS=PASS\n";
PHP;
    file_put_contents($path, $code);
}

function gitBytes(string $root, array $args): string
{
    $command = array_merge(['git', '-C', $root], $args);
    [$exit, $stdout, $stderr] = runCommand($command);
    if ($exit !== 0) {
        throw new RuntimeException('GIT_FAILED_' . $exit . ':' . trim($stderr));
    }
    return $stdout;
}

function gitWorktreeAdd(string $root, string $path, string $commit): void
{
    [$exit, , $stderr] = runCommand(['git', '-C', $root, 'worktree', 'add', '--detach', $path, $commit]);
    if ($exit !== 0) {
        throw new RuntimeException('WORKTREE_ADD_FAILED:' . trim($stderr));
    }
}

function gitWorktreeRemove(string $root, string $path): void
{
    if ($path === '' || !is_dir($path)) {
        return;
    }
    runCommand(['git', '-C', $root, 'worktree', 'remove', '--force', $path]);
}

function runMigratorChild(string $runner, string $root, string $dbName, string $limit, string $collation): void
{
    [$exit, $stdout, $stderr] = runCommand([PHP_BINARY, $runner, $root, $dbName, $limit, $collation]);
    if ($exit !== 0) {
        throw new RuntimeException('MIGRATOR_CHILD_FAILED_' . $exit . ':' . trim($stderr . ' ' . $stdout));
    }
}

/** @param list<string> $command @return array{0:int,1:string,2:string} */
function runCommand(array $command): array
{
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('PROC_OPEN_FAILED');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

function checksumFor(PDO $pdo, string $migration): string
{
    $stmt = $pdo->prepare('SELECT checksum_sha256 FROM system_update_migrations WHERE migration_key=? LIMIT 1');
    $stmt->execute([$migration]);
    return (string) $stmt->fetchColumn();
}

function sqlStartedEvents(PDO $pdo, string $migration): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM system_update_migration_events WHERE migration_key=? AND stage='sql_execution_started'");
    $stmt->execute([$migration]);
    return (int) $stmt->fetchColumn();
}

function driftCount(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM system_update_migrations WHERE state='drifted'")->fetchColumn();
}

function migrationApplied(PDO $pdo, string $migration): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=?');
    $stmt->execute([$migration]);
    return (int) $stmt->fetchColumn() === 1;
}

function migrationCount(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
}
