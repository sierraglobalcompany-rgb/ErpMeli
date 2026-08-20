<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\SaleFinancialService;

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: H3 exige un DSN MariaDB desechable sin base seleccionada.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_h3_financial_' . bin2hex(random_bytes(5));
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-h3-financial-' . bin2hex(random_bytes(5));
@mkdir($temporary . DIRECTORY_SEPARATOR . 'storage', 0770, true);
$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$version = (string) $server->query('SELECT VERSION()')->fetchColumn();
if (stripos($version, 'mariadb') === false) {
    fwrite(STDERR, "ERROR: H3 exige MariaDB.\n");
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

try {
    define('ERP_SHARED_ROOT', $temporary);
    spl_autoload_register(static function (string $class) use ($root): void {
        if (!str_starts_with($class, 'App\\')) {
            return;
        }
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });

    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET SESSION time_zone='+00:00'");
    Database::setConnection($pdo);
    $assert($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) === false, 'pdo_native_prepares_not_enabled');

    $pdo->exec('CREATE TABLE companies(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(160) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_accounts(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,name VARCHAR(160) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE manual_campaigns(id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20) NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE manual_campaign_reservations(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        manual_campaign_id BIGINT UNSIGNED NOT NULL,
        queue_key VARCHAR(80) NOT NULL,
        source_id VARCHAR(80) NOT NULL,
        status VARCHAR(20) NOT NULL,
        expires_at DATETIME(3) NOT NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_orders(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        external_order_id VARCHAR(64) NOT NULL,
        external_pack_id VARCHAR(64) NULL,
        currency_id VARCHAR(8) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE meli_packs(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        external_pack_id VARCHAR(64) NOT NULL,
        integrity_status VARCHAR(30) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE sale_financial_reconciliation_jobs(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        sale_key VARCHAR(96) NOT NULL,
        external_sale_id VARCHAR(64) NOT NULL,
        input_version CHAR(64) NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT "pending",
        priority_tier INT NOT NULL DEFAULT 30,
        origin_type VARCHAR(60) NULL,
        origin_id BIGINT UNSIGNED NULL,
        created_by BIGINT UNSIGNED NULL,
        next_run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        lock_owner VARCHAR(64) NULL,
        lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
        lease_expires_at DATETIME NULL,
        heartbeat_at DATETIME NULL,
        attempts INT NOT NULL DEFAULT 0,
        safe_message VARCHAR(500) NULL,
        retry_until DATETIME NULL,
        remote_pending_since DATETIME NULL,
        completed_at DATETIME NULL,
        last_remote_state VARCHAR(80) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $pdo->exec("INSERT INTO companies VALUES (1,'TestCo')");
    $pdo->exec("INSERT INTO meli_accounts VALUES (10,1,'Cuenta H3')");

    $service = new SaleFinancialService();
    $claim = new ReflectionMethod(SaleFinancialService::class, 'claim');
    $claim->setAccessible(true);
    $finish = new ReflectionMethod(SaleFinancialService::class, 'finish');
    $finish->setAccessible(true);
    $defer = new ReflectionMethod(SaleFinancialService::class, 'deferWithoutAttemptPenalty');
    $defer->setAccessible(true);

    $insertJob = static function (int $attempts, string $saleKey = 'O:1001', string $externalSaleId = '1001') use ($pdo): int {
        $stmt = $pdo->prepare(
            'INSERT INTO sale_financial_reconciliation_jobs
                (company_id,meli_account_id,sale_key,external_sale_id,input_version,status,priority_tier,next_run_at,attempts)
             VALUES (1,10,?,?,?, "pending", 30, UTC_TIMESTAMP(), ?)'
        );
        $stmt->execute([$saleKey, $externalSaleId, str_repeat('a', 64), $attempts]);
        return (int) $pdo->lastInsertId();
    };
    $row = static function (int $id) use ($pdo): array {
        $stmt = $pdo->prepare('SELECT * FROM sale_financial_reconciliation_jobs WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    };
    $minutesUntil = static function (?string $value): int {
        return (int) round(((strtotime((string) $value . ' UTC') ?: 0) - time()) / 60);
    };

    // A. PACK_INCOMPLETE: no Billing HTTP; claim 8->9; defer 9->8; next run around +60m.
    $packJobId = $insertJob(8, 'P:PACK-H3', 'PACK-H3');
    $pdo->exec("INSERT INTO meli_orders(meli_account_id,external_order_id,external_pack_id,currency_id) VALUES (10,'2001','PACK-H3','COP')");
    $pdo->exec("INSERT INTO meli_packs(meli_account_id,external_pack_id,integrity_status) VALUES (10,'PACK-H3','pending')");
    $summary = $service->processDue(1, $packJobId);
    $pack = $row($packJobId);
    $assert((int) $summary['processed'] === 1 && (int) $summary['deferred'] === 1, 'pack_incomplete_not_deferred');
    $assert($pack['status'] === 'retry', 'pack_status_not_retry');
    $assert((int) $pack['attempts'] === 8, 'pack_attempt_not_refunded_exactly_one');
    $assert($pack['lock_owner'] === null && $pack['lease_expires_at'] === null && $pack['heartbeat_at'] === null, 'pack_lease_not_released');
    $packMinutes = $minutesUntil($pack['next_run_at']);
    $assert($packMinutes >= 55 && $packMinutes <= 65, 'pack_next_run_not_60m');

    // B. RHYTHM SAFE WAIT: claim 8->9; non-failure defer 9->8; next run around +15m.
    $rhythmJobId = $insertJob(8, 'O:RHYTHM-H3', 'RHYTHM-H3');
    $rhythmJob = $claim->invoke($service, $rhythmJobId, null);
    $assert(is_array($rhythmJob) && (int) $rhythmJob['attempts'] === 9, 'rhythm_claim_not_incremented');
    $nextSafeAt = gmdate('Y-m-d H:i:s', time() + 15 * 60);
    $defer->invoke($service, $rhythmJob, 'Billing continuará en su próxima oportunidad segura.', $nextSafeAt);
    $rhythm = $row($rhythmJobId);
    $assert((int) $rhythm['attempts'] === 8, 'rhythm_attempt_not_refunded_exactly_one');
    $assert($rhythm['status'] === 'retry', 'rhythm_status_not_retry');
    $rhythmMinutes = $minutesUntil($rhythm['next_run_at']);
    $assert($rhythmMinutes >= 10 && $rhythmMinutes <= 20, 'rhythm_next_run_not_next_safe_at');

    // D/E. Functional retry and HTTP429-like retry remain penalized by the claimed attempt.
    foreach (['functional_runtime', 'http_429_policy'] as $case) {
        $jobId = $insertJob(8, 'O:' . $case, $case);
        $job = $claim->invoke($service, $jobId, null);
        $finish->invoke($service, $job, 'retry', $case);
        $finished = $row($jobId);
        $assert((int) $finished['attempts'] === 9, $case . '_attempt_was_refunded');
        $assert($minutesUntil($finished['next_run_at']) >= 1000, $case . '_retry_delay_not_preserved');
    }

    // F/G/H. Complete, partial and awaiting_remote keep the historical attempt value.
    foreach ([['complete', false], ['partial', false], ['awaiting_remote', true]] as [$status, $deferred]) {
        $jobId = $insertJob(8, 'O:' . $status, $status);
        $job = $claim->invoke($service, $jobId, null);
        $finish->invoke($service, $job, $status, $status);
        $finished = $row($jobId);
        $assert((int) $finished['attempts'] === 9, $status . '_attempt_changed');
        $assert($finished['status'] === $status, $status . '_status_changed');
        if ($deferred) {
            $assert($minutesUntil($finished['next_run_at']) >= 1000, $status . '_delay_changed');
        }
    }

    // I. owner / generation CAS mismatch fail closed.
    $casJobId = $insertJob(8, 'O:CAS-H3', 'CAS-H3');
    $casJob = $claim->invoke($service, $casJobId, null);
    $badOwner = $casJob;
    $badOwner['lock_owner'] = 'wrong-owner';
    $failed = false;
    try {
        $defer->invoke($service, $badOwner, 'bad owner', gmdate('Y-m-d H:i:s', time() + 15 * 60));
    } catch (ReflectionException|RuntimeException $error) {
        $failed = true;
    }
    $cas = $row($casJobId);
    $assert($failed && (int) $cas['attempts'] === 9 && $cas['status'] === 'running', 'owner_cas_mismatch_not_fail_closed');

    $generationJobId = $insertJob(8, 'O:GEN-H3', 'GEN-H3');
    $generationJob = $claim->invoke($service, $generationJobId, null);
    $badGeneration = $generationJob;
    $badGeneration['lease_generation'] = (int) $generationJob['lease_generation'] + 1;
    $failed = false;
    try {
        $defer->invoke($service, $badGeneration, 'bad generation', gmdate('Y-m-d H:i:s', time() + 15 * 60));
    } catch (ReflectionException|RuntimeException $error) {
        $failed = true;
    }
    $generation = $row($generationJobId);
    $assert($failed && (int) $generation['attempts'] === 9 && $generation['status'] === 'running', 'generation_cas_mismatch_not_fail_closed');

    // J. Attempts drift fail-closed: only the claim attempt observed by the worker may be refunded.
    $driftJobId = $insertJob(8, 'O:DRIFT-H3', 'DRIFT-H3');
    $driftJob = $claim->invoke($service, $driftJobId, null);
    $assert((int) $driftJob['attempts'] === 9, 'attempts_drift_claim_not_incremented');
    $pdo->prepare('UPDATE sale_financial_reconciliation_jobs SET attempts=10 WHERE id=?')->execute([$driftJobId]);
    $failed = false;
    try {
        $defer->invoke($service, $driftJob, 'drift defer', gmdate('Y-m-d H:i:s', time() + 15 * 60));
    } catch (ReflectionException|RuntimeException $error) {
        $failed = true;
    }
    $drift = $row($driftJobId);
    $assert($failed && (int) $drift['attempts'] === 10 && $drift['status'] === 'running', 'attempts_drift_not_fail_closed');

    // K. Helper cannot double-refund; second invocation sees status=retry and fails without decrementing history.
    $doubleJobId = $insertJob(8, 'O:DOUBLE-H3', 'DOUBLE-H3');
    $doubleJob = $claim->invoke($service, $doubleJobId, null);
    $defer->invoke($service, $doubleJob, 'first defer', gmdate('Y-m-d H:i:s', time() + 15 * 60));
    $failed = false;
    try {
        $defer->invoke($service, $doubleJob, 'second defer', gmdate('Y-m-d H:i:s', time() + 15 * 60));
    } catch (ReflectionException|RuntimeException $error) {
        $failed = true;
    }
    $double = $row($doubleJobId);
    $assert($failed && (int) $double['attempts'] === 8 && $double['status'] === 'retry', 'double_refund_not_fenced');

    fwrite(STDOUT, 'FINANCIAL_NONFAILURE_DEFER_H3=PASS checks=' . $checks . PHP_EOL);
} finally {
    try {
        if (isset($server)) {
            $server->exec("DROP DATABASE IF EXISTS `{$database}`");
        }
    } catch (Throwable) {
    }
    if (is_dir($temporary)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir((string) $file) : @unlink((string) $file);
        }
        @rmdir($temporary);
    }
}
