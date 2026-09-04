<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';

use App\Core\Database;
use App\Services\ManagedRuntimePublicationPolicy;
use App\Services\Migrator;
use App\Services\ReleaseIntegrityService;

putenv('APP_ENV=test');
putenv('ML_WRITE_ENABLED=false');
putenv('DB_HOST=' . (getenv('DB_HOST') ?: '127.0.0.1'));
putenv('DB_PORT=' . (getenv('DB_PORT') ?: '3306'));
putenv('DB_USER=' . (getenv('DB_USER') ?: 'root'));
putenv('DB_PASS=' . (string) getenv('DB_PASS'));
putenv('DB_NAME=erp_meli_k1d_test_rc1_release_' . strtolower(bin2hex(random_bytes(4))));

$root = realpath(__DIR__ . '/..');
k1b_assert(is_string($root), 'REPO_ROOT_FOUND');

$harness = K1dSafeTestDatabase::createFromEnvironment();
$componentCount = 0;
$componentMatch = 0;
$componentMissing = 0;
$componentMismatch = 0;
$publicationIssues = [];
$managedRegistryJson = 'FAIL';
$registryCrossHash = 'FAIL';
$failure = '';
$inspection = [];
$releaseErrors = [];

try {
    $pdo = $harness->pdo();
    $migrationPath = realpath($root . '/database/migrations');
    k1b_assert(is_string($migrationPath), 'MIGRATION_PATH_FOUND');
    (new Migrator($pdo, $migrationPath))->run(301);
    $version = trim((string) file_get_contents($root . '/VERSION'));
    $stmt = $pdo->prepare(
        'INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
         VALUES ("app.version",?,0,"system")
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group)'
    );
    $stmt->execute([$version]);

    $inspection = (new ReleaseIntegrityService())->inspectDirectory($root, true, false);
    $releaseErrors = is_array($inspection['errors'] ?? null) ? $inspection['errors'] : [];
    $components = is_array($inspection['components'] ?? null) ? $inspection['components'] : [];
    $componentCount = count($components);
    foreach ($components as $component) {
        if (empty($component['exists'])) {
            $componentMissing++;
        } elseif (empty($component['matches'])) {
            $componentMismatch++;
        } else {
            $componentMatch++;
        }
    }

    $manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true, 32, JSON_THROW_ON_ERROR);
    k1b_assert(is_array($manifest), 'RUNTIME_MANIFEST_JSON');
    $publicationIssues = ManagedRuntimePublicationPolicy::installedManifestIssues($root, $manifest);

    $registryPath = $root . '/resources/release/managed-runtime-dependencies-2.40.1.json';
    $registry = json_decode((string) file_get_contents($registryPath), true, 32, JSON_THROW_ON_ERROR);
    $managedRegistryJson = is_array($registry) && (int) ($registry['schema_version'] ?? 0) === 1 ? 'PASS' : 'FAIL';

    $manifestPaths = [];
    foreach ($components as $component) {
        $path = (string) ($component['path'] ?? '');
        if ($path !== '') {
            $manifestPaths[] = $path;
        }
    }
    sort($manifestPaths, SORT_STRING);
    $computedPathsHash = hash('sha256', implode("\n", $manifestPaths) . "\n");
    $registryCrossHash = hash_equals($computedPathsHash, (string) ($registry['runtime_manifest_paths_sha256'] ?? '')) ? 'PASS' : 'FAIL';
} catch (Throwable $error) {
    $failure = get_class($error) . ':' . preg_replace('/\s+/', ' ', $error->getMessage());
} finally {
    $harness->cleanup();
}

$pass = $failure === ''
    && !empty($inspection['ok'])
    && $componentCount === 1240
    && $componentMatch === 1240
    && $componentMissing === 0
    && $componentMismatch === 0
    && count($publicationIssues) === 0
    && $managedRegistryJson === 'PASS'
    && $registryCrossHash === 'PASS';

echo 'STATUS=' . ($pass ? 'PASS K1D_RC1_RELEASE_INTEGRITY_CANONICAL' : 'BLOCKED K1D_RC1_RELEASE_INTEGRITY_CANONICAL') . "\n";
echo 'RELEASE_INTEGRITY_OK=' . (!empty($inspection['ok']) ? 'YES' : 'NO') . "\n";
echo 'RELEASE_STATE=' . (string) ($inspection['state'] ?? '') . "\n";
echo 'RUNTIME_VERSION=' . (string) ($inspection['version'] ?? '') . "\n";
echo 'MINIMUM_MIGRATION=' . (string) ($inspection['minimum_migration'] ?? '') . "\n";
echo "RUNTIME_COMPONENT_COUNT={$componentCount}\n";
echo "RUNTIME_COMPONENT_MATCH={$componentMatch}\n";
echo "RUNTIME_COMPONENT_MISSING={$componentMissing}\n";
echo "RUNTIME_COMPONENT_MISMATCH={$componentMismatch}\n";
echo 'PUBLICATION_POLICY_ISSUES=' . count($publicationIssues) . "\n";
echo "MANAGED_REGISTRY_JSON={$managedRegistryJson}\n";
echo "REGISTRY_CROSS_HASH={$registryCrossHash}\n";
echo 'RELEASE_INTEGRITY_ERROR_COUNT=' . count($releaseErrors) . "\n";
if ($releaseErrors !== []) {
    echo 'RELEASE_INTEGRITY_ERRORS_JSON=' . json_encode($releaseErrors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}
if ($failure !== '') {
    echo "FAILURE={$failure}\n";
}
echo "PRODUCTION_CHANGED_BY_CODEX=NO\n";
echo "REAL_MELI_HTTP=0\n";
echo "REAL_EMAIL_SENT=0\n";

exit($pass ? 0 : 20);
