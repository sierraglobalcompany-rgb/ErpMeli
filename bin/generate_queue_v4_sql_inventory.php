<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$output = $argv[1] ?? $root . '/docs/queue-v4/QUEUE_V4_SQL_INVENTORY.json';
$contract = json_decode(
    (string) file_get_contents($root . '/docs/queue-v4/QUEUE_V4_CANONICAL_DB_CONTRACT.json'),
    true,
    64,
    JSON_THROW_ON_ERROR,
);
$knownTables = array_fill_keys(array_keys((array) ($contract['tables'] ?? [])), true);
$sources = glob($root . '/app/QueueV4Clean/*.php') ?: [];
sort($sources, SORT_STRING);
$statements = [];
$unknownTables = [];

$decodeLiteral = static function (string $literal): string {
    if (strlen($literal) < 2) {
        return '';
    }
    $quote = $literal[0];
    $body = substr($literal, 1, -1);
    return $quote === "'"
        ? str_replace(["\\\\", "\\'"], ["\\", "'"], $body)
        : stripcslashes($body);
};

foreach ($sources as $sourcePath) {
    $source = (string) file_get_contents($sourcePath);
    $tokens = token_get_all($source);
    $method = '';
    $pendingFunction = false;
    $strings = [];
    foreach ($tokens as $index => $token) {
        if (!is_array($token)) {
            continue;
        }
        [$type, $text, $line] = $token;
        if ($type === T_FUNCTION) {
            $pendingFunction = true;
            continue;
        }
        if ($pendingFunction && $type === T_STRING) {
            $method = $text;
            $pendingFunction = false;
        }
        if ($type === T_CONSTANT_ENCAPSED_STRING) {
            $strings[] = ['sql' => $decodeLiteral($text), 'line' => $line, 'method' => $method];
            continue;
        }
        if ($type === T_ENCAPSED_AND_WHITESPACE) {
            $strings[] = ['sql' => $text, 'line' => $line, 'method' => $method];
        }
    }

    foreach ($strings as $candidate) {
        $sql = trim((string) preg_replace('/\s+/', ' ', $candidate['sql']));
        if (preg_match('/^(SELECT|INSERT|UPDATE|DELETE|SET|ALTER|CREATE|DROP)\b/i', $sql) !== 1) {
            continue;
        }
        preg_match_all('/\b(?:FROM|JOIN|INTO|TABLE)\s+`?([a-z0-9_]+)`?/i', $sql, $tableMatches);
        $tables = array_map('strtolower', $tableMatches[1] ?? []);
        if (preg_match('/^UPDATE\s+`?([a-z0-9_]+)`?/i', $sql, $updateTable) === 1) {
            $tables[] = strtolower($updateTable[1]);
        }
        $tables = array_values(array_unique($tables));
        foreach ($tables as $table) {
            if (!isset($knownTables[$table]) && $table !== 'information_schema') {
                $unknownTables[$table] = true;
            }
        }
        $columns = [];
        foreach ($tables as $table) {
            foreach ((array) (($contract['tables'][$table]['columns'] ?? [])) as $column) {
                $name = strtolower((string) ($column['COLUMN_NAME'] ?? ''));
                if ($name !== '' && preg_match('/\b' . preg_quote($name, '/') . '\b/i', $sql) === 1) {
                    $columns[] = $table . '.' . $name;
                }
            }
        }
        sort($tables, SORT_STRING);
        sort($columns, SORT_STRING);
        $statements[] = [
            'id' => hash('sha256', str_replace('\\', '/', substr($sourcePath, strlen($root) + 1)) . '|' . $candidate['line'] . '|' . $sql),
            'source' => str_replace('\\', '/', substr($sourcePath, strlen($root) + 1)),
            'method' => $candidate['method'],
            'line' => (int) $candidate['line'],
            'verb' => strtoupper((string) strtok($sql, ' ')),
            'tables' => $tables,
            'columns' => array_values(array_unique($columns)),
            'sql_sha256' => hash('sha256', $sql),
            'execution_test' => 'tests/queue_v4_clean_full_flow_2371.php',
        ];
    }
}

usort($statements, static fn (array $a, array $b): int => [$a['source'], $a['line'], $a['id']] <=> [$b['source'], $b['line'], $b['id']]);
ksort($unknownTables, SORT_STRING);
$document = [
    'schema_version' => 1,
    'scope' => 'app/QueueV4Clean/*.php',
    'statement_count' => count($statements),
    'unknown_tables' => array_keys($unknownTables),
    'canonical_contract' => 'docs/queue-v4/QUEUE_V4_CANONICAL_DB_CONTRACT.json',
    'canonical_contract_sha256' => hash_file('sha256', $root . '/docs/queue-v4/QUEUE_V4_CANONICAL_DB_CONTRACT.json'),
    'execution_authority' => 'tests/queue_v4_clean_full_flow_2371.php',
    'statements' => $statements,
];
if ($document['statement_count'] < 1 || $document['unknown_tables'] !== []) {
    throw new RuntimeException('queue_v4_sql_inventory_invalid:' . implode(',', $document['unknown_tables']));
}
$json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($output, $json, LOCK_EX) !== strlen($json)) {
    throw new RuntimeException('queue_v4_sql_inventory_write_failed');
}
fwrite(STDOUT, 'QUEUE_V4_SQL_INVENTORY=' . $output . ' statements=' . count($statements) . PHP_EOL);
