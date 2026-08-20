<?php

declare(strict_types=1);

use App\Core\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * ERP MELI 2.39.8 — H4 historical pack integrity backfill tooling.
 *
 * Local-only, no Mercado Libre HTTP. Production execution requires a separate
 * explicit operator authorization token and an exact plan SHA.
 */

const H4_BACKFILL_AUTHORIZATION_TOKEN = 'H4_PACK_INTEGRITY_BACKFILL_EXPLICITLY_AUTHORIZED';

/** @return array<string,string|bool> */
function h4Arguments(array $argv): array
{
    $arguments = [];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--plan' || $argument === '--dry-run' || $argument === '--execute') {
            $arguments[substr($argument, 2)] = true;
            continue;
        }
        if (str_starts_with($argument, '--') && str_contains($argument, '=')) {
            [$name, $value] = explode('=', substr($argument, 2), 2);
            $arguments[$name] = $value;
        }
    }
    return $arguments;
}

function h4Line(string $key, mixed $value): void
{
    if (is_array($value)) {
        $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } elseif (is_bool($value)) {
        $value = $value ? 'YES' : 'NO';
    } elseif ($value === null) {
        $value = 'NULL';
    }
    echo $key . '=' . (string) $value . PHP_EOL;
}

function h4Connect(): PDO
{
    $dsn = trim((string) getenv('ERP_BACKFILL_TEST_DSN'));
    if ($dsn !== '') {
        return new PDO($dsn, (string) getenv('ERP_BACKFILL_TEST_USER'), (string) getenv('ERP_BACKFILL_TEST_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    require dirname(__DIR__) . '/bootstrap.php';
    Database::useProfile('cli');
    return Database::connection();
}

/** @return array{ok:bool,orders:list<string>,reason:string,fingerprint:string} */
function h4ExpectedOrders(mixed $raw): array
{
    $json = is_string($raw) ? trim($raw) : '';
    if ($json === '') {
        return ['ok' => false, 'orders' => [], 'reason' => 'expected_empty', 'fingerprint' => ''];
    }
    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return ['ok' => false, 'orders' => [], 'reason' => 'expected_invalid_json', 'fingerprint' => ''];
    }
    if (!is_array($decoded) || !array_is_list($decoded) || $decoded === []) {
        return ['ok' => false, 'orders' => [], 'reason' => 'expected_not_nonempty_list', 'fingerprint' => ''];
    }
    $orders = [];
    foreach ($decoded as $value) {
        if (!is_scalar($value)) {
            return ['ok' => false, 'orders' => [], 'reason' => 'expected_non_scalar', 'fingerprint' => ''];
        }
        $externalId = trim((string) $value);
        if ($externalId === '') {
            return ['ok' => false, 'orders' => [], 'reason' => 'expected_blank_order', 'fingerprint' => ''];
        }
        $orders[] = $externalId;
    }
    $unique = array_values(array_unique($orders));
    $sorted = $unique;
    sort($sorted, SORT_STRING);
    if ($orders !== $sorted) {
        return ['ok' => false, 'orders' => [], 'reason' => 'expected_not_deduped_sorted', 'fingerprint' => ''];
    }

    return [
        'ok' => true,
        'orders' => $orders,
        'reason' => 'expected_valid',
        'fingerprint' => hash('sha256', implode("\n", $orders)),
    ];
}

/** @return list<string> */
function h4LinkedOrders(PDO $pdo, array $pack): array
{
    $stmt = $pdo->prepare(
        'SELECT DISTINCT o.external_order_id
           FROM meli_pack_orders po
           INNER JOIN meli_orders o
             ON o.id = po.meli_order_id
            AND o.meli_account_id = :account_id
            AND o.external_pack_id = :external_pack_id
          WHERE po.meli_pack_id = :pack_id
          ORDER BY o.external_order_id ASC'
    );
    $stmt->execute([
        ':account_id' => (int) $pack['meli_account_id'],
        ':external_pack_id' => (string) $pack['external_pack_id'],
        ':pack_id' => (int) $pack['id'],
    ]);
    return array_map(static fn (mixed $value): string => (string) $value, $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** @return array{eligible:bool,reason:string,linked:list<string>,expected:list<string>,fingerprint:string} */
function h4ClassifyPack(PDO $pdo, array $pack): array
{
    if ((string) ($pack['integrity_status'] ?? '') === 'complete') {
        return ['eligible' => false, 'reason' => 'already_complete', 'linked' => [], 'expected' => [], 'fingerprint' => ''];
    }

    $expected = h4ExpectedOrders($pack['expected_orders_json'] ?? null);
    if (!$expected['ok']) {
        return ['eligible' => false, 'reason' => $expected['reason'], 'linked' => [], 'expected' => [], 'fingerprint' => ''];
    }

    $linked = h4LinkedOrders($pdo, $pack);
    if ($linked !== $expected['orders']) {
        $missing = array_diff($expected['orders'], $linked);
        $extra = array_diff($linked, $expected['orders']);
        return [
            'eligible' => false,
            'reason' => $missing !== [] ? 'missing_linked_orders' : ($extra !== [] ? 'extra_linked_orders' : 'linked_set_mismatch'),
            'linked' => $linked,
            'expected' => $expected['orders'],
            'fingerprint' => $expected['fingerprint'],
        ];
    }

    return [
        'eligible' => true,
        'reason' => 'exact_local_pack_integrity',
        'linked' => $linked,
        'expected' => $expected['orders'],
        'fingerprint' => $expected['fingerprint'],
    ];
}

/** @return array{selected:list<array<string,mixed>>,counts:array<string,int>,sha256:string} */
function h4Plan(PDO $pdo, int $limit): array
{
    $stmt = $pdo->query(
        "SELECT id, meli_account_id, external_pack_id, expected_orders_json, integrity_status
           FROM meli_packs
          ORDER BY meli_account_id ASC, id ASC"
    );

    $selected = [];
    $counts = [
        'eligible' => 0,
        'already_complete' => 0,
        'expected_empty' => 0,
        'expected_invalid_json' => 0,
        'expected_not_nonempty_list' => 0,
        'expected_non_scalar' => 0,
        'expected_blank_order' => 0,
        'expected_not_deduped_sorted' => 0,
        'missing_linked_orders' => 0,
        'extra_linked_orders' => 0,
        'linked_set_mismatch' => 0,
    ];

    while (($pack = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        $classification = h4ClassifyPack($pdo, $pack);
        $reason = $classification['eligible'] ? 'eligible' : $classification['reason'];
        $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        if (!$classification['eligible']) {
            continue;
        }
        $selected[] = [
            'id' => (int) $pack['id'],
            'meli_account_id' => (int) $pack['meli_account_id'],
            'external_pack_id' => (string) $pack['external_pack_id'],
            'expected_orders_json' => (string) $pack['expected_orders_json'],
            'integrity_status' => $pack['integrity_status'] === null ? null : (string) $pack['integrity_status'],
            'linked_orders_count' => count($classification['linked']),
            'fingerprint' => $classification['fingerprint'],
        ];
        if (count($selected) >= $limit) {
            break;
        }
    }

    $shaPayload = array_map(static fn (array $row): array => [
        'id' => $row['id'],
        'account' => $row['meli_account_id'],
        'pack' => $row['external_pack_id'],
        'status' => $row['integrity_status'],
        'expected' => hash('sha256', $row['expected_orders_json']),
        'fingerprint' => $row['fingerprint'],
    ], $selected);

    return [
        'selected' => $selected,
        'counts' => $counts,
        'sha256' => hash('sha256', json_encode($shaPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
    ];
}

function h4Apply(PDO $pdo, array $row): void
{
    $lock = $pdo->prepare(
        'SELECT id, meli_account_id, external_pack_id, expected_orders_json, integrity_status
           FROM meli_packs
          WHERE id = :id
            AND meli_account_id = :account_id
            AND external_pack_id = :external_pack_id
          FOR UPDATE'
    );
    $lock->execute([
        ':id' => (int) $row['id'],
        ':account_id' => (int) $row['meli_account_id'],
        ':external_pack_id' => (string) $row['external_pack_id'],
    ]);
    $current = $lock->fetch(PDO::FETCH_ASSOC);
    if (!is_array($current)) {
        throw new RuntimeException('cas_pack_missing');
    }
    if (!hash_equals((string) $row['expected_orders_json'], (string) $current['expected_orders_json'])
        || (string) ($row['integrity_status'] ?? '') !== (string) ($current['integrity_status'] ?? '')
    ) {
        throw new RuntimeException('cas_pack_drift');
    }
    $classification = h4ClassifyPack($pdo, $current);
    if (!$classification['eligible']) {
        throw new RuntimeException('cas_pack_no_longer_eligible:' . $classification['reason']);
    }
    if (!hash_equals((string) $row['fingerprint'], $classification['fingerprint'])) {
        throw new RuntimeException('cas_fingerprint_drift');
    }

    $update = $pdo->prepare(
        "UPDATE meli_packs
            SET linked_orders_count = :linked_orders_count,
                integrity_status = 'complete',
                integrity_message = 'Pack integrity verified from local linked orders.',
                orders_fingerprint = :orders_fingerprint,
                verified_at = UTC_TIMESTAMP()
          WHERE id = :id
            AND meli_account_id = :account_id
            AND external_pack_id = :external_pack_id
            AND (integrity_status <=> :expected_status)
            AND (expected_orders_json <=> :expected_orders_json)"
    );
    $update->execute([
        ':linked_orders_count' => count($classification['linked']),
        ':orders_fingerprint' => $classification['fingerprint'],
        ':id' => (int) $row['id'],
        ':account_id' => (int) $row['meli_account_id'],
        ':external_pack_id' => (string) $row['external_pack_id'],
        ':expected_status' => $row['integrity_status'],
        ':expected_orders_json' => (string) $row['expected_orders_json'],
    ]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('cas_update_refused');
    }
}

$arguments = h4Arguments($_SERVER['argv'] ?? []);
$modeCount = (int) isset($arguments['plan']) + (int) isset($arguments['dry-run']) + (int) isset($arguments['execute']);
$mode = isset($arguments['execute']) ? 'EXECUTE' : (isset($arguments['dry-run']) ? 'DRY_RUN' : (isset($arguments['plan']) ? 'PLAN' : ''));
$limit = max(1, min(500, (int) ($arguments['limit'] ?? 50)));

h4Line('=== ERP_MELI_2398_H4_PACK_INTEGRITY_BACKFILL_BEGIN ===', '');
h4Line('CAPTURED_AT_UTC', gmdate('c'));
h4Line('MODE', $mode === '' ? 'INVALID' : $mode);
h4Line('REAL_MELI_HTTP', 0);

try {
    if ($modeCount !== 1) {
        throw new RuntimeException('exactly_one_mode_required');
    }

    $pdo = h4Connect();
    $pdo->exec("SET SESSION time_zone='+00:00'");
    if ($mode !== 'EXECUTE') {
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    }
    $pdo->beginTransaction();

    h4Line('TRANSACTION_READ_ONLY', $mode === 'EXECUTE' ? 0 : 1);
    h4Line('LIMIT', $limit);

    $plan = h4Plan($pdo, $limit);
    h4Line('ROOT_CLASSIFICATION_COUNTS', $plan['counts']);
    h4Line('CURRENT_BATCH_SELECTED', count($plan['selected']));
    h4Line('PLAN_SHA256', $plan['sha256']);

    if ($mode === 'EXECUTE') {
        $token = (string) ($arguments['authorization-token'] ?? '');
        $expectedSha = (string) ($arguments['expected-plan-sha256'] ?? '');
        if (!hash_equals(H4_BACKFILL_AUTHORIZATION_TOKEN, $token)) {
            throw new RuntimeException('authorization_token_invalid');
        }
        if ($expectedSha === '' || !hash_equals($expectedSha, $plan['sha256'])) {
            throw new RuntimeException('plan_sha_mismatch');
        }
        $mutations = 0;
        foreach ($plan['selected'] as $row) {
            h4Apply($pdo, $row);
            $mutations++;
        }
        $pdo->commit();
        h4Line('PRODUCTION_MUTATIONS', $mutations);
        h4Line('RECOVERY_EXECUTED', 'YES');
        h4Line('STATUS', 'EXECUTED_H4_PACK_INTEGRITY_BACKFILL');
    } else {
        $pdo->rollBack();
        h4Line('PRODUCTION_MUTATIONS', 0);
        h4Line('RECOVERY_EXECUTED', 'NO');
        h4Line('EXECUTE_ALLOWED', $mode === 'PLAN' && count($plan['selected']) > 0 ? 'YES_AFTER_INDEPENDENT_AUTHORIZATION' : 'NO');
        h4Line('STATUS', 'READY_FOR_H4_PACK_INTEGRITY_BACKFILL_REVIEW');
    }
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    h4Line('PRODUCTION_MUTATIONS', 0);
    h4Line('RECOVERY_EXECUTED', 'NO');
    h4Line('STATUS', 'BLOCKED');
    h4Line('BLOCKED_REASON', $error->getMessage());
}

h4Line('=== ERP_MELI_2398_H4_PACK_INTEGRITY_BACKFILL_END ===', '');
