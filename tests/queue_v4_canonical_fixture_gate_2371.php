<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$positiveTests = [
    'tests/queue_v4_clean_greenfield_2370.php',
    'tests/queue_v4_clean_full_flow_2371.php',
    'tests/queue_v4_clean_http_2371.php',
    'tests/materialize_queue_v4_canonical_2371.php',
];
$forbiddenCoreDdl = [
    'schema_migrations',
    'app_settings',
    'meli_accounts',
    'meli_tokens',
];
$checks = 0;
foreach ($positiveTests as $relative) {
    $source = (string) file_get_contents($root . '/' . $relative);
    if ($source === '') {
        throw new RuntimeException('canonical_fixture_source_missing:' . $relative);
    }
    foreach ($forbiddenCoreDdl as $table) {
        $checks++;
        if (preg_match('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?' . preg_quote($table, '/') . '`?/i', $source) === 1) {
            throw new RuntimeException('handwritten_core_schema_refused:' . $relative . ':' . $table);
        }
    }
}

$materializer = (string) file_get_contents($root . '/tests/materialize_queue_v4_canonical_2371.php');
foreach ([
    "new Migrator(\$pdo, \$migrationPath)",
    "WHERE version=?",
    "['version', 'applied_at']",
] as $needle) {
    $checks++;
    if (!str_contains($materializer, $needle)) {
        throw new RuntimeException('canonical_materializer_contract_missing:' . $needle);
    }
}

fwrite(STDOUT, 'Queue V4 canonical fixture gate: PASS checks=' . $checks . PHP_EOL);

