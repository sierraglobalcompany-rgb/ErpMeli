<?php

declare(strict_types=1);

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: H4 pack integrity backfill test exige un DSN MariaDB desechable sin base seleccionada.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_h4_pack_backfill_' . bin2hex(random_bytes(5));
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

/** @return array{exit:int,stdout:string,stderr:string} */
$run = static function (string ...$arguments) use ($root, $dsn, $user, $pass, $database): array {
    $pipes = [];
    $environment = array_merge($_ENV, [
        'ERP_BACKFILL_TEST_DSN' => $dsn . ';dbname=' . $database,
        'ERP_BACKFILL_TEST_USER' => $user,
        'ERP_BACKFILL_TEST_PASS' => $pass,
    ]);
    $process = proc_open(
        array_merge([PHP_BINARY, $root . '/tools/ERP_MELI_2.39.8_PACK_INTEGRITY_BACKFILL.php'], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        $environment,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        return ['exit' => 127, 'stdout' => '', 'stderr' => 'proc_open_failed'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
};

/** @return array<string,string> */
$parse = static function (string $stdout): array {
    $values = [];
    foreach (preg_split('/\R/', trim($stdout)) ?: [] as $line) {
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $values[$key] = $value;
    }
    return $values;
};

$snapshot = static function (PDO $pdo, array $tables): string {
    $state = [];
    foreach ($tables as $table) {
        $state[$table] = $pdo->query('SELECT * FROM `' . $table . '` ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
    }
    return hash('sha256', json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
};

try {
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET SESSION time_zone='+00:00'");
    $pdo->exec('CREATE TABLE meli_accounts(
        id BIGINT UNSIGNED PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        nickname VARCHAR(80) NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_packs(
        id BIGINT UNSIGNED PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        external_pack_id VARCHAR(80) NOT NULL,
        expected_orders_json TEXT NULL,
        linked_orders_count INT NOT NULL DEFAULT 0,
        integrity_status VARCHAR(40) NULL,
        integrity_message VARCHAR(120) NULL,
        orders_fingerprint VARCHAR(64) NULL,
        verified_at DATETIME NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_orders(
        id BIGINT UNSIGNED PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        external_order_id VARCHAR(80) NOT NULL,
        external_pack_id VARCHAR(80) NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_pack_orders(
        id BIGINT UNSIGNED PRIMARY KEY,
        meli_pack_id BIGINT UNSIGNED NOT NULL,
        meli_order_id BIGINT UNSIGNED NOT NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE queue_v4_clean_jobs(id BIGINT UNSIGNED PRIMARY KEY,state VARCHAR(20),available_at DATETIME NULL)');
    $pdo->exec('CREATE TABLE sale_financial_reconciliation_jobs(id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20),attempts INT,next_run_at DATETIME NULL)');
    $pdo->exec('CREATE TABLE meli_billing_capture_runs(id BIGINT UNSIGNED PRIMARY KEY,status VARCHAR(20),http_status INT NULL)');

    $pdo->exec("INSERT INTO meli_accounts VALUES (11,101,'A'),(22,202,'B')");
    $pdo->exec("INSERT INTO meli_orders VALUES
        (101,11,'A1','PACK-EXACT'),(102,11,'A2','PACK-EXACT'),
        (201,11,'B1','PACK-MISSING'),
        (301,11,'C1','PACK-EXTRA'),(302,11,'C2','PACK-EXTRA'),
        (401,11,'D1','PACK-UNSORTED'),(402,11,'D2','PACK-UNSORTED'),
        (501,22,'Z1','PACK-EXACT'),
        (601,11,'E1','PACK-CAS')");
    $pdo->exec("INSERT INTO meli_packs VALUES
        (1,11,'PACK-DONE','[\"DONE1\"]',1,'complete','complete','old',UTC_TIMESTAMP()),
        (2,11,'PACK-EXACT','[\"A1\",\"A2\"]',0,'pending','preimage','old',NULL),
        (3,11,'PACK-MISSING','[\"B1\",\"B2\"]',0,'pending','preimage','old',NULL),
        (4,11,'PACK-EXTRA','[\"C1\"]',0,'pending','preimage','old',NULL),
        (5,11,'PACK-BAD','not-json',0,'pending','preimage','old',NULL),
        (6,11,'PACK-EMPTY','[]',0,'pending','preimage','old',NULL),
        (7,11,'PACK-UNSORTED','[\"D2\",\"D1\"]',0,'pending','preimage','old',NULL),
        (8,22,'PACK-EXACT','[\"Z1\"]',0,'pending','preimage','old',NULL),
        (9,11,'PACK-CAS','[\"E1\"]',0,'pending','preimage','old',NULL)");
    $pdo->exec("INSERT INTO meli_pack_orders VALUES
        (1,2,101),(2,2,102),(3,3,201),(4,4,301),(5,4,302),(6,7,401),(7,7,402),(8,8,501),(9,9,601)");
    $pdo->exec("INSERT INTO queue_v4_clean_jobs VALUES (1,'waiting',UTC_TIMESTAMP())");
    $pdo->exec("INSERT INTO sale_financial_reconciliation_jobs VALUES (1,'waiting',8,UTC_TIMESTAMP())");
    $pdo->exec("INSERT INTO meli_billing_capture_runs VALUES (1,'complete',200)");

    $protectedTables = ['queue_v4_clean_jobs', 'sale_financial_reconciliation_jobs', 'meli_billing_capture_runs'];
    $protectedBefore = $snapshot($pdo, $protectedTables);

    $plan = $run('--plan', '--limit=10');
    $assert($plan['exit'] === 0, 'plan_exit_invalid:' . $plan['stderr']);
    $planValues = $parse($plan['stdout']);
    $assert(($planValues['STATUS'] ?? '') === 'READY_FOR_H4_PACK_INTEGRITY_BACKFILL_REVIEW', 'plan_status_invalid');
    $assert((int) ($planValues['CURRENT_BATCH_SELECTED'] ?? -1) === 3, 'plan_selected_invalid');
    $assert(($planValues['REAL_MELI_HTTP'] ?? '') === '0', 'plan_http_not_zero');
    $planSha = (string) ($planValues['PLAN_SHA256'] ?? '');
    $assert(preg_match('/^[a-f0-9]{64}$/', $planSha) === 1, 'plan_sha_invalid');

    $dry = $run('--dry-run', '--limit=10');
    $dryValues = $parse($dry['stdout']);
    $assert(($dryValues['RECOVERY_EXECUTED'] ?? '') === 'NO', 'dry_run_executed');
    $assert((int) ($dryValues['PRODUCTION_MUTATIONS'] ?? -1) === 0, 'dry_run_mutated');

    $pdo->exec("UPDATE meli_packs SET expected_orders_json='[\"E1\",\"E2\"]' WHERE id=9");
    $blocked = $run(
        '--execute',
        '--limit=10',
        '--authorization-token=H4_PACK_INTEGRITY_BACKFILL_EXPLICITLY_AUTHORIZED',
        '--expected-plan-sha256=' . $planSha,
    );
    $blockedValues = $parse($blocked['stdout']);
    $assert(($blockedValues['STATUS'] ?? '') === 'BLOCKED', 'cas_drift_not_blocked');
    $assert((string) $pdo->query('SELECT integrity_status FROM meli_packs WHERE id=2')->fetchColumn() === 'pending', 'cas_drift_mutated_other_rows');

    $pdo->exec("UPDATE meli_packs SET expected_orders_json='[\"E1\"]' WHERE id=9");
    $plan2 = $run('--plan', '--limit=10');
    $plan2Values = $parse($plan2['stdout']);
    $execute = $run(
        '--execute',
        '--limit=10',
        '--authorization-token=H4_PACK_INTEGRITY_BACKFILL_EXPLICITLY_AUTHORIZED',
        '--expected-plan-sha256=' . (string) $plan2Values['PLAN_SHA256'],
    );
    $executeValues = $parse($execute['stdout']);
    $assert(($executeValues['STATUS'] ?? '') === 'EXECUTED_H4_PACK_INTEGRITY_BACKFILL', 'execute_status_invalid:' . $execute['stdout']);
    $assert((int) ($executeValues['PRODUCTION_MUTATIONS'] ?? -1) === 3, 'execute_mutations_invalid');

    $eligibleRows = $pdo->query("SELECT id, linked_orders_count, integrity_status, orders_fingerprint, verified_at FROM meli_packs WHERE id IN (2,8,9) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($eligibleRows) === 3, 'eligible_rows_missing');
    foreach ($eligibleRows as $row) {
        $assert($row['integrity_status'] === 'complete', 'eligible_not_complete:' . $row['id']);
        $assert((int) $row['linked_orders_count'] >= 1, 'linked_count_missing:' . $row['id']);
        $assert(preg_match('/^[a-f0-9]{64}$/', (string) $row['orders_fingerprint']) === 1, 'fingerprint_missing:' . $row['id']);
        $assert($row['verified_at'] !== null, 'verified_at_missing:' . $row['id']);
    }
    $assert((string) $pdo->query('SELECT integrity_status FROM meli_packs WHERE id=1')->fetchColumn() === 'complete', 'already_complete_changed');
    foreach ([3,4,5,6,7] as $id) {
        $assert((string) $pdo->query('SELECT integrity_status FROM meli_packs WHERE id=' . $id)->fetchColumn() === 'pending', 'excluded_row_changed:' . $id);
    }
    $assert(hash_equals($protectedBefore, $snapshot($pdo, $protectedTables)), 'protected_queue_finance_or_billing_changed');

    $secondPlan = $run('--plan', '--limit=10');
    $secondValues = $parse($secondPlan['stdout']);
    $assert((int) ($secondValues['CURRENT_BATCH_SELECTED'] ?? -1) === 0, 'idempotent_second_plan_not_empty');

    echo 'H4_PACK_INTEGRITY_BACKFILL_MYSQL=PASS checks=' . $checks
        . ' already_complete_excluded=yes exact_eligible=yes missing_excluded=yes extra_excluded=yes'
        . ' invalid_excluded=yes empty_excluded=yes tenant_collision_isolated=yes fingerprint_expected_set=yes'
        . ' idempotent_second_run=yes cas_drift_fail_closed=yes queue_changes=0 finance_changes=0 billing_changes=0 meli_http=0' . PHP_EOL;
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
