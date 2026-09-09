<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';
require __DIR__ . '/cap2_transport_legacy_fixture.php';

use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\AppSettingsService;

putenv('APP_ENV=test');
putenv('ML_WRITE_ENABLED=false');
putenv('DB_HOST=' . (getenv('DB_HOST') ?: '127.0.0.1'));
putenv('DB_PORT=' . (getenv('DB_PORT') ?: '3306'));
putenv('DB_USER=' . (getenv('DB_USER') ?: 'root'));
putenv('DB_PASS=' . (string) getenv('DB_PASS'));
putenv('DB_NAME=erp_meli_k1d_test_rc1_budget_' . strtolower(bin2hex(random_bytes(4))));

$harness = K1dSafeTestDatabase::createFromEnvironment();
$cycles = 0;
$violations = 0;
$maxTransportsPerCycle = 0;
$failure = '';

try {
    $pdo = $harness->pdo();
    k1d_rc1_budget_install_schema($pdo);
    cap2_transport_install_legacy_journal($pdo);
    AppSettingsService::clearCache();

    foreach ([1, 2, 3, 15, 55, 100] as $maxCalls) {
        for ($cycle = 1; $cycle <= 30; $cycle++) {
            $cycles++;
            k1d_rc1_budget_reset_cycle($pdo, $maxCalls + 1, $cycle, $maxCalls);
            $calls = 0;
            $repository = new QueueV4CleanRepository($pdo);
            $handler = static function (array $job) use (&$calls,$pdo): void {
                QueueV4CleanCycleBudget::claim();
                cap2_transport_record_legacy_call($pdo,$job,200);
                $calls++;
            };
            $worker = new QueueV4CleanWorker($pdo, $repository, null, null, $handler);
            QueueV4CleanCycleBudget::start($maxCalls);
            try {
                $result = $worker->run('test', $maxCalls, 45, [2], 2);
            } finally {
                QueueV4CleanCycleBudget::clear();
            }
            $maxTransportsPerCycle = max($maxTransportsPerCycle, $calls);
            k1b_assert($result['physical_http_calls'] === $calls, 'CANONICAL_LEDGER_MATCHES_SIMULATED_TRANSPORTS');
            if ($calls > $maxCalls || (int) ($result['physical_http_calls'] ?? 0) > $maxCalls) {
                $violations++;
            }
            k1b_assert($calls === $maxCalls, 'WORKER_CYCLE_CONSUMED_EXACT_BUDGET_' . $maxCalls . '_' . $cycle);
            k1b_assert(($result['max_calls'] ?? 0) === $maxCalls, 'WORKER_REPORTS_CONFIGURED_BUDGET_' . $maxCalls);
        }
    }
    foreach ([1, 2, 3, 15, 55, 100] as $totalBudget) {
        k1d_rc1_budget_reset_cycle($pdo, $totalBudget + 1, 31, $totalBudget);
        $calls = 0;
        QueueV4CleanCycleBudget::start($totalBudget);
        QueueV4CleanCycleBudget::claim(); // Earlier OAuth/producer stage used one physical call.
        $remaining = QueueV4CleanCycleBudget::remaining();
        $worker = new QueueV4CleanWorker($pdo, new QueueV4CleanRepository($pdo), null, null,
            static function (array $job) use (&$calls): void { QueueV4CleanCycleBudget::claim(); $calls++; });
        try {
            $worker->run('test', $remaining, 45, [2], 2);
            k1b_assert($calls === $totalBudget - 1, 'SHARED_STAGES_REMAINING_' . $totalBudget);
            k1b_assert(QueueV4CleanCycleBudget::snapshot()['used'] === $totalBudget, 'SHARED_STAGES_TOTAL_' . $totalBudget);
        } finally { QueueV4CleanCycleBudget::clear(); }
    }
    k1d_rc1_budget_reset_cycle($pdo, 25, 32, 1);
    QueueV4CleanCycleBudget::start(1);
    $localOnlyWorker = new QueueV4CleanWorker($pdo, new QueueV4CleanRepository($pdo), null, null, static function (array $job): void {});
    try {
        $local = $localOnlyWorker->run('test', 1, 45, [2], 2);
        k1b_assert($local['claimed'] === 20, 'SMALL_BUDGET_LOCAL_POINTER_SAFETY_UNCHANGED');
        k1b_assert(QueueV4CleanCycleBudget::snapshot()['used'] === 0, 'LOCAL_POINTERS_NOT_PHYSICAL_CALLS');
    } finally { QueueV4CleanCycleBudget::clear(); }
} catch (Throwable $error) {
    $failure = get_class($error) . ':' . preg_replace('/\s+/', ' ', $error->getMessage());
} finally {
    QueueV4CleanCycleBudget::clear();
    $harness->cleanup();
}

$pass = $failure === '' && $cycles === 180 && $violations === 0 && $maxTransportsPerCycle === 100;

echo 'STATUS=' . ($pass ? 'PASS K1D_RC1_WORKER_BUDGET_CYCLES' : 'BLOCKED K1D_RC1_WORKER_BUDGET_CYCLES') . "\n";
echo "ACTUAL_WORKER_CYCLES={$cycles}\n";
echo "WORKER_BUDGET_LEVELS=1,2,3,15,55,100\n";
echo "WORKER_CYCLES_PER_LEVEL=30\n";
echo "MAX_TRANSPORTS_PER_CYCLE={$maxTransportsPerCycle}\n";
echo "MAX_CALLS_VIOLATIONS={$violations}\n";
if ($failure !== '') {
    echo "FAILURE={$failure}\n";
}
echo "PRODUCTION_CHANGED_BY_CODEX=NO\n";
echo "REAL_MELI_HTTP=0\n";
echo "REAL_EMAIL_SENT=0\n";

exit($pass ? 0 : 20);

function k1d_rc1_budget_install_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE app_settings (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(190) NOT NULL,
        setting_value TEXT NULL,
        is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
        setting_group VARCHAR(80) NOT NULL DEFAULT "general",
        updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_app_settings_key (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE companies (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        nit VARCHAR(40) NULL,
        status TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_companies_nit (nit)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE meli_accounts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        account_name VARCHAR(160) NOT NULL,
        meli_user_id BIGINT UNSIGNED NULL,
        nickname VARCHAR(160) NULL,
        status VARCHAR(40) NOT NULL DEFAULT "conectado",
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_meli_accounts_company_id_id (company_id,id),
        UNIQUE KEY uq_meli_account_user (meli_user_id),
        KEY idx_meli_accounts_company_status (company_id,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec("INSERT INTO companies (id,name,nit,status) VALUES (1,'Empresa QA RC1','K1D-RC1-BUDGET',1)");
    $pdo->exec("INSERT INTO meli_accounts (id,company_id,account_name,meli_user_id,nickname,status) VALUES (2,1,'Cuenta QA RC1',123456789,'CuentaQA','conectado')");
    $pdo->exec("CREATE TABLE queue_v4_clean_control (
        control_key VARCHAR(32) NOT NULL,
        engine_state ENUM('STOPPED','CERTIFIED','ACTIVE') NOT NULL DEFAULT 'STOPPED',
        readiness_state ENUM('NOT_READY','READY_TO_TEST','TESTING','CERTIFIED','FAILED') NOT NULL DEFAULT 'NOT_READY',
        scheduler_enabled TINYINT(1) NOT NULL DEFAULT 0,
        readiness_passed_accounts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        readiness_error_class VARCHAR(100) NULL,
        certified_at DATETIME(3) NULL,
        activated_at DATETIME(3) NULL,
        stopped_at DATETIME(3) NULL,
        last_scheduler_at DATETIME(3) NULL,
        updated_by BIGINT UNSIGNED NULL,
        created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        PRIMARY KEY (control_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT INTO queue_v4_clean_control (control_key,engine_state,readiness_state,scheduler_enabled) VALUES ('primary','ACTIVE','CERTIFIED',1)");
    $pdo->exec("CREATE TABLE queue_v4_clean_jobs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        company_id BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        job_type ENUM('fresh_orders_discovery','order_exact','domain_exact') NOT NULL,
        resource_id VARCHAR(191) NULL,
        idempotency_key VARCHAR(191) NOT NULL,
        state ENUM('ready','running','waiting','review','dead','completed') NOT NULL DEFAULT 'ready',
        attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
        max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
        available_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        lease_owner VARCHAR(96) NULL,
        lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
        lease_expires_at DATETIME(3) NULL,
        payload_json JSON NOT NULL,
        last_error_class VARCHAR(100) NULL,
        created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        completed_at DATETIME(3) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_qv4_job_identity (company_id,meli_account_id,job_type,idempotency_key),
        UNIQUE KEY uq_qv4_job_tenant_id (id,company_id,meli_account_id),
        KEY idx_qv4_fifo (state,available_at,id),
        KEY idx_qv4_tenant (company_id,meli_account_id,state,id),
        KEY idx_qv4_lease (state,lease_expires_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE queue_v4_clean_attempts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        job_id BIGINT UNSIGNED NOT NULL,
        run_id BIGINT UNSIGNED NOT NULL,
        company_id BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        lease_owner VARCHAR(96) NOT NULL,
        lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
        outcome ENUM('running','completed','waiting','review','dead','lease_expired') NOT NULL DEFAULT 'running',
        error_class VARCHAR(100) NULL,
        dispatch_state ENUM('NOT_DISPATCHED','PHYSICAL_STARTED','RESPONSE_KNOWN') NOT NULL DEFAULT 'NOT_DISPATCHED',
        transport_method VARCHAR(8) NULL,
        endpoint_key VARCHAR(100) NULL,
        physical_http_calls TINYINT UNSIGNED NOT NULL DEFAULT 0,
        physical_started_at DATETIME(3) NULL,
        http_status SMALLINT UNSIGNED NULL,
        response_known_at DATETIME(3) NULL,
        source_closed_at DATETIME(3) NULL,
        started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        finished_at DATETIME(3) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_qv4_attempt (job_id,lease_owner),
        UNIQUE KEY uq_qv4_attempt_tenant_id (id,job_id,company_id,meli_account_id),
        KEY idx_qv4_attempt_tenant (company_id,meli_account_id,started_at),
        KEY idx_qv4_attempt_run (run_id,company_id,meli_account_id),
        KEY idx_qv4_attempt_dispatch (company_id,meli_account_id,dispatch_state,started_at),
        CONSTRAINT fk_qv4_budget_attempt_job FOREIGN KEY (job_id) REFERENCES queue_v4_clean_jobs(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE queue_v4_clean_runs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        launcher ENUM('scheduler','manual','test') NOT NULL,
        worker_ref VARCHAR(96) NOT NULL,
        status ENUM('running','completed','failed','stopped') NOT NULL DEFAULT 'running',
        jobs_claimed INT UNSIGNED NOT NULL DEFAULT 0,
        jobs_completed INT UNSIGNED NOT NULL DEFAULT 0,
        jobs_deferred INT UNSIGNED NOT NULL DEFAULT 0,
        started_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
        finished_at DATETIME(3) NULL,
        PRIMARY KEY (id),
        KEY idx_qv4_runs_status (status,started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function k1d_rc1_budget_reset_cycle(PDO $pdo, int $jobCount, int $cycle, int $maxCalls): void
{
    $pdo->exec('DELETE FROM queue_v4_clean_transport_events');
    $pdo->exec('DELETE FROM queue_v4_clean_attempts');
    $pdo->exec('DELETE FROM queue_v4_clean_jobs');
    $pdo->exec('DELETE FROM queue_v4_clean_runs');
    for ($i = 1; $i <= $jobCount; $i++) {
        $payload = json_encode(['capability' => 'sales', 'cycle' => $cycle, 'i' => $i], JSON_THROW_ON_ERROR);
        $stmt = $pdo->prepare('INSERT INTO queue_v4_clean_jobs
            (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,max_attempts,available_at)
            VALUES (1,2,"order_exact",?,?,?,3,UTC_TIMESTAMP(3))');
        $stmt->execute([(string) ($cycle * 1000 + $i), 'rc1-budget-' . $maxCalls . '-' . $cycle . '-' . $i, $payload]);
    }
}
