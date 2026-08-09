<?php

declare(strict_types=1);

/**
 * Rehearsal opt-in del runtime N-1 HF1.2 real contra schema293.
 *
 * Requiere un runtime materializado con `git archive` desde el commit f91 y
 * un clon MariaDB local/desescartable ya migrado a 293 con app.version 2.35.1.
 */

const HF12_N1_COMMIT = 'f91cd534d271b964db1ad9e682260475eca96820';
const HF12_N1_ACK = 'DISPOSABLE_HF12_SCHEMA293_STOP_REHEARSAL';

$root = dirname(__DIR__);
$fail = static function (string $message): never {
    throw new RuntimeException($message);
};
$assert = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) {
        $fail($message);
    }
};

set_exception_handler(static function (Throwable $error): void {
    $message = preg_replace(
        '/(?i)(password|pass|app[_-]?key|access[_-]?token|refresh[_-]?token)\s*[:=]\s*\S+/',
        '$1=[REDACTED]',
        $error->getMessage()
    ) ?? 'unknown_failure';
    fwrite(STDERR, 'FAIL hf12_n1_schema293_stop_rehearsal ' . mb_substr($message, 0, 500) . PHP_EOL);
    exit(1);
});

/** @return array{host:string,port:int,dbname:string} */
$parseDsn = static function (string $dsn) use ($fail): array {
    if (!str_starts_with(strtolower($dsn), 'mysql:')) {
        $fail('dsn_not_mysql');
    }
    $values = [];
    foreach (explode(';', substr($dsn, 6)) as $part) {
        if (!str_contains($part, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $part, 2));
        $values[strtolower($key)] = $value;
    }
    return [
        'host' => (string) ($values['host'] ?? ''),
        'port' => (int) ($values['port'] ?? 0),
        'dbname' => (string) ($values['dbname'] ?? ''),
    ];
};

/** @return array{exit:int,stdout:string,stderr:string} */
$run = static function (array $command, string $cwd): array {
    $pipes = [];
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('child_process_start_failed');
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    return [
        'exit' => $exit,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
};

/** @return array<string,string> */
$readConfig = static function (string $path): array {
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $values[$key] = trim($value, "\"'");
    }
    return $values;
};

/** @return array<string,array{rows:int,checksum:string}> */
$databaseSnapshot = static function (PDO $pdo): array {
    $tables = $pdo->query(
        "SELECT table_name FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_type='BASE TABLE'
         ORDER BY table_name"
    )->fetchAll(PDO::FETCH_COLUMN);
    $snapshot = [];
    foreach ($tables as $rawTable) {
        $table = (string) $rawTable;
        $identifier = '`' . str_replace('`', '``', $table) . '`';
        $rows = (int) $pdo->query("SELECT COUNT(*) FROM {$identifier}")->fetchColumn();
        $checksumRow = $pdo->query("CHECKSUM TABLE {$identifier}")->fetch(PDO::FETCH_ASSOC);
        $checksum = is_array($checksumRow) && ($checksumRow['Checksum'] ?? null) !== null
            ? (string) $checksumRow['Checksum']
            : 'unsupported';
        $snapshot[$table] = ['rows' => $rows, 'checksum' => $checksum];
    }
    return $snapshot;
};

$verifyGitTree = static function (string $runtime) use ($root, $run, $assert): int {
    $listing = $run([
        'git', '-C', $root, 'ls-tree', '-r', '-l', HF12_N1_COMMIT,
    ], $root);
    $assert($listing['exit'] === 0, 'hf12_git_tree_unavailable');
    $expected = [];
    foreach (preg_split('/\r?\n/', trim($listing['stdout'])) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        $tab = strpos($line, "\t");
        $assert($tab !== false, 'git_tree_line_invalid');
        $metadata = preg_split('/\s+/', substr($line, 0, $tab)) ?: [];
        $path = substr($line, $tab + 1);
        $assert(count($metadata) >= 4 && ($metadata[1] ?? '') === 'blob', 'git_tree_blob_invalid');
        $blob = (string) $metadata[2];
        $size = (int) $metadata[3];
        $file = $runtime . '/' . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $assert(is_file($file), 'hf12_tracked_file_missing:' . $path);
        $contents = file_get_contents($file);
        $assert(is_string($contents) && strlen($contents) === $size, 'hf12_blob_size_mismatch:' . $path);
        $actualBlob = sha1('blob ' . $size . "\0" . $contents);
        $assert(hash_equals($blob, $actualBlob), 'hf12_blob_mismatch:' . $path);
        $expected[str_replace('\\', '/', $path)] = true;
    }
    $actual = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $entry) {
        if (!$entry->isFile()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($runtime) + 1));
        if (
            $relative === 'config.env'
            || $relative === 'PAUSE_MELI_API'
            || $relative === 'PAUSE_ERP_AUTOMATION'
            || str_starts_with($relative, 'storage/')
        ) {
            continue;
        }
        $actual[$relative] = true;
    }
    ksort($expected);
    ksort($actual);
    $assert(array_keys($expected) === array_keys($actual), 'hf12_runtime_has_unmanaged_extra');
    return count($expected);
};

$decodeJson = static function (string $json, string $label) use ($fail): array {
    try {
        $decoded = json_decode(trim($json), true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        $fail($label . '_json_invalid');
    }
    if (!is_array($decoded)) {
        $fail($label . '_json_not_object');
    }
    return $decoded;
};

$ack = (string) getenv('ERP_2361_N1_ACK');
$dsn = trim((string) getenv('ERP_2361_N1_DSN'));
$user = (string) getenv('ERP_2361_N1_USER');
$pass = (string) getenv('ERP_2361_N1_PASS');
$runtime = rtrim((string) getenv('ERP_2361_N1_RUNTIME'), '/\\');
if ($ack !== HF12_N1_ACK) {
    fwrite(STDERR, "ERROR n1_rehearsal_ack_required\n");
    exit(2);
}
$dsnParts = $parseDsn($dsn);
$assert(
    in_array(strtolower($dsnParts['host']), ['127.0.0.1', 'localhost'], true)
    && $dsnParts['port'] >= 1024
    && $dsnParts['port'] !== 3306
    && $dsnParts['dbname'] !== '',
    'disposable_local_nonstandard_port_required'
);
$assert(is_dir($runtime), 'hf12_runtime_missing');
$assert(trim((string) file_get_contents($runtime . '/VERSION')) === '2.35.1', 'hf12_version_not_2351');
$trackedFiles = $verifyGitTree($runtime);

$assert(is_file($runtime . '/PAUSE_MELI_API'), 'pause_meli_api_missing');
$assert(is_file($runtime . '/PAUSE_ERP_AUTOMATION'), 'pause_automation_missing');
$config = $readConfig($runtime . '/config.env');
foreach (['ML_WRITE_ENABLED', 'CRON_V3_ENABLED', 'CRON_V3_SHADOW_ENABLED', 'CRON_V4_ENABLED', 'QUEUE_CORE_ENABLED'] as $flag) {
    $assert(strtolower((string) ($config[$flag] ?? '')) === 'false', 'unsafe_flag:' . $flag);
}
$assert(
    ($config['DB_HOST'] ?? '') === $dsnParts['host']
    && (int) ($config['DB_PORT'] ?? 0) === $dsnParts['port']
    && ($config['DB_NAME'] ?? '') === $dsnParts['dbname'],
    'runtime_dsn_not_clone'
);

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("SET time_zone='+00:00'");
$version = trim((string) $pdo->query(
    "SELECT setting_value FROM app_settings WHERE setting_key='app.version'"
)->fetchColumn());
$assert($version === '2.35.1', 'n1_metadata_not_2351');
$migrationCount = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
$maxMigration = (int) $pdo->query(
    "SELECT MAX(CAST(SUBSTRING_INDEX(version,'_',1) AS UNSIGNED)) FROM schema_migrations"
)->fetchColumn();
$newMigrations = (int) $pdo->query(
    "SELECT COUNT(*) FROM schema_migrations
     WHERE CAST(SUBSTRING_INDEX(version,'_',1) AS UNSIGNED) BETWEEN 280 AND 293"
)->fetchColumn();
$post293 = (int) $pdo->query(
    "SELECT COUNT(*) FROM schema_migrations
     WHERE CAST(SUBSTRING_INDEX(version,'_',1) AS UNSIGNED)>293"
)->fetchColumn();
$assert($migrationCount === 293 && $maxMigration === 293 && $newMigrations === 14 && $post293 === 0, 'schema293_authority_failed');

$v2Source = (string) file_get_contents($runtime . '/jobs/process_sync_queue.php');
$v3Source = (string) file_get_contents($runtime . '/app/Services/CronV3Cli.php');
$doctorSource = (string) file_get_contents($runtime . '/app/Services/CronV3DoctorService.php');
$assert(
    strpos($v2Source, "require __DIR__ . '/_automation_emergency_stop.php'")
        < strrpos($v2Source, "require __DIR__ . '/_bootstrap.php'"),
    'v2_automation_stop_after_bootstrap'
);
$assert(
    strpos($v3Source, 'if ((!$shadow && !$activeEnabled)')
        < strpos($v3Source, "Database::useProfile('cli')"),
    'v3_disabled_gate_after_database'
);
$assert(
    str_contains($doctorSource, "'read_only' => true")
    && str_contains($doctorSource, "'http_calls' => 0")
    && !str_contains($doctorSource, 'MeliApiClient')
    && !str_contains($doctorSource, 'curl_'),
    'v3_doctor_not_static_read_only'
);

$before = $databaseSnapshot($pdo);

$v2 = $run([PHP_BINARY, 'jobs/process_sync_queue.php'], $runtime);
$assert($v2['exit'] === 0, 'v2_exit_not_fail_closed');
$assert(
    str_contains($v2['stdout'], 'reason=manual_automation_stop')
    && str_contains($v2['stdout'], 'remote=false database=false'),
    'v2_stop_receipt_invalid'
);

$v3Local = $run([PHP_BINARY, 'jobs/cron_v3_local.php', '--runtime=5', '--max-items=1'], $runtime);
$v3Remote = $run([PHP_BINARY, 'jobs/cron_v3_remote.php', '--runtime=5', '--max-http=1', '--delay=0'], $runtime);
foreach (['local' => $v3Local, 'remote' => $v3Remote] as $lane => $result) {
    $assert($result['exit'] === 0, 'v3_' . $lane . '_exit_not_zero');
    $payload = $decodeJson($result['stdout'], 'v3_' . $lane);
    $assert(
        ($payload['status'] ?? '') === 'disabled'
        && ($payload['lane'] ?? '') === $lane
        && (int) ($payload['http_calls'] ?? -1) === 0,
        'v3_' . $lane . '_not_disabled_http0'
    );
}

$doctorLocal = $run([PHP_BINARY, 'jobs/cron_v3_local.php', '--doctor', '--json'], $runtime);
$doctorRemote = $run([PHP_BINARY, 'jobs/cron_v3_remote.php', '--doctor', '--json'], $runtime);
foreach (['local' => $doctorLocal, 'remote' => $doctorRemote] as $lane => $result) {
    $assert($result['exit'] === 2, 'doctor_' . $lane . '_must_block');
    $payload = $decodeJson($result['stdout'], 'doctor_' . $lane);
    $assert(
        ($payload['ok'] ?? true) === false
        && ($payload['state'] ?? '') === 'blocked'
        && ($payload['read_only'] ?? false) === true
        && (int) ($payload['http_calls'] ?? -1) === 0
        && ($payload['schema']['database_server']['supported'] ?? false) === true
        && ($payload['schema']['missing_tables'] ?? ['unknown']) === [],
        'doctor_' . $lane . '_schema293_contract_failed'
    );
}

$after = $databaseSnapshot($pdo);
$assert($after === $before, 'n1_smoke_database_delta');

echo 'PASS hf12_n1_schema293_stop_rehearsal'
    . ' tracked=' . $trackedFiles
    . ' tables=' . count($before)
    . ' v2=STOPPED v3_local=DISABLED v3_remote=DISABLED'
    . ' doctors=BLOCKED http=0 claims=0 db_delta=0' . PHP_EOL;
