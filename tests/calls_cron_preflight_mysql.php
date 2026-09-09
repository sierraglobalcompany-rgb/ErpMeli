<?php
declare(strict_types=1);

// An explicit, locally extracted RAW candidate is required. This test never
// generates an integrity waiver, starts Cron, or substitutes readiness/integrity.
$release = str_replace('\\', '/', (string) (getenv('CALLS_PREFLIGHT_RAW_ROOT') ?: ''));
$reuse = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--raw-root=')) $release = str_replace('\\', '/', substr($argument, 11));
    elseif (str_starts_with($argument, '--reuse=')) $reuse = substr($argument, 8);
    else throw new RuntimeException('Unknown preflight fixture argument.');
}
if (!str_starts_with($release, 'D:/Codex/tmp/erp-meli/') || str_contains($release, '..')
    || !is_file($release . '/resources/runtime-manifest.json') || !is_file($release . '/jobs/queue_v4_clean.php')) {
    throw new RuntimeException('Explicit local RAW candidate root required; no SKIP.');
}
define('ERP_RELEASE_ROOT', rtrim($release, '/'));
define('ERP_INSTALLATION_ROOT', 'D:/Codex/tmp/erp-meli/calls-20260906/readiness/preflight-' . bin2hex(random_bytes(6)));
define('ERP_SHARED_ROOT', ERP_INSTALLATION_ROOT . '/shared');
// Separate writable installation state from the supplied immutable RAW tree.
mkdir(ERP_INSTALLATION_ROOT . '/jobs', 0770, true);
if (!copy(ERP_RELEASE_ROOT . '/jobs/queue_v4_clean.php', ERP_INSTALLATION_ROOT . '/jobs/queue_v4_clean.php')) {
    throw new RuntimeException('Cannot stage the exact local launcher file.');
}
require __DIR__ . '/calls_readiness_fixture.php';

$h = null;
if ($reuse === null) {
    $h = calls_readiness_database();
} else {
    // Optional only after the owning browser fixture explicitly yields its DB.
    $path = str_replace('\\', '/', $reuse);
    if (!str_starts_with($path, 'D:/Codex/tmp/erp-meli/calls-20260906/') || str_contains($path, '..')) {
        throw new RuntimeException('Reuse metadata must be local fixture evidence.');
    }
    $metadata = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    $name = (string) ($metadata['db_name'] ?? '');
    if (!preg_match('/^erp_meli_k1d_test_cap2_manual_[a-f0-9]{8}$/D', $name)) throw new RuntimeException('Unexpected fixture DB.');
    foreach (['APP_ENV'=>'test','ML_WRITE_ENABLED'=>'false','DB_HOST'=>'127.0.0.1','DB_PORT'=>'33079',
        'DB_USER'=>'root','DB_PASS'=>'','DB_NAME'=>$name,'APP_KEY'=>'cap2-disposable-test-only-not-a-real-secret',
        'MELI_API_BASE'=>'https://cap2-wire.invalid'] as $key=>$value) putenv($key . '=' . $value);
    $sessions = ERP_SHARED_ROOT . '/sessions';
    mkdir($sessions, 0770, true);
    session_save_path($sessions); session_name('erp_meli_session'); session_id(bin2hex(random_bytes(16))); session_start();
    App\Core\Session::put('user', ['id'=>9007,'role'=>'admin','session_generation'=>(new App\Services\SessionGenerationService())->current()]);
    App\Core\Session::closeReadOnly();
    (new App\Services\EmergencyControlService())->stopAutomation('fixture', 'local preflight fixture');
    (new App\Services\InstalledVersionMarkerService())->write(App\Services\AppVersionService::fileVersion(), '301');
}
$pdo = App\Core\Database::connection();
try {
    $integrity = (new App\Services\ReleaseIntegrityService())->inspect(true);
    k1b_assert($integrity['ok'] === true, 'RAW_candidate_integrity_required:' . json_encode($integrity['errors']));
    k1b_assert($integrity['version'] === '2.40.1', 'Expected unchanged candidate version');
    $control = new App\QueueV4Clean\QueueV4CleanControlService($pdo);
    $control->stop(9007);
    $service = new App\QueueV4Clean\QueueV4CleanReadinessService($pdo);
    $run = $service->prepare(9007);
    for ($step = 1; $step <= 3; $step++) calls_readiness_check($service, $run, $step);
    $activation = $control->activate(9007, $run['run_id'], $run['run_token']);
    $snapshot = $service->snapshot();
    k1b_assert($activation['state'] === 'ACTIVE' && $snapshot['engine'] === 'ACTIVE' && $snapshot['state'] === 'CERTIFIED',
        'Activation receipt ACTIVE is distinct from readiness snapshot CERTIFIED');
    k1b_assert((new App\Services\EmergencyControlService())->automationStopped(), 'Activation does not resume automation');
    // A control timestamp alone must not fabricate an observed physical run.
    $pdo->exec("UPDATE queue_v4_clean_control SET last_scheduler_at=UTC_TIMESTAMP(3) WHERE control_key='primary'");
    calls_preflight_assert_read_only($pdo, false, 'missing_heartbeat');
    // Seed historical telemetry solely in this disposable fixture. No actual
    // Cron/scheduler is executed, and the preflight must not create a heartbeat.
    $pdo->exec("INSERT INTO queue_v4_clean_runs(launcher,worker_ref,status,started_at,finished_at) VALUES('scheduler','preflight-historical-fixture','completed',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $heartbeatId = (int) $pdo->lastInsertId();
    $summary = calls_preflight_assert_read_only($pdo, true, 'certified_active_recent');
    k1b_assert($summary['queue_v4_engine'] === 'ACTIVE' && $summary['queue_v4_readiness'] === 'CERTIFIED'
        && $summary['queue_v4_heartbeat'] === 'RECENT' && $summary['oauth_accounts'] === 3 && $summary['processed'] === 0,
        'Real preflight success must report its distinct states and zero processed');
    $pdo->exec("UPDATE queue_v4_clean_runs SET started_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 DAY),finished_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 DAY) WHERE id=" . $heartbeatId);
    calls_preflight_assert_read_only($pdo, false, 'stale_heartbeat');
    $pdo->exec("UPDATE queue_v4_clean_runs SET started_at=UTC_TIMESTAMP(3),finished_at=UTC_TIMESTAMP(3) WHERE id=" . $heartbeatId);
    $pdo->exec("UPDATE queue_v4_clean_control SET readiness_state='TESTING' WHERE control_key='primary'");
    calls_preflight_assert_read_only($pdo, false, 'uncertified_engine');
    $pdo->exec("UPDATE queue_v4_clean_control SET readiness_state='CERTIFIED' WHERE control_key='primary'");
    $pdo->exec("UPDATE meli_accounts SET status='desconectado' WHERE company_id=9001 AND id=9013");
    calls_preflight_assert_read_only($pdo, false, 'account_count_changed');
    $pdo->exec("UPDATE meli_accounts SET status='conectado' WHERE company_id=9001 AND id=9013");
    $control->stop(9007);
    calls_preflight_assert_read_only($pdo, false, 'stopped_engine');
    // The test bootstrap loads current services; their executed source must
    // match the inspected RAW candidate, allowing checkout line endings only.
    $sourceRoot = str_replace('\\', '/', dirname(__DIR__)) . '/';
    foreach (get_included_files() as $included) {
        $included = str_replace('\\', '/', $included);
        if (!str_starts_with($included, $sourceRoot . 'app/')) continue;
        $relative = substr($included, strlen($sourceRoot));
        $raw = ERP_RELEASE_ROOT . '/' . $relative;
        $canonical = static fn(string $bytes): string => str_replace(["\r\n", "\r"], "\n", $bytes);
        k1b_assert(is_file($raw) && hash_equals(hash('sha256', $canonical((string) file_get_contents($included))),
            hash('sha256', $canonical((string) file_get_contents($raw)))), 'Executed service differs from RAW candidate: ' . $relative);
    }
    echo "CALLS_CRON_PREFLIGHT_MYSQL_OK\nREAL_MELI_HTTP=0\nREAL_CRON_JOBS=0\n";
} finally {
    App\Services\Cap2DomainsWire::$onWire = null;
    if ($h !== null) $h->cleanup(); // Reused fixture belongs to QA and is never dropped here.
}

function calls_preflight_database_digest(PDO $pdo): string
{
    $rows = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('Unsafe fixture table identifier');
        $encoded = array_map(static fn(array $row): string => json_encode($row, JSON_THROW_ON_ERROR),
            $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC));
        sort($encoded, SORT_STRING);
        $rows[$table] = hash('sha256', implode("\n", $encoded));
    }
    ksort($rows);
    return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
}

function calls_preflight_assert_read_only(PDO $pdo, bool $expected, string $case): array
{
    $before = calls_preflight_database_digest($pdo);
    $wire = count(App\Services\Cap2DomainsWire::$calls);
    $result = []; $error = null;
    try { $result = (new App\Services\CronHealthService())->quickReadOnlyPreflight(microtime(true)); }
    catch (Throwable $caught) { $error = $caught; }
    k1b_assert($before === calls_preflight_database_digest($pdo), $case . ': preflight mutated persisted rows');
    k1b_assert(count(App\Services\Cap2DomainsWire::$calls) === $wire, $case . ': preflight attempted physical HTTP');
    k1b_assert(($error === null) === $expected, $case . ': ' . ($error?->getMessage() ?? 'unexpected success'));
    fwrite(STDERR, 'PASS=' . $case . " zero_http_zero_database_mutations\n");
    return $result;
}
