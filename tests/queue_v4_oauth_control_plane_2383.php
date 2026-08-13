<?php

declare(strict_types=1);

$dsn = (string) (getenv('QUEUE_V4_CLEAN_TEST_DSN') ?: '');
if ($dsn === '') {
    fwrite(STDERR, "QUEUE_V4_CLEAN_TEST_DSN is required\n");
    exit(2);
}

$root = dirname(__DIR__);
$private = sys_get_temp_dir() . '/erp-qv4-oauth-2383-' . bin2hex(random_bytes(6));
mkdir($private, 0700, true);
define('ERP_INSTALLATION_ROOT', dirname($private));
define('ERP_SHARED_ROOT', dirname($private));
define('ERP_RELEASE_ROOT', $root);
putenv('ERP_PRIVATE_PATH=' . $private);
putenv('APP_KEY=queue-v4-oauth-2383-local-only');
putenv('ML_WRITE_ENABLED=false');
$_ENV['ERP_PRIVATE_PATH'] = $private;
$_ENV['APP_KEY'] = 'queue-v4-oauth-2383-local-only';
$_ENV['ML_WRITE_ENABLED'] = 'false';

require $root . '/bootstrap.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, $error::class . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
});

use App\Core\Crypto;
use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanOAuthDispatchFence;
use App\QueueV4Clean\QueueV4CleanOAuthOperationRepository;
use App\QueueV4Clean\QueueV4CleanOAuthSupervisor;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\ApiBudgetExhaustedException;
use App\Services\ApiBudgetService;
use App\Services\ApiExecutionMetadataContext;
use App\Services\ApiRhythmDeferredException;
use App\Services\AppSettingsService;
use App\Services\MeliApiException;
use App\Services\MeliTransportSourcePolicy;
use App\Services\OAuthRefreshRequiredException;
use App\Services\OAuthTokenRefreshService;
use App\Services\QueueOAuthDurableRecoveryStore;

$pdo = new PDO(
    $dsn,
    (string) (getenv('QUEUE_V4_CLEAN_TEST_USER') ?: 'root'),
    (string) (getenv('QUEUE_V4_CLEAN_TEST_PASS') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
);
$pdo->exec("SET time_zone='+00:00'");
Database::setConnection($pdo);

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$tables = array_map('strval', $pdo->query(
    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()"
)->fetchAll(PDO::FETCH_COLUMN));
$assert(in_array('oauth_refresh_operations', $tables, true), 'migration_296_operation_table_missing');
$accounts = $pdo->query(
    'SELECT a.company_id,a.id,a.meli_user_id FROM meli_accounts a ORDER BY a.company_id,a.id LIMIT 3'
)->fetchAll(PDO::FETCH_ASSOC);
$assert(count($accounts) === 3, 'three_account_fixture_missing');
$target = $accounts[0];

$settings = new AppSettingsService();
foreach ([
    'oauth.auto_refresh_lead_seconds' => '3600',
    'oauth.auto_refresh_global_reserve_per_15m' => '3',
    'oauth.auto_refresh_account_reserve_per_15m' => '1',
] as $key => $value) {
    $settings->set($key, $value, 'qv4_oauth_2383_test');
}
AppSettingsService::clearCache();

$schedulerOwner = 'oauth-test-scheduler';
$reset = static function (int $version = 40, int $secondsToExpiry = 600) use ($pdo, $accounts, $target, $schedulerOwner): void {
    $pdo->exec('DELETE FROM oauth_refresh_operations');
    $pdo->exec("UPDATE meli_accounts SET status='conectado',last_error=NULL");
    $pdo->exec("UPDATE meli_tokens SET expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 7200 SECOND)");
    $token = $pdo->prepare(
        'UPDATE meli_tokens SET access_token_encrypted=?,refresh_token_encrypted=?,refresh_version=?,
                expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ? SECOND)
         WHERE meli_account_id=?'
    );
    $token->execute([
        Crypto::encrypt('old-access-' . $version),
        Crypto::encrypt('old-refresh-' . $version),
        $version,
        $secondsToExpiry,
        (int) $target['id'],
    ]);
    $lease = $pdo->prepare(
        "UPDATE queue_v4_clean_leases
         SET owner_ref=?,acquired_at=UTC_TIMESTAMP(3),heartbeat_at=UTC_TIMESTAMP(3),
             expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 60 SECOND)
         WHERE lease_key='scheduler'"
    );
    $lease->execute([$schedulerOwner]);
};

$state = static function () use ($pdo): array {
    $row = $pdo->query('SELECT * FROM oauth_refresh_operations ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
};

$run = static function (callable $refresh) use ($pdo, $schedulerOwner): array {
    return (new QueueV4CleanOAuthSupervisor(
        $pdo,
        new QueueV4CleanOAuthOperationRepository($pdo),
        new AppSettingsService(),
        $refresh,
    ))->run($schedulerOwner);
};
$remote = 0;
$dispatch = static function (?int $knownStatus = null) use (&$remote): void {
    ApiExecutionMetadataContext::withTransportMetadata(
        ['transport_request_id' => bin2hex(random_bytes(12))],
        static function () use (&$remote, $knownStatus): void {
            QueueV4CleanOAuthDispatchFence::immediatelyBeforeCurl('POST', '/oauth/token');
            $remote++;
            if ($knownStatus !== null) {
                QueueV4CleanOAuthDispatchFence::responseKnown($knownStatus);
            }
        }
    );
};

// Fuente única y capability exacta.
$assert(MeliTransportSourcePolicy::requiresCurrentOAuthFence(MeliTransportSourcePolicy::QUEUE_V4_OAUTH), 'oauth_fence_capability_missing');
$unknownBlocked = false;
try { MeliTransportSourcePolicy::assertAllowed('unknown-2383', 'GET', '/users/me'); } catch (RuntimeException) { $unknownBlocked = true; }
$assert($unknownBlocked, 'unknown_transport_source_not_blocked');
$wrongEndpointBlocked = false;
try { MeliTransportSourcePolicy::assertAllowed(MeliTransportSourcePolicy::QUEUE_V4_OAUTH, 'GET', '/users/me'); } catch (RuntimeException) { $wrongEndpointBlocked = true; }
$assert($wrongEndpointBlocked, 'oauth_source_non_oauth_endpoint_not_blocked');

// Happy path: una sola frontera física y terminal COMPLETED.
$reset();
$remote = 0;
$callbackError = '';
$summary = $run(static function (array $operation) use (&$remote, &$callbackError, $dispatch): array {
    try {
        $dispatch(200);
    } catch (Throwable $error) {
        $callbackError = $error->getMessage();
        throw $error;
    }
    return ['refresh_version' => (int) $operation['expected_refresh_version'] + 1];
});
$row = $state();
$assert($remote === 1 && $summary['physical_posts'] === 1 && $row['state'] === 'COMPLETED',
    'single_success_path_invalid:' . $callbackError . ':' . json_encode($summary));

// Bloqueo local y fallo de persistencia de MAY_HAVE_DISPATCHED: HTTP 0.
$reset(41);
$remote = 0;
$summary = $run(static function () use (&$remote): array {
    throw new ApiBudgetExhaustedException('local budget', gmdate('Y-m-d H:i:s', time() + 60));
});
$assert($remote === 0 && $summary['waiting'] === 1 && $state()['remote_attempt_count'] === 0, 'local_block_reached_remote');
$reset(42);
$remote = 0;
$summary = $run(static function () use ($pdo, &$remote, $dispatch): array {
    $pdo->exec("UPDATE queue_v4_clean_leases SET owner_ref='lost-owner' WHERE lease_key='scheduler'");
    $dispatch();
    return [];
});
$assert($remote === 0 && $summary['waiting'] === 1 && $state()['remote_dispatch_state'] === 'NOT_DISPATCHED', 'may_persist_failure_reached_remote');

// Crash después de MAY, 5xx y 2xx malformado: inciertos sin segundo POST.
foreach ([
    'crash_after_may' => static function () use (&$remote, $dispatch): array {
        $dispatch();
        throw new RuntimeException('simulated_transport_crash');
    },
    'known_5xx' => static function () use (&$remote, $dispatch): array {
        $dispatch(503);
        throw new MeliApiException('safe oauth failure', 503, 'request', []);
    },
    'known_5xx_retry_after' => static function () use (&$remote, $dispatch): array {
        $dispatch(503);
        throw new ApiRhythmDeferredException(
            'known 5xx with Retry-After',
            gmdate('Y-m-d H:i:s', time() + 120),
            'remote_backoff',
            true,
        );
    },
    'malformed_2xx' => static function () use (&$remote, $dispatch): array {
        $dispatch(200);
        throw new RuntimeException('oauth_response_invalid');
    },
] as $name => $callback) {
    $reset(50 + $checks);
    $remote = 0;
    $summary = $run($callback);
    $row = $state();
    $assert($remote === 1 && $summary['uncertain'] === 1 && $row['state'] === 'REMOTE_UNCERTAIN', $name . '_not_terminal_uncertain');
    $again = $run(static fn (): array => throw new RuntimeException('must_not_claim_terminal'));
    $assert($again['claimed'] === 0, $name . '_was_auto_retried');
}

// 429 conocido espera; invalid_grant exige reconexión.
$reset(60);
$remote = 0;
$summary = $run(static function () use (&$remote, $dispatch): array {
    $dispatch(429);
    throw new ApiRhythmDeferredException('known rate limit', gmdate('Y-m-d H:i:s', time() + 120), 'retry_after', true);
});
$row = $state();
$assert($remote === 1 && $summary['waiting'] === 1 && $row['state'] === 'WAITING'
    && $row['remote_dispatch_state'] === 'RESPONSE_KNOWN' && (int) $row['last_http_status'] === 429,
    'known_429_not_waiting');
$pdo->exec("UPDATE oauth_refresh_operations SET next_attempt_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND)");
$retrySummary = $run(static function (array $operation) use (&$remote, $dispatch): array {
    $dispatch(200);
    return ['refresh_version' => (int) $operation['expected_refresh_version'] + 1];
});
$assert($retrySummary['physical_posts'] === 1 && $retrySummary['completed'] === 1
    && $remote === 2 && $state()['state'] === 'COMPLETED', 'known_429_next_run_retry_invalid');
$reset(61);
$remote = 0;
$summary = $run(static function () use (&$remote, $dispatch): array {
    $dispatch(400);
    throw new MeliApiException('oauth rejected', 400, 'request', ['error' => 'invalid_grant']);
});
$assert($remote === 1 && $summary['reconnect'] === 1 && $state()['state'] === 'RECONNECT_REQUIRED', 'invalid_grant_not_reconnect_required');

// Generación ya avanzada no realiza POST.
$reset(62);
$summary = $run(static fn (array $operation): array => ['refresh_version' => (int) $operation['expected_refresh_version'] + 1]);
$assert($summary['completed'] === 1 && $summary['physical_posts'] === 0, 'advanced_generation_performed_post');

// Escrow 2xx conocido se recupera localmente después de reinicio, sin POST.
$reset(70);
$store = new QueueOAuthDurableRecoveryStore();
$store->stage(
    (int) $target['company_id'],
    (int) $target['id'],
    (string) $target['meli_user_id'],
    70,
    [
        'access_token_encrypted' => Crypto::encrypt('recovered-access'),
        'refresh_token_encrypted' => Crypto::encrypt('recovered-refresh'),
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 21600),
        'scope' => 'offline_access',
        'token_type' => 'Bearer',
    ]
);
$insert = $pdo->prepare(
    "INSERT INTO oauth_refresh_operations
     (company_id,meli_account_id,expected_meli_user_id,expected_refresh_version,state,next_attempt_at,
      lease_owner,lease_generation,lease_expires_at,remote_attempt_count,remote_dispatch_state,
      remote_dispatched_at,response_known_at,last_http_status,last_request_id)
     VALUES (?,?,?,70,'RUNNING',UTC_TIMESTAMP(3),'stale',1,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 5 SECOND),
             1,'RESPONSE_KNOWN',DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 6 SECOND),
             DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 5 SECOND),200,'staged-request')"
);
$insert->execute([(int) $target['company_id'], (int) $target['id'], (string) $target['meli_user_id']]);
$pdo->prepare(
    "UPDATE queue_v4_clean_leases SET owner_ref=?,heartbeat_at=UTC_TIMESTAMP(3),
            expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 60 SECOND) WHERE lease_key='scheduler'"
)->execute([$schedulerOwner]);
$summary = (new QueueV4CleanOAuthSupervisor($pdo, new QueueV4CleanOAuthOperationRepository($pdo)))->run($schedulerOwner);
$version = (int) $pdo->query('SELECT refresh_version FROM meli_tokens WHERE meli_account_id=' . (int) $target['id'])->fetchColumn();
$assert($summary['completed'] === 1 && $summary['physical_posts'] === 0 && $version === 71, 'staged_2xx_not_recovered_locally');

// Respuesta 2xx conocida + escrow durable + fallo local inmediato: la misma
// operacion queda WAITING con dispatch restablecido. El siguiente run adopta
// el escrow sin repetir POST y completa exactamente la generacion rotada.
$reset(72);
$remote = 0;
$summary = $run(static function (array $operation) use ($target, $dispatch): array {
    $dispatch(200);
    (new QueueOAuthDurableRecoveryStore())->stage(
        (int) $target['company_id'],
        (int) $target['id'],
        (string) $target['meli_user_id'],
        (int) $operation['expected_refresh_version'],
        [
            'access_token_encrypted' => Crypto::encrypt('known-2xx-recovery-access'),
            'refresh_token_encrypted' => Crypto::encrypt('known-2xx-recovery-refresh'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 21600),
            'scope' => 'offline_access',
            'token_type' => 'Bearer',
        ]
    );
    throw new RuntimeException('simulated_mariadb_failure_after_known_2xx');
});
$row = $state();
$assert($summary['waiting'] === 1 && $summary['physical_posts'] === 1
    && $row['state'] === 'WAITING' && $row['remote_dispatch_state'] === 'NOT_DISPATCHED'
    && $row['last_error_class'] === 'durable_recovery_pending', 'known_2xx_failure_not_queued_for_local_recovery');
$recoveryCallbackCalls = 0;
$recovered = $run(static function () use ($target, &$recoveryCallbackCalls): array {
    return (new OAuthTokenRefreshService((int) $target['id']))->refresh(
        static function () use (&$recoveryCallbackCalls): array {
            $recoveryCallbackCalls++;
            throw new RuntimeException('durable_recovery_must_not_repeat_oauth_post');
        }
    );
});
$version = (int) $pdo->query('SELECT refresh_version FROM meli_tokens WHERE meli_account_id=' . (int) $target['id'])->fetchColumn();
$assert($recovered['completed'] === 1 && $recovered['physical_posts'] === 0
    && $recoveryCallbackCalls === 0 && $version === 73 && $state()['state'] === 'COMPLETED',
    'known_2xx_durable_recovery_repeated_post_or_failed');

// Un escrow de otra generación nunca autoriza recuperación ni segundo POST.
$reset(74);
$remote = 0;
$mismatch = $run(static function (array $operation) use ($target, $dispatch): array {
    $dispatch(200);
    (new QueueOAuthDurableRecoveryStore())->stage(
        (int) $target['company_id'],
        (int) $target['id'],
        (string) $target['meli_user_id'],
        (int) $operation['expected_refresh_version'] + 1,
        [
            'access_token_encrypted' => Crypto::encrypt('mismatch-access'),
            'refresh_token_encrypted' => Crypto::encrypt('mismatch-refresh'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 21600),
            'scope' => 'offline_access',
            'token_type' => 'Bearer',
        ]
    );
    throw new RuntimeException('simulated_known_2xx_generation_mismatch');
});
$assert($mismatch['uncertain'] === 1 && $mismatch['physical_posts'] === 1
    && $state()['state'] === 'REMOTE_UNCERTAIN'
    && $state()['last_error_class'] === 'oauth_escrow_generation_mismatch',
    'escrow_generation_mismatch_not_fail_closed');
(new QueueOAuthDurableRecoveryStore())->clear((int) $target['id'], 76);

// Lead separado: 3590s inicia el callback; 3700s no lo inicia.
foreach ([3590 => 1, 3700 => 0] as $expirySeconds => $expectedCalls) {
    $reset(80 + $expirySeconds, $expirySeconds);
    $operation = (new QueueV4CleanOAuthOperationRepository($pdo))->claimOne('lead-owner-' . $expirySeconds);
    if ($operation === null) {
        (new QueueV4CleanOAuthOperationRepository($pdo))->schedule(
            (int) $target['company_id'], (int) $target['id'], (string) $target['meli_user_id'], 80 + $expirySeconds
        );
        $operation = (new QueueV4CleanOAuthOperationRepository($pdo))->claimOne('lead-owner-' . $expirySeconds);
    }
    $assert(is_array($operation), 'lead_operation_missing_' . $expirySeconds);
    $calls = 0;
    ApiExecutionMetadataContext::run([
        'source' => MeliTransportSourcePolicy::QUEUE_V4_OAUTH,
        'company_id' => (int) $target['company_id'],
        'account_id' => (int) $target['id'],
        'expected_meli_user_id' => (string) $target['meli_user_id'],
        'expected_refresh_version' => 80 + $expirySeconds,
        'oauth_operation_id' => (int) $operation['id'],
        'oauth_lease_owner' => (string) $operation['lease_owner'],
        'oauth_lease_generation' => (int) $operation['lease_generation'],
    ], static function () use ($target, &$calls): void {
        (new OAuthTokenRefreshService((int) $target['id']))->refresh(static function () use (&$calls): array {
            $calls++;
            return ['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 21600, 'token_type' => 'Bearer'];
        });
    });
    $assert($calls === $expectedCalls, 'automatic_lead_boundary_invalid_' . $expirySeconds);
}

// Si el lead cambia despues de programar, un retorno con la misma generacion
// no se declara COMPLETED ni se pierde para siempre: vuelve a WAITING sin POST.
$reset(899, 3700);
$operations = new QueueV4CleanOAuthOperationRepository($pdo);
$operations->schedule((int) $target['company_id'], (int) $target['id'], (string) $target['meli_user_id'], 899);
$notDue = $run(static function () use ($pdo, $target): array {
    return (array) $pdo->query(
        'SELECT refresh_version,expires_at FROM meli_tokens WHERE meli_account_id=' . (int) $target['id']
    )->fetch(PDO::FETCH_ASSOC);
});
$assert($notDue['waiting'] === 1 && $notDue['physical_posts'] === 0
    && $state()['state'] === 'WAITING'
    && $state()['last_error_class'] === 'oauth_not_due_after_recheck', 'not_due_recheck_was_lost_or_completed');

// Reserva OAuth también se aplica a orders_event_sync.
$budget = new ApiBudgetService();
$effective = new ReflectionMethod($budget, 'effectiveLimit');
$assert($effective->invoke($budget, 300, 'oauth', 3) === 300, 'oauth_global_budget_not_full');
$assert($effective->invoke($budget, 300, 'orders_event_sync', 3) === 297, 'orders_event_consumed_oauth_reserve');
$assert($effective->invoke($budget, 120, 'orders_event_sync', 1) === 119, 'orders_event_consumed_account_reserve');
$invalidReserveBlocked = false;
try { $effective->invoke($budget, 3, 'orders_event_sync', 3); } catch (Throwable) { $invalidReserveBlocked = true; }
$assert($invalidReserveBlocked, 'capacity_equal_to_reserve_not_fail_closed');

// Tres cuentas vencen simultaneamente: se programan las tres, pero este run
// cruza como maximo una frontera fisica OAuth.
$pdo->exec('DELETE FROM oauth_refresh_operations');
$due = $pdo->prepare(
    'UPDATE meli_tokens SET access_token_encrypted=?,refresh_token_encrypted=?,refresh_version=?,
            expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 600 SECOND)
     WHERE meli_account_id=?'
);
foreach ($accounts as $index => $account) {
    $version = 900 + $index;
    $due->execute([
        Crypto::encrypt('due-access-' . $version),
        Crypto::encrypt('due-refresh-' . $version),
        $version,
        (int) $account['id'],
    ]);
}
$pdo->prepare(
    "UPDATE queue_v4_clean_leases SET owner_ref=?,heartbeat_at=UTC_TIMESTAMP(3),
            expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 60 SECOND) WHERE lease_key='scheduler'"
)->execute([$schedulerOwner]);
$remote = 0;
$threeDue = $run(static function (array $operation) use (&$remote, $dispatch): array {
    $dispatch(200);
    return ['refresh_version' => (int) $operation['expected_refresh_version'] + 1];
});
$operationStates = $pdo->query(
    'SELECT state,COUNT(*) rows_count FROM oauth_refresh_operations GROUP BY state ORDER BY state'
)->fetchAll(PDO::FETCH_KEY_PAIR);
$assert($threeDue['scheduled'] === 3 && $threeDue['physical_posts'] === 1 && $remote === 1
    && (int) ($operationStates['COMPLETED'] ?? 0) === 1
    && (int) ($operationStates['SCHEDULED'] ?? 0) === 2, 'three_due_accounts_exceeded_one_post');

// Dos cron no pueden duplicar el POST: una operacion RUNNING/leased por el
// primer proceso no es reclamable por el segundo.
$reset(920);
$operations = new QueueV4CleanOAuthOperationRepository($pdo);
$operations->schedule((int) $target['company_id'], (int) $target['id'], (string) $target['meli_user_id'], 920);
$held = $operations->claimOne('first-cron-owner');
$assert(is_array($held), 'first_cron_did_not_claim_operation');
$secondCronCallbacks = 0;
$secondCron = $run(static function () use (&$secondCronCallbacks): array {
    $secondCronCallbacks++;
    return [];
});
$assert($secondCron['claimed'] === 0 && $secondCron['physical_posts'] === 0
    && $secondCronCallbacks === 0, 'two_cron_duplicate_oauth_post');

// El lock por cuenta es compartido con la renovacion manual. Mientras otra
// sesion posee el lock, Cron espera y no alcanza el callback remoto.
$reset(921);
$lockPdo = new PDO(
    $dsn,
    (string) (getenv('QUEUE_V4_CLEAN_TEST_USER') ?: 'root'),
    (string) (getenv('QUEUE_V4_CLEAN_TEST_PASS') ?: ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);
$lockName = 'erp_meli_oauth_refresh_' . (int) $target['id'];
$lockStatement = $lockPdo->prepare('SELECT GET_LOCK(?,0)');
$lockStatement->execute([$lockName]);
$assert((int) $lockStatement->fetchColumn() === 1, 'manual_refresh_lock_fixture_failed');
$manualCollisionRemote = 0;
try {
    $manualCollision = $run(static function () use ($target, &$manualCollisionRemote): array {
        return (new OAuthTokenRefreshService((int) $target['id']))->refresh(
            static function () use (&$manualCollisionRemote): array {
                $manualCollisionRemote++;
                return [];
            }
        );
    });
} finally {
    $releaseLock = $lockPdo->prepare('SELECT RELEASE_LOCK(?)');
    $releaseLock->execute([$lockName]);
}
$assert($manualCollision['waiting'] === 1 && $manualCollision['physical_posts'] === 0
    && $manualCollisionRemote === 0, 'cron_manual_refresh_duplicate_oauth_post');

// Un backlog comercial grande no puede desplazar el plano OAuth, que se
// ejecuta antes de Producer/Worker y fuera de su FIFO.
$pdo->exec('DELETE FROM queue_v4_clean_attempts');
$pdo->exec('DELETE FROM queue_v4_clean_runs');
$pdo->exec('DELETE FROM queue_v4_clean_jobs');
$repository = new QueueV4CleanRepository($pdo);
for ($index = 1; $index <= 500; $index++) {
    $repository->enqueue(
        (int) $target['company_id'],
        (int) $target['id'],
        'order_exact',
        (string) (9000000 + $index),
        'oauth-backlog-' . $index,
        ['order_id' => (string) (9000000 + $index)],
        3,
    );
}
$reset(922);
$remote = 0;
$backlogOAuth = $run(static function (array $operation) use (&$remote, $dispatch): array {
    $dispatch(200);
    return ['refresh_version' => (int) $operation['expected_refresh_version'] + 1];
});
$readyBacklog = (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE state='ready'")->fetchColumn();
$assert($backlogOAuth['physical_posts'] === 1 && $remote === 1 && $readyBacklog === 500,
    'business_backlog_starved_oauth_control_plane');

// Prueba dinamica: el worker reclama un job, encuentra OAuth pendiente y
// revierte exactamente el intento funcional; no crea review ni dead.
$pdo->exec('DELETE FROM queue_v4_clean_attempts');
$pdo->exec('DELETE FROM queue_v4_clean_runs');
$pdo->exec('DELETE FROM queue_v4_clean_jobs');
$pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE' WHERE control_key='primary'");
$jobId = $repository->enqueue(
    (int) $target['company_id'],
    (int) $target['id'],
    'order_exact',
    '99999991',
    'oauth-wait-no-penalty',
    ['order_id' => '99999991'],
    3,
);
$workerResult = (new QueueV4CleanWorker(
    $pdo,
    $repository,
    null,
    null,
    static fn (array $job): never => throw new OAuthRefreshRequiredException((int) $job['meli_account_id']),
))->run('test', 1, 10);
$workerJob = $pdo->query('SELECT state,attempt_count,last_error_class FROM queue_v4_clean_jobs WHERE id=' . $jobId)->fetch(PDO::FETCH_ASSOC);
$workerAttempt = $pdo->query('SELECT outcome,error_class FROM queue_v4_clean_attempts WHERE job_id=' . $jobId)->fetch(PDO::FETCH_ASSOC);
$workerTerminal = (int) $pdo->query("SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE state IN ('review','dead')")->fetchColumn();
$assert($workerResult['claimed'] === 1 && $workerResult['deferred'] === 1
    && ($workerJob['state'] ?? '') === 'waiting' && (int) ($workerJob['attempt_count'] ?? -1) === 0
    && ($workerAttempt['outcome'] ?? '') === 'waiting' && $workerTerminal === 0,
    'worker_oauth_wait_applied_attempt_penalty');

// El contrato estatico complementa la prueba dinamica y evita que una futura
// refactorizacion vuelva a capturar OAuth como RuntimeException generica.
$worker = (string) file_get_contents($root . '/app/QueueV4Clean/QueueV4CleanWorker.php');
$oauthCatch = strpos($worker, 'catch (OAuthRefreshRequiredException)');
$runtimeCatch = strpos($worker, 'catch (RuntimeException');
$assert($oauthCatch !== false && $runtimeCatch !== false && $oauthCatch < $runtimeCatch, 'worker_oauth_catch_order_invalid');
$assert(str_contains($worker, 'deferWithoutAttemptPenalty'), 'worker_oauth_wait_attempt_penalty_present');

$logs = '';
foreach (['api_request_logs', 'api_error_logs'] as $table) {
    $columns = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_intersect(['safe_message', 'response_json'], array_map('strval', $columns)) as $column) {
        $logs .= implode("\n", array_map('strval', $pdo->query("SELECT COALESCE(`{$column}`,'') FROM `{$table}` ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_COLUMN)));
    }
}
$assert(!str_contains($logs, 'old-refresh-') && !str_contains($logs, 'recovered-refresh')
    && !str_contains($logs, 'new-refresh'), 'oauth_secret_found_in_logs');

fwrite(STDOUT, 'QUEUE_V4_OAUTH_CONTROL_PLANE_2383=PASS checks=' . $checks
    . ' max_posts_per_run=' . QueueV4CleanOAuthSupervisor::MAX_PHYSICAL_POSTS_PER_RUN
    . ' real_meli_http=0 raw_storage_touched=false' . PHP_EOL);
