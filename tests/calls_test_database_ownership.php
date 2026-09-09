<?php
declare(strict_types=1);

// Regression: CREATE IF NOT EXISTS must never grant ownership of another fixture's DB.
require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';

$root = rtrim(str_replace('\\', '/', (string) getenv('CALLS_VERIFY_QA_ROOT')), '/');
k1b_assert(str_starts_with($root, 'D:/Codex/') && !in_array('..', explode('/', $root), true), 'EXPLICIT_LOCAL_EVIDENCE_ROOT');
foreach (['APP_ENV'=>'test','ML_WRITE_ENABLED'=>'false','DB_HOST'=>'127.0.0.1','DB_PORT'=>'33079','DB_USER'=>'root','DB_PASS'=>''] as $key=>$value) putenv($key . '=' . $value);
$admin = new PDO('mysql:host=127.0.0.1;port=33079;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$ownerLog = $root . '/ownership-regression-owner.jsonl';
$helperLog = $root . '/k1d-test-databases.jsonl';
$owned = [];
$failures = [];
$observed = [];
$check = static function(bool $ok, string $label) use (&$failures): void {
    if (!$ok) $failures[] = $label;
    echo ($ok ? 'PASS=' : 'FAIL=') . $label . PHP_EOL;
};
$record = static function(string $event, string $name) use ($ownerLog): void {
    $line = json_encode(['event'=>$event,'db_name'=>$name,'db_host'=>'127.0.0.1','db_port'=>'33079','pid'=>getmypid(),'at'=>gmdate('c')], JSON_THROW_ON_ERROR) . "\n";
    k1b_assert(file_put_contents($ownerLog, $line, FILE_APPEND | LOCK_EX) === strlen($line), 'TEST_OWNER_RECORD_DURABLE');
};
$exists = static function(string $name) use ($admin): bool {
    $q = $admin->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
    $q->execute([$name]);
    return (int) $q->fetchColumn() === 1;
};
$records = static function(string $name) use ($helperLog): array {
    if (!is_file($helperLog)) return [];
    $rows = array_map(static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($helperLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    return array_values(array_filter($rows, static fn(array $row): bool => $row['db_name'] === $name));
};
$fresh = null;
try {
    $preexisting = 'erp_meli_k1d_test_ownership_existing_' . bin2hex(random_bytes(7));
    $record('create_intent', $preexisting);
    $admin->exec('CREATE DATABASE `' . $preexisting . '` CHARACTER SET utf8mb4');
    $owned[] = $preexisting; // Only successful CREATE authorizes this test's cleanup.
    $record('created', $preexisting);
    $admin->exec('CREATE TABLE `' . $preexisting . '`.owner_sentinel (id INT PRIMARY KEY, marker INT NOT NULL)');
    $admin->exec('INSERT INTO `' . $preexisting . '`.owner_sentinel VALUES (1,73149)');
    putenv('DB_NAME=' . $preexisting);
    $adopted = null;
    $rejected = false;
    try { $adopted = K1dSafeTestDatabase::createFromEnvironment(); }
    catch (PDOException $error) { $rejected = (int) ($error->errorInfo[1] ?? 0) === 1007; }
    if ($adopted !== null) $adopted->cleanup(); // RED may delete only our newly-created sacrificial fixture.
    $survives = $exists($preexisting);
    $marker = $survives ? $admin->query('SELECT marker FROM `' . $preexisting . '`.owner_sentinel WHERE id=1')->fetchColumn() : null;
    $check($rejected, 'PREEXISTING_DATABASE_IS_REJECTED_NOT_ADOPTED');
    $check($survives && (int) $marker === 73149, 'PREEXISTING_OWNER_SENTINEL_NEVER_DELETED');
    $observed['preexisting'] = ['db_name'=>$preexisting,'rejected'=>$rejected,'survives'=>$survives,'marker'=>$marker,'helper_records'=>$records($preexisting)];
    $check(array_column($records($preexisting), 'event') === ['create_intent'], 'FAILED_CREATE_HAS_INTENT_ONLY_NOT_OWNERSHIP');

    $name = 'erp_meli_k1d_test_ownership_fresh_' . bin2hex(random_bytes(7));
    putenv('DB_NAME=' . $name);
    $fresh = K1dSafeTestDatabase::createFromEnvironment();
    $beforePdo = $records($name);
    $check(array_column($beforePdo, 'event') === ['create_intent','created'], 'CREATION_RECORDED_BEFORE_FIRST_CONSUMER_PDO_OR_MIGRATION');
    $check(count($beforePdo) === 2 && $beforePdo[0]['attempt_id'] === $beforePdo[1]['attempt_id'] && $beforePdo[1]['pid'] === getmypid(), 'OWNERSHIP_RECORDS_CORRELATE_LOCAL_PROCESS_AND_ATTEMPT');
    $pdo = $fresh->pdo();
    $pdo->exec('CREATE TABLE consumer_sentinel (id INT PRIMARY KEY)');
    $pdo->exec('INSERT INTO consumer_sentinel VALUES (19)');
    $borrowed = K1dSafeTestDatabase::connectExistingFromEnvironment();
    $borrowed->cleanup();
    $check($exists($name) && (int) $pdo->query('SELECT id FROM consumer_sentinel')->fetchColumn() === 19, 'CONNECT_EXISTING_CLEANUP_IS_NOOP');
    $check($records($name) === $beforePdo, 'BORROWER_NEVER_RECORDS_OWNERSHIP_OR_DROP');
    $fresh->cleanup();
    $fresh->cleanup();
    $check(!$exists($name), 'OWNED_FRESH_DATABASE_DROPPED');
    $after = $records($name);
    $check(array_column($after, 'event') === ['create_intent','created','dropped'], 'DROP_DURABLE_AND_CLEANUP_IDEMPOTENT');
    $observed['fresh'] = ['db_name'=>$name,'records_before_consumer_pdo'=>$beforePdo,'records_after_cleanup'=>$after];
} finally {
    if ($fresh !== null) $fresh->cleanup();
    foreach ($owned as $name) {
        if ($exists($name)) $admin->exec('DROP DATABASE `' . $name . '`');
        $record('dropped', $name);
    }
    $observed['failures'] = $failures;
    $observed['test_owned_databases_remaining'] = array_values(array_filter($owned, $exists));
    file_put_contents($root . '/database-ownership-result.json', json_encode($observed, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}
k1b_assert($failures === [], 'DATABASE_OWNERSHIP_INVARIANTS:' . implode(',', $failures));
echo "TEST_DATABASE_OWNERSHIP_OK\n";
