<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanProducer;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanScheduler;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\CronDeadlineContext;
use App\Services\QueueV4PreTransportDeferredException;

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: F2B exige un DSN MariaDB desechable sin base seleccionada.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_f2b_gate_' . bin2hex(random_bytes(5));
$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$version = (string) $server->query('SELECT VERSION()')->fetchColumn();
if (stripos($version, 'mariadb') === false) {
    fwrite(STDERR, "ERROR: F2B exige MariaDB.\n");
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
$quote = static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`';

try {
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
    putenv('ML_WRITE_ENABLED=false');

    $contract = json_decode(
        (string) file_get_contents($root . '/resources/release/queue-v4-canonical-db-contract-2.38.9.json'),
        true,
        64,
        JSON_THROW_ON_ERROR,
    );
    foreach ($contract['tables'] as $table => $definition) {
        $columns = [];
        foreach ($definition['columns'] as $column) {
            $sql = $quote((string) $column['COLUMN_NAME']) . ' ' . (string) $column['COLUMN_TYPE'];
            if (($column['CHARACTER_SET_NAME'] ?? null) !== null) {
                $sql .= ' CHARACTER SET ' . (string) $column['CHARACTER_SET_NAME'];
            }
            if (($column['COLLATION_NAME'] ?? null) !== null) {
                $sql .= ' COLLATE ' . (string) $column['COLLATION_NAME'];
            }
            if (str_contains((string) $column['EXTRA'], 'GENERATED')) {
                $expression = match ((string) $column['COLUMN_NAME']) {
                    'default_slot' => "CASE WHEN status='active' AND is_default=1 THEN 1 ELSE NULL END",
                    'available' => 'on_hand-reserved',
                    default => throw new RuntimeException('unknown_generated_column'),
                };
                $sql .= ' GENERATED ALWAYS AS (' . $expression . ') STORED';
            } else {
                $sql .= (string) $column['IS_NULLABLE'] === 'YES' ? ' NULL' : ' NOT NULL';
                if ($column['COLUMN_DEFAULT'] !== null) {
                    $sql .= ' DEFAULT ' . (string) $column['COLUMN_DEFAULT'];
                }
                if (trim((string) $column['EXTRA']) !== '') {
                    $sql .= ' ' . (string) $column['EXTRA'];
                }
            }
            $columns[] = $sql;
        }
        $indexes = [];
        foreach ($definition['indexes'] as $index) {
            $indexes[(string) $index['INDEX_NAME']][] = $index;
        }
        foreach ($indexes as $name => $parts) {
            usort($parts, static fn (array $a, array $b): int => (int) $a['SEQ_IN_INDEX'] <=> (int) $b['SEQ_IN_INDEX']);
            $indexed = array_map(
                static fn (array $part): string => $quote((string) $part['COLUMN_NAME'])
                    . ($part['SUB_PART'] !== null ? '(' . (int) $part['SUB_PART'] . ')' : ''),
                $parts,
            );
            $prefix = $name === 'PRIMARY'
                ? 'PRIMARY KEY'
                : ((int) $parts[0]['NON_UNIQUE'] === 0 ? 'UNIQUE KEY ' . $quote($name) : 'KEY ' . $quote($name));
            $columns[] = $prefix . ' (' . implode(',', $indexed) . ')';
        }
        $pdo->exec(
            'CREATE TABLE ' . $quote((string) $table) . ' (' . implode(',', $columns) . ') ENGINE='
            . $definition['engine'] . ' DEFAULT CHARSET=utf8mb4 COLLATE=' . $definition['collation'],
        );
    }
    $pdo->exec('CREATE TABLE companies(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(160) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE internal_products(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_orders(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        external_order_id VARCHAR(64) NOT NULL,
        external_pack_id VARCHAR(64) NULL,
        status VARCHAR(60) NULL,
        currency_id VARCHAR(8) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    foreach ($contract['tables'] as $table => $definition) {
        $foreignKeys = [];
        foreach ($definition['foreign_keys'] as $foreignKey) {
            $foreignKeys[(string) $foreignKey['CONSTRAINT_NAME']][] = $foreignKey;
        }
        foreach ($foreignKeys as $name => $parts) {
            usort($parts, static fn (array $a, array $b): int => (int) $a['ORDINAL_POSITION'] <=> (int) $b['ORDINAL_POSITION']);
            $local = array_map(static fn (array $row): string => $quote((string) $row['COLUMN_NAME']), $parts);
            $remote = array_map(static fn (array $row): string => $quote((string) $row['REFERENCED_COLUMN_NAME']), $parts);
            $first = $parts[0];
            $pdo->exec(
                'ALTER TABLE ' . $quote((string) $table)
                . ' ADD CONSTRAINT ' . $quote($name)
                . ' FOREIGN KEY (' . implode(',', $local) . ') REFERENCES '
                . $quote((string) $first['REFERENCED_TABLE_NAME']) . ' (' . implode(',', $remote) . ')'
                . ' ON UPDATE ' . $first['UPDATE_RULE'] . ' ON DELETE ' . $first['DELETE_RULE'],
            );
        }
    }
    $pdo->exec((string) file_get_contents($root . '/database/migrations/299_queue_v4_domain_exact_admission_2_39_3.sql'));

    $pdo->exec("INSERT INTO companies(id,name) VALUES (10,'Empresa A'),(20,'Empresa B'),(30,'Empresa C')");
    $pdo->exec("INSERT INTO meli_accounts(company_id,account_name,status) VALUES
        (10,'Cuenta A','conectado'),(20,'Cuenta B','conectado'),(30,'Cuenta C','conectado')");
    $accounts = $pdo->query('SELECT company_id,id FROM meli_accounts ORDER BY company_id')->fetchAll(PDO::FETCH_NUM);
    foreach ($accounts as [$companyId, $accountId]) {
        $token = $pdo->prepare(
            'INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at)
             VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))',
        );
        $token->execute([(int) $accountId, 'fixture', 'fixture']);
    }
    $pdo->exec("INSERT INTO queue_v4_clean_control
        (control_key,engine_state,readiness_state,scheduler_enabled,readiness_passed_accounts)
        VALUES ('primary','ACTIVE','CERTIFIED',1,3)");
    $pdo->exec("INSERT INTO queue_v4_clean_readiness_runs
        (state,expected_accounts,passed_accounts,started_by,finished_at)
        VALUES ('CERTIFIED',3,3,1,UTC_TIMESTAMP(3))");
    $readinessId = (int) $pdo->lastInsertId();
    $readiness = $pdo->prepare(
        "INSERT INTO queue_v4_clean_readiness_accounts
         (readiness_run_id,company_id,meli_account_id,outcome) VALUES (?,?,?,'PASS')",
    );
    foreach ($accounts as [$companyId, $accountId]) {
        $readiness->execute([$readinessId, (int) $companyId, (int) $accountId]);
    }
    $targetCompany = (int) $accounts[0][0];
    $targetAccount = (int) $accounts[0][1];

    $reset = static function () use ($pdo, $accounts): void {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['queue_v4_clean_attempts', 'queue_v4_clean_runs', 'queue_v4_clean_transport_events',
                     'queue_v4_clean_jobs', 'queue_v4_clean_checkpoints'] as $table) {
            $pdo->exec('TRUNCATE TABLE ' . $table);
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $checkpoint = $pdo->prepare(
            'INSERT INTO queue_v4_clean_checkpoints
             (producer_key,company_id,meli_account_id,watermark_at,next_due_at,last_job_id)
             VALUES (?,?,?,?,?,?)',
        );
        foreach ($accounts as $index => [$companyId, $accountId]) {
            $freshDue = $index === 0 ? time() - 1 : time() + 86400;
            $checkpoint->execute(['fresh_orders', (int) $companyId, (int) $accountId,
                gmdate('Y-m-d H:i:s', time() - 300), gmdate('Y-m-d H:i:s', $freshDue), null]);
            $checkpoint->execute(['inventory_order_refresh', (int) $companyId, (int) $accountId,
                null, gmdate('Y-m-d H:i:s', time() + 86400), 0]);
            $checkpoint->execute(['inventory_pending_floor', (int) $companyId, (int) $accountId,
                null, gmdate('Y-m-d H:i:s'), 0]);
            $checkpoint->execute(['inventory_pending_cursor', (int) $companyId, (int) $accountId,
                null, gmdate('Y-m-d H:i:s'), 0]);
        }
    };
    $seed = static function (string $type, string $state, string $key, bool $future = false) use (
        $pdo,
        $targetCompany,
        $targetAccount,
    ): void {
        $payload = match ($type) {
            'domain_exact' => ['capability' => 'financial_recalc', 'source_id' => 9001],
            'order_exact' => ['order_id' => '9001'],
            default => ['from' => gmdate(DATE_ATOM, time() - 300), 'to' => gmdate(DATE_ATOM), 'offset' => 0, 'limit' => 20],
        };
        $statement = $pdo->prepare(
            'INSERT INTO queue_v4_clean_jobs
             (company_id,meli_account_id,job_type,resource_id,idempotency_key,state,available_at,payload_json)
             VALUES (?,?,?,?,?,?,?,?)',
        );
        $statement->execute([
            $targetCompany,
            $targetAccount,
            $type,
            $type === 'fresh_orders_discovery' ? null : '9001',
            $key,
            $state,
            gmdate('Y-m-d H:i:s', time() + ($future ? 3600 : -60)),
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    };
    $freshDelta = static function () use ($pdo, $targetCompany, $targetAccount): int {
        $before = (int) $pdo->query(
            "SELECT COUNT(*) FROM queue_v4_clean_jobs
             WHERE company_id={$targetCompany} AND meli_account_id={$targetAccount}
               AND job_type='fresh_orders_discovery'",
        )->fetchColumn();
        (new QueueV4CleanProducer($pdo, new QueueV4CleanRepository($pdo)))->produce(300);
        $after = (int) $pdo->query(
            "SELECT COUNT(*) FROM queue_v4_clean_jobs
             WHERE company_id={$targetCompany} AND meli_account_id={$targetAccount}
               AND job_type='fresh_orders_discovery'",
        )->fetchColumn();
        return $after - $before;
    };

    $reset();
    $seed('domain_exact', 'ready', 'A');
    $assert($freshDelta() === 1, 'A_domain_ready_blocked_fresh');
    $reset();
    $seed('domain_exact', 'waiting', 'B', true);
    $assert($freshDelta() === 1, 'B_domain_waiting_blocked_fresh');
    $reset();
    $seed('fresh_orders_discovery', 'ready', 'C');
    $assert($freshDelta() === 0, 'C_fresh_ready_did_not_block');
    $reset();
    $seed('order_exact', 'ready', 'D');
    $assert($freshDelta() === 0, 'D_order_exact_ready_did_not_block');
    $reset();
    foreach (['completed', 'review', 'dead'] as $state) {
        $seed('domain_exact', $state, 'E:' . $state);
    }
    $assert($freshDelta() === 1, 'E_terminal_domain_blocked_fresh');
    $reset();
    foreach (['completed', 'review', 'dead'] as $state) {
        $seed('fresh_orders_discovery', $state, 'F:' . $state);
    }
    $assert($freshDelta() === 1, 'F_terminal_fresh_blocked_new_frontier');

    $capacityMethod = new ReflectionMethod(QueueV4CleanScheduler::class, 'functionalCapacity');
    $capacity = static fn (int $oauth, int $audit, int $worker): array =>
        $capacityMethod->invoke(null, 3, $oauth, $audit, $worker);
    CronDeadlineContext::start(10, 8, 2, 1);
    try {
        $schedulerCases = [
            'S1' => [0, 0, 3, 0, 3],
            'S2' => [0, 0, 2, 1, 3],
            'S3' => [1, 0, 2, 0, 3],
            'S4' => [0, 1, 2, 0, 3],
            'S5' => [1, 1, 1, 0, 3],
            'S6' => [0, 0, 1, 1, 2],
        ];
        foreach ($schedulerCases as $case => [$oauth, $audit, $worker, $expectedRepair, $expectedTotal]) {
            $plan = $capacity($oauth, $audit, $worker);
            $repair = $plan['remaining_slots'] > 0 && CronDeadlineContext::canAcceptWork(3) ? 1 : 0;
            $total = $oauth + $audit + $worker + $repair;
            $assert($repair === $expectedRepair, $case . '_repair_claim_invalid');
            $assert($total === $expectedTotal && $total <= 3, $case . '_claimed_total_invalid');
        }
    } finally {
        CronDeadlineContext::clear();
    }
    CronDeadlineContext::start(5, 1, 2, 1);
    try {
        $deadlinePlan = $capacity(0, 0, 1);
        $deadlineRepair = $deadlinePlan['remaining_slots'] > 0 && CronDeadlineContext::canAcceptWork(3) ? 1 : 0;
        $assert($deadlineRepair === 0, 'S7_deadline_allowed_repair');
    } finally {
        CronDeadlineContext::clear();
    }

    $reset();
    $repository = new QueueV4CleanRepository($pdo);
    for ($index = 0; $index < 3; $index++) {
        $repository->enqueue(
            $targetCompany,
            $targetAccount,
            'order_exact',
            (string) (9100 + $index),
            'S8:' . $index,
            ['order_id' => (string) (9100 + $index)],
        );
    }
    CronDeadlineContext::start(10, 8, 2, 1);
    try {
        $worker = new QueueV4CleanWorker(
            $pdo,
            $repository,
            null,
            null,
            static fn (): never => throw new QueueV4PreTransportDeferredException(
                gmdate('Y-m-d H:i:s', time() + 60),
            ),
        );
        $s8 = $worker->run('test', 3, 10);
        $s8Plan = $capacity(0, 0, (int) $s8['claimed']);
        $s8Repair = $s8Plan['remaining_slots'] > 0 && CronDeadlineContext::canAcceptWork(3) ? 1 : 0;
        $physicalHttp = (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_transport_events')->fetchColumn();
        $assert($s8 === ['claimed' => 3, 'completed' => 0, 'deferred' => 3], 'S8_worker_result_invalid');
        $assert($s8Repair === 0 && $physicalHttp === 0, 'S8_backpressure_or_transport_invalid');
        $nextWorker = $worker->run('test', 3, 10);
        $resumePlan = $capacity(0, 0, (int) $nextWorker['claimed']);
        $resumeRepair = $resumePlan['remaining_slots'] > 0 && CronDeadlineContext::canAcceptWork(3) ? 1 : 0;
        $assert($nextWorker['claimed'] === 0 && $resumeRepair === 1, 'repair_did_not_resume_without_latch');
    } finally {
        CronDeadlineContext::clear();
    }

    $reset();
    $seed('domain_exact', 'ready', 'fresh-domain-fifo');
    $assert($freshDelta() === 1, 'fresh_domain_admission_failed');
    $seen = [];
    CronDeadlineContext::start(10, 8, 2, 1);
    try {
        $fifoWorker = new QueueV4CleanWorker(
            $pdo,
            new QueueV4CleanRepository($pdo),
            null,
            null,
            static function (array $job) use (&$seen): void {
                $seen[] = (string) $job['job_type'];
            },
        );
        $fifoWorker->run('test', 1, 10);
    } finally {
        CronDeadlineContext::clear();
    }
    $assert($seen === ['domain_exact'], 'fresh_was_prioritized_over_older_domain');

    $schedulerSource = (string) file_get_contents($root . '/app/QueueV4Clean/QueueV4CleanScheduler.php');
    $repositorySource = (string) file_get_contents($root . '/app/QueueV4Clean/QueueV4CleanRepository.php');
    $producerSource = (string) file_get_contents($root . '/app/QueueV4Clean/QueueV4CleanProducer.php');
    $producerAt = strpos($schedulerSource, '$producer =');
    $workerAt = strpos($schedulerSource, '$worker =');
    $repairAt = strpos($schedulerSource, '$salesRepair =');
    $assert($producerAt !== false && $workerAt !== false && $repairAt !== false
        && $producerAt < $workerAt && $workerAt < $repairAt, 'scheduler_order_invalid');
    $assert(str_contains($schedulerSource, 'CronDeadlineContext::canAcceptWork(3)'), 'repair_deadline_authority_missing');
    $assert(str_contains($repositorySource, "job_type IN ('fresh_orders_discovery','order_exact')"), 'fresh_guard_types_invalid');
    $assert(str_contains($repositorySource, 'ORDER BY available_at ASC,id ASC'), 'fifo_sql_changed');
    $assert(str_contains($producerSource, 'hasOutstandingFreshFrontierWork(')
        && !str_contains($producerSource, 'hasOutstandingOperationalWork('), 'producer_guard_name_invalid');

    fwrite(STDOUT, 'QUEUE_V4_F2B_BACKPRESSURE_FRESH_GATE_2393=PASS checks=' . $checks
        . ' fresh_cases=6 scheduler_cases=8 max_claimed=3 physical_http=0 fifo=PASS resume=PASS' . PHP_EOL);
} finally {
    CronDeadlineContext::clear();
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
