<?php

declare(strict_types=1);

use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanDatabaseContract;
use App\QueueV4Clean\QueueV4CleanDispatchFence;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\ApiExecutionMetadataContext;
use App\Services\CronAdmissionService;
use App\Services\OrderFinancialRecalcJobService;
use App\Services\SaleFinancialService;
use App\Services\SchemaInspectorService;

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: K1 exige un DSN MariaDB desechable sin base seleccionada.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_k1_domain_' . bin2hex(random_bytes(5));
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-k1-domain-' . bin2hex(random_bytes(5));
@mkdir($temporary . DIRECTORY_SEPARATOR . 'storage', 0770, true);
$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$version = (string) $server->query('SELECT VERSION()')->fetchColumn();
if (stripos($version, 'mariadb') === false || version_compare(preg_replace('/[^0-9.].*$/', '', $version) ?: '0', '11.8.0', '<')) {
    fwrite(STDERR, "ERROR: K1 exige MariaDB 11.8+.\n");
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

    // Materialize the historical canonical contract, then apply only migration299.
    $oldContract = json_decode(
        (string) file_get_contents($root . '/resources/release/queue-v4-canonical-db-contract-2.38.9.json'),
        true,
        64,
        JSON_THROW_ON_ERROR
    );
    foreach ($oldContract['tables'] as $table => $definition) {
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
    // Referenced business roots are outside the Queue V4 contract document.
    $pdo->exec('CREATE TABLE companies(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(160) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE internal_products(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_orders(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        external_order_id VARCHAR(64) NOT NULL,
        external_pack_id VARCHAR(64) NULL,
        currency_id VARCHAR(8) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    foreach ($oldContract['tables'] as $table => $definition) {
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
    $columnType = (string) $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_v4_clean_jobs' AND COLUMN_NAME='job_type'"
    )->fetchColumn();
    $assert($columnType === "enum('fresh_orders_discovery','order_exact','domain_exact')", 'migration299_job_type_invalid');
    $assert((new QueueV4CleanDatabaseContract($pdo))->issues() === [], 'schema299_canonical_contract_failed');

    // Minimal domain source schema used by the exact boundary and its financial producers.
    $pdo->exec('CREATE TABLE order_financial_recalc_jobs(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NULL,
        meli_account_id BIGINT UNSIGNED NULL,
        mode VARCHAR(30) NOT NULL,
        source_type VARCHAR(40) NOT NULL,
        source_id BIGINT NULL,
        status VARCHAR(30) NOT NULL DEFAULT "pending",
        created_by BIGINT NULL,
        current_phase VARCHAR(30) NULL,
        settings_snapshot_json LONGTEXT NULL,
        total_items INT NOT NULL DEFAULT 0,
        processed_items INT NOT NULL DEFAULT 0,
        error_items INT NOT NULL DEFAULT 0,
        safe_message VARCHAR(500) NULL,
        started_at DATETIME NULL,
        completed_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE order_financial_recalc_job_items(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_financial_recalc_job_id BIGINT UNSIGNED NOT NULL,
        meli_order_id BIGINT UNSIGNED NOT NULL,
        external_order_id VARCHAR(64) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT "pending",
        phase VARCHAR(30) NULL,
        billing_status VARCHAR(30) NULL,
        financial_status VARCHAR(30) NULL,
        UNIQUE KEY uq_recalc_item(order_financial_recalc_job_id,meli_order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE sale_financial_state(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        sale_key VARCHAR(191) NOT NULL,
        input_version CHAR(64) NOT NULL,
        official_status VARCHAR(30) NOT NULL DEFAULT "missing",
        UNIQUE KEY uq_sale_state(company_id,meli_account_id,sale_key,input_version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
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
    $pdo->exec('CREATE TABLE cron_v3_work(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');

    $pdo->exec("INSERT INTO companies(id,name) VALUES (10,'Empresa K1'),(20,'Empresa ajena')");
    $pdo->exec("INSERT INTO meli_accounts(company_id,account_name) VALUES (10,'Cuenta K1'),(20,'Cuenta ajena')");
    $accountId = (int) $pdo->query("SELECT id FROM meli_accounts WHERE company_id=10")->fetchColumn();
    $otherAccountId = (int) $pdo->query("SELECT id FROM meli_accounts WHERE company_id=20")->fetchColumn();
    $pdo->prepare('INSERT INTO meli_orders(meli_account_id,external_order_id,currency_id) VALUES (?,"10001","COP")')
        ->execute([$accountId]);
    $orderId = (int) $pdo->lastInsertId();

    // Invalid submissions are fail-closed before queue DML.
    $pdo->exec("INSERT INTO order_financial_recalc_jobs(company_id,meli_account_id,mode,source_type,status) VALUES (10,{$accountId},'repaired','fixture','pending')");
    $fixtureSource = (int) $pdo->lastInsertId();
    $admission = new CronAdmissionService($pdo);
    $beforeJobs = (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn();
    $assert($admission->submit('unknown', 10, $accountId, $fixtureSource, 'x')['reason'] === 'UNSUPPORTED_CAPABILITY', 'unsupported_capability_not_closed');
    try {
        $admission->submit('financial_recalc', 10, $accountId, $fixtureSource, 'outside-transaction');
        throw new RuntimeException('admission_without_source_transaction_was_allowed');
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === 'cron_admission_source_transaction_required', 'source_transaction_not_enforced');
    }
    $pdo->beginTransaction();
    $assert($admission->submit('financial_recalc', 10, $otherAccountId, $fixtureSource, 'x')['reason'] === 'INVALID_TENANT', 'invalid_tenant_not_closed');
    $assert($admission->submit('financial_recalc', 10, $accountId, 999999, 'x')['reason'] === 'INVALID_SOURCE', 'missing_source_not_closed');
    $pdo->rollBack();
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn() === $beforeJobs, 'invalid_admission_mutated_queue');

    // Source and pointer share one caller-owned transaction.
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO order_financial_recalc_jobs(company_id,meli_account_id,mode,source_type,status) VALUES (10,{$accountId},'repaired','fixture','pending')");
    $atomicSource = (int) $pdo->lastInsertId();
    $atomicReceipt = $admission->submit('financial_recalc', 10, $accountId, $atomicSource, 'atomic-source');
    $pdo->commit();
    $assert($atomicReceipt['accepted'] === true && $atomicReceipt['deduplicated'] === false, 'atomic_admission_not_accepted');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE resource_id='{$atomicSource}'")->fetchColumn() === 1, 'atomic_pointer_missing');

    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO order_financial_recalc_jobs(company_id,meli_account_id,mode,source_type,status) VALUES (10,{$accountId},'repaired','fixture','pending')");
    $rolledSource = (int) $pdo->lastInsertId();
    $admission->submit('financial_recalc', 10, $accountId, $rolledSource, 'rolled-source');
    $pdo->rollBack();
    $assert((int) $pdo->query("SELECT COUNT(*) FROM order_financial_recalc_jobs WHERE id={$rolledSource}")->fetchColumn() === 0, 'source_rollback_failed');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE resource_id='{$rolledSource}'")->fetchColumn() === 0, 'pointer_rollback_failed');

    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO order_financial_recalc_jobs(company_id,meli_account_id,mode,source_type,status) VALUES (10,{$accountId},'repaired','fixture','pending')");
    $failedSource = (int) $pdo->lastInsertId();
    try {
        $admission->submit('financial_recalc', 10, $accountId, $failedSource, 'oversize', ['blob' => str_repeat('x', 5000)]);
        throw new RuntimeException('oversized_admission_was_not_rejected');
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === 'cron_admission_payload_too_large', 'unexpected_admission_failure');
        $pdo->rollBack();
    }
    $assert((int) $pdo->query("SELECT COUNT(*) FROM order_financial_recalc_jobs WHERE id={$failedSource}")->fetchColumn() === 0, 'admission_failure_did_not_rollback_source');

    // Duplicate receipts preserve every existing state, including completed/review.
    $pointerId = (int) $atomicReceipt['job_id'];
    foreach (['ready', 'running', 'waiting'] as $state) {
        $pdo->exec("UPDATE queue_v4_clean_jobs SET state='{$state}' WHERE id={$pointerId}");
        $pdo->beginTransaction();
        $receipt = $admission->submit('financial_recalc', 10, $accountId, $atomicSource, 'atomic-source');
        $pdo->commit();
        $assert($receipt['accepted'] === true && $receipt['deduplicated'] === true && $receipt['reason'] === 'ALREADY_QUEUED', 'active_duplicate_semantics_failed:' . $state);
        $assert((string) $pdo->query("SELECT state FROM queue_v4_clean_jobs WHERE id={$pointerId}")->fetchColumn() === $state, 'active_duplicate_changed_state:' . $state);
    }
    foreach (['completed' => 'ALREADY_COMPLETED', 'review' => 'REVIEW_HELD'] as $state => $reason) {
        $pdo->exec("UPDATE queue_v4_clean_jobs SET state='{$state}' WHERE id={$pointerId}");
        $pdo->beginTransaction();
        $receipt = $admission->submit('financial_recalc', 10, $accountId, $atomicSource, 'atomic-source');
        $pdo->commit();
        $assert($receipt['accepted'] === false && $receipt['reason'] === $reason, 'terminal_duplicate_semantics_failed:' . $state);
        $assert((string) $pdo->query("SELECT state FROM queue_v4_clean_jobs WHERE id={$pointerId}")->fetchColumn() === $state, 'terminal_pointer_reactivated:' . $state);
    }
    $pdo->beginTransaction();
    try {
        $admission->submit('financial_recalc', 10, $accountId, $fixtureSource, 'atomic-source');
        throw new RuntimeException('idempotency_conflict_not_rejected');
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === 'cron_admission_idempotency_conflict', 'idempotency_conflict_wrong_result');
        $pdo->rollBack();
    }

    // Actual sales-repair producer creates one recalc source and one domain ticket.
    SchemaInspectorService::clearCache();
    $recalcId = (new OrderFinancialRecalcJobService())->createForOrderIds(
        [$orderId],
        'repaired',
        'sales_repair',
        77,
        null
    );
    $recalcTicketCount = (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='domain_exact' AND resource_id='{$recalcId}'")->fetchColumn();
    $assert(
        $recalcTicketCount === 1,
        'sales_repair_recalc_ticket_missing:' . json_encode($pdo->query("SELECT id,total_items,status,source_type FROM order_financial_recalc_jobs WHERE id={$recalcId}")->fetch(PDO::FETCH_ASSOC))
    );
    $assert((int) $pdo->query('SELECT COUNT(*) FROM sale_financial_reconciliation_jobs')->fetchColumn() === 0, 'sales_repair_created_parallel_reconciliation');

    // Actual reconciliation creation uses domain admission and never Cron V3 for this chain.
    $enqueueSale = new ReflectionMethod(SaleFinancialService::class, 'enqueueSale');
    $financial = new SaleFinancialService();
    $inputA = str_repeat('a', 64);
    $reconciliationId = (int) $enqueueSale->invoke(
        $financial,
        10,
        $accountId,
        'O:10001',
        '10001',
        'order',
        'COP',
        'financial_recalc_local',
        $recalcId,
        30,
        null,
        $inputA
    );
    $assert($reconciliationId > 0, 'reconciliation_source_not_created');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='domain_exact' AND resource_id='{$reconciliationId}' AND payload_json LIKE '%financial_reconciliation%'")->fetchColumn() === 1, 'reconciliation_ticket_missing');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM cron_v3_work')->fetchColumn() === 0, 'cron_v3_received_domain_finance');
    $successorId = (int) $enqueueSale->invoke(
        $financial,
        10,
        $accountId,
        'O:10001',
        '10001',
        'order',
        'COP',
        'domain_input_changed',
        $reconciliationId,
        30,
        null,
        str_repeat('b', 64)
    );
    $assert($successorId !== $reconciliationId, 'input_version_successor_not_created');
    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE resource_id='{$successorId}' AND payload_json LIKE '%financial_reconciliation%'")->fetchColumn() === 1, 'successor_ticket_not_exactly_once');

    // Historical and tenant-null rows remain outside K1.
    $pdo->exec("INSERT INTO order_financial_recalc_jobs(company_id,meli_account_id,mode,source_type,status) VALUES (NULL,NULL,'repaired','legacy','pending')");
    $tenantNullId = (int) $pdo->lastInsertId();
    $pdo->beginTransaction();
    $tenantNullReceipt = $admission->submit('financial_recalc', 10, $accountId, $tenantNullId, 'tenant-null');
    $pdo->rollBack();
    $assert($tenantNullReceipt['reason'] === 'INVALID_SOURCE', 'tenant_null_source_admitted');
    $historicalBefore = (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn();
    $pdo->exec("INSERT INTO order_financial_recalc_jobs(company_id,meli_account_id,mode,source_type,status) VALUES (10,{$accountId},'repaired','historical','pending')");
    $assert((int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn() === $historicalBefore, 'historical_backlog_auto_admitted');

    // Isolated worker mapping: complete/waiting/review and FIFO, bounded to three claims.
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec('TRUNCATE TABLE queue_v4_clean_attempts');
    $pdo->exec('TRUNCATE TABLE queue_v4_clean_runs');
    $pdo->exec('TRUNCATE TABLE queue_v4_clean_jobs');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("INSERT INTO queue_v4_clean_control(control_key,engine_state,readiness_state,scheduler_enabled) VALUES ('primary','ACTIVE','CERTIFIED',1)");
    $sourceIds = [];
    foreach (['pending', 'pending', 'pending', 'pending'] as $status) {
        $pdo->prepare('INSERT INTO order_financial_recalc_jobs(company_id,meli_account_id,mode,source_type,status) VALUES (10,?,"repaired","worker",?)')
            ->execute([$accountId, $status]);
        $sourceId = (int) $pdo->lastInsertId();
        $sourceIds[] = $sourceId;
        $pdo->beginTransaction();
        $admission->submit('financial_recalc', 10, $accountId, $sourceId, 'worker:' . $sourceId);
        $pdo->commit();
    }
    $seen = [];
    $physicalBoundaries = 0;
    $targetStates = [$sourceIds[0] => 'complete', $sourceIds[1] => 'pending', $sourceIds[2] => 'error'];
    $domainHandler = static function (string $capability, int $sourceId, int $claimedAccount) use (
        $pdo,
        $accountId,
        &$seen,
        &$physicalBoundaries,
        $targetStates,
        $sourceIds,
    ): void {
        if ($capability !== 'financial_recalc' || $claimedAccount !== $accountId) {
            throw new RuntimeException('worker_domain_scope_invalid');
        }
        $seen[] = $sourceId;
        if ($sourceId === $sourceIds[0]) {
            ApiExecutionMetadataContext::withTransportMetadata(
                ['transport_request_id' => 'k1-domain-physical-boundary'],
                static function () use (&$physicalBoundaries): void {
                    QueueV4CleanDispatchFence::immediatelyBeforeCurl(
                        'GET',
                        '/billing/integration/group/ML/order/details'
                    );
                    $physicalBoundaries++;
                    QueueV4CleanDispatchFence::responseKnown(200);
                }
            );
        }
        $pdo->prepare('UPDATE order_financial_recalc_jobs SET status=? WHERE id=? AND meli_account_id=?')
            ->execute([$targetStates[$sourceId] ?? 'pending', $sourceId, $claimedAccount]);
    };
    QueueV4CleanCycleBudget::start(3);
    try {
        $workerResult = (new QueueV4CleanWorker($pdo, new QueueV4CleanRepository($pdo), null, null, null, $domainHandler))
            ->run('test', 3, 10);
        $budget = QueueV4CleanCycleBudget::snapshot();
    } finally {
        QueueV4CleanCycleBudget::clear();
    }
    $assert($workerResult['claimed'] === 3, 'max_jobs_three_not_preserved');
    $assert($seen === array_slice($sourceIds, 0, 3), 'domain_fifo_changed');
    $assert($physicalBoundaries === 1 && (int) ($budget['used'] ?? -1) === 1, 'domain_exact_physical_http_not_bounded_to_one');
    $assert((int) $pdo->query(
        "SELECT COUNT(*) FROM queue_v4_clean_transport_events
         WHERE source_kind='queue' AND endpoint_key='billing_orders'
           AND dispatch_state='RESPONSE_KNOWN' AND http_status=200"
    )->fetchColumn() === 1, 'domain_exact_physical_journal_invalid');
    $states = $pdo->query('SELECT resource_id,state,last_error_class,attempt_count FROM queue_v4_clean_jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $assert((string) $states[0]['state'] === 'completed', 'source_complete_pointer_not_completed');
    $assert((string) $states[1]['state'] === 'waiting' && (int) $states[1]['attempt_count'] === 0, 'source_pending_pointer_not_nonfailure_waiting');
    $assert((string) $states[2]['state'] === 'review', 'source_error_pointer_not_review');
    $assert((string) $states[3]['state'] === 'ready', 'worker_exceeded_max_jobs_three');

    // Corrupt/missing pointer mapping is inspected directly because Repository::claim already
    // rejects a mismatched company/account pair before the Worker can receive it.
    $worker = new QueueV4CleanWorker($pdo, new QueueV4CleanRepository($pdo), null, null, null, $domainHandler);
    $handleDomain = new ReflectionMethod($worker, 'handleDomainExact');
    $tenantMismatch = $handleDomain->invoke(
        $worker,
        ['resource_id' => (string) $sourceIds[0]],
        10,
        $otherAccountId,
        ['capability' => 'financial_recalc', 'source_id' => $sourceIds[0]],
    );
    $missing = $handleDomain->invoke(
        $worker,
        ['resource_id' => '999999'],
        10,
        $accountId,
        ['capability' => 'financial_recalc', 'source_id' => 999999],
    );
    $assert(($tenantMismatch['classification'] ?? '') === 'domain_source_tenant_mismatch', 'worker_tenant_mismatch_not_quarantined');
    $assert(($missing['classification'] ?? '') === 'domain_source_missing', 'worker_missing_source_not_quarantined');

    $repairSource = (string) file_get_contents($root . '/app/Services/SalesAuditExactRepairService.php');
    $workerSource = (string) file_get_contents($root . '/app/QueueV4Clean/QueueV4CleanWorker.php');
    $schedulerSource = (string) file_get_contents($root . '/app/QueueV4Clean/QueueV4CleanScheduler.php');
    $repositorySource = (string) file_get_contents($root . '/app/QueueV4Clean/QueueV4CleanRepository.php');
    $saleSource = (string) file_get_contents($root . '/app/Services/SaleFinancialService.php');
    $assert(!str_contains($repairSource, 'queueFromOrderIds('), 'sales_repair_parallel_reconciliation_still_present');
    $assert(str_contains($saleSource, 'processSelected(1, $jobId, false, true)'), 'domain_exact_successor_not_enabled');
    $assert(str_contains($saleSource, 'processSelected(1,$jobId,true)'), 'manual_exact_semantics_changed');
    $assert(str_contains($repositorySource, 'ORDER BY available_at ASC,id ASC'), 'fifo_sql_changed');
    $assert(!str_contains($schedulerSource, 'domain_exact'), 'domain_stage_added_to_scheduler');
    $assert(str_contains($workerSource, "if (\$type === 'domain_exact')"), 'worker_domain_handler_missing');
    $assert(str_contains($workerSource, 'processExact($sourceId, $accountId, 1)'), 'financial_recalc_not_exact_one');
    $assert(str_contains($workerSource, 'processDomainExactBatch('), 'financial_reconciliation_not_queue_owned_batch');

    fwrite(STDOUT, 'DOMAIN_EXACT_FINANCE_ADMISSION_2393=PASS checks=' . $checks
        . ' schema=299 fake_http=1 real_http=0 max_jobs=3 historical=0 tenant_null=0' . PHP_EOL);
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    @rmdir($temporary . DIRECTORY_SEPARATOR . 'storage');
    @rmdir($temporary);
}
