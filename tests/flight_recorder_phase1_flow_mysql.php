<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';

use App\Core\Crypto;
use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\Services\ApiExecutionMetadataContext;
use App\Services\ApiFlightRecorderConfig;
use App\Services\MeliApiClient;
use App\Services\MeliHttpTransportInterface;

final class FlightRecorderFlowTransport implements MeliHttpTransportInterface
{
    /** @var list<array<string,mixed>> */
    public static array $responses = [];
    /** @var list<array<string,scalar|null>> */
    public static array $calls = [];
    public static ?Closure $afterResponse = null;

    public function request(string $method, string $url, array $data, array $headers, bool $form, array $timeouts): array
    {
        self::$calls[] = ApiExecutionMetadataContext::current();
        $response = array_shift(self::$responses);
        if (!is_array($response)) {
            throw new RuntimeException('Unexpected fake transport call.');
        }
        if (self::$afterResponse !== null) {
            $hook = self::$afterResponse;
            self::$afterResponse = null;
            $hook();
        }
        return $response;
    }
}

$root = rtrim(str_replace('\\', '/', (string) (getenv('CALLS_VERIFY_QA_ROOT') ?: dirname(__DIR__) . '/storage/codex-flight-recorder-phase1')), '/');
foreach ([
    'APP_ENV' => 'test',
    'ML_WRITE_ENABLED' => 'false',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '33079',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'DB_NAME' => 'erp_meli_k1d_test_flight_recorder_' . bin2hex(random_bytes(4)),
    'APP_KEY' => 'flight-recorder-disposable-test-only',
    'PRIVATE_STORAGE_PATH' => $root . '/private',
    'CALLS_VERIFY_QA_ROOT' => $root,
    'MELI_API_BASE' => 'https://flight-recorder-wire.invalid',
] as $key => $value) {
    putenv($key . '=' . $value);
}
if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) {
    throw new RuntimeException('Flight Recorder local test root is unavailable.');
}
define('ERP_INSTALLATION_ROOT', $root . '/install-' . bin2hex(random_bytes(4)));
mkdir(ERP_INSTALLATION_ROOT, 0770, true);

$db = K1dSafeTestDatabase::createFromEnvironment();
$failures = [];
$check = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
        echo 'EXPECTED_RED=' . $label . "\n";
    }
};
$resetConfig = static function (): void {
    $property = new ReflectionProperty(ApiFlightRecorderConfig::class, 'processConfig');
    $property->setValue(null, null);
};
$runClient = static function (callable $callback): mixed {
    QueueV4CleanCycleBudget::start(3, 'manual', microtime(true) + 45);
    try {
        return $callback();
    } finally {
        QueueV4CleanCycleBudget::clear();
    }
};
$setSetting = static function (PDO $pdo, string $key, string $value): void {
    $pdo->prepare('INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group) VALUES(?,?,0,\'api\') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=0')
        ->execute([$key, $value]);
};
$clearCycle = static function (PDO $pdo): void {
    $pdo->exec('DELETE FROM api_remote_permits');
    $pdo->exec('DELETE FROM api_request_logs');
    $pdo->exec('DELETE FROM api_rhythm_penalties');
    $pdo->exec("UPDATE api_rhythm_states SET next_allowed_at=NULL,block_pause_until=NULL,calls_in_block=0 WHERE scope_key='global'");
    App\Services\AppSettingsService::clearCache();
};
$baseResponse = static fn (int $status = 200): array => [
    'status' => $status,
    'body' => $status >= 500 ? ['message' => 'fixture temporary failure'] : ['id' => 99011],
    'headers' => ['retry-after' => '1'],
    'curl_error' => '',
    'duration_ms' => 2,
    'wire_bytes' => 20,
    'decoded_bytes' => 20,
    'raw_body' => '{"id":99011}',
    'physical_started_at_process' => '2026-10-07 12:00:00.123',
    'rate_limit_headers_json' => '{"retry-after":"1","x-ratelimit-remaining":"7"}',
];

try {
    $pdo = $db->pdo();
    (new App\Services\Migrator($pdo, __DIR__ . '/../database/migrations'))->run(301);
    $migration = file_get_contents(__DIR__ . '/../database/migrations/304_api_flight_recorder_phase1_core.sql');
    if (!is_string($migration)) {
        throw new RuntimeException('Could not read migration 304.');
    }
    $columns = $pdo->query('SHOW COLUMNS FROM api_request_logs')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('trace_id', $columns, true)) {
        $pdo->exec($migration);
    }
    $migrationColumns = $pdo->query('SHOW COLUMNS FROM api_request_logs')->fetchAll(PDO::FETCH_ASSOC);
    $migrationByName = [];
    foreach ($migrationColumns as $column) {
        $migrationByName[$column['Field']] = $column;
    }
    foreach (['trace_id' => 'varchar(64)', 'physical_started_at_process' => 'datetime(3)', 'rate_limit_headers_json' => 'text'] as $name => $type) {
        $check(strtolower((string) ($migrationByName[$name]['Type'] ?? '')) === $type
            && ($migrationByName[$name]['Null'] ?? '') === 'YES', 'migration has nullable ' . $name);
    }
    $pdo->exec("INSERT INTO api_request_logs(meli_account_id,request_id,method,endpoint_path,http_status,duration_ms,retry_after_seconds,attempt,was_blocked,safe_message) VALUES(NULL,'legacy-migration-insert','GET','/users/me',200,1,NULL,1,0,NULL)");
    $check((int) $pdo->query("SELECT COUNT(*) FROM api_request_logs WHERE request_id='legacy-migration-insert'")->fetchColumn() === 1, 'old-style api request log insert remains valid after 304');
    $pdo->exec("DELETE FROM api_request_logs WHERE request_id='legacy-migration-insert'");

    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9001,'Flight Recorder Phase 1',1)");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9011,9001,'Flight Recorder Phase 1',99011,'conectado')");
    $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at) VALUES(9011,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))')
        ->execute([Crypto::encrypt('flight-recorder-test-access'), Crypto::encrypt('flight-recorder-test-refresh')]);
    $setSetting($pdo, 'api.trace.mode', 'basic');
    $setSetting($pdo, 'api.trace.diagnostic_until', '2026-10-07T12:05:00Z');
    $setSetting($pdo, 'api.guard.jitter_min_ms', '0');
    $setSetting($pdo, 'api.guard.jitter_max_ms', '0');
    $setSetting($pdo, 'api.rhythm.short_wait_ceiling_ms', '1500');
    App\Services\AppSettingsService::clearCache();

    // Active end-to-end flow: caller lifecycle context -> API client -> fake transport -> primary DB writer.
    $clearCycle($pdo);
    $resetConfig();
    FlightRecorderFlowTransport::$responses = [$baseResponse()];
    FlightRecorderFlowTransport::$calls = [];
    $activeTrace = 'flight:run-7:job-21';
    $runClient(static fn (): array => ApiExecutionMetadataContext::withTechnicalOperation('oauth_profile', static fn (): array => ApiExecutionMetadataContext::run(
        ['source' => 'web', 'company_id' => 9001, 'account_id' => 9011, 'trace_id' => $activeTrace],
        static fn (): array => (new MeliApiClient(9011, new FlightRecorderFlowTransport()))->get('/users/me')
    )));
    $activeRow = $pdo->query("SELECT request_id,trace_id,physical_started_at_process,rate_limit_headers_json FROM api_request_logs WHERE endpoint_path='/users/me' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $check(($activeRow['trace_id'] ?? null) === $activeTrace, 'active trace reaches primary writer');
    $check(($activeRow['physical_started_at_process'] ?? null) === '2026-10-07 12:00:00.123', 'active physical start reaches primary writer');
    $check(($activeRow['rate_limit_headers_json'] ?? null) === '{"retry-after":"1","x-ratelimit-remaining":"7"}', 'active safe headers reach primary writer');
    $check((FlightRecorderFlowTransport::$calls[0]['trace_id'] ?? null) === $activeTrace
        && (FlightRecorderFlowTransport::$calls[0]['flight_recorder_mode'] ?? null) === 'basic', 'active metadata reaches transport context');

    // OFF ignores even supplied fake transport evidence and caller trace metadata.
    $clearCycle($pdo);
    $setSetting($pdo, 'api.trace.mode', 'off');
    $resetConfig();
    FlightRecorderFlowTransport::$responses = [$baseResponse()];
    $runClient(static fn (): array => ApiExecutionMetadataContext::withTechnicalOperation('oauth_profile', static fn (): array => ApiExecutionMetadataContext::run(
        ['source' => 'web', 'company_id' => 9001, 'account_id' => 9011, 'trace_id' => $activeTrace],
        static fn (): array => (new MeliApiClient(9011, new FlightRecorderFlowTransport()))->get('/users/me')
    )));
    $offRow = $pdo->query("SELECT trace_id,physical_started_at_process,rate_limit_headers_json FROM api_request_logs WHERE endpoint_path='/users/me' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $check(($offRow['trace_id'] ?? null) === null && ($offRow['physical_started_at_process'] ?? null) === null
        && ($offRow['rate_limit_headers_json'] ?? null) === null, 'off writer receives null for all recorder fields');
    $check((FlightRecorderFlowTransport::$calls[array_key_last(FlightRecorderFlowTransport::$calls)]['flight_recorder_mode'] ?? null) === 'off', 'off mode reaches transport context');

    // Pretransport policy pause records a new attempt without calling the fake transport.
    $clearCycle($pdo);
    $setSetting($pdo, 'api.trace.mode', 'basic');
    $resetConfig();
    $pdo->exec("UPDATE api_rhythm_states SET block_pause_until=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 10 MINUTE) WHERE scope_key='global'");
    $callsBefore = count(FlightRecorderFlowTransport::$calls);
    $pretransport = null;
    try {
        $runClient(static fn (): array => ApiExecutionMetadataContext::withTechnicalOperation('oauth_profile', static fn (): array => ApiExecutionMetadataContext::run(
            ['source' => 'web', 'company_id' => 9001, 'account_id' => 9011, 'trace_id' => $activeTrace],
            static fn (): array => (new MeliApiClient(9011, new FlightRecorderFlowTransport()))->get('/users/me')
        )));
    } catch (App\Services\ApiRhythmDeferredException $blocked) {
        $pretransport = $blocked;
    }
    $preRow = $pdo->query("SELECT trace_id,physical_started_at_process,rate_limit_headers_json,was_blocked FROM api_request_logs WHERE endpoint_path='/users/me' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $check($pretransport instanceof App\Services\ApiRhythmDeferredException && count(FlightRecorderFlowTransport::$calls) === $callsBefore, 'pretransport block never calls transport');
    $check(($preRow['physical_started_at_process'] ?? null) === null && ($preRow['rate_limit_headers_json'] ?? null) === null
        && (int) ($preRow['was_blocked'] ?? 0) === 1, 'pretransport writer has no physical evidence');

    // Retry contamination: a first known 503 has evidence; the next attempt is paused before transport.
    $clearCycle($pdo);
    $resetConfig();
    FlightRecorderFlowTransport::$responses = [$baseResponse(503)];
    FlightRecorderFlowTransport::$calls = [];
    FlightRecorderFlowTransport::$afterResponse = static function () use ($pdo): void {
        $pdo->exec("UPDATE api_rhythm_states SET block_pause_until=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 10 MINUTE) WHERE scope_key='global'");
    };
    $retryError = null;
    try {
        $runClient(static fn (): array => ApiExecutionMetadataContext::withTechnicalOperation('oauth_profile', static fn (): array => ApiExecutionMetadataContext::run(
            ['source' => 'web', 'company_id' => 9001, 'account_id' => 9011, 'trace_id' => $activeTrace],
            static fn (): array => (new MeliApiClient(9011, new FlightRecorderFlowTransport()))->get('/users/me')
        )));
    } catch (Throwable $error) {
        $retryError = $error;
    }
    $retryRows = $pdo->query("SELECT request_id,trace_id,physical_started_at_process,rate_limit_headers_json,attempt,was_blocked FROM api_request_logs WHERE endpoint_path='/users/me' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $firstRetry = $retryRows[0] ?? [];
    $secondRetry = $retryRows[1] ?? [];
    $check($retryError instanceof App\Services\ApiRhythmDeferredException && count($retryRows) === 2, 'retry reaches a pretransport blocked second attempt');
    $check(($firstRetry['physical_started_at_process'] ?? null) === '2026-10-07 12:00:00.123'
        && ($firstRetry['rate_limit_headers_json'] ?? null) === '{"retry-after":"1","x-ratelimit-remaining":"7"}', 'first retry attempt persists its physical evidence');
    $check(($secondRetry['physical_started_at_process'] ?? null) === null && ($secondRetry['rate_limit_headers_json'] ?? null) === null, 'second pretransport retry does not inherit physical evidence');
    $check(($secondRetry['trace_id'] ?? null) === $activeTrace, 'lifecycle trace survives retry');
    $check(($firstRetry['request_id'] ?? null) !== ($secondRetry['request_id'] ?? null), 'each retry gets its own request ID');

    if ($failures !== []) {
        throw new RuntimeException('flight_recorder_flow_failures=' . implode(',', $failures));
    }
    echo "FLIGHT_RECORDER_PHASE1_FLOW_OK\n";
} finally {
    FlightRecorderFlowTransport::$afterResponse = null;
    QueueV4CleanCycleBudget::clear();
    $db->cleanup();
}
