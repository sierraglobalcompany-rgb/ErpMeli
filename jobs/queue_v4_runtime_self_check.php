<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\PrivatePathAuthority;
use App\QueueV4Clean\QueueV4CleanDatabaseContract;
use App\Services\MeliCliRuntimeCapabilityService;
use App\Services\QueueOAuthDurableRecoveryStore;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

$capabilityService = new MeliCliRuntimeCapabilityService();
$capabilities = $capabilityService->inspect();
$storage = false;
$databaseContract = false;
$authority = new PrivatePathAuthority();
$rootIdentity = ['source' => 'UNKNOWN', 'id' => ''];
$path = [
    'absolute' => false,
    'symlink_free' => false,
    'derived_public_root_available' => false,
    'document_root_state' => 'INVALID',
    'inside_served_tree' => true,
];
$probe = ['created' => false, 'removed' => false];
try {
    $rootIdentity = $authority->privateRootIdentity();
    $store = new QueueOAuthDurableRecoveryStore(null, $authority);
    $path = $store->pathDiagnostics();
    try {
        $store->assertStorageReady();
    } finally {
        $probe = $store->probeStatus();
    }
    $rootIdentity = $authority->privateRootIdentity();
    $storage = true;
} catch (Throwable) {
    // La autoridad de filesystem se informa sin rutas ni excepciones.
}
try {
    Database::useProfile('cli');
    $pdo = Database::connectionFresh();
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $databaseContract = (new QueueV4CleanDatabaseContract($pdo))->issues() === [];
} catch (Throwable) {
    // La autoridad de base de datos es independiente y fail-closed.
}

$verdict = $capabilityService->oauthReady($capabilities) && $storage && $databaseContract;
echo 'PHP_VERSION=' . $capabilities['php_version'] . PHP_EOL;
echo 'CURL_AVAILABLE=' . ($capabilities['curl_available'] ? 'YES' : 'NO') . PHP_EOL;
echo 'PDO_MYSQL_AVAILABLE=' . ($capabilities['pdo_mysql_available'] ? 'YES' : 'NO') . PHP_EOL;
echo 'JSON_AVAILABLE=' . ($capabilities['json_available'] ? 'YES' : 'NO') . PHP_EOL;
echo 'CRYPTO_AVAILABLE=' . ($capabilities['crypto_available'] ? 'YES' : 'NO') . PHP_EOL;
echo 'FSYNC_AVAILABLE=' . ($capabilities['fsync_available'] ? 'YES' : 'NO') . PHP_EOL;
echo 'PRIVATE_ROOT_SOURCE=' . $rootIdentity['source'] . PHP_EOL;
echo 'PRIVATE_ROOT_ID=' . $rootIdentity['id'] . PHP_EOL;
echo 'PRIVATE_PATH_ABSOLUTE=' . (($path['absolute'] ?? false) ? 'YES' : 'NO') . PHP_EOL;
echo 'PRIVATE_PATH_SYMLINK_FREE=' . (($path['symlink_free'] ?? false) ? 'YES' : 'NO') . PHP_EOL;
echo 'DERIVED_PUBLIC_ROOT_AVAILABLE=' . (($path['derived_public_root_available'] ?? false) ? 'YES' : 'NO') . PHP_EOL;
echo 'DOCUMENT_ROOT_STATE=' . ($path['document_root_state'] ?? 'INVALID') . PHP_EOL;
echo 'PRIVATE_INSIDE_SERVED_TREE=' . (($path['inside_served_tree'] ?? true) ? 'YES' : 'NO') . PHP_EOL;
echo 'PRIVATE_STORAGE_READY=' . ($storage ? 'YES' : 'NO') . PHP_EOL;
echo 'EPHEMERAL_PRIVATE_PROBE_CREATED=' . ($probe['created'] ? 'YES' : 'NO') . PHP_EOL;
echo 'EPHEMERAL_PRIVATE_PROBE_REMOVED=' . ($probe['removed'] ? 'YES' : 'NO') . PHP_EOL;
echo 'DB_CONTRACT=' . ($databaseContract ? 'PASS' : 'BLOCK') . PHP_EOL;
echo 'RUNTIME_CAPABILITY_VERDICT=' . ($verdict ? 'PASS' : 'BLOCK') . PHP_EOL;
exit($verdict ? 0 : 1);
