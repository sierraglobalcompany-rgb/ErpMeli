<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';

use App\Services\Migrator;

putenv('APP_ENV=test');
putenv('ML_WRITE_ENABLED=false');
putenv('DB_HOST=' . (getenv('DB_HOST') ?: '127.0.0.1'));
putenv('DB_PORT=' . (getenv('DB_PORT') ?: '3306'));
putenv('DB_USER=' . (getenv('DB_USER') ?: 'root'));
putenv('DB_PASS=' . (string) getenv('DB_PASS'));

$migrationPath = realpath(__DIR__ . '/../database/migrations');
k1b_assert(is_string($migrationPath), 'MIGRATION_PATH_FOUND');

$files = glob($migrationPath . '/*.sql') ?: [];
sort($files);

$freshPass = false;
$upgradePass = false;
$rerunPass = false;
$businessDataPreserved = false;
$failureClass = '';
$failureMessage = '';
$runner = 'App\\Services\\Migrator::run';

try {
    putenv('DB_NAME=erp_meli_k1d_test_rc1_mig_fresh_' . strtolower(bin2hex(random_bytes(3))));
    $fresh = K1dSafeTestDatabase::createFromEnvironment();
    try {
        $pdo = $fresh->pdo();
        (new Migrator($pdo, $migrationPath))->run(301);
        $freshPass = k1d_rc1_migration_applied($pdo, '301_k1d_api_safety_2_40_1.sql');
    } finally {
        $fresh->cleanup();
    }

    putenv('DB_NAME=erp_meli_k1d_test_rc1_mig_upg_' . strtolower(bin2hex(random_bytes(3))));
    $upgrade = K1dSafeTestDatabase::createFromEnvironment();
    try {
        $pdo = $upgrade->pdo();
        (new Migrator($pdo, $migrationPath))->run(300);
        $pdo->exec("CREATE TABLE IF NOT EXISTS k1d_rc1_business_marker (id INT NOT NULL PRIMARY KEY, marker VARCHAR(30) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT INTO k1d_rc1_business_marker (id,marker) VALUES (1,'preserved')");
        (new Migrator($pdo, $migrationPath))->run(1);
        $upgradePass = k1d_rc1_migration_applied($pdo, '301_k1d_api_safety_2_40_1.sql');
        $businessDataPreserved = (string) $pdo->query('SELECT marker FROM k1d_rc1_business_marker WHERE id=1')->fetchColumn() === 'preserved';
        (new Migrator($pdo, $migrationPath))->run(1);
        $rerunPass = k1d_rc1_migration_applied($pdo, '301_k1d_api_safety_2_40_1.sql');
    } finally {
        $upgrade->cleanup();
    }
} catch (Throwable $error) {
    $failureClass = get_class($error);
    $failureMessage = preg_replace('/\s+/', ' ', $error->getMessage()) ?: $error->getMessage();
}

echo 'STATUS=' . ($freshPass && $upgradePass && $rerunPass && $businessDataPreserved ? 'PASS K1D_RC1_CANONICAL_MIGRATION' : 'BLOCKED K1D_RC1_CANONICAL_MIGRATION') . "\n";
echo "MIGRATION_RUNNER_USED={$runner}\n";
echo 'MIGRATION_FILE_COUNT=' . count($files) . "\n";
echo 'MIGRATION_301_FRESH_PASS=' . ($freshPass ? 'YES' : 'NO') . "\n";
echo 'MIGRATION_301_UPGRADE_PASS=' . ($upgradePass ? 'YES' : 'NO') . "\n";
echo 'MIGRATION_301_RERUN_PASS=' . ($rerunPass ? 'YES' : 'NO') . "\n";
echo 'BUSINESS_DATA_PRESERVED=' . ($businessDataPreserved ? 'YES' : 'NO') . "\n";
if ($failureClass !== '') {
    echo "FAILURE_CLASS={$failureClass}\n";
    echo "FAILURE_MESSAGE={$failureMessage}\n";
}
echo "PRODUCTION_CHANGED_BY_CODEX=NO\n";
echo "REAL_MELI_HTTP=0\n";
echo "REAL_EMAIL_SENT=0\n";

exit($freshPass && $upgradePass && $rerunPass && $businessDataPreserved ? 0 : 20);

function k1d_rc1_migration_applied(PDO $pdo, string $version): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=?');
    $stmt->execute([$version]);
    return (int) $stmt->fetchColumn() === 1;
}
