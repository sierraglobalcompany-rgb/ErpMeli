<?php

declare(strict_types=1);

/**
 * Certifica Control de ventas contra el esquema MySQL/MariaDB real completo.
 *
 * El DSN debe apuntar al servidor, nunca a una base existente.
 */

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
$strict = filter_var((string) getenv('ERP_RELEASE_STRICT'), FILTER_VALIDATE_BOOL);
if ($dsn === '') {
    if ($strict) {
        fwrite(STDERR, "ERROR: ERP_MIGRATOR_TEST_DSN es obligatorio para certificar la release.\n");
        exit(2);
    }
    fwrite(STDOUT, "SKIP: defina ERP_MIGRATOR_TEST_DSN para probar Control de ventas 2.21.1.\n");
    exit(0);
}
if (!str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: el DSN debe ser MySQL y no puede seleccionar una base existente.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_sales_control_2211_' . bin2hex(random_bytes(5));
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-sales-control-2211-' . bin2hex(random_bytes(5));
@mkdir($temporary . DIRECTORY_SEPARATOR . 'storage', 0770, true);

$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$serverVersion = (string) $server->query('SELECT VERSION()')->fetchColumn();
if ($strict) {
    $isMariaDb = stripos($serverVersion, 'mariadb') !== false;
    $numericVersion = preg_replace('/[^0-9.].*$/', '', $serverVersion) ?: '0';
    $supported = $isMariaDb
        ? version_compare($numericVersion, '11.8.0', '>=')
        : version_compare($numericVersion, '8.0.0', '>=');
    if (!$supported) {
        fwrite(
            STDERR,
            'ERROR: la certificación exige MariaDB 11.8+ o MySQL 8+. Detectado: '
            . preg_replace('/[^A-Za-z0-9_.-]/', '_', $serverVersion) . ".\n"
        );
        exit(2);
    }
}
$server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    define('ERP_SHARED_ROOT', $temporary);
    require $root . '/bootstrap.php';
    restore_exception_handler();
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    \App\Core\Database::setConnection($pdo);

    $results = (new \App\Services\Migrator($pdo, $root . '/database/migrations'))->run();
    $failed = array_values(array_filter(
        $results,
        static fn (array $row): bool => in_array((string) ($row['status'] ?? ''), ['failed', 'drifted'], true)
    ));
    if ($failed !== []) {
        throw new RuntimeException('Una migración falló durante la instalación limpia: ' . json_encode($failed));
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM app_versions WHERE version='2.24.0'")->fetchColumn() !== 1) {
        throw new RuntimeException('No se registró la versión 2.24.0.');
    }
    if ((int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='meli_accounts' AND COLUMN_NAME='deleted_at'"
    )->fetchColumn() !== 0) {
        throw new RuntimeException('La prueba requiere el esquema canónico sin meli_accounts.deleted_at.');
    }

    $pdo->exec(
        "INSERT INTO companies (name,status,deleted_at)
         VALUES ('Empresa activa',1,NULL),('Empresa eliminada',0,UTC_TIMESTAMP())"
    );
    $activeCompany = (int) $pdo->query("SELECT id FROM companies WHERE name='Empresa activa'")->fetchColumn();
    $deletedCompany = (int) $pdo->query("SELECT id FROM companies WHERE name='Empresa eliminada'")->fetchColumn();
    $accountInsert = $pdo->prepare(
        'INSERT INTO meli_accounts
         (company_id,account_name,meli_user_id,site_id,status)
         VALUES (?,?,?,?,?)'
    );
    $accountInsert->execute([$activeCompany, 'Cuenta conectada', 900001, 'MCO', 'conectado']);
    $connectedId = (int) $pdo->lastInsertId();
    $accountInsert->execute([$activeCompany, 'Cuenta histórica', 900002, 'MCO', 'desconectado']);
    $historicalId = (int) $pdo->lastInsertId();
    $accountInsert->execute([$deletedCompany, 'Cuenta oculta', 900003, 'MCO', 'conectado']);

    $gateway = new \App\Services\SalesAuditAccessGateway();
    $accounts = $gateway->accounts();
    if (count($accounts) !== 2) {
        throw new RuntimeException('El listado debe conservar la cuenta histórica y ocultar la empresa eliminada.');
    }
    if ((int) $gateway->account($connectedId, $activeCompany)['company_id'] !== $activeCompany) {
        throw new RuntimeException('La cuenta no quedó aislada por empresa.');
    }
    try {
        $gateway->account($connectedId, $deletedCompany);
        throw new RuntimeException('Se permitió cruzar una cuenta con otra empresa.');
    } catch (\App\Core\HttpException $expected) {
        if ($expected->status !== 404) {
            throw $expected;
        }
    }

    $control = new \App\Services\SalesControlService();
    $schema = $control->schemaStatus();
    if (!$schema['ready'] || !$control->available()) {
        throw new RuntimeException('El contrato de esquema no reconoce la instalación completa.');
    }
    $overview = $control->overview($connectedId, 2026, $activeCompany);
    if (count($overview['months']) !== 12 || (string) $overview['months'][0]['name'] !== 'Enero') {
        throw new RuntimeException('El resumen anual no presentó enero y los doce meses.');
    }

    $jobId = $control->checkMonth($connectedId, 2026, 1, $activeCompany, null);
    $sameJobId = $control->checkMonth($connectedId, 2026, 1, $activeCompany, null);
    if ($jobId <= 0 || $jobId !== $sameJobId) {
        throw new RuntimeException('Comprobar enero no es idempotente.');
    }
    try {
        $control->checkMonth($historicalId, 2026, 1, $activeCompany, null);
        throw new RuntimeException('Una cuenta desconectada pudo crear una consulta nueva.');
    } catch (RuntimeException $expected) {
        if (!str_contains($expected->getMessage(), 'reautorizarla')) {
            throw $expected;
        }
    }

    $auditAdapter = null;
    foreach ((new \App\Services\WorkQueueRegistry())->adapters() as $adapter) {
        if ($adapter->key() === 'sales_audit') {
            $auditAdapter = $adapter;
            break;
        }
    }
    if (!$auditAdapter instanceof \App\Services\WorkQueueAdapter) {
        throw new RuntimeException('No se encontró el adaptador sales_audit.');
    }
    $projected = $auditAdapter->project();
    if (!$auditAdapter->projectSucceeded() || count($projected) !== 1) {
        throw new RuntimeException('La cola sales_audit no pudo proyectar started_at.');
    }

    $runId = (int) $pdo->query(
        'SELECT sync_sales_audit_run_id FROM sync_sales_audit_jobs WHERE id=' . $jobId
    )->fetchColumn();
    $pdo->prepare(
        'UPDATE sync_sales_audit_runs
         SET status="complete",remote_coverage="complete",local_presence="complete",
             temporal_quality="correct",reconciliation_status="ready",
             snapshot_hash=?,coverage_http_status=200,remote_reported_total=0,
             remote_unique_total=0,checked_total=0,completed_at=UTC_TIMESTAMP(),
             capture_finished_at=UTC_TIMESTAMP()
         WHERE id=?'
    )->execute([hash('sha256', 'empty-january'), $runId]);
    $pdo->prepare(
        'INSERT INTO sync_sales_audit_run_pages
         (sync_sales_audit_run_id,company_id,meli_account_id,page_offset,page_limit,
          result_count,remote_reported_total,http_status,ids_hash)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([
        $runId,
        $activeCompany,
        $connectedId,
        0,
        50,
        0,
        0,
        200,
        hash('sha256', ''),
    ]);
    $coverage = (new \App\Services\VerifiedSalesCaptureService())->verifyCoverage($runId);
    if (!$coverage['valid']) {
        throw new RuntimeException('La captura vacía y completa no superó la validación estricta.');
    }
    $pdo->prepare(
        'UPDATE sync_sales_audit_runs
         SET coverage_validation_state="valid",coverage_validation_json=? WHERE id=?'
    )->execute([json_encode($coverage, JSON_UNESCAPED_UNICODE), $runId]);
    $control->recordAuditRun($runId);
    $capture = $pdo->prepare(
        'SELECT coverage_confidence,requested_from_utc,requested_to_utc
         FROM sales_control_captures WHERE sync_sales_audit_run_id=?'
    );
    $capture->execute([$runId]);
    $captureRow = $capture->fetch(PDO::FETCH_ASSOC);
    if (!is_array($captureRow)
        || (string) $captureRow['coverage_confidence'] !== 'complete'
        || empty($captureRow['requested_from_utc'])
        || empty($captureRow['requested_to_utc'])) {
        throw new RuntimeException('La evidencia mensual no conservó rango y cobertura verificable.');
    }

    $orderInsert = $pdo->prepare(
        'INSERT INTO meli_orders
            (meli_account_id,external_order_id,external_pack_id,date_created,status,
             total_amount,paid_amount,currency_id,synced_at)
         VALUES (?,?,?,"2026-07-28 13:04:00","paid",?,?, "COP",UTC_TIMESTAMP())'
    );
    $orderInsert->execute([$connectedId, 2000017629499388, 2000014234269247, 60191, 60191]);
    $firstOrder = (int) $pdo->lastInsertId();
    $orderInsert->execute([$connectedId, 2000017629499386, 2000014234269247, 49980, 49980]);
    $secondOrder = (int) $pdo->lastInsertId();
    $itemInsert = $pdo->prepare(
        'INSERT INTO meli_order_items
            (meli_order_id,meli_account_id,external_item_id,title,seller_sku,quantity,unit_price,sale_fee)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $itemInsert->execute([$firstOrder, $connectedId, 'MCO-PACK-ONE', 'Kit de bolsas y bomba', 'PACK-ONE', 1, 60191, 10533]);
    $itemInsert->execute([$secondOrder, $connectedId, 'MCO-PACK-TWO', 'Bolsas al vacío', 'PACK-TWO', 2, 24990, 8746]);
    $rebuilt = (new \App\Services\HistoricalPackReconciliationService())->rebuildLocal($connectedId);
    if ($rebuilt['packs'] !== 1
        || (int) $pdo->query(
            'SELECT COUNT(*) FROM meli_pack_orders po
             JOIN meli_packs p ON p.id=po.meli_pack_id
             WHERE p.meli_account_id=' . $connectedId . ' AND p.external_pack_id=2000014234269247'
        )->fetchColumn() !== 2) {
        throw new RuntimeException('La reconstrucción local no agrupó las dos órdenes bajo el pack visible.');
    }
    $pdo->exec(
        "UPDATE meli_packs
         SET integrity_status='complete',expected_orders_count=2,linked_orders_count=2,
             expected_orders_json='[\"2000017629499388\",\"2000017629499386\"]',
             verified_at=UTC_TIMESTAMP()
         WHERE meli_account_id={$connectedId} AND external_pack_id=2000014234269247"
    );
    $financial = (new \App\Services\SaleFinancialService())->calculate([
        ['id' => 1, 'gross' => 60191, 'units' => 1],
        ['id' => 2, 'gross' => 49980, 'units' => 2],
    ], [
        ['line_group' => 'sale_fee', 'amount' => 10533],
        ['line_group' => 'sale_fee', 'amount' => 8746],
        ['line_group' => 'shipping', 'amount' => 25600],
        ['line_group' => 'tax', 'amount' => 4027],
    ]);
    if ((float) $financial['net_amount'] !== 61265.0) {
        throw new RuntimeException('La conciliación agrupada no produjo el neto de aceptación $61.265.');
    }
    $dailyQueue = new ReflectionMethod(\App\Services\SaleFinancialService::class, 'enqueueDailyDue');
    $dailyQueue->invoke(new \App\Services\SaleFinancialService());
    if ((int) $pdo->query(
        "SELECT COUNT(*) FROM sale_financial_reconciliation_jobs
         WHERE meli_account_id={$connectedId} AND sale_key='P:2000014234269247'"
    )->fetchColumn() !== 1) {
        throw new RuntimeException('La captura diaria no encoló una sola conciliación por venta agrupada.');
    }
    $projectedKeys = [];
    foreach ((new \App\Services\WorkQueueRegistry())->adapters() as $adapter) {
        if (!in_array($adapter->key(), ['sale_pack_reconciliation', 'sale_financial_reconciliation'], true)) {
            continue;
        }
        $adapter->project();
        if (!$adapter->projectSucceeded()) {
            throw new RuntimeException('La cola nueva no pudo proyectarse: ' . $adapter->key());
        }
        $projectedKeys[] = $adapter->key();
    }
    sort($projectedKeys);
    if ($projectedKeys !== ['sale_financial_reconciliation', 'sale_pack_reconciliation']) {
        throw new RuntimeException('Automatización no registró ambas colas agrupadas.');
    }

    $second = (new \App\Services\Migrator($pdo, $root . '/database/migrations'))->run();
    if (count(array_filter($second, static fn (array $row): bool => ($row['status'] ?? '') !== 'skip')) !== 0) {
        throw new RuntimeException('La segunda ejecución de migraciones no fue idempotente.');
    }
    fwrite(STDOUT, "OK: Ventas 2.24.0 certificadas con enero, pack histórico y neto agrupado.\n");
} finally {
    $server->exec("DROP DATABASE IF EXISTS `{$database}`");
    if (is_dir($temporary)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($temporary);
    }
}
