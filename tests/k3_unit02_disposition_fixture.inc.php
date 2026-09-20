<?php

declare(strict_types=1);

require_once __DIR__ . '/r0_h3_transport_fixture.inc.php';

/** @return list<array{company_id:int,meli_account_id:int}> */
function k3u2_scope(): array
{
    return [
        ['company_id' => 7100, 'meli_account_id' => 7101],
        ['company_id' => 7200, 'meli_account_id' => 7201],
        ['company_id' => 7200, 'meli_account_id' => 7202],
    ];
}

/** @return array<string,mixed> */
function k3u2_create_unit(PDO $pdo, string $label, int $accountId, bool $businessClosed): array
{
    $companyId = 7200;
    $past = gmdate('Y-m-d H:i:s', time() - 3600);
    $packExternal = $label === 'UNIT-01' ? '830001' : '830002';
    $expected = [$label === 'UNIT-01' ? '930001' : '930002'];
    $packId = r0h3_insert($pdo, 'meli_packs', [
        'meli_account_id' => $accountId,
        'external_pack_id' => $packExternal,
        'status' => $businessClosed ? 'confirmed' : 'unknown',
        'integrity_status' => $businessClosed ? 'complete' : 'provisional',
        'expected_orders_count' => 1,
        'linked_orders_count' => 1,
        'expected_orders_json' => json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'orders_fingerprint' => hash('sha256', implode('|', $expected)),
        'verified_at' => $businessClosed ? $past : null,
        'synced_at' => $past,
    ]);
    $orderId = r0h3_insert($pdo, 'meli_orders', [
        'meli_account_id' => $accountId,
        'external_order_id' => $expected[0],
        'external_pack_id' => $packExternal,
        'status' => 'paid',
        'synced_at' => $past,
    ]);
    r0h3_insert($pdo, 'meli_pack_orders', [
        'meli_pack_id' => $packId,
        'meli_order_id' => $orderId,
    ]);
    $sourceId = r0h3_insert($pdo, 'order_resource_enrichment_jobs', [
        'meli_account_id' => $accountId,
        'meli_order_id' => $orderId,
        'resource_type' => 'pack',
        'external_resource_id' => $packExternal,
        'status' => $businessClosed ? 'complete' : 'error',
        'priority' => 10,
        'attempts' => 2,
        'next_run_at' => $past,
        'lock_token' => null,
        'locked_at' => null,
        'lease_generation' => $businessClosed ? 2 : 1,
        'failure_class' => $businessClosed ? null : 'remote_result_uncertain_safe_get',
        'reached_remote' => 1,
        'completed_at' => $businessClosed ? $past : null,
    ]);
    $queueId = r0h3_insert($pdo, 'queue_v4_clean_jobs', [
        'company_id' => $companyId,
        'meli_account_id' => $accountId,
        'job_type' => 'domain_exact',
        'resource_id' => (string) $sourceId,
        'idempotency_key' => 'domain:order_enrichment_pack:' . strtolower($label),
        'payload_json' => json_encode([
            'capability' => 'order_enrichment_pack',
            'source_id' => $sourceId,
            'payload' => ['pack_id' => $packExternal],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'state' => $businessClosed ? 'completed' : 'review',
        'available_at' => $past,
        'attempt_count' => 2,
        'lease_owner' => null,
        'lease_expires_at' => null,
        'lease_generation' => $businessClosed ? 2 : 1,
        'last_error_class' => $businessClosed ? null : 'remote_result_uncertain_safe_get',
        'completed_at' => $businessClosed ? $past : null,
    ]);
    $historicalAttemptId = r0h3_insert($pdo, 'queue_v4_clean_attempts', [
        'job_id' => $queueId,
        'run_id' => $label === 'UNIT-01' ? 991001 : 992001,
        'company_id' => $companyId,
        'meli_account_id' => $accountId,
        'lease_owner' => 'k3-unit02-historical',
        'lease_generation' => 1,
        'outcome' => 'review',
        'error_class' => 'remote_result_uncertain_safe_get',
        'dispatch_state' => 'PHYSICAL_STARTED',
        'transport_method' => 'GET',
        'endpoint_key' => 'pack_exact',
        'physical_http_calls' => 1,
        'physical_started_at' => $past,
        'started_at' => $past,
        'finished_at' => $past,
    ]);
    $historicalEventId = r0h3_insert($pdo, 'queue_v4_clean_transport_events', [
        'company_id' => $companyId,
        'meli_account_id' => $accountId,
        'source_kind' => 'queue',
        'work_id' => $queueId,
        'attempt_id' => $historicalAttemptId,
        'lease_generation' => 1,
        'request_id' => 'k3-unit02-historical-' . $queueId,
        'method' => 'GET',
        'endpoint_key' => 'pack_exact',
        'dispatch_state' => 'PHYSICAL_STARTED',
        'physical_started_at' => $past,
    ]);
    $closureAttemptId = null;
    $closureEventId = null;
    if ($businessClosed) {
        $closureAttemptId = r0h3_insert($pdo, 'queue_v4_clean_attempts', [
            'job_id' => $queueId,
            'run_id' => 992002,
            'company_id' => $companyId,
            'meli_account_id' => $accountId,
            'lease_owner' => 'k3-unit02-closure',
            'lease_generation' => 2,
            'outcome' => 'completed',
            'error_class' => null,
            'dispatch_state' => 'RESPONSE_KNOWN',
            'transport_method' => 'GET',
            'endpoint_key' => 'pack_exact',
            'physical_http_calls' => 1,
            'http_status' => 200,
            'physical_started_at' => $past,
            'response_known_at' => $past,
            'started_at' => $past,
            'finished_at' => $past,
            'source_closed_at' => $past,
        ]);
        $closureEventId = r0h3_insert($pdo, 'queue_v4_clean_transport_events', [
            'company_id' => $companyId,
            'meli_account_id' => $accountId,
            'source_kind' => 'queue',
            'work_id' => $queueId,
            'attempt_id' => $closureAttemptId,
            'lease_generation' => 2,
            'request_id' => 'k3-unit02-closure-' . $queueId,
            'method' => 'GET',
            'endpoint_key' => 'pack_exact',
            'dispatch_state' => 'RESPONSE_KNOWN',
            'physical_started_at' => $past,
            'response_known_at' => $past,
            'http_status' => 200,
        ]);
    }

    return [
        'label' => $label,
        'company_id' => $companyId,
        'meli_account_id' => $accountId,
        'source_id' => $sourceId,
        'queue_id' => $queueId,
        'pack_row_id' => $packId,
        'external_pack_id' => $packExternal,
        'historical_attempt_id' => $historicalAttemptId,
        'historical_transport_event_id' => $historicalEventId,
        'historical_generation' => 1,
        'closure_attempt_id' => $closureAttemptId,
        'closure_transport_event_id' => $closureEventId,
        'closure_generation' => $businessClosed ? 2 : null,
    ];
}

/** @return array{unit01:array<string,mixed>,unit02:array<string,mixed>,healthy:list<array<string,mixed>>} */
function k3u2_seed(PDO $pdo): array
{
    $base = r0h3_seed($pdo);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['manual_campaign_reservations','manual_campaign_items','manual_campaign_operations','manual_campaigns'] as $table) {
        $pdo->exec('DELETE FROM ' . $table);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->exec('DELETE FROM audit_logs');
    $pdo->exec("INSERT INTO users(id,name,email,password_hash,role,status,is_temporary)
                VALUES(5001,'K3 synthetic owner','k3-owner@example.invalid','unused','admin',1,0)
                ON DUPLICATE KEY UPDATE name=VALUES(name),status=1,is_temporary=0");
    $pdo->exec("DELETE FROM app_settings WHERE setting_key='queue_v4.pack_discovery_unit02_disposition'");
    return [
        'unit01' => k3u2_create_unit($pdo, 'UNIT-01', 7201, false),
        'unit02' => k3u2_create_unit($pdo, 'UNIT-02', 7202, true),
        'healthy' => $base['healthy'],
    ];
}

/** @param array<string,mixed> $unit */
function k3u2_add_active_reservation(PDO $pdo, array $unit): void
{
    $campaignId = r0h3_insert($pdo, 'manual_campaigns', [
        'campaign_token' => str_repeat('a', 40),
        'created_by_user_id' => 5001,
        'company_scope_key' => (int) $unit['company_id'],
        'scope_key' => 'company',
        'preset' => 'safe',
        'status' => 'active',
        'configuration_json' => '{}',
    ]);
    $operationId = r0h3_insert($pdo, 'manual_campaign_operations', [
        'manual_campaign_id' => $campaignId,
        'queue_key' => 'order_enrichment',
        'operation_key' => 'pack_exact',
        'meli_account_id' => (int) $unit['meli_account_id'],
        'company_id' => (int) $unit['company_id'],
    ]);
    $itemId = r0h3_insert($pdo, 'manual_campaign_items', [
        'manual_campaign_id' => $campaignId,
        'operation_id' => $operationId,
        'queue_key' => 'order_enrichment',
        'operation_key' => 'pack_exact',
        'source_id' => (string) $unit['source_id'],
        'meli_account_id' => (int) $unit['meli_account_id'],
        'company_id' => (int) $unit['company_id'],
        'human_label' => 'K3 UNIT-02 synthetic reservation',
        'status' => 'pending',
        'position_no' => 1,
    ]);
    r0h3_insert($pdo, 'manual_campaign_reservations', [
        'manual_campaign_id' => $campaignId,
        'manual_campaign_item_id' => $itemId,
        'queue_key' => 'order_enrichment',
        'source_id' => (string) $unit['source_id'],
        'company_id' => (int) $unit['company_id'],
        'meli_account_id' => (int) $unit['meli_account_id'],
        'lease_generation' => (int) $unit['closure_generation'],
        'status' => 'active',
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 900),
    ]);
}

/** @param array<string,mixed> $unit @return array<string,mixed> */
function k3u2_authorized_evidence(PDO $pdo, array $unit): array
{
    return [
        'identity' => [
            'company_id' => (int) $unit['company_id'],
            'meli_account_id' => (int) $unit['meli_account_id'],
            'source_id' => (int) $unit['source_id'],
            'queue_id' => (int) $unit['queue_id'],
            'pack_row_id' => (int) $unit['pack_row_id'],
            'external_pack_id' => (string) $unit['external_pack_id'],
            'historical_attempt_id' => (int) $unit['historical_attempt_id'],
            'historical_transport_event_id' => (int) $unit['historical_transport_event_id'],
            'historical_generation' => (int) $unit['historical_generation'],
            'closure_attempt_id' => (int) $unit['closure_attempt_id'],
            'closure_transport_event_id' => (int) $unit['closure_transport_event_id'],
            'closure_generation' => (int) $unit['closure_generation'],
        ],
        'hashes' => [
            'source_sha256' => r0h3_source_hash($pdo, (int) $unit['source_id']),
            'queue_sha256' => r0h3_queue_hash($pdo, (int) $unit['queue_id']),
            'attempts_sha256' => r0h3_attempts_hash($pdo, (int) $unit['company_id'], (int) $unit['meli_account_id'], (int) $unit['queue_id']),
            'transport_events_sha256' => r0h3_transport_hash($pdo, (int) $unit['company_id'], (int) $unit['meli_account_id'], (int) $unit['queue_id']),
            'evidence_zip_sha256' => str_repeat('e', 64),
        ],
    ];
}

/** @param array<string,mixed> $unit */
function k3u2_unit_evidence_hash(PDO $pdo, array $unit): string
{
    return hash('sha256', json_encode([
        r0h3_source_hash($pdo, (int) $unit['source_id']),
        r0h3_queue_hash($pdo, (int) $unit['queue_id']),
        r0h3_attempts_hash($pdo, (int) $unit['company_id'], (int) $unit['meli_account_id'], (int) $unit['queue_id']),
        r0h3_transport_hash($pdo, (int) $unit['company_id'], (int) $unit['meli_account_id'], (int) $unit['queue_id']),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/** @param list<array<string,mixed>> $historical */
function k3u2_h3_hash(PDO $pdo, array $historical): string
{
    $rows = [];
    foreach ($historical as $entry) {
        $rows[] = [
            r0h3_source_hash($pdo, (int) $entry['source_id']),
            r0h3_queue_hash($pdo, (int) $entry['queue_id']),
            r0h3_attempts_hash($pdo, (int) $entry['company_id'], (int) $entry['meli_account_id'], (int) $entry['queue_id']),
            r0h3_transport_hash($pdo, (int) $entry['company_id'], (int) $entry['meli_account_id'], (int) $entry['queue_id']),
        ];
    }

    return hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}
