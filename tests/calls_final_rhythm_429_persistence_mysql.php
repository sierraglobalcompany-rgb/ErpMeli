<?php
declare(strict_types=1);

define('ERP_SHARED_ROOT', sys_get_temp_dir() . '/erp-calls-rhythm-429-' . getmypid());
define('ERP_INSTALLATION_ROOT', ERP_SHARED_ROOT . '/install');
if (!is_dir(ERP_INSTALLATION_ROOT)) {
    mkdir(ERP_INSTALLATION_ROOT, 0777, true);
}

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';
require __DIR__ . '/calls_transport_wire_fixture.php';

use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\Services\ApiExecutionMetadataContext;
use App\Services\AppSettingsService;
use App\Services\Cap2DomainsWire;
use App\Services\MeliApiClient;

foreach ([
    'APP_ENV' => 'test',
    'ML_WRITE_ENABLED' => 'false',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '33079',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'APP_KEY' => 'calls-final-rhythm-429-test-key',
    'PRIVATE_STORAGE_PATH' => ERP_SHARED_ROOT . '/private',
    'MELI_API_BASE' => 'https://calls-rhythm-429.invalid',
] as $key => $value) {
    putenv($key . '=' . $value);
}

final class CallsFinalRhythm429Fault
{
    public static bool $armed = false;
    public static int $failedTransactions = 0;
}

final class CallsFinalRhythm429Pdo extends PDO
{
}

final class CallsFinalRhythm429Statement extends PDOStatement
{
    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        if (CallsFinalRhythm429Fault::$armed
            && CallsFinalRhythm429Fault::$failedTransactions < 2
            && str_contains($this->queryString, 'UPDATE api_rhythm_states')
            && str_contains($this->queryString, 'block_pause_until')) {
            CallsFinalRhythm429Fault::$failedTransactions++;
            throw new PDOException('CALLS_FINAL_RHYTHM_429_PERSISTENCE_FAILURE');
        }
        return parent::execute($params);
    }
}

function calls_final_rhythm_429_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string,mixed> */
function calls_final_rhythm_429_meta(int $jobId, int $attemptId, string $owner): array
{
    return [
        'source' => 'queue_v4_clean',
        'company_id' => 9001,
        'account_id' => 9011,
        'queue_v4_job_id' => $jobId,
        'queue_v4_attempt_id' => $attemptId,
        'queue_v4_lease_owner' => $owner,
        'queue_v4_lease_generation' => 1,
    ];
}

function calls_final_rhythm_429_child(string $mode): void
{
    $pdo = Database::connectionFresh();
    $owner = $mode === 'first' ? 'calls-final-first' : 'calls-final-second';
    $budgetOwner = $mode === 'first' ? 'manual' : 'automatic';
    $key = bin2hex(random_bytes(20));
    $pdo->prepare("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,lease_owner,lease_generation,lease_expires_at) VALUES(9001,9011,'order_exact','8101',?,'{}','running',?,1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))")
        ->execute([$key, $owner]);
    $jobId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO queue_v4_clean_attempts(job_id,run_id,company_id,meli_account_id,lease_owner,lease_generation) VALUES(?,9998,9001,9011,?,1)")
        ->execute([$jobId, $owner]);
    $attemptId = (int) $pdo->lastInsertId();

    Cap2DomainsWire::$responses['/orders/8101'] = [429, ['message' => 'rate limited']];
    if ($mode === 'first') {
        $fault = new CallsFinalRhythm429Pdo(
            'mysql:host=127.0.0.1;port=33079;dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
            'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $fault->exec("SET time_zone='+00:00'");
        $fault->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CallsFinalRhythm429Statement::class]);
        Database::setConnection($fault);
        Cap2DomainsWire::$onWire = static function (): void { CallsFinalRhythm429Fault::$armed = true; };
    }

    QueueV4CleanCycleBudget::start(1, $budgetOwner, microtime(true) + 45);
    $error = null;
    try {
        ApiExecutionMetadataContext::run(
            calls_final_rhythm_429_meta($jobId, $attemptId, $owner),
            static fn () => (new MeliApiClient(9011))->get('/orders/8101')
        );
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        Cap2DomainsWire::$onWire = null;
        QueueV4CleanCycleBudget::clear();
    }

    if ($mode === 'first') {
        $real = new PDO('mysql:host=127.0.0.1;port=33079;dbname=' . getenv('DB_NAME') . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $real->exec("SET time_zone='+00:00'");
        $permit = $real->query("SELECT CONCAT(status,':',COALESCE(http_status,'NULL')) FROM api_remote_permits ORDER BY id DESC LIMIT 1")->fetchColumn();
        calls_final_rhythm_429_assert(count(Cap2DomainsWire::$calls) === 1, 'FIRST_PROCESS_WIRE_NOT_ONE');
        calls_final_rhythm_429_assert(CallsFinalRhythm429Fault::$failedTransactions >= 1, 'FIRST_PERSISTENCE_FAILURE_NOT_CONTROLLED');
        calls_final_rhythm_429_assert($permit === 'dispatched:429', 'FIRST_SENT_PERMIT_NOT_RECONCILABLE:' . (string) $permit);
        calls_final_rhythm_429_assert((string) $real->query("SELECT COALESCE(block_pause_until,'') FROM api_rhythm_states WHERE scope_key='global'")->fetchColumn() === '', 'FIRST_PAUSE_UNEXPECTEDLY_PERSISTED');
        $real->exec("UPDATE api_remote_permits SET expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE status='dispatched' AND http_status=429");
        echo "FIRST_PROCESS_WIRE=1\nFIRST_STATUS=429\n";
        return;
    }

    calls_final_rhythm_429_assert(count(Cap2DomainsWire::$calls) === 0, 'SECOND_PROCESS_WIRE_NOT_ZERO:' . ($error?->getMessage() ?? 'none'));
    echo "SECOND_PROCESS_WIRE=0\n";
}

if (($argv[1] ?? '') === 'first' || ($argv[1] ?? '') === 'second') {
    calls_final_rhythm_429_child((string) $argv[1]);
    exit(0);
}

$database = 'erp_meli_k1d_test_calls_final_rhythm_429_' . bin2hex(random_bytes(4));
putenv('DB_NAME=' . $database);
$h = K1dSafeTestDatabase::createFromEnvironment();
$repo = dirname(__DIR__);

try {
    $pdo = $h->pdo();
    (new App\Services\Migrator($pdo, $repo . '/database/migrations'))->run(301);
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9001,'Rhythm 429',1)");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9011,9001,'Rhythm 429',99011,'conectado')");
    $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at) VALUES(9011,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))')
        ->execute([App\Core\Crypto::encrypt('test-access'), App\Core\Crypto::encrypt('test-refresh')]);
    $pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE',readiness_state='CERTIFIED' WHERE control_key='primary'");
    $pdo->exec("UPDATE queue_engine_control SET active_engine='v4'");
    $pdo->exec("UPDATE app_settings SET setting_value='0' WHERE setting_key='alerts.email.enabled'");
    AppSettingsService::clearCache();

    foreach (['first', 'second'] as $mode) {
        $lines = [];
        $exit = 0;
        exec('"' . PHP_BINARY . '" "' . __FILE__ . '" ' . $mode, $lines, $exit);
        calls_final_rhythm_429_assert($exit === 0, strtoupper($mode) . '_PROCESS_FAILED:' . implode("\n", $lines));
        $output = implode("\n", $lines);
        if ($mode === 'first') {
            calls_final_rhythm_429_assert(str_contains($output, 'FIRST_PROCESS_WIRE=1') && str_contains($output, 'FIRST_STATUS=429'), 'FIRST_PROCESS_OUTPUT:' . $output);
        } else {
            calls_final_rhythm_429_assert(str_contains($output, 'SECOND_PROCESS_WIRE=0'), 'SECOND_PROCESS_OUTPUT:' . $output);
        }
    }

    echo "CALLS_AFTER_FIRST_429=0\nFAIL_CLOSED_DURABLE_OR_RECONCILABLE=YES\nSTATUS=PASS CALLS_FINAL_RHYTHM_429_PERSISTENCE MYSQL=REAL REAL_MELI_HTTP=0\n";
} finally {
    $h->cleanup();
}
