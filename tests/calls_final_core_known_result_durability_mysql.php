<?php
declare(strict_types=1);

define('ERP_SHARED_ROOT', sys_get_temp_dir() . '/erp-calls-core-known-' . getmypid());
define('ERP_INSTALLATION_ROOT', ERP_SHARED_ROOT . '/install');
if (!is_dir(ERP_INSTALLATION_ROOT)) {
    mkdir(ERP_INSTALLATION_ROOT, 0777, true);
}

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';
require __DIR__ . '/cap2_transport_wire_fixture.php';

use App\Core\Database;
use App\QueueCore\QueueCoreRepository;
use App\QueueCore\QueueJob;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\Services\ApiExecutionMetadataContext;
use App\Services\ApiRhythmDeferredException;
use App\Services\AppSettingsService;
use App\Services\Cap2DomainsWire;
use App\Services\CronDeadlineContext;
use App\Services\MeliApiClient;
use App\Services\RemoteResultUncertainException;

foreach ([
    'APP_ENV' => 'test',
    'ML_WRITE_ENABLED' => 'false',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '33079',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'DB_NAME' => 'erp_meli_k1d_test_core_known_' . bin2hex(random_bytes(4)),
    'APP_KEY' => 'calls-final-core-known-test-key',
    'PRIVATE_STORAGE_PATH' => ERP_SHARED_ROOT . '/private',
    'MELI_API_BASE' => 'https://calls-core-known.invalid',
] as $key => $value) {
    putenv($key . '=' . $value);
}

final class CallsFinalCoreKnownFault
{
    public static string $mode = '';
    public static bool $triggered = false;
}

final class CallsFinalCoreKnownPdo extends PDO
{
}

final class CallsFinalCoreKnownStatement extends PDOStatement
{
    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        if (CallsFinalCoreKnownFault::$mode === 'known_before'
            && !CallsFinalCoreKnownFault::$triggered
            && str_contains($this->queryString, "UPDATE queue_core_jobs SET dispatch_state='DISPATCHED_RESULT_KNOWN'")) {
            CallsFinalCoreKnownFault::$triggered = true;
            throw new PDOException('CALLS_FINAL_CORE_KNOWN_WRITE_FAILED');
        }

        return parent::execute($params);
    }
}

function calls_final_core_known_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function calls_final_core_known_seed(PDO $pdo, string $repo): void
{
    (new App\Services\Migrator($pdo, $repo . '/database/migrations'))->run(301);
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9001,'Core known',1)");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9011,9001,'Core known',99011,'conectado')");
    $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at) VALUES(9011,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))')
        ->execute([App\Core\Crypto::encrypt('test-access'), App\Core\Crypto::encrypt('test-refresh')]);
    $pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE',readiness_state='CERTIFIED' WHERE control_key='primary'");
    $pdo->exec("UPDATE queue_engine_control SET active_engine='v4'");
    $pdo->exec("UPDATE app_settings SET setting_value='0' WHERE setting_key='alerts.email.enabled'");
}

function calls_final_core_known_reset(PDO $pdo): void
{
    $pdo->exec('DELETE FROM api_remote_permits');
    $pdo->exec('DELETE FROM api_request_logs');
    $pdo->exec('DELETE FROM api_rhythm_penalties');
    $pdo->exec('DELETE FROM api_circuit_breakers');
    $pdo->exec("UPDATE api_rhythm_states SET next_allowed_at=NULL,block_pause_until=NULL,calls_in_block=0 WHERE scope_key='global'");
    CallsFinalCoreKnownFault::$mode = '';
    CallsFinalCoreKnownFault::$triggered = false;
    Cap2DomainsWire::$responses = [];
    Cap2DomainsWire::$calls = [];
    AppSettingsService::clearCache();
    QueueV4CleanCycleBudget::clear();
    CronDeadlineContext::clear();
}

function calls_final_core_known_meta(PDO $pdo): array
{
    $repo = new QueueCoreRepository($pdo);
    $jobId = $repo->enqueue(new QueueJob(
        9001,
        9011,
        'manual_exact',
        'remote',
        '8101',
        'normal',
        0,
        bin2hex(random_bytes(20)),
        '1',
        'test',
        null,
        [],
        [],
        1
    ));
    $pdo->exec("UPDATE queue_core_jobs SET state='running',lease_owner='calls-final',lease_generation=1,lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE) WHERE id={$jobId} AND company_id=9001 AND meli_account_id=9011");
    $pdo->exec("INSERT INTO queue_core_attempts(job_id,company_id,meli_account_id,lease_owner,lease_generation,launcher) VALUES({$jobId},9001,9011,'calls-final',1,'manual')");

    return [
        'source' => 'queue_core',
        'company_id' => 9001,
        'account_id' => 9011,
        'queue_core_launcher' => 'manual',
        'queue_core_capability_launcher' => 'manual',
        'queue_core_domain' => 'manual',
        'queue_core_uses_api' => 1,
        'queue_core_max_remote_calls' => 1,
        'queue_core_expected_method' => 'GET',
        'queue_core_expected_endpoint_pattern' => '#^/orders/8101$#D',
        'queue_core_expected_operation' => 'order_exact',
        'queue_core_job_id' => $jobId,
        'queue_core_attempt_id' => (int) $pdo->lastInsertId(),
        'queue_core_lease_owner' => 'calls-final',
        'queue_core_lease_generation' => 1,
        'queue_core_work_type' => 'manual_exact',
    ];
}

function calls_final_core_known_call(array $meta): ?Throwable
{
    try {
        ApiExecutionMetadataContext::run(
            $meta,
            static fn () => (new MeliApiClient(9011))->get('/orders/8101')
        );

        return null;
    } catch (Throwable $error) {
        return $error;
    }
}

$h = K1dSafeTestDatabase::createFromEnvironment();
$repo = dirname(__DIR__);

try {
    $pdo = $h->pdo();
    calls_final_core_known_seed($pdo, $repo);
    $faultPdo = new CallsFinalCoreKnownPdo(
        'mysql:host=127.0.0.1;port=33079;dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
        'root',
        '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $faultPdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CallsFinalCoreKnownStatement::class]);
    $faultPdo->exec("SET time_zone='+00:00'");

    foreach ([429, 200] as $status) {
        calls_final_core_known_reset($pdo);
        Database::setConnection($faultPdo);
        CallsFinalCoreKnownFault::$mode = 'known_before';
        Cap2DomainsWire::$responses['/orders/8101'] = [$status, ['id' => 8101, 'message' => 'known-result']];
        QueueV4CleanCycleBudget::start(3, 'manual', microtime(true) + 45);
        $before = count(Cap2DomainsWire::$calls);
        $error = calls_final_core_known_call(calls_final_core_known_meta($pdo));
        $snapshot = QueueV4CleanCycleBudget::snapshot();
        Database::setConnection($pdo);

        calls_final_core_known_check(CallsFinalCoreKnownFault::$triggered, 'core_known_fault_triggered_' . $status);
        calls_final_core_known_check(count(Cap2DomainsWire::$calls) - $before === 1, 'core_known_wire_once_' . $status);
        calls_final_core_known_check($snapshot['physical_http_calls'] === 1 && $snapshot['physical_http_calls_certainty'] === 'CERTIFIED', 'core_known_physical_certified_' . $status);
        calls_final_core_known_check(!($error instanceof PDOException), 'core_known_no_raw_pdo_' . $status);

        if ($status === 429) {
            calls_final_core_known_check($error instanceof ApiRhythmDeferredException, 'core_known_429_returns_safe_defer');
            calls_final_core_known_check($snapshot['stopped_reason'] === 'remote_429_global_pause', 'core_known_429_stops_cycle');
            $paused = (string) $pdo->query("SELECT COALESCE(block_pause_until,'') FROM api_rhythm_states WHERE scope_key='global'")->fetchColumn();
            calls_final_core_known_check($paused !== '', 'core_known_429_persists_global_pause');

            $pdo->exec("UPDATE api_remote_permits SET expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE status IN ('reserved','dispatched','completed','expired')");
            usleep(1200000);
            QueueV4CleanCycleBudget::clear();
            QueueV4CleanCycleBudget::start(3, 'manual', microtime(true) + 45);
            $laterBefore = count(Cap2DomainsWire::$calls);
            $later = calls_final_core_known_call(calls_final_core_known_meta($pdo));
            calls_final_core_known_check($later instanceof ApiRhythmDeferredException, 'core_known_429_later_still_deferred');
            calls_final_core_known_check(count(Cap2DomainsWire::$calls) === $laterBefore, 'core_known_429_later_zero_wire');
        } else {
            calls_final_core_known_check($error instanceof RemoteResultUncertainException, 'core_known_200_returns_uncertain');
            calls_final_core_known_check($snapshot['stopped_reason'] === 'remote_result_uncertain', 'core_known_200_stops_cycle');
            $permitStatus = $pdo->query("SELECT CONCAT(status,':',COALESCE(http_status,'NULL')) FROM api_remote_permits ORDER BY id DESC LIMIT 1")->fetchColumn();
            calls_final_core_known_check($permitStatus === 'completed:200' || $permitStatus === 'expired:200', 'core_known_200_keeps_known_physical_permit:' . (string) $permitStatus);
        }
    }

    echo "STATUS=PASS CALLS_FINAL_CORE_KNOWN_RESULT_DURABILITY MYSQL=REAL REAL_MELI_HTTP=0\n";
} finally {
    QueueV4CleanCycleBudget::clear();
    CronDeadlineContext::clear();
    if (isset($pdo)) {
        Database::setConnection($pdo);
    }
    $h->cleanup();
}
