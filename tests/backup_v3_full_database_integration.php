<?php

declare(strict_types=1);

/**
 * Certificación conductual de respaldo V3 y restauración sobre el clon local
 * completo. No modifica datos comerciales ni realiza transporte remoto.
 */

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\AppPaths;
use App\Core\Database;
use App\Core\Env;
use App\Services\BackupArchiveService;
use App\Services\BackupCenterService;
use App\Services\EmergencyControlService;
use App\Services\RestoreService;

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
});

/** @param bool $condition */
function fullBackupAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

Database::useProfile('cli');
$pdo = Database::connection();
$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
fullBackupAssert(
    str_contains($version, 'MariaDB') && version_compare($version, '11.8.0', '>='),
    'La certificación exige MariaDB 11.8 real.'
);
$safety = new EmergencyControlService();
fullBackupAssert(
    $safety->apiStopped() && $safety->automationStopped(),
    'La certificación exige ambas paradas físicas.'
);
fullBackupAssert(
    (int) $pdo->query(
        "SELECT COUNT(*) FROM system_backup_archives
         WHERE status IN ('prepared','queued','creating','verifying')"
    )->fetchColumn() === 0,
    'Ya existe una copia activa.'
);
fullBackupAssert(
    (int) $pdo->query(
        "SELECT COUNT(*) FROM system_restore_plans
         WHERE status IN ('prepared','validated','queued','restoring','verifying','ready_to_switch')"
    )->fetchColumn() === 0,
    'Ya existe una restauración activa.'
);

$sourceTables = (int) $pdo->query(
    'SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema=DATABASE() AND table_type="BASE TABLE"'
)->fetchColumn();
$sourceOrders = (int) $pdo->query('SELECT COUNT(*) FROM meli_orders')->fetchColumn();
$sourceItems = (int) $pdo->query('SELECT COUNT(*) FROM meli_order_items')->fetchColumn();
$sourceCampaign = $pdo->query(
    'SELECT status,total_items,total_units,completed_items,completed_units
     FROM manual_campaigns WHERE id=6'
)->fetch(PDO::FETCH_ASSOC);
fullBackupAssert(is_array($sourceCampaign), 'La campaña #6 no existe en el clon.');

$center = new BackupCenterService();
$backupId = 0;
$backupPath = null;
$restoreId = 0;
$restoreDatabase = 'erp_backup_v3_restore_' . bin2hex(random_bytes(5));
$renamed = AppPaths::storage('tmp/portable-v3-' . bin2hex(random_bytes(5)) . '.erpbackup');
$server = null;
$result = [];

try {
    $job = $center->enqueue(1, 'manual');
    $backupId = (int) $job['id'];
    for ($cycle = 1; $cycle <= 1000; $cycle++) {
        $result = $center->processRequested();
        if ((string) ($result['result'] ?? '') === 'completed') {
            break;
        }
        fullBackupAssert(
            (string) ($result['result'] ?? '') === 'partial',
            'El respaldo devolvió un estado no reanudable.'
        );
    }
    fullBackupAssert(
        (string) ($result['result'] ?? '') === 'completed',
        'El respaldo V3 no terminó dentro del límite de ciclos.'
    );
    $archive = $center->archive($backupId);
    $backupPath = $center->pathForArchive($archive);
    $verified = (new BackupArchiveService())->verify($backupPath);
    $manifest = $verified['manifest'];
    $checks = [];
    foreach (is_array($manifest['tables'] ?? null) ? $manifest['tables'] : [] as $check) {
        if (is_array($check)) {
            $name = (string) ($check['table'] ?? $check['table_name'] ?? '');
            if ($name !== '') {
                $checks[$name] = (int) ($check['rows'] ?? $check['row_count'] ?? -1);
            }
        }
    }
    foreach ([
        'system_backup_archives',
        'system_backup_jobs',
        'system_backup_table_checks',
        'system_restore_plans',
    ] as $catalog) {
        fullBackupAssert(
            array_key_exists($catalog, $checks) && $checks[$catalog] === 0,
            'El respaldo no conserva la estructura vacía de ' . $catalog . '.'
        );
    }
    fullBackupAssert(
        ($checks['meli_orders'] ?? -1) === $sourceOrders
        && ($checks['meli_order_items'] ?? -1) === $sourceItems,
        'El respaldo truncó órdenes o productos vendidos.'
    );
    fullBackupAssert(
        (int) ($manifest['table_count'] ?? 0) === $sourceTables,
        'El manifiesto no contiene todas las tablas.'
    );
    if (!is_dir(dirname($renamed))) {
        mkdir(dirname($renamed), 0700, true);
    }
    fullBackupAssert(copy($backupPath, $renamed), 'No se pudo preparar la copia renombrada.');
    (new BackupArchiveService())->verify($renamed);

    $host = (string) Env::get('DB_HOST', '127.0.0.1');
    $port = (string) Env::get('DB_PORT', '3306');
    $user = (string) Env::get('DB_USER', '');
    $pass = (string) Env::get('DB_PASS', '');
    $server = new PDO(
        'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $server->exec(
        'CREATE DATABASE `' . $restoreDatabase
        . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci'
    );
    $restore = new RestoreService();
    $plan = $restore->prepare($backupId, 1, 'diagnostic_clone', [
        'host' => $host,
        'port' => $port,
        'database' => $restoreDatabase,
        'user' => $user,
        'password' => $pass,
    ]);
    $restoreId = (int) $plan['id'];
    $restoreResult = [];
    for ($cycle = 1; $cycle <= 100; $cycle++) {
        $restoreResult = $restore->processRequested();
        if ((string) ($restoreResult['result'] ?? '') === 'completed') {
            break;
        }
        fullBackupAssert(
            (string) ($restoreResult['result'] ?? '') === 'partial',
            'La restauración devolvió un estado no reanudable.'
        );
    }
    fullBackupAssert(
        (string) ($restoreResult['result'] ?? '') === 'completed',
        'La restauración no terminó dentro del límite de ciclos.'
    );
    $target = new PDO(
        'mysql:host=' . $host . ';port=' . $port . ';dbname='
        . $restoreDatabase . ';charset=utf8mb4',
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $targetTables = (int) $target->query(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_type="BASE TABLE"'
    )->fetchColumn();
    fullBackupAssert($targetTables === $sourceTables, 'La base restaurada perdió tablas.');
    fullBackupAssert(
        (int) $target->query('SELECT COUNT(*) FROM meli_orders')->fetchColumn() === $sourceOrders
        && (int) $target->query('SELECT COUNT(*) FROM meli_order_items')->fetchColumn() === $sourceItems,
        'La base restaurada perdió órdenes o productos vendidos.'
    );
    $acceptance = $target->query(
        "SELECT COUNT(DISTINCT o.id) AS api_orders,
                COUNT(*) AS product_lines,SUM(oi.quantity) AS units
         FROM meli_orders o
         INNER JOIN meli_order_items oi ON oi.meli_order_id=o.id
         WHERE o.external_pack_id='2000014234269247'"
    )->fetch(PDO::FETCH_ASSOC);
    fullBackupAssert(
        is_array($acceptance)
        && (int) $acceptance['api_orders'] === 2
        && (int) $acceptance['product_lines'] === 2
        && (int) $acceptance['units'] === 3,
        'La venta de aceptación no conserva 2 órdenes, 2 productos y 3 unidades.'
    );
    $targetCampaign = $target->query(
        'SELECT status,total_items,total_units,completed_items,completed_units
         FROM manual_campaigns WHERE id=6'
    )->fetch(PDO::FETCH_ASSOC);
    fullBackupAssert(
        is_array($targetCampaign) && $targetCampaign === $sourceCampaign,
        'La campaña #6 cambió durante respaldo o restauración.'
    );
    foreach ([
        'system_backup_archives',
        'system_backup_jobs',
        'system_backup_table_checks',
        'system_restore_plans',
    ] as $catalog) {
        fullBackupAssert(
            (int) $target->query(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name=' . $target->quote($catalog)
            )->fetchColumn() === 1,
            'La restauración no creó ' . $catalog . '.'
        );
    }
    fullBackupAssert(
        (int) $target->query('SELECT COUNT(*) FROM meli_tokens')->fetchColumn() === 0,
        'El clon diagnóstico conserva tokens utilizables.'
    );

    echo json_encode([
        'status' => 'PASS',
        'engine' => $version,
        'source_tables' => $sourceTables,
        'orders' => $sourceOrders,
        'items' => $sourceItems,
        'renamed_archive' => true,
        'restored_catalog_schema' => true,
        'acceptance_sale' => '2/2/3',
        'campaign_6' => 'preserved',
        'remote_transport' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    @unlink($renamed);
    if ($server instanceof PDO) {
        $server->exec('DROP DATABASE IF EXISTS `' . $restoreDatabase . '`');
    }
    if ($restoreId > 0) {
        $pdo->prepare('DELETE FROM system_restore_plans WHERE id=:id')
            ->execute(['id' => $restoreId]);
    }
    if ($backupId > 0) {
        $pdo->prepare('DELETE FROM system_backup_archives WHERE id=:id')
            ->execute(['id' => $backupId]);
    }
    if (is_string($backupPath) && $backupPath !== '') {
        @unlink($backupPath);
    }
}
