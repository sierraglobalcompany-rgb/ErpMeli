<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\CronAdmissionService;
use App\Services\SaleFinancialService;

$mode = strtolower(trim((string) ($argv[1] ?? 'post')));
if (!in_array($mode, ['pre', 'post'], true)) {
    fwrite(STDERR, "Usage: php billing_terminal_hy093_convergence_2395_mysql.php [pre|post]\n");
    exit(2);
}

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: H2 exige un DSN MariaDB desechable sin base seleccionada.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_h2_billing_' . bin2hex(random_bytes(5));
$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$version = (string) $server->query('SELECT VERSION()')->fetchColumn();
if (stripos($version, 'mariadb') === false) {
    fwrite(STDERR, "ERROR: H2 exige MariaDB.\n");
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
    putenv('ML_WRITE_ENABLED=false');
    $_ENV['ML_WRITE_ENABLED'] = 'false';
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

    $contract = json_decode(
        (string) file_get_contents($root . '/resources/release/queue-v4-canonical-db-contract-2.38.9.json'),
        true,
        64,
        JSON_THROW_ON_ERROR
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
        $indexGroups = [];
        foreach ($definition['indexes'] as $index) {
            $indexGroups[(string) $index['INDEX_NAME']][] = $index;
        }
        foreach ($indexGroups as $name => $parts) {
            usort($parts, static fn (array $a, array $b): int => (int) $a['SEQ_IN_INDEX'] <=> (int) $b['SEQ_IN_INDEX']);
            $indexed = array_map(
                static fn (array $part): string => $quote((string) $part['COLUMN_NAME'])
                    . ($part['SUB_PART'] !== null ? '(' . (int) $part['SUB_PART'] . ')' : ''),
                $parts
            );
            $prefix = $name === 'PRIMARY'
                ? 'PRIMARY KEY'
                : ((int) $parts[0]['NON_UNIQUE'] === 0 ? 'UNIQUE KEY ' . $quote($name) : 'KEY ' . $quote($name));
            $columns[] = $prefix . ' (' . implode(',', $indexed) . ')';
        }
        $pdo->exec(
            'CREATE TABLE ' . $quote((string) $table) . ' (' . implode(',', $columns) . ') ENGINE='
            . $definition['engine'] . ' DEFAULT CHARSET=utf8mb4 COLLATE=' . $definition['collation']
        );
    }

    $pdo->exec('CREATE TABLE companies(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(160) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE internal_products(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_orders(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        external_order_id VARCHAR(64) NOT NULL,
        external_pack_id VARCHAR(64) NULL,
        currency_id VARCHAR(8) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    foreach ($contract['tables'] as $table => $definition) {
        $groups = [];
        foreach ($definition['foreign_keys'] as $foreignKey) {
            $groups[(string) $foreignKey['CONSTRAINT_NAME']][] = $foreignKey;
        }
        foreach ($groups as $name => $parts) {
            usort($parts, static fn (array $a, array $b): int => (int) $a['ORDINAL_POSITION'] <=> (int) $b['ORDINAL_POSITION']);
            $local = array_map(static fn (array $row): string => $quote((string) $row['COLUMN_NAME']), $parts);
            $remote = array_map(static fn (array $row): string => $quote((string) $row['REFERENCED_COLUMN_NAME']), $parts);
            $first = $parts[0];
            $pdo->exec(
                'ALTER TABLE ' . $quote((string) $table)
                . ' ADD CONSTRAINT ' . $quote($name)
                . ' FOREIGN KEY (' . implode(',', $local) . ') REFERENCES '
                . $quote((string) $first['REFERENCED_TABLE_NAME']) . ' (' . implode(',', $remote) . ')'
                . ((string) $first['MATCH_OPTION'] !== 'NONE' ? ' MATCH ' . $first['MATCH_OPTION'] : '')
                . ' ON UPDATE ' . $first['UPDATE_RULE']
                . ' ON DELETE ' . $first['DELETE_RULE']
            );
        }
    }
    $pdo->exec((string) file_get_contents($root . '/database/migrations/299_queue_v4_domain_exact_admission_2_39_3.sql'));
    $pdo->prepare('INSERT INTO schema_migrations(version) VALUES (?)')->execute([
        '299_queue_v4_domain_exact_admission_2_39_3.sql',
    ]);
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
        lock_owner VARCHAR(96) NULL,
        lease_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
        lease_expires_at DATETIME NULL,
        heartbeat_at DATETIME NULL,
        attempts INT NOT NULL DEFAULT 0,
        UNIQUE KEY uq_sale_reconciliation(company_id,meli_account_id,sale_key,input_version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $pdo->exec("INSERT INTO companies(id,name) VALUES (10,'Empresa H2')");
    $pdo->exec("INSERT INTO meli_accounts(company_id,account_name) VALUES (10,'Cuenta H2')");
    $accountId = (int) $pdo->query('SELECT id FROM meli_accounts WHERE company_id=10')->fetchColumn();
    $pdo->exec("INSERT INTO queue_v4_clean_control(control_key,engine_state,readiness_state,scheduler_enabled) VALUES ('primary','ACTIVE','CERTIFIED',1)");

    $service = new SaleFinancialService();
    $finish = new ReflectionMethod($service, 'finishInTransaction');
    $admission = new CronAdmissionService($pdo);

    /** @return array{job:array<string,mixed>,pointer_id:int} */
    $seed = static function (string $label) use ($pdo, $accountId, $admission): array {
        $owner = 'h2-owner-' . $label;
        $inputVersion = hash('sha256', 'h2-' . $label);
        $pdo->beginTransaction();
        $insert = $pdo->prepare(
            'INSERT INTO sale_financial_reconciliation_jobs
             (company_id,meli_account_id,sale_key,external_sale_id,input_version,status,origin_type,
              next_run_at,lock_owner,lease_generation,lease_expires_at,heartbeat_at,attempts)
             VALUES (10,?, ?, ?, ?, "running", "h2_fixture", UTC_TIMESTAMP(), ?, 7,
                     DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE),UTC_TIMESTAMP(),2)'
        );
        $insert->execute([$accountId, 'O:' . $label, $label, $inputVersion, $owner]);
        $sourceId = (int) $pdo->lastInsertId();
        $receipt = $admission->submit(
            'financial_reconciliation',
            10,
            $accountId,
            $sourceId,
            'h2:' . $label
        );
        $pdo->commit();
        if (($receipt['accepted'] ?? false) !== true || (int) ($receipt['job_id'] ?? 0) < 1) {
            throw new RuntimeException('h2_pointer_admission_failed:' . $label);
        }
        return [
            'job' => [
                'id' => $sourceId,
                'lock_owner' => $owner,
                'lease_generation' => 7,
                'attempts' => 2,
            ],
            'pointer_id' => (int) $receipt['job_id'],
        ];
    };

    $invoke = static function (array $job, string $status) use ($pdo, $finish, $service): void {
        $pdo->beginTransaction();
        try {
            $finish->invoke($service, $pdo, $job, $status, 'H2 ' . $status, 'RESPONSE_KNOWN');
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    };

    if ($mode === 'pre') {
        $fixture = $seed('pre-complete');
        $hy093 = false;
        try {
            $invoke($fixture['job'], 'complete');
        } catch (PDOException $error) {
            $hy093 = (string) $error->getCode() === 'HY093'
                && str_contains(strtolower($error->getMessage()), 'parameter was not defined');
        }
        $assert($hy093, 'pre_fix_hy093_not_reproduced');
        $row = $pdo->query(
            'SELECT status,lock_owner,lease_generation FROM sale_financial_reconciliation_jobs WHERE id=' . (int) $fixture['job']['id']
        )->fetch(PDO::FETCH_ASSOC);
        $assert((string) $row['status'] === 'running', 'pre_fix_source_did_not_rollback');
        $assert((string) $row['lock_owner'] === (string) $fixture['job']['lock_owner'], 'pre_fix_lock_changed');
        fwrite(STDOUT, 'BILLING_TERMINAL_HY093_2395_PRE=PASS checks=' . $checks
            . ' native_prepares=1 sqlstate=HY093 source_rollback=1 real_http=0' . PHP_EOL);
        return;
    }

    $matrix = [];
    $mapping = [];
    $terminalPointerIds = [];
    $domainHandlerCalls = 0;
    $worker = new QueueV4CleanWorker(
        $pdo,
        new QueueV4CleanRepository($pdo),
        null,
        null,
        null,
        static function (string $capability, int $sourceId, int $claimedAccount) use (
            $accountId,
            &$domainHandlerCalls,
        ): void {
            if ($capability !== 'financial_reconciliation' || $sourceId < 1 || $claimedAccount !== $accountId) {
                throw new RuntimeException('h2_domain_scope_invalid');
            }
            $domainHandlerCalls++;
        },
    );

    foreach (['complete', 'partial', 'review', 'error', 'retry', 'awaiting_remote'] as $status) {
        $fixture = $seed($status);
        $before = (string) $pdo->query('SELECT UTC_TIMESTAMP()')->fetchColumn();
        try {
            $invoke($fixture['job'], $status);
            $matrix[$status] = 'PASS';
        } catch (PDOException $error) {
            if ((string) $error->getCode() === 'HY093') {
                $matrix[$status] = 'HY093';
                continue;
            }
            throw $error;
        }

        $source = $pdo->query(
            'SELECT status,next_run_at,lock_owner,lease_expires_at,heartbeat_at,completed_at
             FROM sale_financial_reconciliation_jobs WHERE id=' . (int) $fixture['job']['id']
        )->fetch(PDO::FETCH_ASSOC);
        $assert((string) $source['status'] === $status, 'source_status_not_converged:' . $status);
        $assert($source['lock_owner'] === null, 'source_owner_not_released:' . $status);
        $assert($source['lease_expires_at'] === null, 'source_lease_not_released:' . $status);
        $assert($source['heartbeat_at'] === null, 'source_heartbeat_not_released:' . $status);
        if (in_array($status, ['complete', 'partial', 'review', 'error'], true)) {
            $assert($source['completed_at'] !== null, 'terminal_completed_at_missing:' . $status);
            $terminalPointerIds[] = (int) $fixture['pointer_id'];
        } else {
            $assert($source['completed_at'] === null, 'deferred_completed_at_changed:' . $status);
            $assert(strtotime((string) $source['next_run_at'] . ' UTC') > strtotime($before . ' UTC'), 'deferred_delay_missing:' . $status);
        }

        $result = $worker->run('test', 1, 10);
        $assert((int) $result['claimed'] === 1, 'worker_did_not_claim_pointer:' . $status);
        $pointer = $pdo->query(
            'SELECT state,available_at FROM queue_v4_clean_jobs WHERE id=' . (int) $fixture['pointer_id']
        )->fetch(PDO::FETCH_ASSOC);
        $outcome = (string) $pdo->query(
            'SELECT outcome FROM queue_v4_clean_attempts WHERE job_id=' . (int) $fixture['pointer_id'] . ' ORDER BY id DESC LIMIT 1'
        )->fetchColumn();
        $expectedPointer = $status === 'complete'
            ? 'completed'
            : (in_array($status, ['retry', 'awaiting_remote'], true) ? 'waiting' : 'review');
        $assert((string) $pointer['state'] === $expectedPointer, 'pointer_mapping_invalid:' . $status);
        $assert($outcome === $expectedPointer, 'attempt_mapping_invalid:' . $status);
        if ($expectedPointer === 'waiting') {
            $assert(strtotime((string) $pointer['available_at'] . ' UTC') > time(), 'pointer_wait_not_future:' . $status);
        }
        $mapping[$status] = $expectedPointer;
    }

    $assert(!in_array('HY093', $matrix, true), 'post_fix_hy093_present');
    $assert($domainHandlerCalls === 2, 'deferred_domain_handler_count_invalid');
    $terminalList = implode(',', array_map('intval', $terminalPointerIds));
    $executableTerminalPointers = (int) $pdo->query(
        "SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE id IN ({$terminalList}) AND state IN ('ready','running','waiting')"
    )->fetchColumn();
    $assert($executableTerminalPointers === 0, 'terminal_pointer_left_executable');

    foreach (['wrong-owner', 'wrong-generation'] as $case) {
        $fixture = $seed($case);
        $wrong = $fixture['job'];
        if ($case === 'wrong-owner') {
            $wrong['lock_owner'] = 'not-the-owner';
        } else {
            $wrong['lease_generation'] = 8;
        }
        $failedClosed = false;
        try {
            $invoke($wrong, 'complete');
        } catch (RuntimeException $error) {
            $failedClosed = $error->getMessage() === 'La conciliación perdió su reserva antes de aprobar el resultado.';
        }
        $assert($failedClosed, 'lease_cas_not_fail_closed:' . $case);
        $source = $pdo->query(
            'SELECT status,lock_owner,lease_generation FROM sale_financial_reconciliation_jobs WHERE id=' . (int) $fixture['job']['id']
        )->fetch(PDO::FETCH_ASSOC);
        $assert((string) $source['status'] === 'running', 'lease_failure_changed_source:' . $case);
        $assert((string) $source['lock_owner'] === (string) $fixture['job']['lock_owner'], 'lease_failure_changed_owner:' . $case);
        $assert((int) $source['lease_generation'] === 7, 'lease_failure_changed_generation:' . $case);
    }

    fwrite(STDOUT, 'BILLING_TERMINAL_HY093_CONVERGENCE_2395=PASS checks=' . $checks
        . ' native_prepares=1 post_fix_hy093=0 parameter_matrix=' . implode(',', array_keys($matrix))
        . ' mapping=complete:completed,partial:review,review:review,error:review,retry:waiting,awaiting_remote:waiting'
        . ' lease_fail_closed=1 deferred_delay=1 executable_terminal_pointers=' . $executableTerminalPointers
        . ' real_http=0' . PHP_EOL);
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
