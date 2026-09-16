<?php
declare(strict_types=1);

require_once __DIR__ . '/calls_transport_wire_fixture.php';
require_once __DIR__ . '/r0_h3_bootstrap.php';

use App\Core\Crypto;
use App\QueueV4Clean\PackDiscoveryOccupancyPolicy;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\AppSettingsService;
use App\Services\Cap2DomainsWire;
use App\Services\CronDeadlineContext;

function r0h3_prepare_transport_fixture(PDO $pdo, int $maxCalls = 10): void
{
    putenv('MELI_API_BASE=https://r0-h3-wire.invalid');
    putenv('ML_WRITE_ENABLED=false');
    Cap2DomainsWire::$responses = [];
    Cap2DomainsWire::$calls = [];
    Cap2DomainsWire::$status = 0;
    Cap2DomainsWire::$raw = '';
    Cap2DomainsWire::$onWire = null;

    $access = Crypto::encrypt('r0-h3-access-token');
    $refresh = Crypto::encrypt('r0-h3-refresh-token');
    $pdo->prepare(
        'UPDATE meli_tokens
            SET access_token_encrypted=?,
                refresh_token_encrypted=?,
                expires_at=?'
    )->execute([$access, $refresh, gmdate('Y-m-d H:i:s', time() + 86400)]);

    $settings = new AppSettingsService();
    foreach ([
        'automation.max_api_calls_per_cycle' => (string) $maxCalls,
        'automation.api_calls_ceiling' => '55',
        'api.rhythm.billing_min_interval_seconds' => '1',
        'api.rhythm.pause_ms' => '0',
        'api.rhythm.minimum_interval_ms' => '0',
        'api.rhythm.burst_size' => '100',
        'api.rhythm.current_adaptive_limit' => '100',
        'api.rhythm.shared_429_jitter_seconds' => '0',
        'sales_financial.commercial_pipeline_enabled' => '1',
    ] as $key => $value) {
        $settings->set($key, $value, str_starts_with($key, 'api.rhythm.') ? 'api' : 'queue_v4_clean');
    }
    AppSettingsService::clearCache();
    $pdo->exec('DELETE FROM api_rhythm_penalties');
    $pdo->exec('DELETE FROM api_remote_permits');
    $pdo->exec(
        "INSERT INTO api_rhythm_states(scope_key,next_allowed_at,block_pause_until,calls_in_block,generation)
         VALUES('global',NULL,NULL,0,1)
         ON DUPLICATE KEY UPDATE next_allowed_at=NULL,block_pause_until=NULL,calls_in_block=0,generation=1"
    );

    if ((int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='queue_engine_control'"
    )->fetchColumn() === 1) {
        $pdo->exec("UPDATE queue_engine_control SET active_engine='v4'");
    }
}

function r0h3_clear_positive_scope(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->exec("DELETE FROM queue_v4_clean_jobs WHERE company_id=7200 OR meli_account_id IN (7201,7202)");
    $pdo->exec("DELETE FROM queue_v4_clean_attempts WHERE company_id=7200 OR meli_account_id IN (7201,7202)");
    $pdo->exec("DELETE FROM queue_v4_clean_transport_events WHERE company_id=7200 OR meli_account_id IN (7201,7202)");
    $pdo->exec("DELETE FROM order_resource_enrichment_job_orders WHERE order_resource_enrichment_job_id IN (SELECT id FROM order_resource_enrichment_jobs WHERE meli_account_id IN (7201,7202))");
    $pdo->exec("DELETE FROM order_resource_enrichment_jobs WHERE meli_account_id IN (7201,7202)");
    $pdo->exec("DELETE FROM sale_financial_reconciliation_jobs WHERE company_id=7200 OR meli_account_id IN (7201,7202)");
    $pdo->exec("DELETE FROM meli_pack_orders WHERE meli_pack_id IN (SELECT id FROM meli_packs WHERE meli_account_id IN (7201,7202))");
    $pdo->exec("DELETE FROM meli_orders WHERE meli_account_id IN (7201,7202)");
    $pdo->exec("DELETE FROM meli_packs WHERE meli_account_id IN (7201,7202)");
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

function r0h3_defer_fresh_order_discovery(PDO $pdo): void
{
    foreach ([[7100, 7101], [7200, 7201], [7200, 7202]] as [$companyId, $accountId]) {
        $pdo->prepare(
            "INSERT INTO queue_v4_clean_checkpoints
             (producer_key,company_id,meli_account_id,watermark_at,next_due_at)
             VALUES ('fresh_orders',?,?,UTC_TIMESTAMP(3),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 DAY))
             ON DUPLICATE KEY UPDATE
               watermark_at=VALUES(watermark_at),
               next_due_at=VALUES(next_due_at)"
        )->execute([$companyId, $accountId]);
    }
}

function r0h3_wait_for_global_rhythm(PDO $pdo, float $maxSeconds = 5.0): void
{
    $deadline = microtime(true) + $maxSeconds;
    do {
        $waitUs = (int) $pdo->query(
            "SELECT GREATEST(0,TIMESTAMPDIFF(
                    MICROSECOND,
                    UTC_TIMESTAMP(6),
                    GREATEST(COALESCE(next_allowed_at,'1970-01-01 00:00:00.000000'),COALESCE(block_pause_until,'1970-01-01 00:00:00.000000'))
                ))
             FROM api_rhythm_states
             WHERE scope_key='global'
             LIMIT 1"
        )->fetchColumn();
        if ($waitUs <= 0) {
            return;
        }
        usleep(min(200000, $waitUs + 20000));
    } while (microtime(true) < $deadline);

    r0h3_assert(false, 'global_rhythm_window_did_not_open', [
        'max_seconds' => $maxSeconds,
        'state' => $pdo->query(
            "SELECT next_allowed_at,block_pause_until
             FROM api_rhythm_states
             WHERE scope_key='global'
             LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC) ?: null,
    ]);
}

/** @param list<array{company_id:int,meli_account_id:int}> $scope */
function r0h3_measure_pack_occupancy(PDO $pdo, array $scope, string $phase): array
{
    r0h3_assert(!$pdo->inTransaction(), 'occupancy_observer_requires_own_transaction', ['phase' => $phase]);
    $startedAt = gmdate('c');
    $pdo->beginTransaction();
    try {
        $authorityRaw = (string) $pdo->query(
            "SELECT setting_value
               FROM app_settings
              WHERE setting_key='queue_v4.pack_discovery_h3_authority'
              LIMIT 1 FOR UPDATE"
        )->fetchColumn();
        $authority = json_decode($authorityRaw, true, 64, JSON_THROW_ON_ERROR);
        $historical = [];
        foreach ((array) ($authority['historical'] ?? []) as $entry) {
            if (is_array($entry)) {
                $historical[(int) ($entry['company_id'] ?? 0) . ':' . (int) ($entry['meli_account_id'] ?? 0) . ':' . (int) ($entry['source_id'] ?? 0)] = true;
            }
        }
        r0h3_assert(($authority['version'] ?? '') === 'r0-h3-v1' && count($historical) === 3, 'occupancy_observer_authority_validated', [
            'phase' => $phase,
            'authority_version' => $authority['version'] ?? null,
            'historical_count' => count($historical),
        ]);

        $count = (new PackDiscoveryOccupancyPolicy($pdo))->outstandingForAccounts($scope);
        $rows = $pdo->query(
            "SELECT q.id queue_id,q.company_id,q.meli_account_id,CAST(q.resource_id AS UNSIGNED) source_id,
                    q.state,q.completed_at,q.last_error_class,
                    j.status source_status,j.completed_at source_completed_at,j.failure_class,
                    j.external_resource_id
               FROM queue_v4_clean_jobs q
               JOIN order_resource_enrichment_jobs j
                 ON j.id=CAST(q.resource_id AS UNSIGNED)
                AND j.meli_account_id=q.meli_account_id
              WHERE q.job_type='domain_exact'
                AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability'))='order_enrichment_pack'
                AND (
                     (q.company_id=7100 AND q.meli_account_id=7101)
                  OR (q.company_id=7200 AND q.meli_account_id=7201)
                  OR (q.company_id=7200 AND q.meli_account_id=7202)
                )
              ORDER BY q.company_id,q.meli_account_id,source_id,queue_id
              FOR UPDATE"
        )->fetchAll(PDO::FETCH_ASSOC);
        $open = [];
        $closed = [];
        foreach ($rows as $row) {
            $key = (int) $row['company_id'] . ':' . (int) $row['meli_account_id'] . ':' . (int) $row['source_id'];
            if (isset($historical[$key])) {
                continue;
            }
            $identity = [
                'company_id' => (int) $row['company_id'],
                'meli_account_id' => (int) $row['meli_account_id'],
                'source_id' => (int) $row['source_id'],
                'queue_id' => (int) $row['queue_id'],
                'pack_id' => (string) ($row['external_resource_id'] ?? ''),
                'queue_state' => (string) ($row['state'] ?? ''),
                'source_status' => (string) ($row['source_status'] ?? ''),
                'queue_completed' => !empty($row['completed_at']),
                'source_completed' => !empty($row['source_completed_at']),
                'last_error_class' => (string) ($row['last_error_class'] ?? ''),
                'failure_class' => (string) ($row['failure_class'] ?? ''),
            ];
            $isClosed = $identity['queue_state'] === 'completed'
                && $identity['source_status'] === 'complete'
                && $identity['queue_completed']
                && $identity['source_completed']
                && $identity['last_error_class'] === ''
                && $identity['failure_class'] === '';
            if ($isClosed) {
                $closed[] = $identity + ['closure_evidence' => 'queue_completed_and_source_complete_without_errors'];
                continue;
            }
            $open[$key] = $identity;
        }
        r0h3_assert($count === count($open), 'occupancy_observer_count_matches_open_identities', [
            'phase' => $phase,
            'policy_count' => $count,
            'identity_count' => count($open),
            'open' => array_values($open),
        ]);

        $pdo->rollBack();
        return [
            'run_id' => getenv('R0_H3_LAB_MANIFEST') ?: '',
            'phase' => $phase,
            'captured_at_utc' => gmdate('c'),
            'observer_transaction_started' => $startedAt,
            'observer_transaction_ended' => gmdate('c'),
            'authority_validated' => true,
            'open_unit_identities' => array_values($open),
            'closed_unit_identities' => $closed,
            'open_unit_count' => $count,
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        r0h3_assert(false, 'occupancy_observer_failed', [
            'phase' => $phase,
            'error' => $exception::class . ':' . $exception->getMessage(),
        ]);
    }
}

function r0h3_add_local_pack(PDO $pdo, int $companyId, int $accountId, string $packExternal, string $firstOrderExternal): array
{
    $past = gmdate('Y-m-d H:i:s', time() - 7200);
    $packId = r0h3_insert($pdo, 'meli_packs', [
        'meli_account_id' => $accountId,
        'external_pack_id' => $packExternal,
        'status' => 'unknown',
        'integrity_status' => 'provisional',
        'expected_orders_count' => 0,
        'linked_orders_count' => 1,
        'expected_orders_json' => null,
        'synced_at' => $past,
    ]);
    $orderId = r0h3_insert($pdo, 'meli_orders', [
        'meli_account_id' => $accountId,
        'external_order_id' => $firstOrderExternal,
        'external_pack_id' => $packExternal,
        'status' => 'paid',
        'total_amount' => 100,
        'paid_amount' => 100,
        'currency_id' => 'COP',
        'synced_at' => $past,
    ]);
    r0h3_insert($pdo, 'meli_pack_orders', [
        'meli_pack_id' => $packId,
        'meli_order_id' => $orderId,
    ]);

    return [
        'company_id' => $companyId,
        'meli_account_id' => $accountId,
        'pack_row_id' => $packId,
        'first_order_row_id' => $orderId,
        'external_pack_id' => $packExternal,
        'first_order_external_id' => $firstOrderExternal,
    ];
}

function r0h3_add_pack_source_for_admission(PDO $pdo, int $companyId, int $accountId, string $packExternal, string $firstOrderExternal): array
{
    $pack = r0h3_add_local_pack($pdo, $companyId, $accountId, $packExternal, $firstOrderExternal);
    $sourceId = (new App\Services\OrderEnrichmentService())->enqueue(
        $accountId,
        (int) $pack['first_order_row_id'],
        'pack',
        $packExternal,
        10
    );

    return $pack + ['source_id' => $sourceId];
}

function r0h3_add_financial_waiting(PDO $pdo, int $companyId, int $accountId, string $packExternal): array
{
    $past = gmdate('Y-m-d H:i:s', time() - 3600);
    $sourceId = r0h3_insert($pdo, 'sale_financial_reconciliation_jobs', [
        'company_id' => $companyId,
        'meli_account_id' => $accountId,
        'sale_key' => 'P:' . $packExternal,
        'external_sale_id' => $packExternal,
        'input_version' => hash('sha256', 'r0-h3-financial:' . $accountId . ':' . $packExternal),
        'status' => 'pending',
        'next_run_at' => $past,
    ]);
    $queueId = r0h3_insert($pdo, 'queue_v4_clean_jobs', [
        'company_id' => $companyId,
        'meli_account_id' => $accountId,
        'job_type' => 'domain_exact',
        'resource_id' => (string) $sourceId,
        'idempotency_key' => 'domain:financial_reconciliation:' . $sourceId,
        'payload_json' => json_encode([
            'capability' => 'financial_reconciliation',
            'source_id' => $sourceId,
            'payload' => ['sale_key' => 'P:' . $packExternal],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'state' => 'waiting',
        'available_at' => $past,
        'last_error_class' => 'domain_source_waiting:financial_reconciliation:pack_incomplete',
    ]);

    return ['source_id' => $sourceId, 'queue_id' => $queueId];
}

function r0h3_wire_pack_response(string $packExternal, string $orderA, string $orderB): array
{
    return [
        'id' => (int) $packExternal,
        'status' => 'confirmed',
        'shipment' => ['id' => null],
        'buyer' => ['id' => 123],
        'orders' => [
            ['id' => (int) $orderA],
            ['id' => (int) $orderB],
        ],
    ];
}

function r0h3_wire_order_response(string $orderExternal, string $packExternal): array
{
    return [
        'id' => (int) $orderExternal,
        'pack_id' => (int) $packExternal,
        'status' => 'paid',
        'status_detail' => 'accredited',
        'date_created' => '2026-09-01T00:00:00.000-00:00',
        'date_closed' => '2026-09-01T00:01:00.000-00:00',
        'total_amount' => 100,
        'paid_amount' => 100,
        'currency_id' => 'COP',
        'shipping' => ['id' => null],
        'buyer' => ['id' => 123],
        'payments' => [],
        'order_items' => [[
            'item' => [
                'id' => 'ITEM-' . $orderExternal,
                'title' => 'R0 H3 child ' . $orderExternal,
                'seller_sku' => 'SKU-' . $orderExternal,
            ],
            'quantity' => 1,
            'unit_price' => 100,
            'sale_fee' => 0,
        ]],
    ];
}

function r0h3_worker_run(PDO $pdo, int $maxCalls, ?array $accounts = null): array
{
    QueueV4CleanCycleBudget::start($maxCalls, 'automatic', microtime(true) + 45.0);
    CronDeadlineContext::start(45, 40, 8, 3);
    try {
        return (new QueueV4CleanWorker($pdo, new QueueV4CleanRepository($pdo)))
            ->run('scheduler', $maxCalls, 45, $accounts);
    } finally {
        CronDeadlineContext::clear();
        QueueV4CleanCycleBudget::clear();
    }
}

function r0h3_fetch_pack_state(PDO $pdo, int $accountId, string $packExternal): array
{
    $stmt = $pdo->prepare(
        'SELECT id,external_pack_id,integrity_status,expected_orders_count,linked_orders_count,expected_orders_json
           FROM meli_packs
          WHERE meli_account_id=? AND external_pack_id=?
          LIMIT 1'
    );
    $stmt->execute([$accountId, $packExternal]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function r0h3_count_financial_pointer_state(PDO $pdo, int $queueId, string $state): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE id=? AND state=?');
    $stmt->execute([$queueId, $state]);
    return (int) $stmt->fetchColumn();
}

function r0h3_pack_discovery_queue_for(PDO $pdo, int $accountId, string $packExternal): ?array
{
    $stmt = $pdo->prepare(
        "SELECT q.*,j.status source_status,j.id source_id
           FROM order_resource_enrichment_jobs j
           JOIN queue_v4_clean_jobs q
             ON q.meli_account_id=j.meli_account_id
            AND q.resource_id=CAST(j.id AS CHAR)
          WHERE j.meli_account_id=?
            AND j.resource_type='pack'
            AND j.external_resource_id=?
            AND q.job_type='domain_exact'
            AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability'))='order_enrichment_pack'
          ORDER BY q.id DESC
          LIMIT 1"
    );
    $stmt->execute([$accountId, $packExternal]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function r0h3_open_pack_units_in_h3_scope(PDO $pdo): int
{
    $authority = json_decode((string) $pdo->query(
        "SELECT setting_value FROM app_settings WHERE setting_key='queue_v4.pack_discovery_h3_authority' LIMIT 1"
    )->fetchColumn(), true, 64);
    $historical = [];
    foreach ((array) ($authority['historical'] ?? []) as $entry) {
        if (is_array($entry)) {
            $historical[(int) ($entry['company_id'] ?? 0) . ':' . (int) ($entry['meli_account_id'] ?? 0) . ':' . (int) ($entry['source_id'] ?? 0)] = true;
        }
    }
    $rows = $pdo->query(
        "SELECT q.company_id,q.meli_account_id,CAST(q.resource_id AS UNSIGNED) source_id,
                q.state,q.completed_at,q.last_error_class,
                j.status source_status,j.completed_at source_completed_at,j.failure_class
         FROM queue_v4_clean_jobs q
         JOIN order_resource_enrichment_jobs j
           ON j.id=CAST(q.resource_id AS UNSIGNED)
          AND j.meli_account_id=q.meli_account_id
         WHERE q.job_type='domain_exact'
           AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability'))='order_enrichment_pack'
           AND (
                (q.company_id=7100 AND q.meli_account_id=7101)
             OR (q.company_id=7200 AND q.meli_account_id=7201)
             OR (q.company_id=7200 AND q.meli_account_id=7202)
           )
           AND NOT (
                q.state='completed'
            AND j.status='complete'
            AND q.completed_at IS NOT NULL
            AND j.completed_at IS NOT NULL
            AND COALESCE(j.failure_class,'')=''
            AND COALESCE(q.last_error_class,'')=''
           )"
    )->fetchAll(PDO::FETCH_ASSOC);
    $seen = [];
    foreach ($rows as $row) {
        $key = (int) $row['company_id'] . ':' . (int) $row['meli_account_id'] . ':' . (int) $row['source_id'];
        if (isset($historical[$key])) {
            continue;
        }
        $seen[$key] = true;
    }
    return count($seen);
}

function r0h3_queue_count(PDO $pdo, string $jobType, int $accountId, string $resourceId = ''): int
{
    $sql = 'SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type=? AND meli_account_id=?';
    $params = [$jobType, $accountId];
    if ($resourceId !== '') {
        $sql .= ' AND resource_id=?';
        $params[] = $resourceId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}
