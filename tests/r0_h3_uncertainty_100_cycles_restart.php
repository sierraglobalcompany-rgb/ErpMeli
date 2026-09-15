<?php
declare(strict_types=1);

require __DIR__ . '/r0_h3_bootstrap.php';

$fixture = r0h3_seed($pdo);
$admission = new App\Services\CronAdmissionService($pdo);
for ($i = 0; $i < 2; $i++) {
    $target = $fixture['healthy'][$i];
    $sourceId = (new App\Services\OrderEnrichmentService())->enqueue((int) $target['meli_account_id'], (int) $target['order_id'], 'pack', (string) $target['external_pack_id'], 10);
    $pdo->beginTransaction();
    $receipt = $admission->submit('order_enrichment_pack', (int) $target['company_id'], (int) $target['meli_account_id'], $sourceId, 'source:' . $sourceId, ['pack_id' => (string) $target['external_pack_id']]);
    $pdo->commit();
    r0h3_assert(!empty($receipt['accepted']), 'uncertainty_setup_admission_expected', ['receipt' => $receipt]);
    $pdo->prepare("UPDATE order_resource_enrichment_jobs SET status='retry', failure_class='remote_result_uncertain_safe_get', reached_remote=1 WHERE id=?")->execute([$sourceId]);
}

$denied = 0;
for ($i = 0; $i < 100; $i++) {
    $accountId = $i % 2 === 0 ? 7201 : 7202;
    $packExternal = (string) (990200000 + $i);
    $orderId = r0h3_insert($pdo, 'meli_orders', [
        'meli_account_id' => $accountId,
        'external_order_id' => (string) (990100000 + $i),
        'external_pack_id' => $packExternal,
        'status' => 'paid',
        'synced_at' => gmdate('Y-m-d H:i:s', time() - 3600),
    ]);
    $packId = r0h3_insert($pdo, 'meli_packs', [
        'meli_account_id' => $accountId,
        'external_pack_id' => $packExternal,
        'status' => 'unknown',
        'integrity_status' => 'provisional',
        'synced_at' => gmdate('Y-m-d H:i:s', time() - 3600),
    ]);
    r0h3_insert($pdo, 'meli_pack_orders', ['meli_pack_id' => $packId, 'meli_order_id' => $orderId]);
    $sourceId = (new App\Services\OrderEnrichmentService())->enqueue($accountId, $orderId, 'pack', $packExternal, 10);
    $pdo->beginTransaction();
    $receipt = $admission->submit('order_enrichment_pack', 7200, $accountId, $sourceId, 'source:' . $sourceId, ['pack_id' => $packExternal]);
    $pdo->commit();
    if (empty($receipt['accepted']) && ($receipt['reason'] ?? '') === 'R0_OCCUPANCY_EXHAUSTED') {
        $denied++;
    }
}

$openBeforeRestart = r0h3_open_new_pack_units($pdo);
$cmd = '"' . PHP_BINARY . '" "' . __DIR__ . DIRECTORY_SEPARATOR . 'r0_h3_restart_probe.php"';
$env = array_merge($_ENV, [
    'R0_H3_DB_NAME' => (string) getenv('R0_H3_DB_NAME'),
    'R0_H3_DB_PORT' => (string) getenv('R0_H3_DB_PORT'),
    'R0_H3_LAB_MANIFEST' => (string) getenv('R0_H3_LAB_MANIFEST'),
]);
$process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env);
r0h3_assert(is_resource($process), 'restart_probe_proc_open_failed');
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($process);

echo json_encode(['denied' => $denied, 'open_before_restart' => $openBeforeRestart, 'restart_code' => $code, 'restart_stdout' => trim($stdout), 'restart_stderr' => trim($stderr)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
r0h3_assert($denied === 100, 'two_retained_uncertain_units_must_deny_100_healthy_replacements', ['denied' => $denied]);
r0h3_assert($openBeforeRestart === 2, 'retained_units_must_remain_two_before_restart', ['open' => $openBeforeRestart]);
r0h3_assert($code === 0, 'restart_same_database_probe_must_pass', ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr]);
