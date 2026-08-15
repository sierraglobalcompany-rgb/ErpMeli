<?php

declare(strict_types=1);

use App\QueueV4Clean\QueueV4CleanDatabaseContract;

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
if ($dsn === '' || !str_starts_with(strtolower($dsn), 'mysql:') || stripos($dsn, 'dbname=') !== false) {
    fwrite(STDERR, "ERROR: transition 2393 exige DSN MariaDB desechable sin base seleccionada.\n");
    exit(2);
}

$root = dirname(__DIR__);
$database = 'erp_r2393_transition_' . bin2hex(random_bytes(5));
$server = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$version = (string) $server->query('SELECT VERSION()')->fetchColumn();
if (stripos($version, 'mariadb') === false || version_compare(preg_replace('/[^0-9.].*$/', '', $version) ?: '0', '11.8.0', '<')) {
    fwrite(STDERR, "ERROR: transition 2393 exige MariaDB 11.8+.\n");
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

    $contract = json_decode(
        (string) file_get_contents($root . '/resources/release/queue-v4-canonical-db-contract-2.38.9.json'),
        true,
        64,
        JSON_THROW_ON_ERROR,
    );
    foreach ($contract['tables'] as $table => $definition) {
        $parts = [];
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
            $parts[] = $sql;
        }
        $groups = [];
        foreach ($definition['indexes'] as $index) {
            $groups[(string) $index['INDEX_NAME']][] = $index;
        }
        foreach ($groups as $name => $indexes) {
            usort($indexes, static fn (array $a, array $b): int => (int) $a['SEQ_IN_INDEX'] <=> (int) $b['SEQ_IN_INDEX']);
            $columns = array_map(
                static fn (array $index): string => $quote((string) $index['COLUMN_NAME'])
                    . ($index['SUB_PART'] !== null ? '(' . (int) $index['SUB_PART'] . ')' : ''),
                $indexes,
            );
            $prefix = $name === 'PRIMARY'
                ? 'PRIMARY KEY'
                : ((int) $indexes[0]['NON_UNIQUE'] === 0 ? 'UNIQUE KEY ' . $quote($name) : 'KEY ' . $quote($name));
            $parts[] = $prefix . ' (' . implode(',', $columns) . ')';
        }
        $pdo->exec(
            'CREATE TABLE ' . $quote((string) $table) . ' (' . implode(',', $parts) . ') ENGINE='
            . $definition['engine'] . ' DEFAULT CHARSET=utf8mb4 COLLATE=' . $definition['collation']
        );
    }
    $pdo->exec('CREATE TABLE companies(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(160) NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE internal_products(id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_orders(id BIGINT UNSIGNED PRIMARY KEY,meli_account_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB');
    foreach ($contract['tables'] as $table => $definition) {
        $groups = [];
        foreach ($definition['foreign_keys'] as $foreignKey) {
            $groups[(string) $foreignKey['CONSTRAINT_NAME']][] = $foreignKey;
        }
        foreach ($groups as $name => $foreignKeys) {
            usort($foreignKeys, static fn (array $a, array $b): int => (int) $a['ORDINAL_POSITION'] <=> (int) $b['ORDINAL_POSITION']);
            $local = array_map(static fn (array $row): string => $quote((string) $row['COLUMN_NAME']), $foreignKeys);
            $remote = array_map(static fn (array $row): string => $quote((string) $row['REFERENCED_COLUMN_NAME']), $foreignKeys);
            $first = $foreignKeys[0];
            $pdo->exec(
                'ALTER TABLE ' . $quote((string) $table) . ' ADD CONSTRAINT ' . $quote($name)
                . ' FOREIGN KEY (' . implode(',', $local) . ') REFERENCES '
                . $quote((string) $first['REFERENCED_TABLE_NAME']) . ' (' . implode(',', $remote) . ')'
                . ((string) $first['MATCH_OPTION'] !== 'NONE' ? ' MATCH ' . $first['MATCH_OPTION'] : '')
                . ' ON UPDATE ' . $first['UPDATE_RULE'] . ' ON DELETE ' . $first['DELETE_RULE']
            );
        }
    }

    for ($number = 1; $number <= 279; $number++) {
        $pdo->prepare('INSERT INTO schema_migrations(version) VALUES (?)')
            ->execute([sprintf('%03d_historical_schema_authority.sql', $number)]);
    }
    foreach (glob($root . '/database/migrations/*.sql') ?: [] as $migration) {
        if ((int) substr(basename($migration), 0, 3) <= 298) {
            $pdo->prepare('INSERT INTO schema_migrations(version) VALUES (?)')->execute([basename($migration)]);
        }
    }
    $assert((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 298, 'schema298_preimage_invalid');

    $pdo->exec("INSERT INTO companies(id,name) VALUES (10,'Empresa transition')");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name) VALUES (20,10,'Cuenta transition')");
    $fixtures = [
        ['fresh_orders_discovery', 'ready', 'fresh:ready', '{"cursor":null}'],
        ['order_exact', 'waiting', 'order:waiting', '{"order_id":"1001"}'],
        ['order_exact', 'review', 'order:review', '{"order_id":"1002"}'],
        ['order_exact', 'completed', 'order:completed', '{"order_id":"1003"}'],
    ];
    foreach ($fixtures as $index => [$type, $state, $key, $payload]) {
        $pdo->prepare(
            'INSERT INTO queue_v4_clean_jobs
             (company_id,meli_account_id,job_type,resource_id,idempotency_key,state,available_at,payload_json,completed_at)
             VALUES (10,20,?,?,?, ?,DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ? SECOND),?,IF(?="completed",UTC_TIMESTAMP(3),NULL))'
        )->execute([$type, (string) (1000 + $index), $key, $state, $index, $payload, $state]);
    }
    $rowsBefore = $pdo->query(
        'SELECT id,company_id,meli_account_id,job_type,resource_id,idempotency_key,state,attempt_count,
                max_attempts,available_at,lease_owner,lease_expires_at,payload_json,last_error_class,
                created_at,updated_at,completed_at
         FROM queue_v4_clean_jobs ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $tablesBefore = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    $columnsBefore = $pdo->query(
        "SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA
         FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
         ORDER BY TABLE_NAME,ORDINAL_POSITION"
    )->fetchAll(PDO::FETCH_ASSOC);
    $indexesBefore = $pdo->query(
        "SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE
         FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
         ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX"
    )->fetchAll(PDO::FETCH_ASSOC);

    $pdo->exec((string) file_get_contents($root . '/database/migrations/299_queue_v4_domain_exact_admission_2_39_3.sql'));
    $pdo->prepare('INSERT INTO schema_migrations(version) VALUES (?)')->execute([
        '299_queue_v4_domain_exact_admission_2_39_3.sql',
    ]);

    $rowsAfter = $pdo->query(
        'SELECT id,company_id,meli_account_id,job_type,resource_id,idempotency_key,state,attempt_count,
                max_attempts,available_at,lease_owner,lease_expires_at,payload_json,last_error_class,
                created_at,updated_at,completed_at
         FROM queue_v4_clean_jobs ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $assert($rowsAfter === $rowsBefore, 'preexisting_queue_rows_changed');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 299, 'schema299_postimage_invalid');
    $enum = (string) $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_v4_clean_jobs' AND COLUMN_NAME='job_type'"
    )->fetchColumn();
    $assert($enum === "enum('fresh_orders_discovery','order_exact','domain_exact')", 'domain_exact_enum_not_admitted');

    $tablesAfter = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    $indexesAfter = $pdo->query(
        "SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE
         FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
         ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX"
    )->fetchAll(PDO::FETCH_ASSOC);
    $columnsAfter = $pdo->query(
        "SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA
         FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
         ORDER BY TABLE_NAME,ORDINAL_POSITION"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columnsBefore as &$column) {
        if ($column['TABLE_NAME'] === 'queue_v4_clean_jobs' && $column['COLUMN_NAME'] === 'job_type') {
            $column['COLUMN_TYPE'] = "enum('fresh_orders_discovery','order_exact','domain_exact')";
        }
    }
    unset($column);
    $assert($tablesAfter === $tablesBefore, 'migration299_changed_tables');
    $assert($indexesAfter === $indexesBefore, 'migration299_changed_indexes');
    $assert($columnsAfter === $columnsBefore, 'migration299_changed_unrelated_columns');

    $pdo->beginTransaction();
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json)
         VALUES (10,20,'domain_exact','9001','domain:transition:9001','{\"capability\":\"financial_recalc\",\"source_id\":9001}')"
    )->execute();
    $assert((int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='domain_exact'")->fetchColumn() === 1, 'domain_exact_insert_failed');
    $pdo->rollBack();
    $assert((new QueueV4CleanDatabaseContract($pdo))->issues() === [], 'schema299_contract_failed');

    echo 'QUEUE_V4_UPDATE_TRANSITION_2393=PASS checks=' . $checks
        . ' schema=299 migrations=299 rows_changed=0 new_tables=0 new_columns=0 data_loss=0 meli_http=0' . PHP_EOL;
} finally {
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
