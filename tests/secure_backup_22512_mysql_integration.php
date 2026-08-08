<?php

declare(strict_types=1);

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: ERP_MIGRATOR_TEST_DSN sin dbname es obligatorio.\n");
    exit(2);
}

$source = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/erp-backup-' . bin2hex(random_bytes(5));
$shared = $temporary . '/shared';
mkdir($shared . '/storage', 0700, true);
file_put_contents($shared . '/config.env', "APP_KEY=test-only\n");
define('ERP_SHARED_ROOT', $shared);
define('ERP_RELEASE_ROOT', $source);
putenv('HOME=' . $temporary);

require $source . '/vendor/autoload.php';

$admin = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$database = 'erp_backup_' . bin2hex(random_bytes(5));
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci");
try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    App\Core\Database::setConnection($pdo);
    (new App\Services\Migrator($pdo, $source . '/database/migrations'))->run();
    $backup = (new App\Services\UpdateBackupService())->createDirect();
    if (!str_ends_with((string) $backup['path'], '.erpbackup') || !is_file((string) $backup['path'])) {
        throw new RuntimeException('No se creó el respaldo cifrado esperado.');
    }
    $head = (string) file_get_contents((string) $backup['path'], false, null, 0, 512);
    if (str_contains($head, 'CREATE TABLE') || str_contains($head, 'APP_KEY')) {
        throw new RuntimeException('El respaldo dejó contenido sensible visible.');
    }
    if ((int) $backup['tables'] < 1 || (int) $backup['size'] < 100) {
        throw new RuntimeException('El respaldo verificado no tiene contenido.');
    }
    $verified = (string) $pdo->query(
        'SELECT status FROM system_update_backups ORDER BY id DESC LIMIT 1'
    )->fetchColumn();
    if ($verified !== 'verified') {
        throw new RuntimeException('El respaldo no quedó certificado.');
    }
    echo 'secure_backup_22512_ok tables=' . (int) $backup['tables'] . PHP_EOL;
} finally {
    App\Core\Database::setConnection($admin);
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($temporary);
}
