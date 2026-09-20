<?php

declare(strict_types=1);

require __DIR__ . '/k3_unit02_disposition_fixture.inc.php';

use App\QueueV4Clean\PackDiscoveryOccupancyPolicy;

$fixture = k3u2_seed($pdo);
$evidence = k3u2_authorized_evidence($pdo, $fixture['unit02']);
$directory = dirname(__DIR__) . '/storage/k3u2-local-20260920/evidence/concurrency-' . bin2hex(random_bytes(4));
if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
    throw new RuntimeException('k3_unit02_concurrency_directory_failed');
}
$evidencePath = $directory . '/evidence.json';
$startPath = $directory . '/start.flag';
file_put_contents($evidencePath, json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

$pdo->beginTransaction();
(new PackDiscoveryOccupancyPolicy($pdo))->lockAdmissionAuthority();

$processes = [];
for ($i = 1; $i <= 2; $i++) {
    $readyPath = $directory . '/ready-' . $i . '.flag';
    $stdoutPath = $directory . '/stdout-' . $i . '.json';
    $stderrPath = $directory . '/stderr-' . $i . '.txt';
    $command = sprintf(
        '"%s" "%s" "%s" "%s" "%s"',
        PHP_BINARY,
        __DIR__ . '/k3_unit02_disposition_apply_participant.php',
        $evidencePath,
        $startPath,
        $readyPath,
    );
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['file', $stdoutPath, 'w'],
        2 => ['file', $stderrPath, 'w'],
    ], $pipes, dirname(__DIR__));
    r0h3_assert(is_resource($process), 'k3_unit02_concurrency_process_started', ['participant' => $i]);
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    $processes[] = [
        'process' => $process,
        'ready' => $readyPath,
        'stdout' => $stdoutPath,
        'stderr' => $stderrPath,
    ];
}

$deadline = microtime(true) + 10.0;
do {
    $ready = count(array_filter($processes, static fn (array $item): bool => is_file($item['ready'])));
    if ($ready === 2) {
        break;
    }
    usleep(20000);
} while (microtime(true) < $deadline);
r0h3_assert($ready === 2, 'k3_unit02_concurrency_participants_ready', ['ready' => $ready]);

file_put_contents($startPath, "START\n", LOCK_EX);
usleep(250000);
$pdo->commit();

$statuses = [];
foreach ($processes as $index => $item) {
    $exit = proc_close($item['process']);
    $stdout = trim((string) file_get_contents($item['stdout']));
    $stderr = trim((string) file_get_contents($item['stderr']));
    r0h3_assert($exit === 0, 'k3_unit02_concurrency_participant_completed', [
        'participant' => $index + 1,
        'exit' => $exit,
        'stderr' => $stderr,
    ]);
    $decoded = json_decode($stdout, true, 64, JSON_THROW_ON_ERROR);
    $statuses[] = (string) ($decoded['status'] ?? '');
}
sort($statuses, SORT_STRING);
r0h3_assert($statuses === ['ALREADY_APPLIED', 'APPLIED'], 'k3_unit02_concurrency_serialized_results', $statuses);
r0h3_assert((int) $pdo->query("SELECT COUNT(*) FROM app_settings WHERE setting_key='queue_v4.pack_discovery_unit02_disposition'")->fetchColumn() === 1
    && (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='pack_discovery_unit02_disposition_applied'")->fetchColumn() === 1,
    'k3_unit02_concurrency_one_authority_one_audit');

$pdo->beginTransaction();
$metrics = (new PackDiscoveryOccupancyPolicy($pdo))->measureForAccounts(k3u2_scope());
$pdo->commit();
r0h3_assert($metrics === [
    'outstanding' => 1,
    'administrative_dispositions' => 1,
    'physical_unknown_dispatches' => 2,
    'measurement_status' => 'CERTIFIED',
    'measurement_scope' => 'H3_CERTIFIED_NON_H3_PACK_DISCOVERY',
], 'k3_unit02_concurrency_final_occupancy', $metrics);

echo json_encode([
    'status' => 'PASS',
    'participants' => 2,
    'serialized_statuses' => $statuses,
    'authority_rows' => 1,
    'audit_rows' => 1,
    'metrics' => $metrics,
    'real_meli_http' => 0,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
