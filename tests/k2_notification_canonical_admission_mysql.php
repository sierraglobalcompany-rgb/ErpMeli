<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';
require __DIR__ . '/cap2_domains_wire_fixture.php';

use App\Core\Crypto;
use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\AppSettingsService;
use App\Services\Cap2DomainsWire;
use App\Services\Migrator;
use App\Services\NotificationCollationRecoveryService;
use App\Services\NotificationWorkItemService;
use App\Services\NotificationWorkerRecoveryService;
use App\Services\WebhookService;

$root = dirname(__DIR__);
$qaRoot = str_replace('\\', '/', $root . '/storage/codex-k2-notifications-canonical-20260919/qa');
foreach ([
    'APP_ENV' => 'test',
    'ML_WRITE_ENABLED' => 'false',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '33079',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'DB_NAME' => 'erp_meli_k1d_test_k2_notifications_' . bin2hex(random_bytes(4)),
    'APP_KEY' => 'k2-disposable-test-only-not-a-real-secret',
    'MELI_CLIENT_ID' => '123456',
    'MELI_API_BASE' => 'https://k2-wire.invalid',
    'WEBHOOK_SPOOL_ENABLED' => 'false',
    'CALLS_VERIFY_QA_ROOT' => $qaRoot,
    'ERP_PRIVATE_PATH' => $qaRoot . '/private',
] as $key => $value) {
    putenv($key . '=' . $value);
}

define('ERP_RELEASE_ROOT', $root);
define('ERP_INSTALLATION_ROOT', $qaRoot . '/install');
foreach ([ERP_INSTALLATION_ROOT, $qaRoot . '/private'] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('K2_QA_DIRECTORY_UNAVAILABLE');
    }
}

$db = K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo = $db->pdo();
    (new Migrator($pdo, $root . '/database/migrations'))->run(301);
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9201,'K2 company A',1),(9202,'K2 company B',1)");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES
        (9211,9201,'K2 account A',99211,'conectado'),
        (9221,9202,'K2 account B',99221,'conectado'),
        (9231,9201,'K2 account race',99231,'conectado'),
        (9241,9201,'K2 account debounce',99241,'conectado'),
        (9251,9202,'K2 account retry',99251,'conectado'),
        (9261,9201,'K2 account worker recovery',99261,'conectado'),
        (9271,9202,'K2 account collation recovery',99271,'conectado'),
        (9281,9201,'K2 account uncertainty',99281,'conectado'),
        (9291,9201,'K2 account concurrent',99291,'conectado'),
        (9301,9201,'K2 account uncertainty real attempt',99301,'conectado'),
        (9302,9202,'K2 account uncertainty isolation',99302,'conectado')");
    $token = Crypto::encrypt('k2-test-access');
    $refresh = Crypto::encrypt('k2-test-refresh');
    $tokens = $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at) VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))');
    $tokens->execute([9211, $token, $refresh]);
    $tokens->execute([9221, $token, $refresh]);
    $tokens->execute([9231, $token, $refresh]);
    foreach ([9241, 9251, 9261, 9271, 9281, 9291, 9301, 9302] as $tokenAccountId) {
        $tokens->execute([$tokenAccountId, $token, $refresh]);
    }
    $pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE',readiness_state='CERTIFIED' WHERE control_key='primary'");
    $pdo->exec("UPDATE queue_engine_control SET active_engine='v4'");
    $settings = new AppSettingsService();
    $settings->set('notifications.debounce_seconds', '0', 'notifications');
    $settings->set('api.rhythm.burst_size', '100', 'api');
    AppSettingsService::clearCache();

    $service = new WebhookService();
    $first = k2_receive($service, 'k2-first', 99211, 9301, '2026-09-19T10:00:00Z');
    k1b_assert($first['accepted'] === true && $first['duplicate'] === false, 'K2_INITIAL_WEBHOOK_ACCEPTED');
    $workId = (int) $pdo->query("SELECT id FROM meli_notification_work_items WHERE meli_account_id=9211 AND resource_type='question' AND remote_resource_id='9301'")->fetchColumn();
    k1b_assert($workId > 0, 'K2_DURABLE_SOURCE_CREATED');
    $pointerId = k2_active_pointer($pdo, 9201, 9211, $workId);
    k1b_assert($pointerId > 0, 'K2_CANONICAL_POINTER_CREATED');
    k1b_assert(Cap2DomainsWire::$calls === [], 'K2_ADMISSION_ZERO_HTTP');

    // Two independent PHP receivers cross the same barrier and must converge
    // on one durable source and one executable canonical pointer.
    $concurrent = k2_receive_concurrently($pdo, $qaRoot, 99291, 9302);
    k1b_assert(
        $concurrent['accepted'] === 2,
        'K2_CONCURRENT_RECEIVERS_ACCEPTED:' . json_encode($concurrent['outputs'], JSON_UNESCAPED_SLASHES)
    );
    $concurrentWork = (int) $pdo->query("SELECT id FROM meli_notification_work_items WHERE meli_account_id=9291 AND resource_type='question' AND remote_resource_id='9302'")->fetchColumn();
    k1b_assert($concurrentWork > 0, 'K2_CONCURRENT_SOURCE_CREATED');
    k1b_assert(k2_pointer_count($pdo, 9201, 9291, $concurrentWork) === 1, 'K2_CONCURRENT_ONE_LOGICAL_POINTER');
    k1b_assert((int) $pdo->query('SELECT occurrence_count FROM meli_notification_work_items WHERE id=' . $concurrentWork)->fetchColumn() === 2, 'K2_CONCURRENT_OCCURRENCES_RETAINED');
    k1b_assert(Cap2DomainsWire::$calls === [], 'K2_CONCURRENT_ADMISSION_ZERO_HTTP');

    $duplicate = k2_receive($service, 'k2-first', 99211, 9301, '2026-09-19T10:00:00Z');
    k1b_assert($duplicate['accepted'] === true && $duplicate['duplicate'] === true, 'K2_IDENTICAL_REPLAY_DEDUPED');
    k1b_assert(k2_pointer_count($pdo, 9201, 9211, $workId) === 1, 'K2_REPLAY_ONE_LOGICAL_POINTER');

    // A second distinct observation before the drainer runs must coalesce into the same active pointer.
    k2_receive($service, 'k2-coalesced', 99211, 9301, '2026-09-19T10:00:01Z');
    k1b_assert(k2_pointer_count($pdo, 9201, 9211, $workId) === 1, 'K2_PRE_DRAIN_COALESCES');

    // A third observation is persisted while the first physical GET is in flight.
    Cap2DomainsWire::$responses['/questions/9301'] = [200, ['id' => 9301, 'text' => 'K2 question', 'status' => 'UNANSWERED', 'seller_id' => 99211]];
    Cap2DomainsWire::$onWire = static function () use ($service): void {
        k2_receive($service, 'k2-during-run', 99211, 9301, '2026-09-19T10:00:02Z');
    };
    try {
        $firstRun = k2_run($pdo, 1, 9211);
    } finally {
        Cap2DomainsWire::$onWire = null;
    }
    k1b_assert($firstRun['physical_http_calls'] === 1, 'K2_FIRST_RUN_ONE_PHYSICAL_GET');
    $source = $pdo->query('SELECT status,latest_event_id,processing_event_id FROM meli_notification_work_items WHERE id=' . $workId)->fetch(PDO::FETCH_ASSOC);
    k1b_assert(($source['status'] ?? '') === 'pending', 'K2_DURING_RUN_SOURCE_REMAINS_PENDING');
    k1b_assert($source['processing_event_id'] === null, 'K2_DURING_RUN_PROCESSING_FENCE_CLOSED');
    k1b_assert((string) $pdo->query('SELECT state FROM queue_v4_clean_jobs WHERE id=' . $pointerId)->fetchColumn() === 'waiting', 'K2_DURING_RUN_POINTER_REMAINS_EXECUTABLE');

    k2_wait_until_pointer_due($pdo, $pointerId);
    $secondRun = k2_run($pdo, 1, 9211);
    $rerunState = $pdo->query('SELECT state,available_at,attempt_count,max_attempts,lease_generation,last_error_class FROM queue_v4_clean_jobs WHERE id=' . $pointerId)->fetch(PDO::FETCH_ASSOC);
    $rerunSource = $pdo->query('SELECT status,next_run_at,locked_by,lock_expires_at FROM meli_notification_work_items WHERE id=' . $workId)->fetch(PDO::FETCH_ASSOC);
    k1b_assert($secondRun['physical_http_calls'] === 1, 'K2_RERUN_ONE_PHYSICAL_GET:' . json_encode([$secondRun, $rerunState, $rerunSource], JSON_UNESCAPED_SLASHES));
    k1b_assert((string) $pdo->query('SELECT status FROM meli_notification_work_items WHERE id=' . $workId)->fetchColumn() === 'complete', 'K2_RERUN_SOURCE_COMPLETED');
    k1b_assert((string) $pdo->query('SELECT state FROM queue_v4_clean_jobs WHERE id=' . $pointerId)->fetchColumn() === 'completed', 'K2_RERUN_POINTER_COMPLETED');

    // A later legitimate event must create one new generation after the old pointer completed.
    k2_receive($service, 'k2-after-close', 99211, 9301, '2026-09-19T10:00:03Z');
    k1b_assert(k2_pointer_count($pdo, 9201, 9211, $workId) === 2, 'K2_AFTER_CLOSE_NEW_CANONICAL_GENERATION');
    $afterClosePointer = k2_active_pointer($pdo, 9201, 9211, $workId);
    k1b_assert($afterClosePointer > 0, 'K2_AFTER_CLOSE_NEW_GENERATION_EXECUTABLE');
    k1b_assert(count(Cap2DomainsWire::$calls) === 2, 'K2_AFTER_CLOSE_ADMISSION_ZERO_HTTP');
    k2_wait_until_pointer_due($pdo, $afterClosePointer);
    $afterCloseRun = k2_run($pdo, 1, 9211);
    $afterCloseState = $pdo->query('SELECT state,available_at,last_error_class FROM queue_v4_clean_jobs WHERE id=' . $afterClosePointer)->fetch(PDO::FETCH_ASSOC);
    $afterCloseSource = $pdo->query('SELECT status,next_run_at FROM meli_notification_work_items WHERE id=' . $workId)->fetch(PDO::FETCH_ASSOC);
    k1b_assert($afterCloseRun['physical_http_calls'] === 1, 'K2_AFTER_CLOSE_GENERATION_ONE_PHYSICAL_GET:' . json_encode([$afterCloseRun, $afterCloseState, $afterCloseSource], JSON_UNESCAPED_SLASHES));
    k1b_assert((string) $pdo->query('SELECT status FROM meli_notification_work_items WHERE id=' . $workId)->fetchColumn() === 'complete', 'K2_AFTER_CLOSE_GENERATION_COMPLETED');

    // An older out-of-order event is durable but must not reopen or downgrade the source.
    $beforePointers = k2_pointer_count($pdo, 9201, 9211, $workId);
    $oldEvent = k2_receive($service, 'k2-old-event', 99211, 9301, '2026-09-19T09:59:00Z');
    k1b_assert(k2_pointer_count($pdo, 9201, 9211, $workId) === $beforePointers, 'K2_OLD_EVENT_NO_NEW_POINTER');
    k1b_assert((string) $pdo->query('SELECT status FROM meli_notification_work_items WHERE id=' . $workId)->fetchColumn() === 'complete', 'K2_OLD_EVENT_DOES_NOT_REOPEN_SOURCE');
    k1b_assert((string) $pdo->query('SELECT disposition FROM meli_notification_events WHERE id=' . (int) $oldEvent['event_id'])->fetchColumn() === 'out_of_order', 'K2_OLD_EVENT_CLASSIFIED');

    // Tenant identity participates in both source and canonical idempotency.
    k2_receive($service, 'k2-company-b', 99221, 9301, '2026-09-19T10:00:04Z');
    $tenantBWork = (int) $pdo->query("SELECT id FROM meli_notification_work_items WHERE meli_account_id=9221 AND remote_resource_id='9301'")->fetchColumn();
    k1b_assert($tenantBWork > 0 && $tenantBWork !== $workId, 'K2_TENANT_SOURCE_ISOLATED');
    $tenantBPointer = k2_active_pointer($pdo, 9202, 9221, $tenantBWork);
    k1b_assert($tenantBPointer > 0, 'K2_TENANT_POINTER_ISOLATED');

    // A legitimate pause can put the canonical pointer in review before the
    // notification domain increments its own attempts. Resume must reopen
    // exactly that protected pointer without manufacturing a new identity.
    $paused = (new NotificationWorkItemService())->pause(9221, [9221]);
    k1b_assert($paused === 1, 'K2_PAUSE_SOURCE_WITH_ZERO_DOMAIN_ATTEMPTS');
    k2_wait_until_pointer_due($pdo, $tenantBPointer);
    $pausedRun = k2_run($pdo, 1, 9221);
    k1b_assert($pausedRun['physical_http_calls'] === 0, 'K2_PAUSED_CYCLE_ZERO_HTTP');
    k1b_assert((string) $pdo->query('SELECT state FROM queue_v4_clean_jobs WHERE id=' . $tenantBPointer)->fetchColumn() === 'review', 'K2_PAUSED_POINTER_REVIEW_HELD');
    k1b_assert((int) $pdo->query('SELECT attempts FROM meli_notification_work_items WHERE id=' . $tenantBWork)->fetchColumn() === 0, 'K2_PAUSED_SOURCE_ATTEMPTS_UNCHANGED');
    $resumedWithoutSyntheticAttempt = (new NotificationWorkItemService())->resume(9221, [9221]);
    k1b_assert($resumedWithoutSyntheticAttempt === 1, 'K2_RESUME_WITH_REAL_COUNTER_REACTIVATED');
    k1b_assert(k2_active_pointer($pdo, 9202, 9221, $tenantBWork) === $tenantBPointer, 'K2_RESUME_REUSES_EXACT_PAUSED_POINTER');
    k1b_assert((new NotificationWorkItemService())->resume(9221, [9221]) === 0, 'K2_RESUME_REPLAY_NO_DUPLICATE');
    k1b_assert(k2_pointer_count($pdo, 9202, 9221, $tenantBWork) === 1, 'K2_RESUME_REPLAY_ONE_LOGICAL_POINTER');

    // Exact race window: A has already calculated completed, B commits E2
    // through the real receiver on a second PDO, then A finalizes its pointer.
    $raceFirst = k2_receive($service, 'k2-race-e1', 99231, 9350, '2026-09-19T10:00:10Z');
    k1b_assert($raceFirst['accepted'] === true, 'K2_RACE_E1_ACCEPTED');
    $raceWork = (int) $pdo->query("SELECT id FROM meli_notification_work_items WHERE meli_account_id=9231 AND remote_resource_id='9350'")->fetchColumn();
    $racePointer = k2_active_pointer($pdo, 9201, 9231, $raceWork);
    k2_wait_until_pointer_due($pdo, $racePointer);
    Cap2DomainsWire::$responses['/questions/9350'] = [200, ['id' => 9350, 'text' => 'K2 race question', 'status' => 'UNANSWERED', 'seller_id' => 99231]];
    $barrierReached = false;
    $raceSecond = null;
    $raceRun = k2_run_with_finalize_barrier(
        $pdo,
        1,
        9231,
        static function (array $job, array $outcome) use ($pdo, &$barrierReached, &$raceSecond): void {
            if ((string) ($outcome['state'] ?? '') !== 'completed') {
                return;
            }
            $barrierReached = true;
            $pdoB = new PDO(
                'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
                (string) getenv('DB_USER'),
                (string) getenv('DB_PASS'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
            );
            $pdoB->exec("SET time_zone='+00:00'");
            Database::setConnection($pdoB);
            try {
                $raceSecond = k2_receive(new WebhookService(), 'k2-race-e2', 99231, 9350, '2026-09-19T10:00:11Z');
            } finally {
                Database::setConnection($pdo);
            }
        }
    );
    k1b_assert($barrierReached, 'K2_RACE_BARRIER_AFTER_DECISION_REACHED');
    k1b_assert(is_array($raceSecond) && $raceSecond['accepted'] === true, 'K2_RACE_E2_COMMITTED');
    k1b_assert($raceRun['physical_http_calls'] === 1, 'K2_RACE_A_ONE_PHYSICAL_GET');
    k1b_assert((string) $pdo->query('SELECT status FROM meli_notification_work_items WHERE id=' . $raceWork)->fetchColumn() === 'pending', 'K2_RACE_E2_REMAINS_PENDING');
    k1b_assert((string) $pdo->query('SELECT state FROM queue_v4_clean_jobs WHERE id=' . $racePointer)->fetchColumn() === 'waiting', 'K2_RACE_POINTER_REMAINS_EXECUTABLE');
    k2_wait_until_pointer_due($pdo, $racePointer);
    $raceClose = k2_run($pdo, 1, 9231);
    k1b_assert($raceClose['physical_http_calls'] === 1, 'K2_RACE_E2_PROCESSED_ONCE');
    k1b_assert((string) $pdo->query('SELECT status FROM meli_notification_work_items WHERE id=' . $raceWork)->fetchColumn() === 'complete', 'K2_RACE_SOURCE_COMPLETED');
    k1b_assert((string) $pdo->query('SELECT state FROM queue_v4_clean_jobs WHERE id=' . $racePointer)->fetchColumn() === 'completed', 'K2_RACE_POINTER_COMPLETED');
    $racePointersBeforeReplay = k2_pointer_count($pdo, 9201, 9231, $raceWork);
    $raceReplay = k2_receive($service, 'k2-race-e2', 99231, 9350, '2026-09-19T10:00:11Z');
    k1b_assert($raceReplay['duplicate'] === true, 'K2_RACE_E2_EXACT_REPLAY_DEDUPED');
    k1b_assert(k2_pointer_count($pdo, 9201, 9231, $raceWork) === $racePointersBeforeReplay, 'K2_RACE_E2_REPLAY_ZERO_NEW_POINTERS');

    $beforeUnknown = (int) $pdo->query('SELECT COUNT(*) FROM meli_notification_work_items')->fetchColumn();
    $unknown = k2_receive($service, 'k2-unknown-account', 99999991, 9303, '2026-09-19T10:00:05Z');
    k1b_assert($unknown['accepted'] === false && $unknown['http_status'] === 403, 'K2_UNKNOWN_ACCOUNT_REJECTED');
    k1b_assert((int) $pdo->query('SELECT COUNT(*) FROM meli_notification_work_items')->fetchColumn() === $beforeUnknown, 'K2_UNKNOWN_ACCOUNT_ZERO_EXECUTABLE_WORK');

    $nonActionable = k2_receive_topic($service, 'k2-payment', 'payments', '/payments/9304', 99211, '2026-09-19T10:00:06Z');
    k1b_assert($nonActionable['accepted'] === true, 'K2_NON_ACTIONABLE_DURABLY_CLASSIFIED');
    $paymentWork = (int) $pdo->query("SELECT id FROM meli_notification_work_items WHERE meli_account_id=9211 AND resource_type='payment' AND remote_resource_id='9304'")->fetchColumn();
    k1b_assert($paymentWork > 0, 'K2_NON_ACTIONABLE_SOURCE_RECORDED');
    k1b_assert(k2_active_pointer($pdo, 9201, 9211, $paymentWork) === 0, 'K2_NON_ACTIONABLE_ZERO_EXECUTABLE_POINTER');

    $invalid = $service->receiveResult('{not-json', 'k2_local_test', false);
    k1b_assert($invalid['accepted'] === false && $invalid['http_status'] === 400, 'K2_INVALID_PAYLOAD_REJECTED');

    // Retry reopens exactly the review produced by the normal worker without
    // changing the notification attempt counter or fabricating a new event.
    k2_receive($service, 'k2-retry-e1', 99251, 9360, '2026-09-19T10:00:20Z');
    $retryWork = (int) $pdo->query("SELECT id FROM meli_notification_work_items WHERE meli_account_id=9251 AND remote_resource_id='9360'")->fetchColumn();
    $retryPointer = k2_active_pointer($pdo, 9202, 9251, $retryWork);
    $pdo->exec('UPDATE meli_notification_work_items SET status="error" WHERE id=' . $retryWork);
    k2_wait_until_pointer_due($pdo, $retryPointer);
    $retryReviewRun = k2_run($pdo, 1, 9251);
    k1b_assert($retryReviewRun['physical_http_calls'] === 0, 'K2_RETRY_REVIEW_ZERO_HTTP');
    k1b_assert((string) $pdo->query('SELECT state FROM queue_v4_clean_jobs WHERE id=' . $retryPointer)->fetchColumn() === 'review', 'K2_RETRY_POINTER_REVIEW_HELD');
    k1b_assert((int) $pdo->query('SELECT attempts FROM meli_notification_work_items WHERE id=' . $retryWork)->fetchColumn() === 0, 'K2_RETRY_REAL_ATTEMPT_COUNTER_ZERO');
    $retried = (new NotificationWorkItemService())->retry($retryWork, [9251]);
    k1b_assert($retried === 1, 'K2_RETRY_SOURCE_REACTIVATED');
    k1b_assert(k2_active_pointer($pdo, 9202, 9251, $retryWork) === $retryPointer, 'K2_RETRY_REUSES_EXACT_REVIEW_POINTER');
    k1b_assert((new NotificationWorkItemService())->retry($retryWork, [9251]) === 0, 'K2_RETRY_REPLAY_NO_DUPLICATE');
    Cap2DomainsWire::$responses['/questions/9360'] = [200, ['id' => 9360, 'text' => 'K2 retry question', 'status' => 'UNANSWERED', 'seller_id' => 99251]];
    k2_wait_until_pointer_due($pdo, $retryPointer);
    k1b_assert(k2_run($pdo, 1, 9251)['physical_http_calls'] === 1, 'K2_RETRY_NATURAL_CLOSE_ONE_GET');

    // The selective worker recovery executes its real candidate query and
    // restores the same review pointer within the recovery transaction.
    k2_receive($service, 'k2-worker-recovery-e1', 99261, 9361, '2026-09-19T10:00:21Z');
    $workerRecoveryWork = (int) $pdo->query("SELECT id FROM meli_notification_work_items WHERE meli_account_id=9261 AND remote_resource_id='9361'")->fetchColumn();
    $workerRecoveryPointer = k2_active_pointer($pdo, 9201, 9261, $workerRecoveryWork);
    $pdo->exec('UPDATE meli_notification_work_items SET status="error",last_error_code="processing_error",last_error_stage="processing",last_error_diagnostic_id="K2-WR-1",last_processed_at=UTC_TIMESTAMP() WHERE id=' . $workerRecoveryWork);
    k2_wait_until_pointer_due($pdo, $workerRecoveryPointer);
    k2_run($pdo, 1, 9261);
    $pdo->prepare('INSERT INTO system_logs(level,message,context_json) VALUES("error","K2 worker recovery fixture",?)')->execute([
        json_encode(['reference' => 'K2-WR-1', 'module' => 'notifications', 'stage' => 'processing', 'error' => 'There is already an active transaction'], JSON_THROW_ON_ERROR),
    ]);
    $workerRecovery = (new NotificationWorkerRecoveryService())->recoverKnownErrors(5, true, [9261]);
    k1b_assert(($workerRecovery['recovered'] ?? 0) === 1, 'K2_WORKER_RECOVERY_EXECUTED');
    k1b_assert(k2_active_pointer($pdo, 9201, 9261, $workerRecoveryWork) === $workerRecoveryPointer, 'K2_WORKER_RECOVERY_POINTER_RESTORED');

    // The public collation canary uses the modified reactivation entry with
    // a minimal confirmed 1267 fixture; no reflection or text-only proof.
    k2_receive($service, 'k2-collation-recovery-e1', 99271, 9362, '2026-09-19T10:00:22Z');
    $collationWork = (int) $pdo->query("SELECT id FROM meli_notification_work_items WHERE meli_account_id=9271 AND remote_resource_id='9362'")->fetchColumn();
    $collationPointer = k2_active_pointer($pdo, 9202, 9271, $collationWork);
    $pdo->exec('UPDATE meli_notification_work_items SET status="error",last_error_code="processing_error",last_error_stage="processing",last_error_diagnostic_id="K2-COLL-1",last_processed_at=UTC_TIMESTAMP() WHERE id=' . $collationWork);
    k2_wait_until_pointer_due($pdo, $collationPointer);
    k2_run($pdo, 1, 9271);
    $pdo->prepare('INSERT INTO system_logs(level,message,context_json) VALUES("error","K2 collation recovery fixture",?)')->execute([
        json_encode([
            'reference' => 'K2-COLL-1',
            'module' => 'notifications',
            'stage' => 'processing',
            'driver_code' => '1267',
            'error' => 'Illegal mix of collations utf8mb4_general_ci and utf8mb4_unicode_ci (1267)',
        ], JSON_THROW_ON_ERROR),
    ]);
    $collationRecovery = (new NotificationCollationRecoveryService())->createCanary(0, [9271]);
    k1b_assert(in_array((string) ($collationRecovery['status'] ?? ''), ['canary_running', 'canary_passed'], true), 'K2_COLLATION_RECOVERY_EXECUTED');
    k1b_assert(k2_active_pointer($pdo, 9202, 9271, $collationWork) === $collationPointer, 'K2_COLLATION_RECOVERY_POINTER_RESTORED');

    // An unresolved physical marker keeps a review protected. Retry rolls its
    // source transition back instead of claiming a successful reactivation.
    k2_receive($service, 'k2-uncertain-e1', 99281, 9363, '2026-09-19T10:00:23Z');
    $uncertainWork = (int) $pdo->query("SELECT id FROM meli_notification_work_items WHERE meli_account_id=9281 AND remote_resource_id='9363'")->fetchColumn();
    $uncertainPointer = k2_active_pointer($pdo, 9201, 9281, $uncertainWork);
    $pdo->exec('UPDATE meli_notification_work_items SET status="error" WHERE id=' . $uncertainWork);
    k2_wait_until_pointer_due($pdo, $uncertainPointer);
    k2_run($pdo, 1, 9281);
    $uncertainGeneration = (int) $pdo->query('SELECT lease_generation FROM queue_v4_clean_jobs WHERE id=' . $uncertainPointer)->fetchColumn();
    $pdo->prepare("INSERT INTO queue_v4_clean_transport_events(company_id,meli_account_id,source_kind,work_id,lease_generation,request_id,method,endpoint_key,dispatch_state,physical_started_at) VALUES(9201,9281,'queue',?,?,?,'GET','notification_work_item','PHYSICAL_STARTED',UTC_TIMESTAMP(3))")
        ->execute([$uncertainPointer, $uncertainGeneration, 'k2-uncertain-' . bin2hex(random_bytes(4))]);
    $uncertainDenied = false;
    $uncertainReason = '';
    try {
        (new NotificationWorkItemService())->retry($uncertainWork, [9281]);
    } catch (Throwable $error) {
        $uncertainReason = $error->getMessage();
        $uncertainDenied = str_contains($error->getMessage(), 'REVIEW_HELD')
            || str_contains($error->getMessage(), 'UNRESOLVED_TRANSPORT_HELD');
    }
    k1b_assert($uncertainDenied, 'K2_UNCERTAIN_REVIEW_NOT_REOPENED:' . $uncertainReason);
    k1b_assert((string) $pdo->query('SELECT status FROM meli_notification_work_items WHERE id=' . $uncertainWork)->fetchColumn() === 'error', 'K2_UNCERTAIN_RETRY_SOURCE_ROLLED_BACK');

    // Failure at canonical insertion must roll back the event and source atomically.
    $pdo->exec("CREATE TRIGGER k2_fail_admission BEFORE INSERT ON queue_v4_clean_jobs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='K2_SYNTHETIC_ADMISSION_FAILURE'");
    $eventCount = (int) $pdo->query('SELECT COUNT(*) FROM meli_notification_events')->fetchColumn();
    $failed = k2_receive($service, 'k2-admission-failure', 99211, 9399, '2026-09-19T10:00:07Z', false);
    $pdo->exec('DROP TRIGGER k2_fail_admission');
    k1b_assert($failed['accepted'] === false && $failed['http_status'] === 503, 'K2_ADMISSION_FAILURE_NOT_FALSE_ACKNOWLEDGED');
    k1b_assert((int) $pdo->query('SELECT COUNT(*) FROM meli_notification_events')->fetchColumn() === $eventCount, 'K2_ADMISSION_FAILURE_ROLLS_BACK_EVENT');
    k1b_assert((int) $pdo->query("SELECT COUNT(*) FROM meli_notification_work_items WHERE remote_resource_id='9399'")->fetchColumn() === 0, 'K2_ADMISSION_FAILURE_ROLLS_BACK_SOURCE');

    // Debounce is still an availability fence and never performs HTTP while
    // receiving or admitting the event.
    $settings->set('notifications.debounce_seconds', '2', 'notifications');
    AppSettingsService::clearCache();
    $beforeDebounceCalls = count(Cap2DomainsWire::$calls);
    k2_receive($service, 'k2-debounce', 99241, 9310, '2026-09-19T10:00:30Z');
    $debouncedWork = (int) $pdo->query("SELECT id FROM meli_notification_work_items WHERE meli_account_id=9241 AND remote_resource_id='9310'")->fetchColumn();
    $debouncedPointer = k2_active_pointer($pdo, 9201, 9241, $debouncedWork);
    k1b_assert($debouncedPointer > 0, 'K2_DEBOUNCE_POINTER_DURABLE');
    k1b_assert((int) $pdo->query('SELECT available_at>UTC_TIMESTAMP(3) FROM queue_v4_clean_jobs WHERE id=' . $debouncedPointer)->fetchColumn() === 1, 'K2_DEBOUNCE_AVAILABILITY_PRESERVED');
    k1b_assert(count(Cap2DomainsWire::$calls) === $beforeDebounceCalls, 'K2_DEBOUNCE_ADMISSION_ZERO_HTTP');
    k1b_assert(k2_run($pdo, 1, 9241)['physical_http_calls'] === 0, 'K2_DEBOUNCE_BEFORE_DUE_ZERO_HTTP');
    Cap2DomainsWire::$responses['/questions/9310'] = [200, ['id' => 9310, 'text' => 'K2 debounce question', 'status' => 'UNANSWERED', 'seller_id' => 99241]];
    k2_wait_until_pointer_due($pdo, $debouncedPointer);
    k1b_assert(k2_run($pdo, 1, 9241)['physical_http_calls'] === 1, 'K2_DEBOUNCE_AFTER_DUE_ONE_HTTP');
    k1b_assert((string) $pdo->query('SELECT state FROM queue_v4_clean_jobs WHERE id=' . $debouncedPointer)->fetchColumn() === 'completed', 'K2_DEBOUNCE_NATURAL_CLOSE');

    // Due time is necessary but not sufficient: source leases, manual
    // reservations, unresolved transport and query failures all fail closed.
    k2_receive($service, 'k2-wakeup-fences', 99241, 9311, '2026-09-19T10:00:31Z');
    $fencedWork = (int) $pdo->query("SELECT id FROM meli_notification_work_items WHERE meli_account_id=9241 AND remote_resource_id='9311'")->fetchColumn();
    $fencedPointer = k2_active_pointer($pdo, 9201, 9241, $fencedWork);
    k2_wait_until_pointer_due($pdo, $fencedPointer);
    $pdo->exec('UPDATE meli_notification_work_items SET locked_by="k2-active-lease",locked_at=UTC_TIMESTAMP(),lock_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE) WHERE id=' . $fencedWork);
    k1b_assert((new QueueV4CleanRepository($pdo))->releaseDueNotificationWaiting([9241], 9241) === 0, 'K2_WAKEUP_ACTIVE_SOURCE_LEASE_BLOCKED');
    k1b_assert((string) $pdo->query('SELECT state FROM queue_v4_clean_jobs WHERE id=' . $fencedPointer)->fetchColumn() === 'waiting', 'K2_WAKEUP_LEASE_PRESERVES_WAITING');
    $pdo->exec('UPDATE meli_notification_work_items SET locked_by=NULL,locked_at=NULL,lock_expires_at=NULL WHERE id=' . $fencedWork);

    $pdo->exec("INSERT INTO manual_campaigns(campaign_token,created_by_user_id,company_scope_key,scope_key,preset,status,configuration_json) VALUES('k2-wakeup-reservation-fixture-0000000001',1,9201,'account:9241','safe','active','{}')");
    $campaignId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO manual_campaign_operations(manual_campaign_id,queue_key,operation_key,meli_account_id,company_id) VALUES(?,'notification_fallback','question_exact',9241,9201)")->execute([$campaignId]);
    $operationId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO manual_campaign_items(manual_campaign_id,operation_id,queue_key,operation_key,source_id,meli_account_id,company_id,human_label,position_no) VALUES(?,?,'notification_fallback','question_exact',?,9241,9201,'K2 reservation fixture',1)")->execute([$campaignId, $operationId, (string) $fencedWork]);
    $campaignItemId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO manual_campaign_reservations(manual_campaign_id,manual_campaign_item_id,queue_key,source_id,company_id,meli_account_id,status,expires_at) VALUES(?,?,'notification_fallback',?,9201,9241,'active',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 5 MINUTE))")
        ->execute([$campaignId, $campaignItemId, (string) $fencedWork]);
    $reservationId = (int) $pdo->lastInsertId();
    k1b_assert((new QueueV4CleanRepository($pdo))->releaseDueNotificationWaiting([9241], 9241) === 0, 'K2_WAKEUP_ACTIVE_RESERVATION_BLOCKED');
    $pdo->exec('UPDATE manual_campaign_reservations SET status="released",released_at=UTC_TIMESTAMP(3) WHERE id=' . $reservationId);

    $pdo->prepare("INSERT INTO queue_v4_clean_transport_events(company_id,meli_account_id,source_kind,work_id,lease_generation,request_id,method,endpoint_key,dispatch_state,physical_started_at) VALUES(9201,9241,'queue',?,0,?,'GET','notification_work_item','PHYSICAL_STARTED',UTC_TIMESTAMP(3))")
        ->execute([$fencedPointer, 'k2-wakeup-unresolved-' . bin2hex(random_bytes(4))]);
    $transportId = (int) $pdo->lastInsertId();
    k1b_assert((new QueueV4CleanRepository($pdo))->releaseDueNotificationWaiting([9241], 9241) === 0, 'K2_WAKEUP_UNRESOLVED_TRANSPORT_BLOCKED');
    $pdo->exec('UPDATE queue_v4_clean_transport_events SET dispatch_state="RESPONSE_KNOWN",response_known_at=UTC_TIMESTAMP(3),http_status=503 WHERE id=' . $transportId);

    $pdo->exec("CREATE TRIGGER k2_fail_notification_wakeup BEFORE UPDATE ON queue_v4_clean_jobs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='K2_SYNTHETIC_WAKEUP_QUERY_FAILURE'");
    $wakeupFailedClosed = false;
    try {
        (new QueueV4CleanRepository($pdo))->releaseDueNotificationWaiting([9241], 9241);
    } catch (Throwable $error) {
        $wakeupFailedClosed = str_contains($error->getMessage(), 'K2_SYNTHETIC_WAKEUP_QUERY_FAILURE');
    } finally {
        $pdo->exec('DROP TRIGGER k2_fail_notification_wakeup');
    }
    k1b_assert($wakeupFailedClosed, 'K2_WAKEUP_INFRASTRUCTURE_ERROR_PROPAGATES');
    k1b_assert((string) $pdo->query('SELECT state FROM queue_v4_clean_jobs WHERE id=' . $fencedPointer)->fetchColumn() === 'waiting', 'K2_WAKEUP_QUERY_FAILURE_NOT_FALSE_RELEASED');

    // C1: the domain itself leases the source, incrementing attempts from 0
    // to 1. The simulated wire crosses the physical boundary but returns no
    // knowable response, leaving the real queue transport journal unresolved.
    // This scenario runs last because an unresolved physical attempt correctly
    // keeps the shared rhythm permit occupied until it is adjudicated.
    k2_receive($service, 'k2-uncertain-real-e1', 99301, 9364, '2026-09-19T10:00:24Z');
    $uncertainRealWork = (int) $pdo->query("SELECT id FROM meli_notification_work_items WHERE meli_account_id=9301 AND remote_resource_id='9364'")->fetchColumn();
    $uncertainRealPointer = k2_active_pointer($pdo, 9201, 9301, $uncertainRealWork);
    $uncertainRealPointersBefore = k2_pointer_count($pdo, 9201, 9301, $uncertainRealWork);
    Cap2DomainsWire::$responses['/questions/9364'] = [0, [], 'K2_SYNTHETIC_TIMEOUT_AFTER_PHYSICAL_START'];
    k2_wait_until_pointer_due($pdo, $uncertainRealPointer);
    $uncertainRealCallsBefore = count(Cap2DomainsWire::$calls);
    $uncertainRealRun = k2_run($pdo, 1, 9301);
    $uncertainRealSourceBeforeRetry = $pdo->query(
        'SELECT status,attempts,last_error_code,last_error_stage FROM meli_notification_work_items WHERE id=' . $uncertainRealWork
    )->fetch(PDO::FETCH_ASSOC);
    $uncertainRealTransport = (int) $pdo->query(
        "SELECT COUNT(*) FROM queue_v4_clean_transport_events WHERE company_id=9201 AND meli_account_id=9301"
        . " AND source_kind='queue' AND work_id=" . $uncertainRealPointer
        . " AND dispatch_state='PHYSICAL_STARTED' AND response_known_at IS NULL"
    )->fetchColumn();
    k1b_assert(count(Cap2DomainsWire::$calls) === $uncertainRealCallsBefore + 1, 'K2_UNCERTAIN_REAL_ONE_SIMULATED_PHYSICAL_ATTEMPT');
    k1b_assert(
        array_key_exists('physical_http_calls', $uncertainRealRun)
        && $uncertainRealRun['physical_http_calls'] === null
        && ($uncertainRealRun['physical_http_calls_certainty'] ?? '') === 'UNKNOWN'
        && (int) ($uncertainRealRun['unresolved_dispatches'] ?? 0) === 1
        && (int) ($uncertainRealRun['possible_physical_calls_max'] ?? 0) === 1
        && (int) (($uncertainRealRun['call_budget']['known_physical_calls'] ?? 0)) === 1,
        'K2_UNCERTAIN_REAL_RECEIPT_PRESERVES_UNKNOWN:' . json_encode($uncertainRealRun, JSON_UNESCAPED_SLASHES)
    );
    k1b_assert((int) ($uncertainRealSourceBeforeRetry['attempts'] ?? 0) === 1, 'K2_UNCERTAIN_REAL_DOMAIN_LEASE_INCREMENTED_ATTEMPT');
    k1b_assert(
        ($uncertainRealSourceBeforeRetry['status'] ?? '') === 'error'
        && ($uncertainRealSourceBeforeRetry['last_error_code'] ?? '') === 'remote_result_uncertain',
        'K2_UNCERTAIN_REAL_SOURCE_RETAINED_ERROR:' . json_encode($uncertainRealSourceBeforeRetry, JSON_UNESCAPED_SLASHES)
    );
    k1b_assert((string) $pdo->query('SELECT state FROM queue_v4_clean_jobs WHERE id=' . $uncertainRealPointer)->fetchColumn() === 'review', 'K2_UNCERTAIN_REAL_POINTER_REVIEW_HELD');
    k1b_assert($uncertainRealTransport === 1, 'K2_UNCERTAIN_REAL_TRANSPORT_JOURNAL_UNRESOLVED');

    Cap2DomainsWire::$responses['/questions/9364'] = [200, ['id' => 9364, 'text' => 'Must remain held', 'status' => 'UNANSWERED', 'seller_id' => 99301]];
    $uncertainRealDenied = false;
    $uncertainRealReason = '';
    try {
        (new NotificationWorkItemService())->retry($uncertainRealWork, [9301]);
    } catch (Throwable $error) {
        $uncertainRealReason = $error->getMessage();
        $uncertainRealDenied = str_contains($uncertainRealReason, 'REVIEW_HELD')
            || str_contains($uncertainRealReason, 'UNRESOLVED_TRANSPORT_HELD');
    }
    $uncertainRealSourceAfterRetry = $pdo->query(
        'SELECT status,attempts,last_error_code,last_error_stage FROM meli_notification_work_items WHERE id=' . $uncertainRealWork
    )->fetch(PDO::FETCH_ASSOC);
    $uncertainRealAfter = [
        'denied' => $uncertainRealDenied,
        'reason' => $uncertainRealReason,
        'source' => $uncertainRealSourceAfterRetry,
        'pointer_count' => k2_pointer_count($pdo, 9201, 9301, $uncertainRealWork),
        'active_pointer' => k2_active_pointer($pdo, 9201, 9301, $uncertainRealWork),
        'review_state' => (string) $pdo->query('SELECT state FROM queue_v4_clean_jobs WHERE id=' . $uncertainRealPointer)->fetchColumn(),
        'unresolved_transport' => (int) $pdo->query(
            "SELECT COUNT(*) FROM queue_v4_clean_transport_events WHERE company_id=9201 AND meli_account_id=9301"
            . " AND source_kind='queue' AND work_id=" . $uncertainRealPointer
            . " AND dispatch_state='PHYSICAL_STARTED' AND response_known_at IS NULL"
        )->fetchColumn(),
    ];
    k1b_assert($uncertainRealDenied, 'K2_UNCERTAIN_REAL_RETRY_NOT_REOPENED:' . json_encode($uncertainRealAfter, JSON_UNESCAPED_SLASHES));
    k1b_assert(
        ($uncertainRealSourceAfterRetry['status'] ?? '') === 'error'
        && (int) ($uncertainRealSourceAfterRetry['attempts'] ?? 0) === 1
        && ($uncertainRealSourceAfterRetry['last_error_code'] ?? '') === 'remote_result_uncertain',
        'K2_UNCERTAIN_REAL_RETRY_SOURCE_ROLLED_BACK:' . json_encode($uncertainRealAfter, JSON_UNESCAPED_SLASHES)
    );
    k1b_assert($uncertainRealAfter['pointer_count'] === $uncertainRealPointersBefore, 'K2_UNCERTAIN_REAL_RETRY_ZERO_NEW_POINTERS');
    k1b_assert($uncertainRealAfter['active_pointer'] === 0 && $uncertainRealAfter['review_state'] === 'review', 'K2_UNCERTAIN_REAL_RETRY_NO_EXECUTABLE_POINTER');
    k1b_assert($uncertainRealAfter['unresolved_transport'] === 1, 'K2_UNCERTAIN_REAL_RETRY_PRESERVES_TRANSPORT');

    // C3: a later accepted event changes both event identity and admission key,
    // but must remain durably coalesced behind the same unresolved source
    // fence already established by C1; no second physical attempt is needed.
    $uncertainLaterFirstEvent = (int) $pdo->query('SELECT latest_event_id FROM meli_notification_work_items WHERE id=' . $uncertainRealWork)->fetchColumn();
    $uncertainLaterAccepted = k2_receive($service, 'k2-uncertain-real-e2', 99301, 9364, '2026-09-19T10:00:25Z');
    $uncertainLaterLatestEvent = (int) $pdo->query('SELECT latest_event_id FROM meli_notification_work_items WHERE id=' . $uncertainRealWork)->fetchColumn();
    k1b_assert($uncertainLaterAccepted['accepted'] === true, 'K2_UNCERTAIN_LATER_EVENT_DURABLY_ACCEPTED');
    k1b_assert($uncertainLaterLatestEvent > $uncertainLaterFirstEvent, 'K2_UNCERTAIN_LATER_EVENT_ID_ADVANCED');
    k1b_assert(k2_pointer_count($pdo, 9201, 9301, $uncertainRealWork) === 1, 'K2_UNCERTAIN_LATER_EVENT_ZERO_ALTERNATE_POINTERS');
    $uncertainLaterPointers = $pdo->query(
        'SELECT id,state,last_error_class,idempotency_key FROM queue_v4_clean_jobs WHERE company_id=9201 AND meli_account_id=9301'
        . ' AND resource_id=' . $uncertainRealWork . ' ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);
    k1b_assert(
        k2_active_pointer($pdo, 9201, 9301, $uncertainRealWork) === 0,
        'K2_UNCERTAIN_LATER_EVENT_ZERO_EXECUTABLE_POINTERS:' . json_encode($uncertainLaterPointers, JSON_UNESCAPED_SLASHES)
    );

    // The same shared frontier also protects resume when an accepted later
    // event was subsequently paused. Its source transition must roll back.
    k1b_assert((new NotificationWorkItemService())->pause(9301, [9301]) === 1, 'K2_UNCERTAIN_LATER_SOURCE_PAUSED');
    $uncertainLaterResumeDenied = false;
    try {
        (new NotificationWorkItemService())->resume(9301, [9301]);
    } catch (Throwable $error) {
        $uncertainLaterResumeDenied = str_contains($error->getMessage(), 'UNRESOLVED_TRANSPORT_HELD')
            || str_contains($error->getMessage(), 'REVIEW_HELD');
    }
    k1b_assert($uncertainLaterResumeDenied, 'K2_UNCERTAIN_LATER_RESUME_NOT_REOPENED');
    k1b_assert((string) $pdo->query('SELECT status FROM meli_notification_work_items WHERE id=' . $uncertainRealWork)->fetchColumn() === 'paused', 'K2_UNCERTAIN_LATER_RESUME_SOURCE_ROLLED_BACK');

    // C5: unresolved evidence in another tenant/source does not contaminate a
    // normal recoverable review in company B.
    k2_receive($service, 'k2-uncertain-isolation-e1', 99302, 9366, '2026-09-19T10:00:27Z');
    $uncertainIsolationWork = (int) $pdo->query("SELECT id FROM meli_notification_work_items WHERE meli_account_id=9302 AND remote_resource_id='9366'")->fetchColumn();
    $uncertainIsolationPointer = k2_active_pointer($pdo, 9202, 9302, $uncertainIsolationWork);
    $pdo->exec('UPDATE meli_notification_work_items SET status="error" WHERE id=' . $uncertainIsolationWork);
    k2_wait_until_pointer_due($pdo, $uncertainIsolationPointer);
    k2_run($pdo, 1, 9302);
    k1b_assert((new NotificationWorkItemService())->retry($uncertainIsolationWork, [9302]) === 1, 'K2_UNCERTAIN_OTHER_TENANT_DOES_NOT_BLOCK_SAFE_RETRY');
    k1b_assert(k2_active_pointer($pdo, 9202, 9302, $uncertainIsolationWork) === $uncertainIsolationPointer, 'K2_UNCERTAIN_ISOLATION_REUSES_SAFE_POINTER');

    // C6 worker recovery reaches the same admission frontier. The recovery
    // transaction must roll back when historical transport remains unknown.
    $pdo->prepare('UPDATE meli_notification_work_items SET status="error",last_error_code="processing_error",last_error_stage="processing",last_error_diagnostic_id="K2-U-WR",last_processed_at=UTC_TIMESTAMP() WHERE id=?')->execute([$uncertainRealWork]);
    $pdo->prepare('INSERT INTO system_logs(level,message,context_json) VALUES("error","K2 uncertain worker recovery fixture",?)')->execute([
        json_encode(['reference' => 'K2-U-WR', 'module' => 'notifications', 'stage' => 'processing', 'error' => 'There is already an active transaction'], JSON_THROW_ON_ERROR),
    ]);
    $uncertainWorkerRecoveryDenied = false;
    try {
        (new NotificationWorkerRecoveryService())->recoverKnownErrors(5, true, [9301]);
    } catch (Throwable $error) {
        $uncertainWorkerRecoveryDenied = str_contains($error->getMessage(), 'UNRESOLVED_TRANSPORT_HELD')
            || str_contains($error->getMessage(), 'REVIEW_HELD');
    }
    k1b_assert($uncertainWorkerRecoveryDenied, 'K2_UNCERTAIN_WORKER_RECOVERY_NOT_REOPENED');
    k1b_assert((string) $pdo->query('SELECT status FROM meli_notification_work_items WHERE id=' . $uncertainRealWork)->fetchColumn() === 'error', 'K2_UNCERTAIN_WORKER_RECOVERY_SOURCE_ROLLED_BACK');
    k1b_assert(k2_pointer_count($pdo, 9201, 9301, $uncertainRealWork) === $uncertainRealPointersBefore, 'K2_UNCERTAIN_WORKER_RECOVERY_ZERO_NEW_POINTERS');

    // The collation-recovery entry shares the same canonical frontier. Its
    // run and source mutations must also roll back on unresolved transport.
    $pdo->prepare('UPDATE meli_notification_work_items SET status="error",last_error_code="processing_error",last_error_stage="processing",last_error_diagnostic_id="K2-U-COLL",last_processed_at=UTC_TIMESTAMP() WHERE id=?')->execute([$uncertainRealWork]);
    $pdo->prepare('INSERT INTO system_logs(level,message,context_json) VALUES("error","K2 uncertain collation recovery fixture",?)')->execute([
        json_encode([
            'reference' => 'K2-U-COLL',
            'module' => 'notifications',
            'stage' => 'processing',
            'driver_code' => '1267',
            'error' => 'Illegal mix of collations utf8mb4_general_ci and utf8mb4_unicode_ci (1267)',
        ], JSON_THROW_ON_ERROR),
    ]);
    $uncertainCollationRunsBefore = (int) $pdo->query('SELECT COUNT(*) FROM meli_notification_recovery_runs')->fetchColumn();
    $uncertainCollationDenied = false;
    try {
        (new NotificationCollationRecoveryService())->createCanary(0, [9301]);
    } catch (Throwable $error) {
        $uncertainCollationDenied = str_contains($error->getMessage(), 'UNRESOLVED_TRANSPORT_HELD')
            || str_contains($error->getMessage(), 'REVIEW_HELD');
    }
    k1b_assert($uncertainCollationDenied, 'K2_UNCERTAIN_COLLATION_RECOVERY_NOT_REOPENED');
    k1b_assert((string) $pdo->query('SELECT status FROM meli_notification_work_items WHERE id=' . $uncertainRealWork)->fetchColumn() === 'error', 'K2_UNCERTAIN_COLLATION_SOURCE_ROLLED_BACK');
    k1b_assert((int) $pdo->query('SELECT COUNT(*) FROM meli_notification_recovery_runs')->fetchColumn() === $uncertainCollationRunsBefore, 'K2_UNCERTAIN_COLLATION_RUN_ROLLED_BACK');
    k1b_assert(k2_pointer_count($pdo, 9201, 9301, $uncertainRealWork) === $uncertainRealPointersBefore, 'K2_UNCERTAIN_COLLATION_ZERO_NEW_POINTERS');

    // Positive control: once the exact durable transport is adjudicated as a
    // known response, its old history no longer blocks a legitimate retry.
    $pdo->prepare('UPDATE queue_v4_clean_transport_events SET dispatch_state="RESPONSE_KNOWN",response_known_at=UTC_TIMESTAMP(3),http_status=503 WHERE company_id=9201 AND meli_account_id=9301 AND source_kind="queue" AND work_id=? AND dispatch_state="PHYSICAL_STARTED" AND response_known_at IS NULL')->execute([$uncertainRealPointer]);
    k1b_assert((int) $pdo->query(
        "SELECT COUNT(*) FROM queue_v4_clean_transport_events WHERE company_id=9201 AND meli_account_id=9301"
        . " AND source_kind='queue' AND work_id=" . $uncertainRealPointer
        . " AND dispatch_state='PHYSICAL_STARTED' AND response_known_at IS NULL"
    )->fetchColumn() === 0, 'K2_RESOLVED_TRANSPORT_NO_LONGER_UNCERTAIN');
    k1b_assert((new NotificationWorkItemService())->retry($uncertainRealWork, [9301]) === 1, 'K2_RESOLVED_TRANSPORT_ALLOWS_LEGITIMATE_RETRY');
    k1b_assert(k2_pointer_count($pdo, 9201, 9301, $uncertainRealWork) === $uncertainRealPointersBefore + 1, 'K2_RESOLVED_TRANSPORT_ONE_NEW_ATTEMPT_POINTER');
    k1b_assert(k2_active_pointer($pdo, 9201, 9301, $uncertainRealWork) > 0, 'K2_RESOLVED_TRANSPORT_EXECUTABLE_POINTER_RESTORED');

    k1b_assert((string) getenv('ML_WRITE_ENABLED') === 'false', 'K2_REMOTE_WRITES_DISABLED');
    echo "STATUS=PASS K2_NOTIFICATION_CANONICAL_ADMISSION_MYSQL\n";
    echo 'PHYSICAL_HTTP_CALLS=' . count(Cap2DomainsWire::$calls) . "\nREAL_MELI_HTTP=0\n";
} finally {
    QueueV4CleanCycleBudget::clear();
    $db->cleanup();
}

/** @return array<string,mixed> */
function k2_receive(WebhookService $service, string $notificationId, int $userId, int $questionId, string $sentAt, bool $allowSpool = true): array
{
    return k2_receive_topic($service, $notificationId, 'questions', '/questions/' . $questionId, $userId, $sentAt, $allowSpool);
}

/** @return array<string,mixed> */
function k2_receive_topic(WebhookService $service, string $notificationId, string $topic, string $resource, int $userId, string $sentAt, bool $allowSpool = true): array
{
    $raw = json_encode([
        '_id' => $notificationId,
        'topic' => $topic,
        'resource' => $resource,
        'user_id' => $userId,
        'application_id' => '123456',
        'attempts' => 1,
        'sent' => $sentAt,
    ], JSON_THROW_ON_ERROR);
    return $service->receiveResult($raw, 'k2_local_test', $allowSpool);
}

/** @return array{accepted:int,outputs:list<string>} */
function k2_receive_concurrently(PDO $pdo, string $qaRoot, int $userId, int $questionId): array
{
    $barrier = $qaRoot . '/concurrency-' . bin2hex(random_bytes(4));
    if (!mkdir($barrier, 0770, true) && !is_dir($barrier)) {
        throw new RuntimeException('K2_CONCURRENT_BARRIER_CREATE_FAILED');
    }
    $contextPath = $barrier . '/context.json';
    $context = [
        'env' => [
            'APP_ENV' => 'test',
            'ML_WRITE_ENABLED' => 'false',
            'DB_HOST' => (string) getenv('DB_HOST'),
            'DB_PORT' => (string) getenv('DB_PORT'),
            'DB_USER' => (string) getenv('DB_USER'),
            'DB_PASS' => (string) getenv('DB_PASS'),
            'DB_NAME' => (string) getenv('DB_NAME'),
            'APP_KEY' => (string) getenv('APP_KEY'),
            'MELI_CLIENT_ID' => (string) getenv('MELI_CLIENT_ID'),
            'WEBHOOK_SPOOL_ENABLED' => 'false',
            'ERP_PRIVATE_PATH' => (string) getenv('ERP_PRIVATE_PATH'),
        ],
        'installation_root' => ERP_INSTALLATION_ROOT,
        'barrier' => $barrier,
        'user_id' => $userId,
        'question_id' => $questionId,
        'sent_at' => '2026-09-19T10:00:00Z',
    ];
    file_put_contents($contextPath, json_encode($context, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    $processes = [];
    foreach (['a', 'b'] as $actor) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/k2_notification_concurrent_actor.php', $contextPath, $actor, 'k2-concurrent-' . $actor],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__)
        );
        if (!is_resource($process)) {
            throw new RuntimeException('K2_CONCURRENT_PROCESS_START_FAILED');
        }
        fclose($pipes[0]);
        $processes[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }
    $deadline = microtime(true) + 10.0;
    while ((!is_file($barrier . '/ready-a') || !is_file($barrier . '/ready-b')) && microtime(true) < $deadline) {
        usleep(10000);
    }
    k1b_assert(is_file($barrier . '/ready-a') && is_file($barrier . '/ready-b'), 'K2_CONCURRENT_ACTORS_READY');
    file_put_contents($barrier . '/go', 'go');

    $accepted = 0;
    $outputs = [];
    foreach ($processes as $entry) {
        $stdout = stream_get_contents($entry['stdout']);
        $stderr = stream_get_contents($entry['stderr']);
        fclose($entry['stdout']);
        fclose($entry['stderr']);
        $exit = proc_close($entry['process']);
        $outputs[] = trim($stdout . "\n" . $stderr);
        $result = json_decode(trim($stdout), true);
        if ($exit === 0 && is_array($result) && !empty($result['accepted'])) {
            $accepted++;
        }
    }
    if ($accepted !== 2) {
        $logs = $pdo->query("SELECT message,context_json FROM system_logs WHERE message LIKE '%webhook%' ORDER BY id DESC LIMIT 5")
            ->fetchAll(PDO::FETCH_ASSOC);
        $outputs[] = 'SYSTEM_LOGS=' . json_encode($logs, JSON_UNESCAPED_SLASHES);
    }
    return ['accepted' => $accepted, 'outputs' => $outputs];
}

function k2_pointer_count(PDO $pdo, int $companyId, int $accountId, int $workId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM queue_v4_clean_jobs
        WHERE company_id=? AND meli_account_id=? AND job_type='domain_exact' AND resource_id=?
          AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))='notification_work_item'");
    $stmt->execute([$companyId, $accountId, (string) $workId]);
    return (int) $stmt->fetchColumn();
}

function k2_active_pointer(PDO $pdo, int $companyId, int $accountId, int $workId): int
{
    $stmt = $pdo->prepare("SELECT id FROM queue_v4_clean_jobs
        WHERE company_id=? AND meli_account_id=? AND job_type='domain_exact' AND resource_id=?
          AND state IN ('ready','running','waiting')
          AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))='notification_work_item'
        ORDER BY id DESC LIMIT 1");
    $stmt->execute([$companyId, $accountId, (string) $workId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

function k2_latest_pointer(PDO $pdo, int $companyId, int $accountId, int $workId): int
{
    $stmt = $pdo->prepare("SELECT id FROM queue_v4_clean_jobs
        WHERE company_id=? AND meli_account_id=? AND job_type='domain_exact' AND resource_id=?
          AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))='notification_work_item'
        ORDER BY id DESC LIMIT 1");
    $stmt->execute([$companyId, $accountId, (string) $workId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

function k2_wait_until_pointer_due(PDO $pdo, int $pointerId): void
{
    $statement = $pdo->prepare('SELECT TIMESTAMPDIFF(MICROSECOND,UTC_TIMESTAMP(3),available_at) FROM queue_v4_clean_jobs WHERE id=?');
    $statement->execute([$pointerId]);
    $remainingMicros = max(0, (int) $statement->fetchColumn());
    usleep(max(1_200_000, min(5_000_000, $remainingMicros + 150_000)));
}

/** @return array<string,mixed> */
function k2_run(PDO $pdo, int $budget, int $accountId): array
{
    QueueV4CleanCycleBudget::start($budget);
    try {
        return (new QueueV4CleanWorker($pdo, new QueueV4CleanRepository($pdo)))
            ->run('test', $budget, 45, [$accountId], $accountId);
    } finally {
        QueueV4CleanCycleBudget::clear();
    }
}

/** @return array<string,mixed> */
function k2_run_with_finalize_barrier(PDO $pdo, int $budget, int $accountId, callable $barrier): array
{
    QueueV4CleanCycleBudget::start($budget);
    try {
        return (new QueueV4CleanWorker($pdo, new QueueV4CleanRepository($pdo), null, null, null, null, null, $barrier))
            ->run('test', $budget, 45, [$accountId], $accountId);
    } finally {
        QueueV4CleanCycleBudget::clear();
    }
}
