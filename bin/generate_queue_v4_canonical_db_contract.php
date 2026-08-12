<?php

declare(strict_types=1);

use App\Core\Database;
require dirname(__DIR__) . '/bootstrap.php';

$output = $argv[1] ?? dirname(__DIR__) . '/docs/queue-v4/QUEUE_V4_CANONICAL_DB_CONTRACT.json';
$baseContractPath = trim((string) (getenv('QUEUE_V4_BASE_CONTRACT') ?: ''));
$extensionTables = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) (getenv('QUEUE_V4_EXTENSION_TABLES') ?: ''))
)));
$baseContract = null;
if ($baseContractPath !== '') {
    $baseContract = json_decode((string) file_get_contents($baseContractPath), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($baseContract) || !is_array($baseContract['tables'] ?? null)) {
        throw new RuntimeException('canonical_base_contract_invalid');
    }
}
$tables = [
    'schema_migrations', 'app_settings', 'meli_accounts', 'meli_tokens',
    'queue_v4_clean_control', 'queue_v4_clean_readiness_runs',
    'queue_v4_clean_readiness_accounts', 'queue_v4_clean_jobs',
    'queue_v4_clean_attempts', 'queue_v4_clean_runs',
    'queue_v4_clean_leases', 'queue_v4_clean_checkpoints',
    'inventory_warehouses', 'inventory_balances',
    'inventory_movements', 'inventory_reviews',
];

$pdo = Database::connectionFresh();
$database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$server = (string) $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
$versionFamily = preg_match('/^(\d+\.\d+\.\d+)/', $server, $versionMatch) === 1
    ? $versionMatch[1]
    : '';
if ($versionFamily === '') {
    throw new RuntimeException('canonical_database_version_invalid');
}
$result = [
    'generated_from' => 'information_schema',
    'database_engine' => str_contains(strtolower($server), 'mariadb') ? 'MariaDB' : 'MySQL',
    'database_version' => $server,
    'database_version_family' => $versionFamily,
    'tables' => [],
];

foreach ($tables as $table) {
    if ($baseContract !== null && $extensionTables !== [] && !in_array($table, $extensionTables, true)) {
        if (!is_array($baseContract['tables'][$table] ?? null)) {
            throw new RuntimeException('canonical_base_table_missing:' . $table);
        }
        $result['tables'][$table] = $baseContract['tables'][$table];
        continue;
    }
    $tableStatement = $pdo->prepare(
        'SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND TABLE_TYPE="BASE TABLE"'
    );
    $tableStatement->execute([$database, $table]);
    $tableRow = $tableStatement->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($tableRow)) {
        throw new RuntimeException('canonical_table_missing:' . $table);
    }

    $columns = $pdo->prepare(
        'SELECT ORDINAL_POSITION,COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,
                COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION'
    );
    $columns->execute([$database, $table]);

    $indexes = $pdo->prepare(
        'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,COLLATION,INDEX_TYPE
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX'
    );
    $indexes->execute([$database, $table]);

    $foreignKeys = $pdo->prepare(
        'SELECT k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.COLUMN_NAME,
                k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,
                r.MATCH_OPTION,r.UPDATE_RULE,r.DELETE_RULE
         FROM information_schema.KEY_COLUMN_USAGE k
         INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r
           ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
          AND r.TABLE_NAME=k.TABLE_NAME
          AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
         WHERE k.CONSTRAINT_SCHEMA=? AND k.TABLE_NAME=?
           AND k.REFERENCED_TABLE_NAME IS NOT NULL
         ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION'
    );
    $foreignKeys->execute([$database, $table]);

    $result['tables'][$table] = [
        'engine' => (string) $tableRow['ENGINE'],
        'collation' => (string) $tableRow['TABLE_COLLATION'],
        'columns' => $columns->fetchAll(\PDO::FETCH_ASSOC),
        'indexes' => $indexes->fetchAll(\PDO::FETCH_ASSOC),
        'foreign_keys' => $foreignKeys->fetchAll(\PDO::FETCH_ASSOC),
    ];
}

$directory = dirname($output);
if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    throw new RuntimeException('canonical_contract_directory_failed');
}
$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($output, $json, LOCK_EX) !== strlen($json)) {
    throw new RuntimeException('canonical_contract_write_failed');
}
fwrite(STDOUT, 'QUEUE_V4_CANONICAL_DB_CONTRACT=' . $output . PHP_EOL);
