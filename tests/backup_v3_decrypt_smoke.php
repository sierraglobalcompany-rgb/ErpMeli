<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\AppPaths;
use App\Core\Database;
use App\Services\BackupArchiveService;
use App\Services\BackupCenterService;

Database::useProfile('cli');
$id = (int) Database::connection()->query(
    "SELECT id FROM system_backup_archives
     WHERE status='ready' AND format_version=3
     ORDER BY id DESC LIMIT 1"
)->fetchColumn();
if ($id < 1) {
    throw new RuntimeException('No existe un respaldo v3 local para comprobar.');
}
$center = new BackupCenterService();
$archive = $center->archive($id);
$source = $center->pathForArchive($archive);
$renamed = AppPaths::storage('tmp/renamed-v3-' . bin2hex(random_bytes(6)) . '.erpbackup');
$target = AppPaths::storage('tmp/backup-v3-decrypt-' . bin2hex(random_bytes(6)) . '.sql.gz');
if (!is_dir(dirname($target))) {
    @mkdir(dirname($target), 0700, true);
}
try {
    if (!copy($source, $renamed)) {
        throw new RuntimeException('No fue posible preparar la prueba de portabilidad.');
    }
    $service = new BackupArchiveService();
    $verified = $service->verify($renamed);
    $service->decryptTo($renamed, $target);
    $gzip = gzopen($target, 'rb');
    $header = $gzip === false ? false : gzgets($gzip);
    if ($gzip !== false) {
        gzclose($gzip);
    }
    if ((int) ($verified['format'] ?? 0) !== 3 || trim((string) $header) !== '-- ERP-MELI-BACKUP-3') {
        throw new RuntimeException('El respaldo v3 no se reconstruyó para restauración.');
    }
    echo json_encode([
        'status' => 'PASS',
        'format' => 3,
        'manifest' => true,
        'chunks_authenticated' => true,
        'renamed_archive' => true,
        'remote_transport' => false,
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    @unlink($target);
    @unlink($renamed);
}
