<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\CronV3;
use App\Services\WorkEnvelope;

$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
if ($dsn === '') {
    echo "SKIP cron_v3_shadow_60_mysql_2291: ERP_MIGRATOR_TEST_DSN is required.\n";
    exit(0);
}

$user = (string) getenv('ERP_MIGRATOR_TEST_USER');
$pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$server = new PDO($dsn, $user, $pass, $options);
$database = 'erp_cron_v3_shadow_2291_' . bin2hex(random_bytes(5));

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$apply = static function (PDO $pdo, string $path): void {
    $sql = (string) file_get_contents($path);
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }
};
$fingerprint = static function (PDO $pdo): string {
    $payload = [
        'sentinel' => $pdo->query('SELECT * FROM shadow_source_sentinel ORDER BY id')->fetchAll(),
        'work' => $pdo->query(
            'SELECT id,company_id,meli_account_id,work_type,lane,status,attempt_count,
                    owner_token,lease_generation,lease_until,available_at
             FROM cron_v3_work ORDER BY id'
        )->fetchAll(),
        'attempts' => (int) $pdo->query('SELECT COUNT(*) FROM cron_v3_attempts')->fetchColumn(),
        'ownership' => $pdo->query(
            'SELECT queue_key,lane,owner_engine,enabled FROM cron_v3_queue_ownership ORDER BY queue_key'
        )->fetchAll(),
    ];
    return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
};

try {
    $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, $options);
    $pdo->exec('CREATE TABLE app_settings (
        setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NULL,
        is_encrypted TINYINT(1) NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE meli_accounts (
        id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE shadow_source_sentinel (
        id BIGINT UNSIGNED PRIMARY KEY,payload_json JSON NOT NULL,updated_at DATETIME(3) NOT NULL
    ) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO meli_accounts (id,company_id) VALUES (1,1),(2,1)");
    $pdo->exec("INSERT INTO shadow_source_sentinel VALUES (1,'{\"source\":\"immutable\"}',UTC_TIMESTAMP(3))");

    $root = dirname(__DIR__);
    $apply($pdo, $root . '/database/migrations/241_cron_v3_engine_2_29_0.sql');
    $rateMigrations = glob($root . '/database/migrations/244_*.sql') ?: [];
    $assert(count($rateMigrations) === 1, 'Exactly one migration 244 is required.');
    $apply($pdo, $rateMigrations[0]);

    CronV3::resetForTests();
    $kernel = CronV3::boot($pdo);
    $pdo->exec("UPDATE cron_v3_queue_ownership SET owner_engine='v3',enabled=1
                WHERE queue_key IN ('financial_recalc','order_exact')");
    for ($index = 1; $index <= 25; $index++) {
        CronV3::enqueue(WorkEnvelope::create(1, ($index % 2) + 1, 'financial_recalc', 'local', 'sale:' . $index, 'v1'));
        CronV3::enqueue(WorkEnvelope::create(1, ($index % 2) + 1, 'order_exact', 'remote', 'order:' . $index, 'v1'));
    }

    $before = $fingerprint($pdo);
    for ($cycle = 1; $cycle <= 60; $cycle++) {
        $local = $kernel->runner(10)->run('local', 45, 50, true);
        $remote = $kernel->runner(10)->run('remote', 35, 12, true);
        $assert(($local['http_calls'] ?? -1) === 0 && ($remote['http_calls'] ?? -1) === 0,
            'Shadow cycle performed HTTP.');
        $assert(($local['source_mutations'] ?? -1) === 0 && ($remote['source_mutations'] ?? -1) === 0,
            'Shadow cycle reported a source mutation.');
    }
    $after = $fingerprint($pdo);
    $assert(hash_equals($before, $after), 'Sixty shadow cycles changed work or source state.');

    $snapshots = $pdo->query(
        "SELECT snapshot_key,generation,payload_json FROM cron_v3_snapshots
         WHERE snapshot_key IN ('run:local','run:remote') ORDER BY snapshot_key"
    )->fetchAll();
    $assert(count($snapshots) === 2, 'Shadow did not persist both lane snapshots.');
    foreach ($snapshots as $snapshot) {
        $assert((int) $snapshot['generation'] === 60, 'Shadow snapshot generation is not 60.');
        $payload = json_decode((string) $snapshot['payload_json'], true, 16, JSON_THROW_ON_ERROR);
        $assert(($payload['mode'] ?? '') === 'shadow' && ($payload['http_calls'] ?? -1) === 0,
            'Shadow snapshot does not prove zero HTTP.');
    }

    echo "PASS cron_v3_shadow_60_mysql_2291 cycles=60x2 http=0 mutations=0\n";
} finally {
    CronV3::resetForTests();
    $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
