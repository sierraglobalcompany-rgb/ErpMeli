<?php

declare(strict_types=1);

use App\QueueV4Clean\QueueV4CleanRepository;
use App\Services\CronAdmissionService;

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: ERP_MIGRATOR_TEST_DSN debe ser un DSN MariaDB descartable sin dbname.\n");
    exit(2);
}

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$database = 'erp_f1_effective_' . bin2hex(random_bytes(5));
$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$ts = static function (string $value): int {
    $parsed = strtotime($value . ' UTC');
    if ($parsed === false) {
        throw new RuntimeException('invalid_timestamp:' . $value);
    }

    return $parsed;
};

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET SESSION time_zone='+00:00'");
    $pdo->exec("CREATE TABLE meli_accounts(
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        account_name VARCHAR(160) NULL,
        KEY idx_account_company(company_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE meli_packs(
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        external_pack_id VARCHAR(191) NOT NULL,
        integrity_status VARCHAR(32) NOT NULL DEFAULT 'provisional',
        UNIQUE KEY uq_pack_account_external(meli_account_id,external_pack_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE sale_financial_reconciliation_jobs(
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        sale_key VARCHAR(191) NOT NULL,
        external_sale_id VARCHAR(191) NOT NULL,
        input_version CHAR(64) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'retry',
        next_run_at DATETIME(3) NULL,
        safe_message VARCHAR(500) NULL,
        lease_expires_at DATETIME(3) NULL,
        lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
        KEY idx_source_tenant(company_id,meli_account_id,status,next_run_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE order_financial_recalc_jobs(
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE queue_v4_clean_jobs(
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        job_type ENUM('fresh_orders_discovery','order_exact','domain_exact') NOT NULL,
        resource_id VARCHAR(191) NULL,
        idempotency_key VARCHAR(191) NOT NULL,
        payload_json JSON NOT NULL,
        state ENUM('ready','running','completed','waiting','review','dead') NOT NULL DEFAULT 'ready',
        attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
        max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
        available_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        lease_owner VARCHAR(96) NULL,
        lease_expires_at DATETIME(3) NULL,
        lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_error_class VARCHAR(100) NULL,
        completed_at DATETIME(3) NULL,
        created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        UNIQUE KEY uq_qv4_idempotency(company_id,meli_account_id,job_type,idempotency_key),
        KEY idx_qv4_claim(state,available_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE queue_v4_clean_runs(
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        launcher ENUM('scheduler','manual','test') NOT NULL,
        worker_ref VARCHAR(96) NOT NULL,
        status ENUM('running','completed','failed','stopped') NOT NULL DEFAULT 'running',
        jobs_claimed INT UNSIGNED NOT NULL DEFAULT 0,
        jobs_completed INT UNSIGNED NOT NULL DEFAULT 0,
        jobs_deferred INT UNSIGNED NOT NULL DEFAULT 0,
        started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        finished_at DATETIME(3) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE queue_v4_clean_attempts(
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        job_id BIGINT UNSIGNED NOT NULL,
        run_id BIGINT UNSIGNED NOT NULL,
        company_id BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        lease_owner VARCHAR(96) NOT NULL,
        lease_generation BIGINT UNSIGNED NOT NULL,
        outcome ENUM('running','completed','waiting','review','dead') NOT NULL DEFAULT 'running',
        error_class VARCHAR(100) NULL,
        dispatch_state VARCHAR(40) NOT NULL DEFAULT 'NOT_DISPATCHED',
        physical_http_calls INT UNSIGNED NOT NULL DEFAULT 0,
        http_status SMALLINT UNSIGNED NULL,
        started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        finished_at DATETIME(3) NULL,
        source_closed_at DATETIME(3) NULL,
        KEY idx_attempt_job(job_id,lease_owner,lease_generation,outcome)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name) VALUES (101,10,'Cuenta F1'),(202,20,'Otra cuenta')");
    $pdo->exec("INSERT INTO meli_packs(meli_account_id,external_pack_id,integrity_status)
        VALUES (101,'PACK-PARTIAL','partial'),(101,'PACK-COMPLETE','complete')");

    $repo = new QueueV4CleanRepository($pdo);
    $admission = new CronAdmissionService($pdo);
    $futureAt = gmdate('Y-m-d H:i:s', time() + 3600);
    $sourceStmt = $pdo->prepare(
        "INSERT INTO sale_financial_reconciliation_jobs
         (company_id,meli_account_id,sale_key,external_sale_id,input_version,status,next_run_at)
         VALUES (10,101,?,?,?, 'retry', ?)"
    );
    $sourceStmt->execute(['O:1001', '1001', hash('sha256', 'input-1001'), $futureAt]);
    $futureSourceId = (int) $pdo->lastInsertId();

    $pdo->beginTransaction();
    $receipt = $admission->submit(
        'financial_reconciliation',
        10,
        101,
        $futureSourceId,
        'source:' . $futureSourceId
    );
    $pdo->commit();
    $assert($receipt['accepted'] === true && $receipt['deduplicated'] === false, 'future_finance_admission_not_accepted');
    $futurePointer = $pdo->query(
        "SELECT id,state,available_at,attempt_count
         FROM queue_v4_clean_jobs
         WHERE id=" . (int) $receipt['job_id']
    )->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($futurePointer), 'future_finance_pointer_missing');
    $assert((string) $futurePointer['state'] === 'waiting', 'future_finance_pointer_ready_before_t');
    $assert($ts((string) $futurePointer['available_at']) >= $ts($futureAt), 'future_finance_available_before_next_run_at');
    $sourceNextRunAt = (string) $pdo->query("SELECT next_run_at FROM sale_financial_reconciliation_jobs WHERE id={$futureSourceId}")->fetchColumn();
    $assert(abs($ts($sourceNextRunAt) - $ts($futureAt)) <= 1, 'source_next_run_at_not_preserved');

    $runId = $repo->beginRun('test', 'f1-owner-no-finance');
    $assert($repo->claim($runId, 'f1-owner-no-finance') === null, 'finance_future_claimed_before_t');
    $repo->finishRun($runId, 'completed');

    $packSourceStmt = $pdo->prepare(
        "INSERT INTO sale_financial_reconciliation_jobs
         (company_id,meli_account_id,sale_key,external_sale_id,input_version,status,next_run_at,safe_message)
         VALUES (10,101,?,?,?, 'retry', DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND), ?)"
    );
    $packSourceStmt->execute(['P:PACK-PARTIAL', 'PACK-PARTIAL', hash('sha256', 'pack-partial'), 'PACK_INCOMPLETE']);
    $partialPackSourceId = (int) $pdo->lastInsertId();
    $partialPackPayload = json_encode(['capability' => 'financial_reconciliation', 'source_id' => $partialPackSourceId], JSON_THROW_ON_ERROR);
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at,last_error_class)
         VALUES (10,101,'domain_exact',?,'domain:financial_reconciliation:pack-partial',?,'ready',DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 40 SECOND),NULL)"
    )->execute([(string) $partialPackSourceId, $partialPackPayload]);
    $partialPackPointerId = (int) $pdo->lastInsertId();
    $runId = $repo->beginRun('test', 'f1b-owner-pack-incomplete');
    $assert($repo->claim($runId, 'f1b-owner-pack-incomplete') === null, 'incomplete_pack_finance_claimed_as_effective_work');
    $repo->finishRun($runId, 'completed');
    $pdo->exec("UPDATE queue_v4_clean_jobs SET state='waiting',available_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 40 SECOND),last_error_class='domain_source_waiting:financial_reconciliation:pack_incomplete' WHERE id={$partialPackPointerId}");
    $released = $repo->releaseDueWaiting();
    $assert($released === 0, 'incomplete_pack_released_from_waiting_to_ready');
    $assert((string) $pdo->query("SELECT state FROM queue_v4_clean_jobs WHERE id={$partialPackPointerId}")->fetchColumn() === 'waiting', 'incomplete_pack_pointer_not_kept_waiting');

    $packSourceStmt->execute(['P:PACK-COMPLETE', 'PACK-COMPLETE', hash('sha256', 'pack-complete'), 'READY']);
    $completePackSourceId = (int) $pdo->lastInsertId();
    $completePackPayload = json_encode(['capability' => 'financial_reconciliation', 'source_id' => $completePackSourceId], JSON_THROW_ON_ERROR);
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at)
         VALUES (10,101,'domain_exact',?,'domain:financial_reconciliation:pack-complete',?,'ready',DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 35 SECOND))"
    )->execute([(string) $completePackSourceId, $completePackPayload]);
    $runId = $repo->beginRun('test', 'f1b-owner-pack-complete');
    $completePackJob = $repo->claim($runId, 'f1b-owner-pack-complete');
    $assert(is_array($completePackJob), 'complete_pack_finance_not_claimable');
    $assert((int) $completePackJob['resource_id'] === $completePackSourceId, 'complete_pack_claimed_wrong_source');
    $repo->complete($completePackJob, $runId);
    $repo->finishRun($runId, 'completed');

    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,available_at)
         VALUES (10,101,'order_exact','2001','order:2001',?,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 10 SECOND))"
    )->execute([json_encode(['order_id' => '2001'], JSON_THROW_ON_ERROR)]);
    $runId = $repo->beginRun('test', 'f1-owner-order');
    $orderJob = $repo->claim($runId, 'f1-owner-order');
    $assert(is_array($orderJob) && (string) $orderJob['job_type'] === 'order_exact', 'order_exact_not_claimed_while_finance_waits');
    $repo->complete($orderJob, $runId);
    $repo->finishRun($runId, 'completed');

    $pdo->exec("UPDATE queue_v4_clean_jobs SET state='waiting',available_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 10 SECOND),last_error_class='domain_source_waiting:financial_reconciliation' WHERE id=" . (int) $futurePointer['id']);
    $released = $repo->releaseDueWaiting();
    $assert($released === 0, 'release_due_waiting_released_finance_with_future_source');
    $pdo->exec("UPDATE queue_v4_clean_jobs SET state='ready',available_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 10 SECOND),last_error_class=NULL WHERE id=" . (int) $futurePointer['id']);
    $runId = $repo->beginRun('test', 'f1-owner-skip-premature');
    $assert($repo->claim($runId, 'f1-owner-skip-premature') === null, 'claim_did_not_skip_premature_ready_finance');
    $repo->finishRun($runId, 'completed');

    $pdo->exec("UPDATE sale_financial_reconciliation_jobs SET next_run_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE id={$futureSourceId}");
    $runId = $repo->beginRun('test', 'f1-owner-finance-due');
    $financeJob = $repo->claim($runId, 'f1-owner-finance-due');
    $assert(is_array($financeJob), 'finance_not_eligible_at_or_after_t');
    $assert((string) $financeJob['job_type'] === 'domain_exact', 'finance_due_claimed_wrong_type');
    $assert((int) $financeJob['resource_id'] === $futureSourceId, 'finance_due_claimed_wrong_source');
    $repo->deferWithoutAttemptPenalty($financeJob, $runId, 'domain_source_waiting:financial_reconciliation', gmdate('Y-m-d H:i:s', time() + 1800));
    $repo->finishRun($runId, 'completed');
    $attemptsAfterNonFailure = (int) $pdo->query("SELECT attempt_count FROM queue_v4_clean_jobs WHERE id=" . (int) $financeJob['id'])->fetchColumn();
    $assert($attemptsAfterNonFailure === 0, 'domain_source_waiting_penalized_attempt_count');

    $secondFuture = gmdate('Y-m-d H:i:s', time() + 7200);
    $sourceStmt->execute(['O:1002', '1002', hash('sha256', 'input-1002'), $secondFuture]);
    $secondSourceId = (int) $pdo->lastInsertId();
    $payload = json_encode(['capability' => 'financial_reconciliation', 'source_id' => $secondSourceId], JSON_THROW_ON_ERROR);
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at)
         VALUES (10,101,'domain_exact',?,'domain:financial_reconciliation:source:" . $secondSourceId . "',?,'ready',DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 30 SECOND))"
    )->execute([(string) $secondSourceId, $payload]);
    $prematureId = (int) $pdo->lastInsertId();
    $pdo->beginTransaction();
    $duplicateReceipt = $admission->submit('financial_reconciliation', 10, 101, $secondSourceId, 'source:' . $secondSourceId);
    $pdo->commit();
    $assert($duplicateReceipt['accepted'] === true && $duplicateReceipt['deduplicated'] === true, 'duplicate_future_finance_not_acknowledged');
    $aligned = $pdo->query("SELECT state,available_at FROM queue_v4_clean_jobs WHERE id={$prematureId}")->fetch(PDO::FETCH_ASSOC);
    $assert((string) $aligned['state'] === 'waiting', 'duplicate_premature_finance_not_moved_to_waiting');
    $assert($ts((string) $aligned['available_at']) >= $ts($secondFuture), 'duplicate_premature_finance_not_aligned_to_source_future');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE idempotency_key='domain:financial_reconciliation:source:{$secondSourceId}'")->fetchColumn() === 1, 'duplicate_finance_pointer_created');

    $pdo->beginTransaction();
    $invalid = $admission->submit('financial_reconciliation', 20, 202, $futureSourceId, 'cross-tenant');
    $pdo->rollBack();
    $assert($invalid['accepted'] === false && $invalid['reason'] === 'INVALID_SOURCE', 'cross_tenant_source_was_admitted');

    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,available_at)
         VALUES (10,101,'order_exact','3001','order:3001',?,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 30 SECOND)),
                (10,101,'order_exact','3002','order:3002',?,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 20 SECOND))"
    )->execute([
        json_encode(['order_id' => '3001'], JSON_THROW_ON_ERROR),
        json_encode(['order_id' => '3002'], JSON_THROW_ON_ERROR),
    ]);
    $runId = $repo->beginRun('test', 'f1-owner-fifo');
    $first = $repo->claim($runId, 'f1-owner-fifo');
    $assert(is_array($first) && (string) $first['resource_id'] === '3001', 'eligible_fifo_order_not_preserved');
    $repo->complete($first, $runId);
    $second = $repo->claim($runId, 'f1-owner-fifo');
    $assert(is_array($second) && (string) $second['resource_id'] === '3002', 'eligible_fifo_second_not_preserved');
    $repo->complete($second, $runId);
    $repo->finishRun($runId, 'completed');

    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE meli_account_id NOT IN (101,202)")->fetchColumn() === 0, 'unexpected_cross_tenant_queue_rows');

    echo 'PASS f1_finance_effective_work_mysql checks=' . $checks . PHP_EOL;
} finally {
    $server->exec("DROP DATABASE IF EXISTS `{$database}`");
}
