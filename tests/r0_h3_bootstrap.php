<?php
declare(strict_types=1);

putenv('APP_ENV=test');
putenv('APP_KEY=' . str_repeat('a', 64));
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=' . ((string) getenv('R0_H3_DB_PORT') ?: '33191'));
putenv('DB_NAME=' . ((string) getenv('R0_H3_DB_NAME') ?: 'erp_meli_r0_h3'));
putenv('DB_USER=root');
putenv('DB_PASS=');
putenv('ML_WRITE_ENABLED=false');

require __DIR__ . '/k1b_bootstrap.php';

use App\Core\Database;

$repoRoot = dirname(__DIR__);
$pdo = Database::connection();

function r0h3_assert(bool $condition, string $message, array $context = []): void
{
    if (!$condition) {
        fwrite(STDERR, json_encode(['assertion' => $message, 'context' => $context], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(255);
    }
}

function r0h3_insert(PDO $pdo, string $table, array $row): int
{
    $columns = array_keys($row);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($row));
    return (int) $pdo->lastInsertId();
}

function r0h3_source_hash(PDO $pdo, int $sourceId): string
{
    $statement = $pdo->prepare('SELECT * FROM order_resource_enrichment_jobs WHERE id=?');
    $statement->execute([$sourceId]);
    return hash('sha256', json_encode($statement->fetch(PDO::FETCH_ASSOC) ?: [], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function r0h3_queue_hash(PDO $pdo, int $queueId): string
{
    $statement = $pdo->prepare('SELECT * FROM queue_v4_clean_jobs WHERE id=?');
    $statement->execute([$queueId]);
    return hash('sha256', json_encode($statement->fetch(PDO::FETCH_ASSOC) ?: [], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function r0h3_transport_hash(PDO $pdo, int $companyId, int $accountId, int $queueId): string
{
    $statement = $pdo->prepare(
        "SELECT * FROM queue_v4_clean_transport_events
         WHERE company_id=? AND meli_account_id=? AND source_kind='queue' AND work_id=?
         ORDER BY id"
    );
    $statement->execute([$companyId, $accountId, $queueId]);
    return hash('sha256', json_encode($statement->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function r0h3_attempts_hash(PDO $pdo, int $companyId, int $accountId, int $queueId): string
{
    $statement = $pdo->prepare(
        "SELECT * FROM queue_v4_clean_attempts
         WHERE company_id=? AND meli_account_id=? AND job_id=?
         ORDER BY id"
    );
    $statement->execute([$companyId, $accountId, $queueId]);
    return hash('sha256', json_encode($statement->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function r0h3_open_new_pack_units(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(DISTINCT q.company_id, q.meli_account_id, q.resource_id)
         FROM queue_v4_clean_jobs q
         JOIN order_resource_enrichment_jobs j
           ON j.id=CAST(q.resource_id AS UNSIGNED) AND j.meli_account_id=q.meli_account_id
         WHERE q.job_type='domain_exact'
           AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability'))='order_enrichment_pack'
           AND q.company_id=7200
           AND NOT (q.state='completed' AND j.status='complete' AND q.completed_at IS NOT NULL AND j.completed_at IS NOT NULL AND COALESCE(j.failure_class,'')='')"
    )->fetchColumn();
}

function r0h3_seed(PDO $pdo): array
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['queue_v4_clean_transport_events','queue_v4_clean_attempts','queue_v4_clean_jobs','order_resource_enrichment_job_orders','order_resource_enrichment_jobs','meli_pack_orders','meli_orders','meli_packs','queue_v4_clean_readiness_accounts','queue_v4_clean_readiness_runs','meli_tokens','meli_accounts','companies','app_settings'] as $table) {
        $pdo->exec('DELETE FROM ' . $table);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec("INSERT INTO queue_v4_clean_control(control_key,engine_state,readiness_state,scheduler_enabled) VALUES('primary','ACTIVE','CERTIFIED',1) ON DUPLICATE KEY UPDATE engine_state='ACTIVE',readiness_state='CERTIFIED',scheduler_enabled=1");

    foreach ([7100, 7200] as $companyId) {
        r0h3_insert($pdo, 'companies', ['id' => $companyId, 'name' => 'R0 H3 ' . $companyId, 'status' => 1]);
    }
    foreach ([[7100,7101], [7200,7201], [7200,7202]] as [$companyId, $accountId]) {
        r0h3_insert($pdo, 'meli_accounts', ['id' => $accountId, 'company_id' => $companyId, 'account_name' => 'R0 H3', 'meli_user_id' => $accountId + 100000, 'status' => 'conectado']);
        r0h3_insert($pdo, 'meli_tokens', ['meli_account_id' => $accountId, 'access_token_encrypted' => 'test', 'refresh_token_encrypted' => 'test', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400)]);
    }
    $runId = r0h3_insert($pdo, 'queue_v4_clean_readiness_runs', ['state' => 'CERTIFIED', 'passed_accounts' => 3, 'started_by' => 1]);
    foreach ([[7100,7101], [7200,7201], [7200,7202]] as [$companyId, $accountId]) {
        r0h3_insert($pdo, 'queue_v4_clean_readiness_accounts', ['readiness_run_id' => $runId, 'company_id' => $companyId, 'meli_account_id' => $accountId, 'outcome' => 'PASS']);
    }

    $past = gmdate('Y-m-d H:i:s', time() - 3600);
    $historical = [];
    for ($i = 1; $i <= 3; $i++) {
        $packExternal = (string) (810000 + $i);
        $packId = r0h3_insert($pdo, 'meli_packs', ['meli_account_id' => 7101, 'external_pack_id' => $packExternal, 'status' => 'unknown', 'integrity_status' => 'provisional', 'synced_at' => $past]);
        $orderId = r0h3_insert($pdo, 'meli_orders', ['meli_account_id' => 7101, 'external_order_id' => (string) (910000 + $i), 'external_pack_id' => $packExternal, 'status' => 'paid', 'synced_at' => $past]);
        r0h3_insert($pdo, 'meli_pack_orders', ['meli_pack_id' => $packId, 'meli_order_id' => $orderId]);
        $sourceId = r0h3_insert($pdo, 'order_resource_enrichment_jobs', [
            'meli_account_id' => 7101,
            'meli_order_id' => $orderId,
            'resource_type' => 'pack',
            'external_resource_id' => $packExternal,
            'status' => $i === 1 ? 'running' : 'retry',
            'priority' => 10,
            'attempts' => 1,
            'next_run_at' => $past,
            'lock_token' => $i === 1 ? str_repeat((string) $i, 32) : null,
            'locked_at' => $i === 1 ? $past : null,
            'lease_generation' => 1,
            'failure_class' => $i === 1 ? null : 'remote_result_uncertain_safe_get',
            'reached_remote' => $i === 1 ? null : 1,
        ]);
        $queueId = r0h3_insert($pdo, 'queue_v4_clean_jobs', [
            'company_id' => 7100,
            'meli_account_id' => 7101,
            'job_type' => 'domain_exact',
            'resource_id' => (string) $sourceId,
            'idempotency_key' => 'domain:order_enrichment_pack:h3-' . $i,
            'payload_json' => json_encode(['capability' => 'order_enrichment_pack', 'source_id' => $sourceId, 'payload' => ['pack_id' => $packExternal]], JSON_UNESCAPED_SLASHES),
            'state' => 'waiting',
            'available_at' => $past,
            'lease_generation' => 1,
            'last_error_class' => 'domain_source_waiting:order_enrichment_pack',
        ]);
        $historical[] = [
            'company_id' => 7100,
            'meli_account_id' => 7101,
            'source_id' => $sourceId,
            'queue_id' => $queueId,
            'source_sha256' => r0h3_source_hash($pdo, $sourceId),
            'queue_sha256' => r0h3_queue_hash($pdo, $queueId),
            'attempts_sha256' => r0h3_attempts_hash($pdo, 7100, 7101, $queueId),
            'transport_events_sha256' => r0h3_transport_hash($pdo, 7100, 7101, $queueId),
        ];
    }
    $authority = [
        'version' => 'r0-h3-v1',
        'scope' => [
            ['company_id' => 7100, 'meli_account_id' => 7101],
            ['company_id' => 7200, 'meli_account_id' => 7201],
            ['company_id' => 7200, 'meli_account_id' => 7202],
        ],
        'historical' => $historical,
    ];
    $pdo->prepare("INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group) VALUES('queue_v4.pack_discovery_h3_authority',?,0,'queue_v4_clean')")->execute([
        json_encode($authority, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);

    $healthy = [];
    for ($i = 1; $i <= 12; $i++) {
        $accountId = $i % 2 === 0 ? 7202 : 7201;
        $packExternal = (string) (820000 + $i);
        $packId = r0h3_insert($pdo, 'meli_packs', ['meli_account_id' => $accountId, 'external_pack_id' => $packExternal, 'status' => 'unknown', 'integrity_status' => 'provisional', 'synced_at' => $past]);
        $orderId = r0h3_insert($pdo, 'meli_orders', ['meli_account_id' => $accountId, 'external_order_id' => (string) (920000 + $i), 'external_pack_id' => $packExternal, 'status' => 'paid', 'synced_at' => $past]);
        r0h3_insert($pdo, 'meli_pack_orders', ['meli_pack_id' => $packId, 'meli_order_id' => $orderId]);
        $healthy[] = ['company_id' => 7200, 'meli_account_id' => $accountId, 'order_id' => $orderId, 'pack_id' => $packId, 'external_pack_id' => $packExternal];
    }

    return ['historical' => $historical, 'healthy' => $healthy];
}
