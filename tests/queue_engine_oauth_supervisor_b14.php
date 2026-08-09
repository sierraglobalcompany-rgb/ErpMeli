<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/bootstrap.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
});

use App\Core\Crypto;
use App\Core\Database;
use App\Core\AppPaths;
use App\QueueCore\QueueCapabilityRegistry;
use App\QueueCore\QueueCoreOAuthRefreshHandler;
use App\QueueCore\QueueCoreOAuthSupervisor;
use App\QueueCore\QueueCoreRepository;
use App\QueueCore\QueueEngineControlService;
use App\QueueCore\QueueEngineRuntimePermit;
use App\QueueCore\QueueExecutionContext;
use App\QueueCore\QueueHandler;
use App\QueueCore\QueueHandlerRegistry;
use App\QueueCore\QueueJob;
use App\QueueCore\QueueResult;
use App\QueueCore\QueueRunner;
use App\QueueCore\QueueRunRequest;
use App\Services\ApiExecutionMetadataContext;
use App\Services\AppSettingsService;
use App\Services\MeliApiException;
use App\Services\OAuthRefreshBusyException;
use App\Services\OAuthTokenRefreshService;
use App\Services\QueueOAuthDurableRecoveryStore;
use App\Services\RotatedCredentialRecoveryUnavailableException;

$dsn = (string) (getenv('QUEUE_CORE_TEST_DSN') ?: '');
$user = (string) (getenv('QUEUE_CORE_TEST_USER') ?: '');
$pass = (string) (getenv('QUEUE_CORE_TEST_PASS') ?: '');
if ($dsn === '') {
    fwrite(STDERR, "QUEUE_CORE_TEST_DSN is required\n");
    exit(2);
}

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("SET time_zone='+00:00'");
Database::setConnection($pdo);
putenv('APP_KEY=queue-core-b14-local-test-key');
$private = sys_get_temp_dir() . '/erp-meli-b14-' . bin2hex(random_bytes(6));
putenv('ERP_PRIVATE_PATH=' . $private);
$_ENV['ERP_PRIVATE_PATH'] = $private;
$_SERVER['ERP_PRIVATE_PATH'] = $private;

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['queue_core_pending_capabilities', 'queue_core_dispatch_journal', 'queue_core_attempts',
    'queue_core_events', 'queue_core_jobs', 'queue_core_producer_checkpoints',
    'queue_core_scheduler_state', 'queue_core_execution_leases', 'queue_engine_control',
    'app_settings', 'meli_tokens', 'meli_accounts'] as $table) {
    $pdo->exec("DROP TABLE IF EXISTS `$table`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE meli_accounts (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    meli_user_id VARCHAR(80) NULL,
    account_name VARCHAR(100) NULL,
    status VARCHAR(40) NOT NULL,
    last_error VARCHAR(500) NULL
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE meli_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meli_account_id BIGINT UNSIGNED NOT NULL UNIQUE,
    access_token_encrypted MEDIUMTEXT NOT NULL,
    refresh_token_encrypted MEDIUMTEXT NULL,
    expires_at DATETIME NULL,
    scope VARCHAR(500) NULL,
    token_type VARCHAR(40) NULL,
    refresh_version BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE app_settings (
    setting_key VARCHAR(190) NOT NULL PRIMARY KEY,
    setting_value LONGTEXT NULL,
    is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
    setting_group VARCHAR(80) NOT NULL DEFAULT 'general',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB");
$pdo->exec("INSERT INTO app_settings(setting_key,setting_value,setting_group)
    VALUES ('oauth.token_expiry_skew_seconds','120','oauth'),('oauth.refresh_lock_wait_seconds','0','oauth')");

$apply = static function (PDO $pdo, string $path): void {
    $sql = file_get_contents($path);
    if (!is_string($sql)) {
        throw new RuntimeException('migration missing: ' . basename($path));
    }
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
        $statement = trim($statement);
        $statement = preg_replace('/^--[^\n]*\n(?:--[^\n]*\n)*/', '', $statement) ?? $statement;
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
};
$migrations = [
    '280_queue_core_cron_v4_phase_b1.sql',
    '281_queue_core_reaudit1_fifo_fencing.sql',
    '282_queue_core_architecture_closeout_b1_2.sql',
    '283_queue_engine_control_oauth_supervisor_b1_4.sql',
];
foreach ([1, 2] as $passNumber) {
    foreach ($migrations as $migration) {
        $apply($pdo, $root . '/database/migrations/' . $migration);
    }
}

$passed = 0;
$total = 0;
$check = static function (bool $condition, string $message) use (&$passed, &$total): void {
    $total++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passed++;
};
$check(AppPaths::privateRoot() === $private, 'test private root was not isolated: ' . AppPaths::privateRoot());

$control = new QueueEngineControlService($pdo);
$initial = $control->snapshot();
$check($initial['active_engine'] === 'disabled' && $initial['generation'] === 0, 'engine default is not disabled');
$toV4 = $control->compareAndSwap('v4', 0, 'test');
$check($toV4['ok'] && $toV4['generation'] === 1, 'v4 CAS failed');
$permitResult = $control->acquireRuntime('v4', 'operational');
$permit = $permitResult['permit'] ?? null;
$check($permitResult['ok'] && $permit instanceof QueueEngineRuntimePermit, 'v4 runtime permit failed');
$sameConnectionCutover = $control->compareAndSwap('v3', 1, 'same-process');
$check(
    !$sameConnectionCutover['ok'] && $sameConnectionCutover['reason'] === 'engine_runtime_busy',
    'cutover crossed a runtime lock re-entered by the same DB connection'
);

$second = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$second->exec("SET time_zone='+00:00'");
$busyCutover = (new QueueEngineControlService($second))->compareAndSwap('v3', 1, 'other-process');
$check(!$busyCutover['ok'] && $busyCutover['reason'] === 'engine_runtime_busy', 'cutover crossed an active runtime');
$control->releaseRuntime($permit);
$toV3 = (new QueueEngineControlService($second))->compareAndSwap('v3', 1, 'other-process');
$check($toV3['ok'] && $toV3['generation'] === 2, 'v3 CAS after release failed');
$stale = $control->compareAndSwap('v4', 1, 'stale');
$check(!$stale['ok'] && $stale['reason'] === 'stale_generation', 'stale generation changed engine');

// Cron V4 must have exactly one operational QueueRunner. OAuth is produced
// before that run but cannot receive a privileged claim pass ahead of FIFO.
$cronV4Source = file_get_contents($root . '/app/QueueCore/CronV4Cli.php');
$check(
    is_string($cronV4Source) && substr_count($cronV4Source, "\$core['runner']->run(") === 1,
    'Cron V4 does not expose exactly one operational QueueRunner'
);
$check(
    is_string($cronV4Source)
        && !str_contains($cronV4Source, "['oauth_refresh']")
        && !str_contains($cronV4Source, '$oauthRun'),
    'Cron V4 still gives OAuth a priority pass outside FIFO'
);

$encryptedAccess = Crypto::encrypt('access-old');
$encryptedRefresh = Crypto::encrypt('refresh-old');
$pdo->exec("INSERT INTO meli_accounts VALUES
    (1,1,'101','A','conectado',NULL),(2,1,'102','B','conectado',NULL)");
$insertToken = $pdo->prepare(
    "INSERT INTO meli_tokens
     (meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,scope,token_type,refresh_version)
     VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),'read','Bearer',0)"
);
$insertToken->execute([1, $encryptedAccess, $encryptedRefresh, -60]);
$insertToken->execute([2, $encryptedAccess, $encryptedRefresh, 3600]);

$repository = new QueueCoreRepository($pdo);
$supervisor = new QueueCoreOAuthSupervisor($pdo, $repository);
$firstSchedule = $supervisor->scheduleDueAccounts();
$supervisor->scheduleDueAccounts();
$oauthJobs = (int) $pdo->query("SELECT COUNT(*) FROM queue_core_jobs WHERE work_type='oauth_refresh'")->fetchColumn();
$check($firstSchedule['due'] === 1 && $oauthJobs === 1, 'OAuth due producer is not idempotent');

$waitingId = $repository->enqueue(new QueueJob(
    1, 1, 'order_exact', 'order', '9001', 'normal', 0,
    'waiting-order', 'v1', 'test', null, [], [], 3, null, 'operational'
));
$pdo->prepare("UPDATE queue_core_jobs SET state='waiting_oauth',wait_refresh_version=0 WHERE id=?")
    ->execute([$waitingId]);

$refreshCalls = 0;
$handler = new QueueCoreOAuthRefreshHandler(
    static function (int $accountId) use (&$refreshCalls, $pdo): array {
        $refreshCalls++;
        $pdo->prepare("UPDATE meli_tokens
            SET refresh_version=refresh_version+1,expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 6 HOUR)
            WHERE meli_account_id=?")->execute([$accountId]);
        return ['ok' => true];
    },
    $pdo,
);
$registry = new QueueHandlerRegistry();
$registry->register('oauth_refresh', $handler);
$registry->register('order_exact', new class implements QueueHandler {
    public function handle(\App\QueueCore\QueueClaim $job, QueueExecutionContext $context): QueueResult
    {
        return QueueResult::completed(1);
    }
});
$runner = new QueueRunner($repository, $registry, null, new QueueCapabilityRegistry());
$oauthRun = $runner->run(new QueueRunRequest(
    'test', 'oauth-test', 1, microtime(true) + 10, 30, [], ['oauth_refresh'], null, null, 'operational'
));
$check($oauthRun['completed'] === 1 && $refreshCalls === 1, 'OAuth exact handler did not complete once');
$resumed = $repository->claimNext(new QueueRunRequest(
    'test', 'resume-test', 1, microtime(true) + 10, 30, [], ['order_exact'], null, null, 'operational'
), ['order_exact']);
$check($resumed?->id === $waitingId, 'waiting_oauth did not resume after refresh generation advanced');

$store = new QueueOAuthDurableRecoveryStore($private . '/store-contract');
$store->assertStorageReady();
$store->stage(1, 1, '101', 1, [
    'access_token_encrypted' => Crypto::encrypt('access-staged'),
    'refresh_token_encrypted' => Crypto::encrypt('refresh-staged'),
    'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    'scope' => 'read',
    'token_type' => 'Bearer',
]);
$recovery = $store->load(1, 1, '101');
$check(is_array($recovery) && (int) $recovery['target_refresh_version'] === 2, 'durable recovery could not authenticate its fence');
$store->clear(1, 2);
$check($store->load(1, 1, '101') === null, 'durable recovery was not cleared');

// Real OAuthTokenRefreshService with fake response: validates queue metadata,
// durable preflight/stage, CAS persistence and cleanup without real HTTP.
$pdo->prepare("UPDATE meli_accounts SET status='conectado',last_error=NULL WHERE id=1")->execute();
$pdo->prepare("UPDATE meli_tokens SET refresh_version=2,expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE meli_account_id=1")->execute();
$check(!is_file($private . '/queue-oauth-recovery/account-1.json'), 'unexpected default escrow before real refresh');
$realCalls = 0;
$refreshed = ApiExecutionMetadataContext::run([
    'source' => 'queue_core',
    'company_id' => 1,
    'account_id' => 1,
    'queue_core_work_type' => 'oauth_refresh',
], static function () use (&$realCalls): array {
    return ApiExecutionMetadataContext::withTransportMetadata([
        'transport_meli_account_id' => 1,
        'expected_meli_user_id' => '101',
        'queue_core_oauth_refresh' => 1,
    ], static function () use (&$realCalls): array {
        return (new OAuthTokenRefreshService(1))->refresh(static function () use (&$realCalls): array {
            $realCalls++;
            return [
                'access_token' => 'access-new',
                'refresh_token' => 'refresh-new',
                'expires_in' => 21600,
                'token_type' => 'Bearer',
                'scope' => 'read',
            ];
        });
    });
});
$check(
    $realCalls === 1 && (int) ($refreshed['refresh_version'] ?? 0) === 3,
    'real refresh service did not advance exactly one generation: calls=' . $realCalls
        . ' version=' . (int) ($refreshed['refresh_version'] ?? 0)
        . ' db=' . (int) $pdo->query('SELECT refresh_version FROM meli_tokens WHERE meli_account_id=1')->fetchColumn()
);
$check(!is_file($private . '/queue-oauth-recovery/account-1.json'), 'queue OAuth escrow remained after committed token');

// The durable preflight must happen before the remote boundary. If the
// filesystem becomes unavailable after that boundary and the fenced DB CAS
// also loses its row, the caller must receive the stable recovery class.
$pdo->prepare("UPDATE meli_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE meli_account_id=1")
    ->execute();
$recoveryRoot = $private . '/queue-oauth-recovery';
$combinedFailure = null;
$combinedCalls = 0;
try {
    ApiExecutionMetadataContext::run([
        'source' => 'queue_core',
        'company_id' => 1,
        'account_id' => 1,
        'queue_core_work_type' => 'oauth_refresh',
    ], static function () use (&$combinedCalls, $pdo, $recoveryRoot): array {
        return ApiExecutionMetadataContext::withTransportMetadata([
            'transport_meli_account_id' => 1,
            'expected_meli_user_id' => '101',
            'queue_core_oauth_refresh' => 1,
        ], static function () use (&$combinedCalls, $pdo, $recoveryRoot): array {
            return (new OAuthTokenRefreshService(1))->refresh(
                static function () use (&$combinedCalls, $pdo, $recoveryRoot): array {
                    $combinedCalls++;
                    if (!is_dir($recoveryRoot)) {
                        throw new RuntimeException('OAuth recovery preflight did not precede transport.');
                    }
                    if (!@rmdir($recoveryRoot) || file_put_contents($recoveryRoot, 'blocked') === false) {
                        throw new RuntimeException('Could not build the post-transport escrow failure fixture.');
                    }
                    $pdo->exec('DELETE FROM meli_tokens WHERE meli_account_id=1');
                    return [
                        'access_token' => 'access-rotated-unavailable',
                        'refresh_token' => 'refresh-rotated-unavailable',
                        'expires_in' => 21600,
                        'token_type' => 'Bearer',
                        'scope' => 'read',
                    ];
                }
            );
        });
    });
} catch (Throwable $error) {
    $combinedFailure = $error;
} finally {
    if (is_file($recoveryRoot)) {
        @unlink($recoveryRoot);
    }
    $insertToken->execute([1, $encryptedAccess, $encryptedRefresh, -60]);
}
$check($combinedCalls === 1, 'combined escrow/DB failure did not cross exactly one fake transport boundary');
$check(
    $combinedFailure instanceof RotatedCredentialRecoveryUnavailableException,
    'combined escrow/DB failure did not preserve rotated_credential_recovery_unavailable'
);

// Single-flight uses a per-account database advisory lock.
$pdo->prepare("UPDATE meli_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE meli_account_id=1")->execute();
$lock = $second->prepare('SELECT GET_LOCK(?,0)');
$lock->execute(['erp_meli_oauth_refresh_1']);
$busyCaught = false;
try {
    ApiExecutionMetadataContext::run([
        'source' => 'queue_core', 'company_id' => 1, 'account_id' => 1,
        'queue_core_work_type' => 'oauth_refresh', 'transport_meli_account_id' => 1,
        'expected_meli_user_id' => '101', 'queue_core_oauth_refresh' => 1,
    ], static fn (): array => (new OAuthTokenRefreshService(1))->refresh(
        static fn (): array => throw new RuntimeException('must not call transport')
    ));
} catch (OAuthRefreshBusyException) {
    $busyCaught = true;
} finally {
    $release = $second->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute(['erp_meli_oauth_refresh_1']);
}
$check($busyCaught, 'per-account OAuth single-flight did not block the second worker');

// invalid_grant is terminal for one account only.
$invalidHandler = new QueueCoreOAuthRefreshHandler(
    static fn (): array => throw new MeliApiException('invalid_grant', 400, 'fake', ['error' => 'invalid_grant']),
    $pdo,
);
$job = $repository->job((int) $pdo->query("SELECT id FROM queue_core_jobs WHERE work_type='oauth_refresh' LIMIT 1")->fetchColumn());
$claim = new \App\QueueCore\QueueClaim(
    (int) $job['id'], 1, 1, 'oauth_refresh', 'oauth_account', '1', 'recovery', 0,
    'running', 1, 5, 'invalid-test', 1, 'NOT_DISPATCHED',
    ['expected_meli_user_id' => '101', 'expected_refresh_version' => 0], 'test', null
);
$invalid = $invalidHandler->handle($claim, new QueueExecutionContext(1, microtime(true) + 10, 'test'));
$statuses = $pdo->query('SELECT id,status FROM meli_accounts ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
$check($invalid->outcome === 'review' && $invalid->errorClass === 'oauth_invalid_grant', 'invalid_grant was not isolated for review');
$check(($statuses[1] ?? '') === 'vencido' && ($statuses[2] ?? '') === 'conectado', 'invalid_grant contaminated another account');

echo 'PASS queue_engine_oauth_supervisor_b14 ' . $passed . '/' . $total . PHP_EOL;
