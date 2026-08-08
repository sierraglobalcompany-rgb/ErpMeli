<?php

declare(strict_types=1);

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: se requiere un DSN MySQL/MariaDB sin base seleccionada.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_commercial_2255_' . bin2hex(random_bytes(5));
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-commercial-2255-' . bin2hex(random_bytes(5));
@mkdir($temporary . DIRECTORY_SEPARATOR . 'storage', 0770, true);
$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$serverVersion = (string) $server->query('SELECT VERSION()')->fetchColumn();
$collation = stripos($serverVersion, 'mariadb') !== false
    ? 'utf8mb4_uca1400_ai_ci'
    : 'utf8mb4_unicode_ci';
$server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE {$collation}");

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
        static fn(array $row): bool => in_array((string) ($row['status'] ?? ''), ['failed', 'drifted'], true)
    ));
    if ($failed !== []) {
        throw new RuntimeException('Falló la instalación limpia: ' . json_encode($failed));
    }

    $settings = new \App\Services\AppSettingsService();
    $settings->set('api.pacing.enabled', '1', 'api_pacing');
    $settings->set('api.pacing.adaptive_enabled', '0', 'api_pacing');
    $settings->set('api.pacing.max_wait_seconds', '1', 'api_pacing');
    foreach ([1, 20, 59] as $rpm) {
        $settings->set('api.pacing.ceiling_rpm', (string) $rpm, 'api_pacing');
        $pdo->exec('DELETE FROM api_request_pacing_state');
        (new \App\Services\ApiPacingService())->reserve(null, 'GET', '/orders/123456');
        $state = $pdo->query(
            'SELECT effective_rpm,
                    TIMESTAMPDIFF(MICROSECOND,last_reserved_at,next_allowed_at) interval_us
             FROM api_request_pacing_state WHERE scope_key="global"'
        )->fetch(PDO::FETCH_ASSOC);
        $expectedUs = (int) ceil(60_000_000 / $rpm);
        if ((int) ($state['effective_rpm'] ?? 0) !== $rpm
            || abs((int) ($state['interval_us'] ?? 0) - $expectedUs) > 5) {
            throw new RuntimeException('El intervalo persistido no corresponde a ' . $rpm . ' RPM.');
        }

        $pdo->exec(
            'UPDATE api_request_pacing_state
             SET next_allowed_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 SECOND)'
        );
        $before = (string) $pdo->query(
            'SELECT DATE_FORMAT(next_allowed_at,"%Y-%m-%d %H:%i:%s.%f")
             FROM api_request_pacing_state WHERE scope_key="global"'
        )->fetchColumn();
        try {
            (new \App\Services\ApiPacingService())->reserve(null, 'GET', '/orders/123456');
            throw new RuntimeException('El pacing reservó un turno que no cabía en la ventana permitida.');
        } catch (\App\Services\ApiBudgetExhaustedException) {
        }
        $after = (string) $pdo->query(
            'SELECT DATE_FORMAT(next_allowed_at,"%Y-%m-%d %H:%i:%s.%f")
             FROM api_request_pacing_state WHERE scope_key="global"'
        )->fetchColumn();
        if ($before !== $after) {
            throw new RuntimeException('Un turno diferido movió el checkpoint y podría causar inanición.');
        }
    }

    $pdo->exec("INSERT INTO companies (name,status) VALUES ('Empresa A',1),('Empresa B',1)");
    $companyA = (int) $pdo->query("SELECT id FROM companies WHERE name='Empresa A'")->fetchColumn();
    $companyB = (int) $pdo->query("SELECT id FROM companies WHERE name='Empresa B'")->fetchColumn();
    $accountInsert = $pdo->prepare(
        'INSERT INTO meli_accounts (company_id,account_name,meli_user_id,site_id,status)
         VALUES (?,?,?,?,?)'
    );
    $accountInsert->execute([$companyA, 'Cuenta A', 800001, 'MCO', 'conectado']);
    $accountA = (int) $pdo->lastInsertId();
    $accountInsert->execute([$companyB, 'Cuenta B', 800002, 'MCO', 'conectado']);

    // Un item que agotó todos sus intentos no puede mantener su job en
    // "partial": Cron lo reclamaría cada minuto sin trabajo recuperable.
    $pdo->prepare(
        'INSERT INTO meli_item_sync_jobs
            (meli_account_id,search_mode,phase,next_run_at)
         VALUES (?,"offset","partial",UTC_TIMESTAMP())'
    )->execute([$accountA]);
    $itemSyncJobId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO meli_item_sync_job_items
            (meli_item_sync_job_id,external_item_id,status,attempts,last_error_message)
         VALUES (?,"MCO-TERMINAL-1","error",3,"Error terminal simulado")'
    )->execute([$itemSyncJobId]);
    $itemSync = new \App\Services\MeliItemSyncJobService();
    $refresh = new ReflectionMethod($itemSync, 'refresh');
    $refresh->invoke($itemSync, $itemSyncJobId);
    $terminalPhase = (string) $pdo->query(
        'SELECT phase FROM meli_item_sync_jobs WHERE id=' . $itemSyncJobId
    )->fetchColumn();
    if ($terminalPhase !== 'error') {
        throw new RuntimeException('Un job de productos sin items reintentables permaneció en el selector de Cron.');
    }
    $emptyPass = $itemSync->processDue(1, microtime(true) + 1);
    if ((string) ($emptyPass['phase'] ?? '') !== 'empty' || (int) ($emptyPass['processed'] ?? 0) !== 0) {
        throw new RuntimeException('Cron volvió a reclamar un job de productos con errores terminales.');
    }

    $orderInsert = $pdo->prepare(
        'INSERT INTO meli_orders
            (meli_account_id,external_order_id,date_created,status,total_amount,paid_amount,currency_id,synced_at)
         VALUES (?,?,"2026-07-28 13:00:00","paid",100000,100000,"COP",UTC_TIMESTAMP())'
    );
    $orderInsert->execute([$accountA, 2000017626806190]);
    $orderId = (int) $pdo->lastInsertId();

    $financial = new \App\Services\SaleFinancialService();
    $jobId = $financial->queueFromOrderId($orderId, 'notification', 10, 10);
    $pdo->prepare(
        'UPDATE sale_financial_reconciliation_jobs
         SET status="review",remote_pending_since=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 31 DAY),
             retry_until=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY),last_remote_state="processing"
         WHERE id=?'
    )->execute([$jobId]);
    $sameJobId = $financial->queueFromOrderId($orderId, 'notification', 11, 10);
    $job = $pdo->query(
        'SELECT company_id,meli_account_id,status,remote_pending_since,retry_until,last_remote_state
         FROM sale_financial_reconciliation_jobs WHERE id=' . $jobId
    )->fetch(PDO::FETCH_ASSOC);
    if ($sameJobId !== $jobId
        || (int) ($job['company_id'] ?? 0) !== $companyA
        || (int) ($job['meli_account_id'] ?? 0) !== $accountA
        || (string) ($job['status'] ?? '') !== 'pending'
        || $job['remote_pending_since'] !== null
        || $job['retry_until'] !== null
        || $job['last_remote_state'] !== null) {
        throw new RuntimeException('La nueva notificación no reactivó limpiamente el trabajo financiero aislado.');
    }

    fwrite(STDOUT, "OK: pipeline 2.25.5 certificado con pacing 1/20/59 y reactivación financiera.\n");
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    if (is_dir($temporary)) {
        @rmdir($temporary . DIRECTORY_SEPARATOR . 'storage');
        @rmdir($temporary);
    }
}
