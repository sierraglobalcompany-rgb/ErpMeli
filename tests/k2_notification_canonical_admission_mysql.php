<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';
require __DIR__ . '/cap2_domains_wire_fixture.php';

use App\Core\Crypto;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\AppSettingsService;
use App\Services\Cap2DomainsWire;
use App\Services\Migrator;
use App\Services\NotificationWorkItemService;
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
        (9221,9202,'K2 account B',99221,'conectado')");
    $token = Crypto::encrypt('k2-test-access');
    $refresh = Crypto::encrypt('k2-test-refresh');
    $tokens = $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at) VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))');
    $tokens->execute([9211, $token, $refresh]);
    $tokens->execute([9221, $token, $refresh]);
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
    $concurrent = k2_receive_concurrently($pdo, $qaRoot, 99211, 9302);
    k1b_assert(
        $concurrent['accepted'] === 2,
        'K2_CONCURRENT_RECEIVERS_ACCEPTED:' . json_encode($concurrent['outputs'], JSON_UNESCAPED_SLASHES)
    );
    $concurrentWork = (int) $pdo->query("SELECT id FROM meli_notification_work_items WHERE meli_account_id=9211 AND resource_type='question' AND remote_resource_id='9302'")->fetchColumn();
    k1b_assert($concurrentWork > 0, 'K2_CONCURRENT_SOURCE_CREATED');
    k1b_assert(k2_pointer_count($pdo, 9201, 9211, $concurrentWork) === 1, 'K2_CONCURRENT_ONE_LOGICAL_POINTER');
    k1b_assert((int) $pdo->query('SELECT occurrence_count FROM meli_notification_work_items WHERE id=' . $concurrentWork)->fetchColumn() === 2, 'K2_CONCURRENT_OCCURRENCES_RETAINED');
    k1b_assert(Cap2DomainsWire::$calls === [], 'K2_CONCURRENT_ADMISSION_ZERO_HTTP');
    $concurrentPointer = k2_active_pointer($pdo, 9201, 9211, $concurrentWork);
    $pdo->exec('UPDATE queue_v4_clean_jobs SET state="waiting",available_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 DAY) WHERE id=' . $concurrentPointer);
    $pdo->exec('UPDATE meli_notification_work_items SET next_run_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE id=' . $concurrentWork);

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

    $pdo->exec('UPDATE queue_v4_clean_jobs SET state="ready",available_at=UTC_TIMESTAMP(3) WHERE id=' . $pointerId . ' AND state="waiting"');
    $secondRun = k2_run($pdo, 1, 9211);
    k1b_assert($secondRun['physical_http_calls'] === 1, 'K2_RERUN_ONE_PHYSICAL_GET');
    k1b_assert((string) $pdo->query('SELECT status FROM meli_notification_work_items WHERE id=' . $workId)->fetchColumn() === 'complete', 'K2_RERUN_SOURCE_COMPLETED');
    k1b_assert((string) $pdo->query('SELECT state FROM queue_v4_clean_jobs WHERE id=' . $pointerId)->fetchColumn() === 'completed', 'K2_RERUN_POINTER_COMPLETED');

    // A later legitimate event must create one new generation after the old pointer completed.
    k2_receive($service, 'k2-after-close', 99211, 9301, '2026-09-19T10:00:03Z');
    k1b_assert(k2_pointer_count($pdo, 9201, 9211, $workId) === 2, 'K2_AFTER_CLOSE_NEW_CANONICAL_GENERATION');
    k1b_assert(k2_active_pointer($pdo, 9201, 9211, $workId) > 0, 'K2_AFTER_CLOSE_NEW_GENERATION_EXECUTABLE');
    k1b_assert(count(Cap2DomainsWire::$calls) === 2, 'K2_AFTER_CLOSE_ADMISSION_ZERO_HTTP');
    $afterCloseRun = k2_run($pdo, 1, 9211);
    k1b_assert($afterCloseRun['physical_http_calls'] === 1, 'K2_AFTER_CLOSE_GENERATION_ONE_PHYSICAL_GET');
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
    k1b_assert(k2_active_pointer($pdo, 9202, 9221, $tenantBWork) > 0, 'K2_TENANT_POINTER_ISOLATED');

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

    // A recovery transition must restore canonical executability, not only change source status.
    $active = k2_latest_pointer($pdo, 9201, 9211, $workId);
    $pdo->exec('UPDATE queue_v4_clean_jobs SET state="completed" WHERE id=' . $active);
    $pdo->exec('UPDATE meli_notification_work_items SET status="error",attempts=attempts+1 WHERE id=' . $workId);
    $retried = (new NotificationWorkItemService())->retry($workId, [9211]);
    k1b_assert($retried === 1, 'K2_RETRY_SOURCE_REACTIVATED');
    k1b_assert(k2_active_pointer($pdo, 9201, 9211, $workId) > 0, 'K2_RETRY_CANONICAL_POINTER_RESTORED');

    // Failure at canonical insertion must roll back the event and source atomically.
    $pdo->exec("CREATE TRIGGER k2_fail_admission BEFORE INSERT ON queue_v4_clean_jobs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='K2_SYNTHETIC_ADMISSION_FAILURE'");
    $eventCount = (int) $pdo->query('SELECT COUNT(*) FROM meli_notification_events')->fetchColumn();
    $failed = k2_receive($service, 'k2-admission-failure', 99211, 9399, '2026-09-19T10:00:07Z', false);
    $pdo->exec('DROP TRIGGER k2_fail_admission');
    k1b_assert($failed['accepted'] === false && $failed['http_status'] === 503, 'K2_ADMISSION_FAILURE_NOT_FALSE_ACKNOWLEDGED');
    k1b_assert((int) $pdo->query('SELECT COUNT(*) FROM meli_notification_events')->fetchColumn() === $eventCount, 'K2_ADMISSION_FAILURE_ROLLS_BACK_EVENT');
    k1b_assert((int) $pdo->query("SELECT COUNT(*) FROM meli_notification_work_items WHERE remote_resource_id='9399'")->fetchColumn() === 0, 'K2_ADMISSION_FAILURE_ROLLS_BACK_SOURCE');

    // Administrative resume restores the canonical pointer in the same
    // transaction instead of merely changing the source status.
    $pdo->exec('UPDATE queue_v4_clean_jobs SET state="completed" WHERE id=' . $concurrentPointer);
    $pdo->exec('UPDATE meli_notification_work_items SET status="paused",attempts=attempts+1 WHERE id=' . $concurrentWork);
    $resumed = (new NotificationWorkItemService())->resume(9211, [9211]);
    k1b_assert($resumed === 1, 'K2_RESUME_SOURCE_REACTIVATED');
    k1b_assert(k2_active_pointer($pdo, 9201, 9211, $concurrentWork) > 0, 'K2_RESUME_CANONICAL_POINTER_RESTORED');

    // Debounce is still an availability fence and never performs HTTP while
    // receiving or admitting the event.
    $settings->set('notifications.debounce_seconds', '2', 'notifications');
    AppSettingsService::clearCache();
    $beforeDebounceCalls = count(Cap2DomainsWire::$calls);
    k2_receive($service, 'k2-debounce', 99211, 9310, '2026-09-19T10:00:08Z');
    $debouncedWork = (int) $pdo->query("SELECT id FROM meli_notification_work_items WHERE meli_account_id=9211 AND remote_resource_id='9310'")->fetchColumn();
    $debouncedPointer = k2_active_pointer($pdo, 9201, 9211, $debouncedWork);
    k1b_assert($debouncedPointer > 0, 'K2_DEBOUNCE_POINTER_DURABLE');
    k1b_assert((int) $pdo->query('SELECT available_at>UTC_TIMESTAMP(3) FROM queue_v4_clean_jobs WHERE id=' . $debouncedPointer)->fetchColumn() === 1, 'K2_DEBOUNCE_AVAILABILITY_PRESERVED');
    k1b_assert(count(Cap2DomainsWire::$calls) === $beforeDebounceCalls, 'K2_DEBOUNCE_ADMISSION_ZERO_HTTP');

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
