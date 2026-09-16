<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

$fixture = r0h3_seed($pdo);
$healthy = $fixture['healthy'];
$admissionA = new App\Services\CronAdmissionService($pdo);

$first = $healthy[0];
$firstSource = (new App\Services\OrderEnrichmentService())->enqueue((int) $first['meli_account_id'], (int) $first['order_id'], 'pack', (string) $first['external_pack_id'], 10);
$pdo->beginTransaction();
$firstReceipt = $admissionA->submit('order_enrichment_pack', (int) $first['company_id'], (int) $first['meli_account_id'], $firstSource, 'source:' . $firstSource, ['pack_id' => (string) $first['external_pack_id']]);
$pdo->commit();
r0h3_assert(!empty($firstReceipt['accepted']), 'first_admission_expected', ['receipt' => $firstReceipt]);

$second = $healthy[1];
$secondSource = (new App\Services\OrderEnrichmentService())->enqueue((int) $second['meli_account_id'], (int) $second['order_id'], 'pack', (string) $second['external_pack_id'], 10);
$third = $healthy[2];
$thirdSource = (new App\Services\OrderEnrichmentService())->enqueue((int) $third['meli_account_id'], (int) $third['order_id'], 'pack', (string) $third['external_pack_id'], 10);

$pdoB = new PDO('mysql:host=127.0.0.1;port=' . getenv('R0_H3_DB_PORT') . ';dbname=' . getenv('R0_H3_DB_NAME') . ';charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdoB->beginTransaction();
$staleSeen = (int) $pdoB->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE job_type='domain_exact' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))='order_enrichment_pack' AND company_id=7200")->fetchColumn();
r0h3_assert($staleSeen === 1, 'test_must_start_transaction_b_with_one_visible_unit', ['stale_seen' => $staleSeen]);

$pdo->beginTransaction();
$secondReceipt = $admissionA->submit('order_enrichment_pack', (int) $second['company_id'], (int) $second['meli_account_id'], $secondSource, 'source:' . $secondSource, ['pack_id' => (string) $second['external_pack_id']]);
$pdo->commit();
r0h3_assert(!empty($secondReceipt['accepted']), 'second_admission_expected', ['receipt' => $secondReceipt]);

$receiptB = (new App\Services\CronAdmissionService($pdoB))->submit('order_enrichment_pack', (int) $third['company_id'], (int) $third['meli_account_id'], $thirdSource, 'source:' . $thirdSource, ['pack_id' => (string) $third['external_pack_id']]);
$pdoB->commit();

$count = r0h3_open_new_pack_units($pdo);
echo json_encode(['stale_seen' => $staleSeen, 'second' => $secondReceipt, 'third_from_stale_tx' => $receiptB, 'bounded_units' => $count], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert(
    empty($receiptB['accepted']) && in_array(($receiptB['reason'] ?? ''), ['R0_OCCUPANCY_EXHAUSTED', 'R0_H3_AUTHORITY_UNAVAILABLE', 'R0_OCCUPANCY_UNAVAILABLE'], true) && $count === 2,
    'stale_repeatable_read_transaction_must_fail_closed_and_not_admit_third',
    ['receipt' => $receiptB, 'count' => $count]
);
