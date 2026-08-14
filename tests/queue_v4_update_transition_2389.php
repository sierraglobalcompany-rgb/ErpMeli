<?php

declare(strict_types=1);

$dsn = (string) (getenv('QUEUE_V4_CLEAN_TEST_DSN') ?: '');
if ($dsn === '') {
    fwrite(STDERR, "QUEUE_V4_CLEAN_TEST_DSN is required\n");
    exit(2);
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Services\Migrator;

$pdo = new PDO(
    $dsn,
    (string) (getenv('QUEUE_V4_CLEAN_TEST_USER') ?: 'root'),
    (string) (getenv('QUEUE_V4_CLEAN_TEST_PASS') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);
$pdo->exec("SET time_zone='+00:00'");

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$migrator = new Migrator($pdo, $root . '/database/migrations');
$assert((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 297, 'preimage_schema_not_297');
$assert($migrator->pendingCount() === 1, 'migration_298_not_only_pending_change');

$tenant = $pdo->query(
    'SELECT company_id,id meli_account_id FROM meli_accounts ORDER BY id LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
$assert(is_array($tenant) && (int) ($tenant['company_id'] ?? 0) > 0, 'tenant_fixture_missing');
$requestId = 'pre298-' . bin2hex(random_bytes(8));
$pdo->prepare(
    "INSERT INTO queue_v4_clean_transport_events
     (company_id,meli_account_id,source_kind,work_id,attempt_id,lease_generation,
      request_id,method,endpoint_key,dispatch_state,physical_started_at,response_known_at,http_status)
     VALUES (?,?,'queue',999999,NULL,1,?,'GET','order_exact','RESPONSE_KNOWN',
             UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),200)"
)->execute([(int) $tenant['company_id'], (int) $tenant['meli_account_id'], $requestId]);
$eventId = (int) $pdo->lastInsertId();

$tables = [
    'companies', 'meli_accounts', 'meli_orders', 'sync_sales_repair_jobs',
    'sync_sales_repair_job_items', 'inventory_movements',
];
$counts = static function () use ($pdo, $tables): array {
    $rows = [];
    foreach ($tables as $table) {
        $rows[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }
    return $rows;
};
$before = $counts();
$applied = $migrator->run(1);
$appliedOnly = array_values(array_filter(
    $applied,
    static fn(array $row): bool => ($row['status'] ?? '') === 'applied',
));
$assert(count($appliedOnly) === 1
    && (string) ($appliedOnly[0]['version'] ?? '') === '298_queue_v4_sales_repair_transport_authority_2_38_9.sql',
    'migration_298_not_applied_exactly_once:' . json_encode($applied));
$assert((int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 298
    && $migrator->pendingCount() === 0, 'postimage_schema_not_298');
$assert($counts() === $before, 'migration_298_changed_business_data');

$event = $pdo->query(
    'SELECT source_kind,work_id,lease_generation,request_id,dispatch_state,http_status
     FROM queue_v4_clean_transport_events WHERE id=' . $eventId
)->fetch(PDO::FETCH_ASSOC);
$assert($event === [
    'source_kind' => 'queue',
    'work_id' => 999999,
    'lease_generation' => 1,
    'request_id' => $requestId,
    'dispatch_state' => 'RESPONSE_KNOWN',
    'http_status' => 200,
], 'preexisting_transport_journal_changed:' . json_encode($event));
$enum = (string) $pdo->query(
    "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_v4_clean_transport_events'
       AND COLUMN_NAME='source_kind'"
)->fetchColumn();
$assert($enum === "enum('queue','oauth','sales_audit','sales_repair')", 'postimage_enum_not_exact:' . $enum);
$secondRun = (new Migrator($pdo, $root . '/database/migrations'))->run();
$assert(array_filter(
    $secondRun,
    static fn(array $row): bool => ($row['status'] ?? '') === 'applied',
) === [], 'migration_298_not_idempotent');

echo 'QUEUE_V4_UPDATE_TRANSITION_2389=PASS checks=' . $checks
    . ' schema=298 pending=0 migrations=1 data_preserved=yes meli_http=0' . PHP_EOL;
