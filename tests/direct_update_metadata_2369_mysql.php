<?php

declare(strict_types=1);

$dsn = getenv('ERP_2369_MYSQL_DSN') ?: '';
$user = getenv('ERP_2369_MYSQL_USER') ?: '';
$password = getenv('ERP_2369_MYSQL_PASS') ?: '';
if ($dsn === '') {
    fwrite(STDOUT, "Direct update metadata 2.36.9: SKIP (ERP_2369_MYSQL_DSN absent)\n");
    exit(0);
}

$temporary = sys_get_temp_dir() . '/erp-meli-2369-db-' . bin2hex(random_bytes(6));
if (!mkdir($temporary . '/storage', 0770, true) && !is_dir($temporary . '/storage')) {
    throw new RuntimeException('temporary_create_failed');
}
define('ERP_INSTALLATION_ROOT', $temporary);
define('ERP_SHARED_ROOT', $temporary);
$appKey = 'local-2369-test-key-' . bin2hex(random_bytes(16));
putenv('APP_KEY=' . $appKey);

$root = dirname(__DIR__);
require $root . '/app/Core/Env.php';
require $root . '/app/Core/AppPaths.php';
require $root . '/app/Services/InstalledVersionMarkerService.php';
require $root . '/app/Services/DirectUpdateMetadataPromotionService.php';

use App\Services\DirectUpdateMetadataPromotionService;
use App\Services\InstalledVersionMarkerService;

$server = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_PERSISTENT => false,
]);
$database = 'erp_2369_' . bin2hex(random_bytes(5));
$server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
    ]);
    $pdo->exec('CREATE TABLE app_settings(setting_key VARCHAR(191) PRIMARY KEY,setting_value TEXT NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE app_versions(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,version VARCHAR(32) NOT NULL UNIQUE,notes TEXT NULL,installed_at DATETIME NOT NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO app_settings(setting_key,setting_value) VALUES('app.version','2.36.8')");
    $pdo->exec("INSERT INTO app_versions(version,notes,installed_at) VALUES('2.36.8','baseline',CURRENT_TIMESTAMP)");

    $marker = new InstalledVersionMarkerService();
    $assert($marker->write('2.36.8', '293_queue_core_runtime_profile_defaults_b2_1.sql'), 'Baseline marker write failed.');
    $baselineMarker = file_get_contents($temporary . '/storage/installed-release.json');
    $assert(is_string($baselineMarker), 'Baseline marker missing.');

    $service = new DirectUpdateMetadataPromotionService();
    $result = $service->promote(
        $pdo,
        '2.36.9',
        '293_queue_core_runtime_profile_defaults_b2_1.sql',
        'local test'
    );
    $assert($result['previous_version'] === '2.36.8', 'Previous version receipt mismatch.');
    $assert($pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn() === '2.36.9', 'app.version was not promoted.');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM app_versions WHERE version='2.36.9'")->fetchColumn() === 1, 'Target history row missing.');
    $observed = $marker->read();
    $assert($observed['valid'] && $observed['version'] === '2.36.9', 'Target marker invalid.');

    $service->promote($pdo, '2.36.9', '293_queue_core_runtime_profile_defaults_b2_1.sql', 'repeat');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM app_versions WHERE version='2.36.9'")->fetchColumn() === 1, 'Idempotent retry duplicated history.');

    $pdo->exec("UPDATE app_settings SET setting_value='2.36.10' WHERE setting_key='app.version'");
    $markerBeforeDowngrade = file_get_contents($temporary . '/storage/installed-release.json');
    try {
        $service->promote($pdo, '2.36.9', '293_queue_core_runtime_profile_defaults_b2_1.sql');
        throw new RuntimeException('Downgrade unexpectedly succeeded.');
    } catch (RuntimeException $expected) {
        $assert($expected->getMessage() === 'direct_update_downgrade_refused', 'Unexpected downgrade error.');
    }
    $assert(file_get_contents($temporary . '/storage/installed-release.json') === $markerBeforeDowngrade, 'Downgrade changed marker.');

    $pdo->exec("UPDATE app_settings SET setting_value='2.36.8' WHERE setting_key='app.version'");
    $pdo->exec("DELETE FROM app_versions WHERE version='2.36.9'");
    file_put_contents($temporary . '/storage/installed-release.json', $baselineMarker);
    putenv('APP_KEY');
    $_ENV['APP_KEY'] = '';
    $_SERVER['APP_KEY'] = '';
    try {
        $service->promote($pdo, '2.36.9', '293_queue_core_runtime_profile_defaults_b2_1.sql');
        throw new RuntimeException('Marker failure unexpectedly succeeded.');
    } catch (RuntimeException $expected) {
        $assert(
            $expected->getMessage() === 'installed_release_marker_write_failed',
            'Unexpected marker failure error: ' . $expected->getMessage()
        );
    }
    $assert($pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn() === '2.36.8', 'DB rollback after marker failure failed.');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM app_versions WHERE version='2.36.9'")->fetchColumn() === 0, 'History rollback after marker failure failed.');
    $assert(file_get_contents($temporary . '/storage/installed-release.json') === $baselineMarker, 'Marker preimage changed on failed promotion.');

    putenv('APP_KEY=' . $appKey);
    unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);
    @unlink($temporary . '/storage/installed-release.json');
    $service->promote($pdo, '2.36.9', '293_queue_core_runtime_profile_defaults_b2_1.sql', 'absent marker');
    $absentTarget = $marker->read();
    $assert($absentTarget['valid'] && $absentTarget['version'] === '2.36.9', 'Absent marker was not promoted safely.');

    $pdo->exec("UPDATE app_settings SET setting_value='2.36.8' WHERE setting_key='app.version'");
    $pdo->exec("DELETE FROM app_versions WHERE version='2.36.9'");
    file_put_contents($temporary . '/storage/installed-release.json', '{"tampered":true}');
    $service->promote($pdo, '2.36.9', '293_queue_core_runtime_profile_defaults_b2_1.sql', 'tampered marker');
    $tamperedTarget = $marker->read();
    $assert($tamperedTarget['valid'] && $tamperedTarget['version'] === '2.36.9', 'Tampered marker was not replaced by exact target authority.');

    $pdo->exec("UPDATE app_settings SET setting_value='2.36.8' WHERE setting_key='app.version'");
    $pdo->exec("DELETE FROM app_versions WHERE version='2.36.9'");
    file_put_contents($temporary . '/storage/installed-release.json', $baselineMarker);
    $contender = new PDO($dsn . ';dbname=' . $database, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
    ]);
    $assert((int) $contender->query("SELECT GET_LOCK('erp_meli_direct_update_metadata',0)")->fetchColumn() === 1, 'Could not acquire contention fixture lock.');
    try {
        $service->promote($pdo, '2.36.9', '293_queue_core_runtime_profile_defaults_b2_1.sql', 'contended');
        throw new RuntimeException('Contended promotion unexpectedly succeeded.');
    } catch (RuntimeException $expected) {
        $assert($expected->getMessage() === 'direct_update_lock_busy', 'Unexpected contention failure.');
    } finally {
        $contender->query("SELECT RELEASE_LOCK('erp_meli_direct_update_metadata')");
        $contender = null;
    }
    $assert($pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn() === '2.36.8', 'Lock contention changed app.version.');
    $assert(file_get_contents($temporary . '/storage/installed-release.json') === $baselineMarker, 'Lock contention changed marker.');

    $stale = new PDO($dsn . ';dbname=' . $database, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
    ]);
    $stale->exec('SET SESSION wait_timeout=1');
    sleep(2);
    try {
        $stale->query('SELECT 1');
        throw new RuntimeException('Expired connection unexpectedly remained usable.');
    } catch (PDOException) {
        $assert(true, 'Expired connection was rejected.');
    }
    $fresh = new PDO($dsn . ';dbname=' . $database, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false,
    ]);
    $service->promote($fresh, '2.36.9', '293_queue_core_runtime_profile_defaults_b2_1.sql', 'fresh after expiry');
    $assert($fresh->query("SELECT setting_value FROM app_settings WHERE setting_key='app.version'")->fetchColumn() === '2.36.9', 'Fresh connection did not complete promotion.');

    fwrite(STDOUT, 'Direct update metadata 2.36.9: PASS checks=' . $checks . PHP_EOL);
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    @unlink($temporary . '/storage/installed-release.json');
    @rmdir($temporary . '/storage');
    @rmdir($temporary);
    putenv('APP_KEY');
}
