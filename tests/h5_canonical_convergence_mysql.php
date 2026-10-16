<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\CronAdmissionService;
use App\Services\OrderEnrichmentService;
use App\Services\SaleFinancialService;

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: H5 exige un DSN MariaDB desechable sin base seleccionada.\n");
    exit(2);
}
$root = dirname(__DIR__);
$database = 'erp_h5_' . bin2hex(random_bytes(5));
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-h5-' . bin2hex(random_bytes(5));
@mkdir($temporary . DIRECTORY_SEPARATOR . 'storage', 0770, true);
@mkdir($temporary . DIRECTORY_SEPARATOR . 'private', 0700, true);
putenv('APP_KEY=h5-local-test-only');
putenv('ML_WRITE_ENABLED=false');
putenv('ERP_PRIVATE_PATH=' . $temporary . DIRECTORY_SEPARATOR . 'private');
$_ENV['APP_KEY'] = 'h5-local-test-only';
define('ERP_SHARED_ROOT', $temporary);
define('ERP_INSTALLATION_ROOT', $temporary);
define('ERP_RELEASE_ROOT', $root);

spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$version = (string) $server->query('SELECT VERSION()')->fetchColumn();
if (stripos($version, 'mariadb') === false) {
    fwrite(STDERR, "ERROR: H5 exige MariaDB.\n");
    exit(2);
}
$server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $target = $path . DIRECTORY_SEPARATOR . $entry;
        is_dir($target) ? $removeTree($target) : unlink($target);
    }
    rmdir($path);
};

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET SESSION time_zone='+00:00'");
    Database::setConnection($pdo);

    $pdo->exec('CREATE TABLE companies(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(160),status TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_accounts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,account_name VARCHAR(160),status VARCHAR(20) DEFAULT "active") ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE queue_v4_clean_jobs(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        job_type ENUM("fresh_orders_discovery","order_exact","domain_exact") NOT NULL,
        resource_id VARCHAR(191) NOT NULL,
        idempotency_key VARCHAR(191) NOT NULL,
        payload_json LONGTEXT NULL,
        state VARCHAR(20) NOT NULL DEFAULT "ready",
        available_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        max_attempts INT NOT NULL DEFAULT 3,
        attempt_count INT NOT NULL DEFAULT 0,
        lease_owner VARCHAR(80) NULL,
        lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        UNIQUE KEY uq_qv4_job(company_id,meli_account_id,job_type,idempotency_key)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE sale_financial_state(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        sale_key VARCHAR(191) NOT NULL,
        input_version CHAR(64) NOT NULL,
        official_status VARCHAR(30) NOT NULL DEFAULT "missing",
        UNIQUE KEY uq_state(company_id,meli_account_id,sale_key,input_version)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_sale_financials(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        sale_key VARCHAR(191) NOT NULL,
        external_sale_id VARCHAR(191) NOT NULL,
        identity_type VARCHAR(20) NOT NULL,
        currency_id VARCHAR(8) NOT NULL,
        reconciliation_status VARCHAR(30) NOT NULL,
        safe_message VARCHAR(500) NULL,
        UNIQUE KEY uq_sale_financial(meli_account_id,sale_key)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE sale_financial_reconciliation_jobs(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        sale_key VARCHAR(191) NOT NULL,
        external_sale_id VARCHAR(191) NOT NULL,
        input_version CHAR(64) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT "pending",
        priority_tier INT NOT NULL DEFAULT 30,
        origin_type VARCHAR(40) NOT NULL,
        origin_id BIGINT NULL,
        created_by BIGINT NULL,
        next_run_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
        remote_pending_since DATETIME NULL,
        retry_until DATETIME NULL,
        last_remote_state VARCHAR(30) NULL,
        safe_message VARCHAR(500) NULL,
        completed_at DATETIME NULL,
        attempts INT NOT NULL DEFAULT 0,
        UNIQUE KEY uq_sale_reconciliation(company_id,meli_account_id,sale_key,input_version)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_orders(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        external_order_id VARCHAR(80) NOT NULL,
        external_pack_id VARCHAR(80) NULL,
        enrichment_status VARCHAR(40) NULL,
        enrichment_error_message VARCHAR(500) NULL,
        enriched_at DATETIME NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_packs(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        external_pack_id VARCHAR(80) NOT NULL,
        external_shipment_id VARCHAR(80) NULL,
        expected_orders_json LONGTEXT NULL,
        linked_orders_count INT NULL,
        integrity_status VARCHAR(40) NULL,
        UNIQUE KEY uq_pack(meli_account_id,external_pack_id)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_pack_orders(meli_pack_id BIGINT UNSIGNED NOT NULL,meli_order_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(meli_pack_id,meli_order_id)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE order_resource_enrichment_jobs(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        meli_order_id BIGINT UNSIGNED NOT NULL,
        resource_type VARCHAR(40) NOT NULL,
        external_resource_id VARCHAR(100) NOT NULL,
        status VARCHAR(40) NOT NULL DEFAULT "pending",
        priority INT NOT NULL DEFAULT 50,
        attempts INT NOT NULL DEFAULT 0,
        next_run_at DATETIME NULL,
        locked_at DATETIME NULL,
        lock_token VARCHAR(80) NULL,
        lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
        heartbeat_at DATETIME NULL,
        last_started_at DATETIME NULL,
        completed_at DATETIME NULL,
        last_processed_at DATETIME NULL,
        last_error_message VARCHAR(500) NULL,
        last_error_diagnostic_id VARCHAR(80) NULL,
        last_error_code VARCHAR(80) NULL,
        failure_class VARCHAR(80) NULL,
        reached_remote TINYINT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE order_resource_enrichment_job_orders(order_resource_enrichment_job_id BIGINT UNSIGNED NOT NULL,meli_order_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(order_resource_enrichment_job_id,meli_order_id)) ENGINE=InnoDB');

    $pdo->exec("INSERT INTO companies(id,name,status) VALUES (10,'Tenant A',1),(20,'Tenant B',1)");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name) VALUES (101,10,'A'),(202,20,'B')");
    $pdo->exec("INSERT INTO meli_orders(id,meli_account_id,external_order_id,external_pack_id) VALUES (1001,101,'ORDER-1','PACK-1'),(2001,202,'ORDER-X','PACK-1')");
    $versionHash = str_repeat('a', 64);
    $pdo->prepare('INSERT INTO sale_financial_state(company_id,meli_account_id,sale_key,input_version,official_status) VALUES (10,101,?,?, "queued")')
        ->execute(['P:PACK-1', $versionHash]);

    $enqueueSale = new ReflectionMethod(SaleFinancialService::class, 'enqueueSale');
    $enqueueSale->setAccessible(true);

    $pdo->prepare('INSERT INTO sale_financial_reconciliation_jobs(company_id,meli_account_id,sale_key,external_sale_id,input_version,status,origin_type) VALUES (10,101,"P:PACK-1","PACK-1",?,"retry","financial_recalc_local")')
        ->execute([$versionHash]);
    $existingId = (int) $pdo->lastInsertId();
    $resultId = (int) $enqueueSale->invoke(new SaleFinancialService(), 10, 101, 'P:PACK-1', 'PACK-1', 'pack', 'COP', 'financial_recalc_local', 7, 30, null, $versionHash);
    $assert($resultId === $existingId, 'finance_existing_source_id_changed');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='domain_exact' AND resource_id='{$existingId}' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))='financial_reconciliation'")->fetchColumn() === 1, 'finance_existing_source_missing_pointer');

    $freshId = (int) $enqueueSale->invoke(new SaleFinancialService(), 10, 101, 'P:PACK-2', 'PACK-2', 'pack', 'COP', 'domain_input_changed', 8, 30, null, str_repeat('b', 64));
    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE resource_id='{$freshId}' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))='financial_reconciliation'")->fetchColumn() === 1, 'finance_new_source_missing_pointer');

    $pdo->prepare('INSERT INTO sale_financial_state(company_id,meli_account_id,sale_key,input_version,official_status) VALUES (10,101,?,?, "queued")')
        ->execute(['P:PACK-COMPLETE', str_repeat('c', 64)]);
    $pdo->prepare('INSERT INTO sale_financial_reconciliation_jobs(company_id,meli_account_id,sale_key,external_sale_id,input_version,status,origin_type) VALUES (10,101,"P:PACK-COMPLETE","PACK-COMPLETE",?,"complete","financial_recalc_local")')
        ->execute([str_repeat('c', 64)]);
    $completeId = (int) $pdo->lastInsertId();
    $enqueueSale->invoke(new SaleFinancialService(), 10, 101, 'P:PACK-COMPLETE', 'PACK-COMPLETE', 'pack', 'COP', 'financial_recalc_local', 9, 30, null, str_repeat('c', 64));
    $assert((string) $pdo->query("SELECT status FROM sale_financial_reconciliation_jobs WHERE id={$completeId}")->fetchColumn() === 'complete', 'finance_complete_reopened');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE resource_id='{$completeId}'")->fetchColumn() === 0, 'finance_complete_pointer_created');

    $pdo->exec("INSERT INTO meli_packs(id,meli_account_id,external_pack_id,expected_orders_json,integrity_status) VALUES (501,101,'PACK-DONE','[\"ORDER-1\"]','complete')");
    $skipped = (new OrderEnrichmentService())->enqueue(101, 1001, 'pack', 'PACK-DONE', 10);
    $assert($skipped === 0, 'complete_pack_enrichment_not_skipped');

    $jobId = (new OrderEnrichmentService())->enqueue(101, 1001, 'pack', 'PACK-1', 10);
    $assert($jobId > 0, 'pack_enrichment_source_not_created');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='domain_exact' AND resource_id='{$jobId}' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))='order_enrichment'")->fetchColumn() === 1, 'pack_enrichment_pointer_missing');
    $again = (new OrderEnrichmentService())->enqueue(101, 1001, 'pack', 'PACK-1', 10);
    $assert($again === $jobId, 'pack_enrichment_not_deduped');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE meli_account_id=101 AND resource_type='pack' AND external_resource_id='PACK-1'")->fetchColumn() === 1, 'pack_enrichment_duplicate_source');

    $admission = new CronAdmissionService($pdo);
    $pdo->beginTransaction();
    $badTenant = $admission->submit('order_enrichment', 20, 101, $jobId, 'bad-tenant');
    $pdo->rollBack();
    $assert(($badTenant['reason'] ?? '') === 'INVALID_TENANT', 'order_enrichment_cross_tenant_not_blocked');

    $workerSource = (string) file_get_contents($root . '/app/QueueV4Clean/QueueV4CleanWorker.php');
    $policySource = (string) file_get_contents($root . '/app/Services/MeliTransportSourcePolicy.php');
    $fenceSource = (string) file_get_contents($root . '/app/QueueV4Clean/QueueV4CleanDispatchFence.php');
    $syncSource = (string) file_get_contents($root . '/app/Services/OrderSyncService.php');
    $assert(str_contains($workerSource, "'order_enrichment'"), 'worker_order_enrichment_capability_missing');
    $assert(str_contains($workerSource, 'OrderEnrichmentService())->processExact($sourceId)'), 'worker_order_enrichment_handler_missing');
    $assert(str_contains($policySource, "#^/packs/[0-9]+$#D") && str_contains($policySource, "#^/shipments/[0-9]+$#D"), 'domain_exact_pack_shipment_policy_missing');
    $assert(str_contains($fenceSource, 'pack_exact') && str_contains($fenceSource, 'shipment_exact'), 'dispatch_fence_pack_shipment_missing');
    $assert(str_contains($syncSource, 'enqueueOrder($accountId, $orderId, $packId, $shipmentId)'), 'order_exact_enrichment_admission_missing');

    echo 'H5_CANONICAL_CONVERGENCE_MYSQL=PASS checks=' . $checks . ' real_http=0 migrations=0 new_tables=0 new_queues=0' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'H5_CANONICAL_CONVERGENCE_MYSQL=FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    try {
        $server->exec("DROP DATABASE IF EXISTS `{$database}`");
    } catch (Throwable) {
    }
    $removeTree($temporary);
}
