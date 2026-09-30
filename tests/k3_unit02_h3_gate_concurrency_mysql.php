<?php

declare(strict_types=1);

require __DIR__ . '/k3_unit02_disposition_fixture.inc.php';

use App\QueueV4Clean\PackDiscoveryOccupancyPolicy;

$fixture = k3u2_seed($pdo);
$evidence = k3u2_authorized_evidence($pdo, $fixture['unit02']);
$directory = dirname(__DIR__) . '/storage/k3u2-local-20260920/evidence/h3-gate-concurrency-' . bin2hex(random_bytes(4));
if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
    throw new RuntimeException('k3_unit02_h3_concurrency_directory_failed');
}
$evidencePath = $directory . '/evidence.json';
$startPath = $directory . '/start.flag';
$readyPath = $directory . '/ready.flag';
$stdoutPath = $directory . '/stdout.json';
$stderrPath = $directory . '/stderr.txt';
file_put_contents($evidencePath, json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

$pdo->beginTransaction();
(new PackDiscoveryOccupancyPolicy($pdo))->lockAdmissionAuthority();

$command = sprintf('"%s" "%s" "%s" "%s" "%s"', PHP_BINARY, __DIR__ . '/k3_unit02_disposition_apply_participant.php', $evidencePath, $startPath, $readyPath);
$process = proc_open($command, [
    0 => ['pipe', 'r'],
    1 => ['file', $stdoutPath, 'w'],
    2 => ['file', $stderrPath, 'w'],
], $pipes, dirname(__DIR__));
r0h3_assert(is_resource($process), 'k3_unit02_h3_concurrency_process_started');
if (isset($pipes[0]) && is_resource($pipes[0])) {
    fclose($pipes[0]);
}
$deadline = microtime(true) + 10.0;
while (!is_file($readyPath) && microtime(true) < $deadline) {
    usleep(20000);
}
r0h3_assert(is_file($readyPath), 'k3_unit02_h3_concurrency_participant_ready');
file_put_contents($startPath, "START\n", LOCK_EX);
usleep(300000);
$statusWhileLocked = proc_get_status($process);
r0h3_assert(($statusWhileLocked['running'] ?? false) === true, 'k3_unit02_h3_concurrency_participant_blocked_by_real_lock', $statusWhileLocked);

$pdo->prepare('UPDATE app_settings SET setting_value=? WHERE setting_key=?')->execute(['{', PackDiscoveryOccupancyPolicy::AUTHORITY_KEY]);
$pdo->commit();
$exit = proc_close($process);
$stderr = trim((string) file_get_contents($stderrPath));
r0h3_assert($exit !== 0 && str_contains($stderr, 'k3_unit02_h3_authority_malformed'),
    'k3_unit02_h3_concurrency_revalidates_after_lock', ['exit' => $exit, 'stderr' => $stderr]);
r0h3_assert(
    (int) $pdo->query("SELECT COUNT(*) FROM app_settings WHERE setting_key='queue_v4.pack_discovery_unit02_disposition'")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='pack_discovery_unit02_disposition_applied'")->fetchColumn() === 0,
    'k3_unit02_h3_concurrency_zero_partial_writes'
);

echo json_encode(['status' => 'PASS', 'real_lock_wait_observed' => true, 'real_meli_http' => 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
