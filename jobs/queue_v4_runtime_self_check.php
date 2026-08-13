<?php

declare(strict_types=1);

use App\Core\Database;
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
try {
    Database::useProfile('cli');
    $pdo = Database::connectionFresh();
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $databaseContract = (new QueueV4CleanDatabaseContract($pdo))->issues() === [];
    (new QueueOAuthDurableRecoveryStore())->assertStorageReady();
    $storage = true;
} catch (Throwable) {
    // El self-check sólo publica estados booleanos sanitizados.
}

$verdict = $capabilityService->oauthReady($capabilities) && $storage && $databaseContract;
echo 'PHP_VERSION=' . $capabilities['php_version'] . PHP_EOL;
echo 'CURL_AVAILABLE=' . ($capabilities['curl_available'] ? 'YES' : 'NO') . PHP_EOL;
echo 'PDO_MYSQL_AVAILABLE=' . ($capabilities['pdo_mysql_available'] ? 'YES' : 'NO') . PHP_EOL;
echo 'JSON_AVAILABLE=' . ($capabilities['json_available'] ? 'YES' : 'NO') . PHP_EOL;
echo 'CRYPTO_AVAILABLE=' . ($capabilities['crypto_available'] ? 'YES' : 'NO') . PHP_EOL;
echo 'PRIVATE_STORAGE_READY=' . ($storage ? 'YES' : 'NO') . PHP_EOL;
echo 'DB_CONTRACT=' . ($databaseContract ? 'PASS' : 'BLOCK') . PHP_EOL;
echo 'RUNTIME_CAPABILITY_VERDICT=' . ($verdict ? 'PASS' : 'BLOCK') . PHP_EOL;
exit($verdict ? 0 : 1);
