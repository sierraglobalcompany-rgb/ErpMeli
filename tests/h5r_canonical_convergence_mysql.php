<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\CronAdmissionService;
use App\Services\OrderEnrichmentService;

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: H5R exige un DSN MariaDB desechable sin base seleccionada.\n");
    exit(2);
}
$root = dirname(__DIR__);
$database = 'erp_h5r_' . bin2hex(random_bytes(5));
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-h5r-' . bin2hex(random_bytes(5));
@mkdir($temporary . DIRECTORY_SEPARATOR . 'private', 0700, true);
putenv('APP_KEY=h5r-local-test-only');
putenv('ML_WRITE_ENABLED=false');
putenv('ERP_PRIVATE_PATH=' . $temporary . DIRECTORY_SEPARATOR . 'private');
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
    fwrite(STDERR, "ERROR: H5R exige MariaDB.\n");
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
$runTool = static function (string $script, array $args = []) use ($root, $database, $dsn, $user, $pass): array {
    preg_match('/host=([^;]+)/', $dsn, $hostMatch);
    preg_match('/port=([^;]+)/', $dsn, $portMatch);
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = array_merge($_ENV, [
        'DB_HOST' => $hostMatch[1] ?? '127.0.0.1',
        'DB_PORT' => $portMatch[1] ?? '3306',
        'DB_NAME' => $database,
        'DB_USER' => $user,
        'DB_PASS' => $pass,
        'APP_KEY' => 'h5r-local-test-only',
        'ML_WRITE_ENABLED' => 'false',
    ]);
    $proc = proc_open($command, $descriptor, $pipes, $root, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException('tool_process_failed');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $map = [];
    foreach (preg_split('/\R/', trim($stdout)) ?: [] as $line) {
        if ($line !== '' && str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $map[$key] = $value;
        }
    }
    return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr, 'map' => $map];
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
    $pdo->exec('CREATE TABLE meli_accounts(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,account_name VARCHAR(160),meli_user_id VARCHAR(80),status VARCHAR(20) DEFAULT "active") ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE queue_v4_clean_control(control_key VARCHAR(64) PRIMARY KEY,engine_state VARCHAR(20),readiness_state VARCHAR(20),scheduler_enabled TINYINT DEFAULT 1,readiness_passed_accounts INT DEFAULT 0,last_scheduler_at DATETIME(3) NULL,updated_at DATETIME(3) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE queue_v4_clean_runs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,launcher ENUM("scheduler","manual","test") NOT NULL,worker_ref VARCHAR(96),status ENUM("running","completed","failed","stopped") NOT NULL DEFAULT "running",jobs_claimed INT UNSIGNED NOT NULL DEFAULT 0,jobs_completed INT UNSIGNED NOT NULL DEFAULT 0,jobs_deferred INT UNSIGNED NOT NULL DEFAULT 0,started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),finished_at DATETIME(3) NULL) ENGINE=InnoDB');
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
        lease_expires_at DATETIME(3) NULL,
        lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_error_class VARCHAR(100) NULL,
        completed_at DATETIME(3) NULL,
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        UNIQUE KEY uq_qv4_job(company_id,meli_account_id,job_type,idempotency_key)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE queue_v4_clean_attempts(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        job_id BIGINT UNSIGNED,
        run_id BIGINT UNSIGNED,
        company_id BIGINT UNSIGNED,
        meli_account_id BIGINT UNSIGNED,
        lease_owner VARCHAR(80),
        lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
        outcome VARCHAR(40) NOT NULL DEFAULT "running",
        dispatch_state VARCHAR(40) NOT NULL DEFAULT "NOT_DISPATCHED",
        started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        finished_at DATETIME(3) NULL,
        source_closed_at DATETIME(3) NULL,
        error_class VARCHAR(100) NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE sale_financial_reconciliation_jobs(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NULL,
        meli_account_id BIGINT UNSIGNED NULL,
        sale_key VARCHAR(191) NOT NULL,
        external_sale_id VARCHAR(191) NOT NULL,
        input_version CHAR(64) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT "pending",
        priority_tier INT NOT NULL DEFAULT 30,
        origin_type VARCHAR(40) NULL,
        origin_id BIGINT NULL,
        next_run_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
        safe_message VARCHAR(500) NULL,
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
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id) VALUES (101,10,'A','501'),(202,20,'B','502')");
    $pdo->exec("INSERT INTO queue_v4_clean_control(control_key,engine_state,readiness_state,scheduler_enabled,readiness_passed_accounts,last_scheduler_at,updated_at) VALUES ('primary','ACTIVE','CERTIFIED',1,2,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    for ($i = 55; $i >= 0; $i--) {
        $stmt = $pdo->prepare("INSERT INTO queue_v4_clean_runs(launcher,worker_ref,status,jobs_claimed,jobs_completed,jobs_deferred,started_at,finished_at) VALUES ('scheduler','test','completed',3,3,0,DATE_SUB(UTC_TIMESTAMP(3), INTERVAL ? MINUTE),DATE_SUB(UTC_TIMESTAMP(3), INTERVAL ? MINUTE))");
        $stmt->execute([$i, $i]);
    }

    $hash = str_repeat('a', 64);
    $pdo->prepare("INSERT INTO sale_financial_reconciliation_jobs(company_id,meli_account_id,sale_key,external_sale_id,input_version,status,origin_type) VALUES (10,101,'D:1','D1',?,'retry','financial_recalc_local')")
        ->execute([$hash]);
    $domainId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO sale_financial_reconciliation_jobs(company_id,meli_account_id,sale_key,external_sale_id,input_version,status,origin_type) VALUES (10,101,'N:1','N1',?,'retry','manual_import')")
        ->execute([$hash]);

    $financePlan = $runTool('artifacts-local/h5r-canonical-convergence/H5R_FINANCE_POINTER_RECOVERY.php', ['--plan', '--limit=10', '--releases-per-hour=1']);
    $assert($financePlan['code'] === 0, 'finance_plan_failed:' . $financePlan['stderr'] . $financePlan['stdout']);
    $assert(($financePlan['map']['CAPACITY_CERTIFIED'] ?? '') === 'YES', 'capacity_not_certified');
    $assert(($financePlan['map']['DOMAIN_MISSING_POINTER'] ?? '') === '1', 'domain_missing_pointer_count_wrong');
    $assert(($financePlan['map']['NON_DOMAIN_WITHOUT_QUEUE_V4_POINTER'] ?? '') === '1', 'non_domain_source_without_pointer_not_reported');
    $assert(($financePlan['map']['CURRENT_BATCH_SELECTED'] ?? '') === '1', 'non_domain_source_without_pointer_recovered');
    $financeSha = (string) $financePlan['map']['PLAN_SHA256'];
    $financeDry = $runTool('artifacts-local/h5r-canonical-convergence/H5R_FINANCE_POINTER_RECOVERY.php', ['--dry-run', '--limit=10', '--releases-per-hour=1', '--authorization-token=H5R_FINANCE_POINTER_RECOVERY_EXPLICITLY_AUTHORIZED', '--expected-plan-sha256=' . $financeSha]);
    $assert($financeDry['code'] === 0, 'finance_dry_run_failed:' . $financeDry['stderr'] . $financeDry['stdout']);
    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE resource_id='{$domainId}'")->fetchColumn() === 0, 'finance_dry_run_left_pointer');
    $rateBlocked = $runTool('artifacts-local/h5r-canonical-convergence/H5R_FINANCE_POINTER_RECOVERY.php', ['--execute', '--limit=10', '--releases-per-hour=999']);
    $assert($rateBlocked['code'] !== 0 && str_contains($rateBlocked['stdout'], 'requested_rate_exceeds_capacity'), 'finance_rate_gate_not_blocked');

    $pdo->exec("INSERT INTO meli_orders(id,meli_account_id,external_order_id,external_pack_id) VALUES (1001,101,'ORDER-1','PACK-1'),(1002,101,'ORDER-2','PACK-2'),(1003,101,'ORDER-3','PACK-COMPLETE')");
    $pdo->exec("INSERT INTO meli_packs(id,meli_account_id,external_pack_id,expected_orders_json,integrity_status) VALUES (501,101,'PACK-1',NULL,'partial'),(502,101,'PACK-2',NULL,'partial'),(503,101,'PACK-COMPLETE',NULL,'partial')");
    $pdo->exec("INSERT INTO meli_pack_orders(meli_pack_id,meli_order_id) VALUES (501,1001),(502,1002),(503,1003)");
    $pdo->exec("INSERT INTO order_resource_enrichment_jobs(id,meli_account_id,meli_order_id,resource_type,external_resource_id,status,next_run_at) VALUES (701,101,1002,'pack','PACK-2','retry',UTC_TIMESTAMP()),(702,101,1003,'pack','PACK-COMPLETE','complete',UTC_TIMESTAMP())");
    $packPlan = $runTool('artifacts-local/h5r-canonical-convergence/H5R_PACK_ENRICHMENT_RECOVERY.php', ['--plan', '--limit=10', '--releases-per-hour=1']);
    $assert($packPlan['code'] === 0, 'pack_plan_failed:' . $packPlan['stderr'] . $packPlan['stdout']);
    $assert(($packPlan['map']['CURRENT_BATCH_SELECTED'] ?? '') === '2', 'pack_batch_selected_wrong');
    $packSha = (string) $packPlan['map']['PLAN_SHA256'];
    $packDry = $runTool('artifacts-local/h5r-canonical-convergence/H5R_PACK_ENRICHMENT_RECOVERY.php', ['--dry-run', '--limit=10', '--releases-per-hour=1', '--authorization-token=H5R_PACK_ENRICHMENT_RECOVERY_EXPLICITLY_AUTHORIZED', '--expected-plan-sha256=' . $packSha]);
    $assert($packDry['code'] === 0, 'pack_dry_run_failed:' . $packDry['stderr'] . $packDry['stdout']);
    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))='order_enrichment'")->fetchColumn() === 0, 'pack_dry_run_left_pointer');
    $pdo->exec("UPDATE order_resource_enrichment_jobs SET status='running' WHERE id=701");
    $packBlocked = $runTool('artifacts-local/h5r-canonical-convergence/H5R_PACK_ENRICHMENT_RECOVERY.php', ['--plan', '--limit=10', '--releases-per-hour=1']);
    $assert($packBlocked['code'] !== 0 && str_contains($packBlocked['stdout'], 'source_running_without_active_pointer'), 'pack_running_anomaly_not_blocked');

    $pdo->exec("UPDATE order_resource_enrichment_jobs SET status='retry' WHERE id=701");
    $repo = new QueueV4CleanRepository($pdo);
    $admission = new CronAdmissionService($pdo);
    $pdo->beginTransaction();
    $receipt = $admission->submit('order_enrichment', 10, 101, 701, 'source:701', ['resource_type' => 'pack']);
    $pdo->commit();
    $assert(($receipt['accepted'] ?? false) === true, 'order_enrichment_admission_failed');
    $handlerCalls = 0;
    $worker = new QueueV4CleanWorker(
        $pdo,
        $repo,
        null,
        null,
        null,
        static function (string $capability, int $sourceId, int $accountId) use ($pdo, &$handlerCalls): void {
            $handlerCalls++;
            $pdo->prepare("UPDATE order_resource_enrichment_jobs SET status='complete',completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=? AND meli_account_id=?")
                ->execute([$sourceId, $accountId]);
        }
    );
    $result = $worker->run('scheduler', 1, 10);
    $assert($result['claimed'] === 1 && $result['completed'] === 1, 'worker_domain_exact_order_enrichment_not_completed');
    $assert($handlerCalls === 1, 'worker_domain_handler_not_called_once');
    $assert((string) $pdo->query('SELECT status FROM order_resource_enrichment_jobs WHERE id=701')->fetchColumn() === 'complete', 'worker_domain_source_not_completed');

    echo 'H5R_CANONICAL_CONVERGENCE_MYSQL=PASS checks=' . $checks
        . ' NON_DOMAIN_SOURCE_WITHOUT_POINTER_NOT_RECOVERED=PASS'
        . ' CAPACITY_GATE=PASS STRONG_PLAN_SHA=PASS PACK_POINTER_VERIFY=PASS'
        . ' WORKER_DOMAIN_EXACT_E2E=PASS real_http=0 migrations=0 new_tables=0 new_queues=0' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'H5R_CANONICAL_CONVERGENCE_MYSQL=FAIL ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    try {
        $server->exec("DROP DATABASE IF EXISTS `{$database}`");
    } catch (Throwable) {
    }
    $removeTree($temporary);
}
