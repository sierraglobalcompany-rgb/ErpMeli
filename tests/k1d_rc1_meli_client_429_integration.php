<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';

use App\Core\Crypto;
use App\Core\Database;
use App\Services\ApiRhythmDeferredException;
use App\Services\AppSettingsService;
use App\Services\MeliApiClient;
use App\Services\MeliHttpTransportInterface;
use App\Services\Migrator;

putenv('APP_ENV=test');
putenv('ML_WRITE_ENABLED=false');
putenv('APP_KEY=base64:' . base64_encode(str_repeat('r', 32)));
putenv('MELI_API_BASE=http://127.0.0.1:9');
putenv('DB_HOST=' . (getenv('DB_HOST') ?: '127.0.0.1'));
putenv('DB_PORT=' . (getenv('DB_PORT') ?: '3306'));
putenv('DB_USER=' . (getenv('DB_USER') ?: 'root'));
putenv('DB_PASS=' . (string) getenv('DB_PASS'));
putenv('DB_NAME=erp_meli_k1d_test_rc1_client_' . strtolower(bin2hex(random_bytes(4))));

final class K1dRc1FakeMeliTransport implements MeliHttpTransportInterface
{
    /** @var list<array{method:string,url:string}> */
    public array $ledger = [];

    public function request(
        string $method,
        string $url,
        array $data,
        array $headers,
        bool $form,
        array $timeouts
    ): array {
        $this->ledger[] = ['method' => $method, 'url' => $url];
        return [
            'status' => 429,
            'body' => ['message' => 'fixture 429', 'error' => 'too_many_requests'],
            'headers' => [],
            'curl_error' => '',
            'duration_ms' => 1,
            'wire_bytes' => 64,
            'decoded_bytes' => 64,
        ];
    }
}

$harness = K1dSafeTestDatabase::createFromEnvironment();
$fake = new K1dRc1FakeMeliTransport();
$sourceBTransportInvocations = 0;
$sourceBBlockingScope = '';
$globalPausePersisted = false;
$apiRequestRemoteRows = 0;
$failure = '';

try {
    $pdo = $harness->pdo();
    $migrationPath = realpath(__DIR__ . '/../database/migrations');
    k1b_assert(is_string($migrationPath), 'MIGRATION_PATH_FOUND');
    (new Migrator($pdo, $migrationPath))->run(301);

    $pdo->exec("INSERT INTO companies (id,name,nit,status) VALUES (1,'Empresa QA RC1','RC1-CLIENT',1) ON DUPLICATE KEY UPDATE name=VALUES(name),status=VALUES(status)");
    $pdo->exec("INSERT INTO meli_accounts (id,company_id,account_name,meli_user_id,nickname,site_id,country_id,status) VALUES (2,1,'Cuenta QA RC1',987654321,'CuentaQA','MCO','CO','conectado') ON DUPLICATE KEY UPDATE company_id=VALUES(company_id),account_name=VALUES(account_name),status=VALUES(status)");
    $token = Crypto::encrypt('fixture-access-token');
    $refresh = Crypto::encrypt('fixture-refresh-token');
    $stmt = $pdo->prepare('INSERT INTO meli_tokens (meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,token_type,scope) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE access_token_encrypted=VALUES(access_token_encrypted),refresh_token_encrypted=VALUES(refresh_token_encrypted),expires_at=VALUES(expires_at),token_type=VALUES(token_type),scope=VALUES(scope)');
    $stmt->execute([2, $token, $refresh, gmdate('Y-m-d H:i:s', time() + 86400), 'Bearer', 'read']);

    $settings = new AppSettingsService();
    $settings->set('alerts.email.enabled', '0', 'alerts');
    $settings->set('api.rhythm.minimum_interval_ms', '0', 'api_rhythm');
    $settings->set('api.rhythm.rolling_window_seconds', '60', 'api_rhythm');
    $settings->set('api.rhythm.current_adaptive_limit', '100', 'api_rhythm');
    $settings->set('api.rhythm.shared_429_backoff_seconds', '1800', 'api_rhythm');
    $settings->set('api.rhythm.shared_429_jitter_seconds', '0', 'api_rhythm');
    AppSettingsService::clearCache();

    try {
        (new MeliApiClient(2, $fake))->get('/orders/search', ['seller' => 987654321], [
            'source' => 'queue_v4_clean',
            'job_type' => 'sales',
            'company_id' => 1,
            'source_work_id' => 'rc1-source-a',
            'response_count_strategy' => 'orders_search',
        ]);
    } catch (ApiRhythmDeferredException $deferred) {
        k1b_assert($deferred->blockingScope === 'remote_429_global_pause', 'SOURCE_A_429_DEFERRED_WITH_GLOBAL_SCOPE');
    }

    $pauseUntil = (string) ($pdo->query("SELECT block_pause_until FROM api_rhythm_states WHERE scope_key='global'")->fetchColumn() ?: '');
    $globalPausePersisted = $pauseUntil !== '' && strtotime($pauseUntil . ' UTC') > time();

    try {
        (new MeliApiClient(2, $fake))->get('/orders/search', ['seller' => 987654321], [
            'source' => 'queue_v4_clean',
            'job_type' => 'sales',
            'company_id' => 1,
            'source_work_id' => 'rc1-source-b',
        ]);
    } catch (ApiRhythmDeferredException $deferred) {
        $sourceBBlockingScope = $deferred->blockingScope;
    }
    $sourceBTransportInvocations = max(0, count($fake->ledger) - 1);
    $apiRequestRemoteRows = (int) $pdo->query("SELECT COUNT(*) FROM api_request_logs WHERE endpoint_path='/orders/search' AND http_status=429 AND reached_remote=1")->fetchColumn();
} catch (Throwable $error) {
    $failure = get_class($error) . ':' . preg_replace('/\s+/', ' ', $error->getMessage());
} finally {
    $harness->cleanup();
}

$pass = $failure === ''
    && count($fake->ledger) === 1
    && $sourceBTransportInvocations === 0
    && $sourceBBlockingScope === 'rhythm_block_pause'
    && $globalPausePersisted
    && $apiRequestRemoteRows === 1;

echo 'STATUS=' . ($pass ? 'PASS K1D_RC1_MELI_CLIENT_429_INTEGRATION' : 'BLOCKED K1D_RC1_MELI_CLIENT_429_INTEGRATION') . "\n";
echo "MELI_CLIENT_FAKE_TRANSPORT_USED=YES\n";
echo 'FAKE_TRANSPORT_LEDGER_ROWS=' . count($fake->ledger) . "\n";
echo 'GLOBAL_BLOCK_PAUSE_UNTIL_PERSISTED=' . ($globalPausePersisted ? 'YES' : 'NO') . "\n";
echo "SOURCE_B_BLOCKING_SCOPE={$sourceBBlockingScope}\n";
echo "SOURCE_B_TRANSPORT_INVOCATIONS={$sourceBTransportInvocations}\n";
echo "API_REQUEST_LOGS_REMOTE_429_ROWS={$apiRequestRemoteRows}\n";
if ($failure !== '') {
    echo "FAILURE={$failure}\n";
}
echo "PRODUCTION_CHANGED_BY_CODEX=NO\n";
echo "REAL_MELI_HTTP=0\n";
echo "REAL_EMAIL_SENT=0\n";

exit($pass ? 0 : 20);
