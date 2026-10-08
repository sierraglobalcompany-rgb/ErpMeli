<?php

declare(strict_types=1);

$migrationPath = __DIR__ . '/../database/migrations/304_api_flight_recorder_phase1_core.sql';
$sql = is_file($migrationPath) ? file_get_contents($migrationPath) : false;
if (!is_string($sql)) {
    throw new RuntimeException('RED: additive Flight Recorder migration 304 is missing.');
}

$exactShape = static function (string $candidate): bool {
    $withoutComments = preg_replace('/^\s*--.*$/m', '', $candidate);
    if (!is_string($withoutComments)) {
        return false;
    }
    $normalized = preg_replace('/\s+/', ' ', trim($withoutComments));
    if (!is_string($normalized)) {
        return false;
    }
    return preg_match(
        '/^ALTER TABLE api_request_logs ADD COLUMN trace_id VARCHAR\(64\) NULL AFTER request_id, ADD COLUMN physical_started_at_process DATETIME\(3\) NULL AFTER trace_id, ADD COLUMN rate_limit_headers_json TEXT NULL AFTER physical_started_at_process;$/iD',
        $normalized
    ) === 1;
};

if (!$exactShape($sql)) {
    throw new RuntimeException('Migration 304 must contain exactly the three expected nullable columns in one statement.');
}
foreach ([
    preg_replace('/ADD COLUMN trace_id VARCHAR\(64\) NULL AFTER request_id,\s*/i', '', $sql) ?: '',
    $sql . "\nALTER TABLE unrelated ADD COLUMN extra INT NULL;",
    preg_replace('/;\s*$/', '; UPDATE api_request_logs SET trace_id=request_id;', $sql) ?: '',
    preg_replace('/;\s*$/', '; DROP TABLE api_request_logs;', $sql) ?: '',
    preg_replace('/;\s*$/', '; DELETE FROM api_request_logs;', $sql) ?: '',
    preg_replace('/;\s*$/', '; TRUNCATE TABLE api_request_logs;', $sql) ?: '',
    preg_replace('/;\s*$/', '; RENAME TABLE api_request_logs TO api_request_logs_old;', $sql) ?: '',
    preg_replace('/;\s*$/', '; CREATE TABLE recorder_extra(id INT);', $sql) ?: '',
    preg_replace('/;\s*$/', '; CREATE INDEX idx_recorder ON api_request_logs(trace_id);', $sql) ?: '',
    str_replace('TEXT NULL', 'TEXT NOT NULL', $sql),
    str_replace('rate_limit_headers_json TEXT NULL AFTER physical_started_at_process;', 'rate_limit_headers_json TEXT NULL AFTER physical_started_at_process, ADD INDEX idx_trace (trace_id);', $sql),
] as $mutant) {
    if ($exactShape($mutant)) {
        throw new RuntimeException('Migration 304 shape validator accepted a forbidden mutation.');
    }
}

if (in_array('--mysql', $argv, true)) {
    require __DIR__ . '/k1b_bootstrap.php';
    require __DIR__ . '/K1dSafeTestDatabase.php';
    $root = rtrim(str_replace('\\', '/', dirname(__DIR__) . '/storage/codex-flight-recorder-phase1'), '/');
    foreach ([
        'APP_ENV' => 'test',
        'ML_WRITE_ENABLED' => 'false',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '33079',
        'DB_USER' => 'root',
        'DB_PASS' => '',
        'DB_NAME' => 'erp_meli_k1d_test_fr304_' . bin2hex(random_bytes(4)),
        'CALLS_VERIFY_QA_ROOT' => $root,
    ] as $key => $value) {
        putenv($key . '=' . $value);
    }
    $db = K1dSafeTestDatabase::createFromEnvironment();
    try {
        $pdo = $db->pdo();
        (new App\Services\Migrator($pdo, __DIR__ . '/../database/migrations'))->run(301);
        $before = $pdo->query('SHOW COLUMNS FROM api_request_logs')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('trace_id', $before, true)) {
            throw new RuntimeException('Isolated baseline unexpectedly contains migration 304 columns.');
        }
        $pdo->exec($sql);
        $after = $pdo->query('SHOW COLUMNS FROM api_request_logs')->fetchAll(PDO::FETCH_ASSOC);
        $byName = [];
        foreach ($after as $column) {
            $byName[$column['Field']] = $column;
        }
        foreach (['trace_id' => 'varchar(64)', 'physical_started_at_process' => 'datetime(3)', 'rate_limit_headers_json' => 'text'] as $name => $type) {
            if (strtolower((string) ($byName[$name]['Type'] ?? '')) !== $type || ($byName[$name]['Null'] ?? '') !== 'YES') {
                throw new RuntimeException('Migration 304 column type/nullability mismatch: ' . $name);
            }
        }
        $pdo->exec("INSERT INTO api_request_logs(meli_account_id,request_id,method,endpoint_path,http_status,duration_ms,retry_after_seconds,attempt,was_blocked,safe_message) VALUES(NULL,'legacy-insert','GET','/users/me',200,1,NULL,1,0,NULL)");
        $pdo->exec("INSERT INTO api_request_logs(meli_account_id,request_id,trace_id,physical_started_at_process,rate_limit_headers_json,method,endpoint_path,http_status,duration_ms,retry_after_seconds,attempt,was_blocked,safe_message) VALUES(NULL,'phase1-insert','trace:one','2026-10-07 12:00:00.123','{\"retry-after\":\"1\"}','GET','/users/me',200,1,NULL,1,0,NULL)");
        if ((int) $pdo->query("SELECT COUNT(*) FROM api_request_logs WHERE request_id IN ('legacy-insert','phase1-insert')")->fetchColumn() !== 2) {
            throw new RuntimeException('Legacy and new primary API log INSERTs did not persist.');
        }
        echo "FLIGHT_RECORDER_PHASE1_MYSQL_MIGRATION_OK\n";
    } finally {
        $db->cleanup();
    }
}

echo "FLIGHT_RECORDER_PHASE1_MIGRATION_OK\n";
