<?php

declare(strict_types=1);

$dsn = (string) (getenv('QUEUE_V4_CLEAN_TEST_DSN') ?: '');
if ($dsn === '') {
    fwrite(STDERR, "QUEUE_V4_CLEAN_TEST_DSN is required\n");
    exit(2);
}

$root = dirname(__DIR__);
$fixtureHome = sys_get_temp_dir() . '/erp-qv4-oauth-2384-' . bin2hex(random_bytes(6)) . '/home/user';
$private = $fixtureHome . '/.erp-meli-private';
$installation = $fixtureHome . '/domains/example.test/public_html/erp-meli';
mkdir($private, 0700, true);
mkdir($installation, 0700, true);
define('ERP_TEST_RUNTIME', true);
define('ERP_INSTALLATION_ROOT', $installation);
define('ERP_SHARED_ROOT', $installation . '/shared');
define('ERP_RELEASE_ROOT', $root);
$_SERVER['DOCUMENT_ROOT'] = $fixtureHome;
putenv('ERP_PRIVATE_PATH=' . $private);
putenv('APP_KEY=queue-v4-oauth-2384-local-only');
putenv('ML_WRITE_ENABLED=false');
$_ENV['ERP_PRIVATE_PATH'] = $private;
$_ENV['APP_KEY'] = 'queue-v4-oauth-2384-local-only';
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
use App\QueueV4Clean\QueueV4CleanOAuthStageContext;
use App\QueueV4Clean\QueueV4CleanOAuthSupervisor;
use App\QueueV4Clean\QueueV4CleanScheduler;
use App\Services\ApiExecutionMetadataContext;
use App\Services\ApiBudgetInfrastructureException;
use App\Services\AppSettingsService;
use App\Services\MeliApiClient;
use App\Services\MeliCliRuntimeCapabilityService;
use App\Services\MeliHttpTransportInterface;

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
$accounts = $pdo->query(
    'SELECT a.company_id,a.id,a.meli_user_id FROM meli_accounts a ORDER BY a.company_id,a.id LIMIT 3'
)->fetchAll(PDO::FETCH_ASSOC);
$assert(count($accounts) === 3, 'three_account_fixture_missing');
$target = $accounts[0];
$schedulerOwner = 'oauth-2384-scheduler';

$settings = new AppSettingsService();
foreach ([
    'oauth.auto_refresh_lead_seconds' => '3600',
    'oauth.auto_refresh_global_reserve_per_15m' => '3',
    'oauth.auto_refresh_account_reserve_per_15m' => '1',
] as $key => $value) {
    $settings->set($key, $value, 'qv4_oauth_2384_test');
}
AppSettingsService::clearCache();

$reset = static function (int $version = 1000) use ($pdo, $target, $schedulerOwner): void {
    QueueV4CleanOAuthStageContext::installTestHook(null);
    $pdo->exec('DELETE FROM oauth_refresh_operations');
    foreach (['api_remote_permits', 'api_budget_windows', 'api_rhythm_penalties', 'api_request_logs', 'api_error_logs', 'api_rhythm_states'] as $table) {
        $pdo->exec('DELETE FROM `' . $table . '`');
    }
    $pdo->exec("UPDATE meli_accounts SET status='conectado',last_error=NULL");
    $pdo->exec("UPDATE meli_tokens SET expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 7200 SECOND)");
    $update = $pdo->prepare(
        'UPDATE meli_tokens SET access_token_encrypted=?,refresh_token_encrypted=?,refresh_version=?,
                expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 600 SECOND) WHERE meli_account_id=?'
    );
    $update->execute([
        Crypto::encrypt('old-access-' . $version),
        Crypto::encrypt('old-refresh-' . $version),
        $version,
        (int) $target['id'],
    ]);
    $pdo->prepare(
        "UPDATE queue_v4_clean_leases SET owner_ref=?,acquired_at=UTC_TIMESTAMP(3),heartbeat_at=UTC_TIMESTAMP(3),
                expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 60 SECOND) WHERE lease_key='scheduler'"
    )->execute([$schedulerOwner]);
    $pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE',scheduler_enabled=1 WHERE control_key='primary'");
};
$latest = static function () use ($pdo): array {
    $row = $pdo->query('SELECT * FROM oauth_refresh_operations ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
};

final class QueueV4FakeOAuthTransport2384 implements MeliHttpTransportInterface
{
    public int $physicalCalls = 0;
    public function __construct(private readonly int $status = 200, private readonly bool $throwAfterFence = false) {}
    public function request(string $method, string $url, array $data, array $headers, bool $form, array $timeouts): array
    {
        QueueV4CleanOAuthStageContext::set(QueueV4CleanOAuthStageContext::OAUTH_DISPATCH_FENCE);
        QueueV4CleanOAuthDispatchFence::immediatelyBeforeCurl($method, (string) (parse_url($url, PHP_URL_PATH) ?: '/'));
        $this->physicalCalls++;
        if ($this->throwAfterFence) {
            throw new TypeError('fake_transport_crash_after_fence');
        }
        QueueV4CleanOAuthStageContext::set(QueueV4CleanOAuthStageContext::RESPONSE_KNOWN);
        QueueV4CleanOAuthDispatchFence::responseKnown($this->status);
        $body = $this->status >= 200 && $this->status < 300
            ? ['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 21600, 'token_type' => 'Bearer']
            : ['error' => $this->status === 429 ? 'too_many_requests' : 'server_error'];
        return ['status' => $this->status, 'body' => $body, 'headers' => [], 'curl_error' => '', 'duration_ms' => 1, 'wire_bytes' => 10, 'decoded_bytes' => 10];
    }
}

$realRun = static function (QueueV4FakeOAuthTransport2384 $transport) use ($pdo, $schedulerOwner): array {
    return (new QueueV4CleanOAuthSupervisor(
        $pdo,
        new QueueV4CleanOAuthOperationRepository($pdo),
        new AppSettingsService(),
        null,
        static fn (int $accountId): MeliApiClient => new MeliApiClient($accountId, $transport),
    ))->run($schedulerOwner);
};

// Camino real completo: sólo el último transporte físico es fake.
$reset(1001);
$transport = new QueueV4FakeOAuthTransport2384();
$summary = $realRun($transport);
$row = $latest();
$assert($transport->physicalCalls === 1 && $summary['completed'] === 1
    && $summary['physical_posts'] === 1 && $row['state'] === 'COMPLETED',
    'real_meli_client_path_failed:' . json_encode(['calls' => $transport->physicalCalls, 'summary' => $summary, 'row' => $row]));
$assert((int) $pdo->query('SELECT refresh_version FROM meli_tokens WHERE meli_account_id=' . (int) $target['id'])->fetchColumn() === 1002,
    'real_path_refresh_version_not_advanced');

// El mismo servicio real debe ignorar DOCUMENT_ROOT vacío sin depender del CWD.
$_SERVER['DOCUMENT_ROOT'] = '';
$reset(1003);
$emptyDocumentTransport = new QueueV4FakeOAuthTransport2384();
$emptyDocumentSummary = $realRun($emptyDocumentTransport);
$assert($emptyDocumentTransport->physicalCalls === 1 && $emptyDocumentSummary['completed'] === 1
    && $emptyDocumentSummary['physical_posts'] === 1 && $latest()['state'] === 'COMPLETED',
    'empty_document_root_real_oauth_path_failed');
$_SERVER['DOCUMENT_ROOT'] = $fixtureHome;

// Sin cURL: no se reclama ninguna operación y se aborta todo el ciclo.
$reset(1010);
(new QueueV4CleanOAuthOperationRepository($pdo))->schedule(
    (int) $target['company_id'], (int) $target['id'], (string) $target['meli_user_id'], 1010
);
$missingCurl = new MeliCliRuntimeCapabilityService(static fn (): array => [
    'php_version' => PHP_VERSION, 'cli_sapi' => true, 'curl_available' => false,
    'pdo_mysql_available' => true, 'json_available' => true, 'crypto_available' => true, 'fsync_available' => true,
]);
$blocked = (new QueueV4CleanOAuthSupervisor(
    $pdo,
    new QueueV4CleanOAuthOperationRepository($pdo),
    new AppSettingsService(),
    static fn (): array => throw new RuntimeException('must_not_claim'),
    null,
    $missingCurl,
))->run($schedulerOwner);
$row = $latest();
$assert($blocked['status'] === 'oauth_control_plane_blocked' && $blocked['claimed'] === 0
    && $blocked['abort_scheduler'] === true && $row['state'] === 'SCHEDULED'
    && (int) $row['remote_attempt_count'] === 0, 'curl_missing_claimed_or_dispatched');

// Un Throwable del propio inspector también queda contenido antes del claim.
$reset(1012);
(new QueueV4CleanOAuthOperationRepository($pdo))->schedule(
    (int) $target['company_id'], (int) $target['id'], (string) $target['meli_user_id'], 1012
);
$brokenInspector = new MeliCliRuntimeCapabilityService(
    static fn (): array => throw new TypeError('runtime_inspector_fault')
);
$preclaimFailure = (new QueueV4CleanOAuthSupervisor(
    $pdo,
    new QueueV4CleanOAuthOperationRepository($pdo),
    new AppSettingsService(),
    static fn (): array => throw new RuntimeException('must_not_claim'),
    null,
    $brokenInspector,
))->run($schedulerOwner);
$preclaimRow = $latest();
$assert($preclaimFailure['status'] === 'oauth_control_plane_blocked'
    && $preclaimFailure['claimed'] === 0
    && $preclaimFailure['abort_scheduler'] === true
    && ($preclaimFailure['diagnostic']['safe_stage'] ?? '') === QueueV4CleanOAuthStageContext::OAUTH_RUNTIME_PREFLIGHT
    && $preclaimRow['state'] === 'SCHEDULED'
    && (int) $preclaimRow['remote_attempt_count'] === 0,
    'runtime_inspector_throwable_claimed_or_escaped');

// La contención comienza inmediatamente después del COMMIT del claim. Una
// falla local antes incluso de leer el contador nunca deja RUNNING huérfano.
$reset(1015);
QueueV4CleanOAuthStageContext::installTestHook(static function (string $stage): void {
    if ($stage === QueueV4CleanOAuthStageContext::OAUTH_OPERATION_CLAIMED) {
        throw new TypeError('post_claim_local_fence_fault');
    }
});
$postClaim = $realRun(new QueueV4FakeOAuthTransport2384());
QueueV4CleanOAuthStageContext::installTestHook(null);
$postClaimRow = $latest();
$assert($postClaim['status'] === 'oauth_unexpected_contained'
    && $postClaim['abort_scheduler'] === true
    && $postClaim['waiting'] === 1
    && $postClaim['physical_posts'] === 0
    && $postClaim['physical_posts_known'] === true
    && $postClaimRow['state'] === 'WAITING', 'post_claim_throwable_left_running_or_unknown');

// Fallos genéricos antes de dispatch nunca dejan RUNNING ni permiten HTTP.
foreach ([
    QueueV4CleanOAuthStageContext::OAUTH_REFRESH_SERVICE,
    QueueV4CleanOAuthStageContext::OPERATION_PROFILE,
    QueueV4CleanOAuthStageContext::OWNERSHIP_GUARD,
    QueueV4CleanOAuthStageContext::METADATA_GUARD,
    QueueV4CleanOAuthStageContext::API_GUARD,
    QueueV4CleanOAuthStageContext::RHYTHM_RESERVATION,
    QueueV4CleanOAuthStageContext::BUDGET_RESERVATION,
    QueueV4CleanOAuthStageContext::TRANSPORT_PREPARE,
] as $index => $faultStage) {
    $reset(1100 + $index);
    $transport = new QueueV4FakeOAuthTransport2384();
    QueueV4CleanOAuthStageContext::installTestHook(static function (string $stage) use ($faultStage): void {
        if ($stage === $faultStage) {
            throw new TypeError('stage_fault');
        }
    });
    $contained = $realRun($transport);
    QueueV4CleanOAuthStageContext::installTestHook(null);
    $row = $latest();
    $assert($contained['status'] === 'oauth_unexpected_contained' && $contained['abort_scheduler'] === true
        && $transport->physicalCalls === 0 && $row['state'] === 'WAITING'
        && $row['remote_dispatch_state'] === 'NOT_DISPATCHED' && (int) $row['remote_attempt_count'] === 0,
        'predispatch_fault_not_contained:' . $faultStage);
}

// Fallos de preparación del cURL real se contienen antes de curl_exec.
foreach ([
    QueueV4CleanOAuthStageContext::CURL_INIT,
    QueueV4CleanOAuthStageContext::CURL_OPTIONS,
] as $index => $faultStage) {
    $reset(1180 + $index);
    QueueV4CleanOAuthStageContext::installTestHook(static function (string $stage) use ($faultStage): void {
        if ($stage === $faultStage) {
            throw new TypeError('curl_boundary_fault');
        }
    });
    $contained = (new QueueV4CleanOAuthSupervisor(
        $pdo, new QueueV4CleanOAuthOperationRepository($pdo), new AppSettingsService()
    ))->run($schedulerOwner);
    QueueV4CleanOAuthStageContext::installTestHook(null);
    $row = $latest();
    $assert($contained['status'] === 'oauth_unexpected_contained' && $row['state'] === 'WAITING'
        && (int) $row['remote_attempt_count'] === 0 && $row['remote_dispatch_state'] === 'NOT_DISPATCHED',
        'curl_boundary_fault_reached_remote:' . $faultStage);
}

// El fallo de infraestructura de presupuesto se clasifica por fence, no por texto.
$reset(1190);
$budgetInfrastructure = (new QueueV4CleanOAuthSupervisor(
    $pdo,
    new QueueV4CleanOAuthOperationRepository($pdo),
    new AppSettingsService(),
    static fn (): array => throw new ApiBudgetInfrastructureException('local_budget_infrastructure'),
))->run($schedulerOwner);
$assert($budgetInfrastructure['status'] === 'oauth_unexpected_contained'
    && $latest()['state'] === 'WAITING' && (int) $latest()['remote_attempt_count'] === 0,
    'budget_infrastructure_not_contained_before_dispatch');

// Un fence que no puede persistir conserva NOT_DISPATCHED y aborta el ciclo.
$reset(1191);
QueueV4CleanOAuthStageContext::installTestHook(static function (string $stage) use ($pdo): void {
    if ($stage === QueueV4CleanOAuthStageContext::OAUTH_DISPATCH_FENCE) {
        $pdo->exec("UPDATE queue_v4_clean_leases SET owner_ref='lost-before-fence' WHERE lease_key='scheduler'");
    }
});
$fenceFailure = (new QueueV4CleanOAuthSupervisor(
    $pdo, new QueueV4CleanOAuthOperationRepository($pdo), new AppSettingsService()
))->run($schedulerOwner);
QueueV4CleanOAuthStageContext::installTestHook(null);
$assert($fenceFailure['status'] === 'oauth_unexpected_contained' && $latest()['state'] === 'WAITING'
    && $latest()['remote_dispatch_state'] === 'NOT_DISPATCHED' && (int) $latest()['remote_attempt_count'] === 0,
    'fence_persist_failure_not_safe');

// Crash tras MAY_HAVE nunca se reintenta automáticamente.
$reset(1200);
$transport = new QueueV4FakeOAuthTransport2384(200, true);
$uncertain = $realRun($transport);
$row = $latest();
$assert($transport->physicalCalls === 1 && $uncertain['uncertain'] === 1
    && $row['state'] === 'REMOTE_UNCERTAIN' && (int) $row['remote_attempt_count'] === 1,
    'may_have_crash_not_uncertain');
$again = $realRun(new QueueV4FakeOAuthTransport2384());
$assert($again['claimed'] === 0, 'remote_uncertain_was_auto_retried');

// Respuestas conocidas: 429 espera; 5xx es incierta; 2xx malformado no repite POST.
foreach ([429 => 'WAITING', 503 => 'REMOTE_UNCERTAIN'] as $status => $expectedState) {
    $reset(1300 + $status);
    $transport = new QueueV4FakeOAuthTransport2384($status);
    $realRun($transport);
    $assert($transport->physicalCalls === 1 && $latest()['state'] === $expectedState,
        'known_status_policy_invalid:' . $status);
    if ($status === 429) {
        $delay = (int) $pdo->query(
            'SELECT TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(3),next_attempt_at)
             FROM oauth_refresh_operations ORDER BY id DESC LIMIT 1'
        )->fetchColumn();
        $assert($delay >= 240, 'known_429_did_not_preserve_canonical_backoff');
    }
}
$reset(1400);
$malformed = new class implements MeliHttpTransportInterface {
    public int $physicalCalls = 0;
    public function request(string $method, string $url, array $data, array $headers, bool $form, array $timeouts): array
    {
        QueueV4CleanOAuthDispatchFence::immediatelyBeforeCurl($method, (string) parse_url($url, PHP_URL_PATH));
        $this->physicalCalls++;
        QueueV4CleanOAuthDispatchFence::responseKnown(200);
        return ['status' => 200, 'body' => [], 'headers' => [], 'curl_error' => '', 'duration_ms' => 1, 'wire_bytes' => 2, 'decoded_bytes' => 2];
    }
};
$malformedSummary = (new QueueV4CleanOAuthSupervisor(
    $pdo, new QueueV4CleanOAuthOperationRepository($pdo), new AppSettingsService(), null,
    static fn (int $accountId): MeliApiClient => new MeliApiClient($accountId, $malformed),
))->run($schedulerOwner);
$assert($malformed->physicalCalls === 1 && $malformedSummary['uncertain'] === 1
    && $latest()['state'] === 'REMOTE_UNCERTAIN', 'known_2xx_without_escrow_was_retried');

// 2xx + escrow + fallo CAS: el siguiente run adopta localmente sin segundo POST.
$reset(1410);
$transport = new QueueV4FakeOAuthTransport2384();
QueueV4CleanOAuthStageContext::installTestHook(static function (string $stage): void {
    if ($stage === QueueV4CleanOAuthStageContext::TOKEN_DB_CAS) {
        throw new TypeError('db_cas_fault_after_escrow');
    }
});
$knownSuccessFailure = $realRun($transport);
QueueV4CleanOAuthStageContext::installTestHook(null);
$escrowPath = $private . '/queue-oauth-recovery/account-' . (int) $target['id'] . '.json';
$versionAfterFailure = (int) $pdo->query(
    'SELECT refresh_version FROM meli_tokens WHERE meli_account_id=' . (int) $target['id']
)->fetchColumn();
$assert($transport->physicalCalls === 1 && $knownSuccessFailure['waiting'] === 1
    && $latest()['state'] === 'WAITING' && $latest()['last_error_class'] === 'durable_recovery_pending'
    && is_file($escrowPath) && !is_link($escrowPath) && $versionAfterFailure === 1410,
    'known_2xx_escrow_not_queued_for_local_recovery');
$noSecondPost = new QueueV4FakeOAuthTransport2384();
$recovered = $realRun($noSecondPost);
$versionAfterRecovery = (int) $pdo->query(
    'SELECT refresh_version FROM meli_tokens WHERE meli_account_id=' . (int) $target['id']
)->fetchColumn();
$assert($noSecondPost->physicalCalls === 0 && $recovered['completed'] === 1
    && $latest()['state'] === 'COMPLETED' && $versionAfterRecovery === 1411
    && !file_exists($escrowPath) && !is_link($escrowPath),
    'known_2xx_escrow_recovery_repeated_post');

// Si la propia autoridad de contención no puede leerse, no se adivina NOT_DISPATCHED.
$reset(1420);
$containmentFailed = (new QueueV4CleanOAuthSupervisor(
    $pdo,
    new QueueV4CleanOAuthOperationRepository($pdo),
    new AppSettingsService(),
    static function () use ($pdo): array {
        $pdo->exec('DELETE FROM oauth_refresh_operations');
        throw new TypeError('containment_authority_missing');
    },
))->run($schedulerOwner);
$assert($containmentFailed['status'] === 'oauth_containment_failed'
    && $containmentFailed['abort_scheduler'] === true
    && isset($containmentFailed['containment_diagnostic']), 'containment_failure_was_guessed');

// Estado productivo exacto: dos leases expirados antes de dispatch se reparan; SCHEDULED se conserva.
$reset(1500);
$insert = $pdo->prepare(
    "INSERT INTO oauth_refresh_operations
     (company_id,meli_account_id,expected_meli_user_id,expected_refresh_version,state,next_attempt_at,
      lease_owner,lease_generation,lease_expires_at,remote_dispatch_state)
     VALUES (?,?,?,?,?,UTC_TIMESTAMP(3),?,1,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 5 SECOND),'NOT_DISPATCHED')"
);
foreach ($accounts as $index => $account) {
    $insert->execute([(int) $account['company_id'], (int) $account['id'], (string) $account['meli_user_id'], 1500 + $index,
        $index < 2 ? 'RUNNING' : 'SCHEDULED', $index < 2 ? 'expired-' . $index : null]);
}
$repaired = (new QueueV4CleanOAuthOperationRepository($pdo))->repairStale();
$states = $pdo->query('SELECT state FROM oauth_refresh_operations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$assert($repaired === 2 && $states === ['WAITING', 'WAITING', 'SCHEDULED'], 'production_stale_preimage_not_repaired_exactly');
$assert((new QueueV4CleanOAuthOperationRepository($pdo))->repairStale() === 0, 'stale_repair_not_idempotent');

// El launcher de scheduler no ejecuta Producer/Worker después de un Throwable no clasificado.
$reset(1600);
$pdo->exec("UPDATE queue_v4_clean_leases SET owner_ref=NULL,acquired_at=NULL,heartbeat_at=NULL,expires_at=NULL WHERE lease_key='scheduler'");
$jobsBefore = (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn();
$runsBefore = (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_runs')->fetchColumn();
QueueV4CleanOAuthStageContext::installTestHook(static function (string $stage): void {
    if ($stage === QueueV4CleanOAuthStageContext::OPERATION_PROFILE) {
        throw new TypeError('scheduler_abort_fault');
    }
});
$scheduler = (new QueueV4CleanScheduler($pdo))->run(10, 45);
QueueV4CleanOAuthStageContext::installTestHook(null);
$assert($scheduler['ok'] === false && $scheduler['status'] === 'oauth_unexpected_contained'
    && ($scheduler['producer']['skipped'] ?? false) === true && ($scheduler['worker']['skipped'] ?? false) === true
    && (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs')->fetchColumn() === $jobsBefore
    && (int) $pdo->query('SELECT COUNT(*) FROM queue_v4_clean_runs')->fetchColumn() === $runsBefore,
    'scheduler_continued_after_unclassified_oauth_throwable');

$source = (string) file_get_contents($root . '/app/QueueV4Clean/QueueV4CleanOAuthSupervisor.php');
$assert(!str_contains($source, "str_contains(\$error->getMessage(), 'recovery')"), 'message_substring_routing_present');
$assert(str_contains($source, 'catch (Throwable $error)') && str_contains($source, 'containUnexpected'), 'final_throwable_containment_missing');
$launcher = (string) file_get_contents($root . '/jobs/queue_v4_clean.php');
$assert(str_contains($launcher, 'diagnostic_id=') && !str_contains($launcher, '$error->getMessage()'), 'cli_diagnostic_not_sanitized');
$schedulerSource = (string) file_get_contents($root . '/app/QueueV4Clean/QueueV4CleanScheduler.php');
$oauthAbortOffset = strpos($schedulerSource, "if ((\$oauth['abort_scheduler'] ?? false) === true)");
$stageResetOffset = strpos($schedulerSource, 'QueueV4CleanOAuthStageContext::reset();', (int) $oauthAbortOffset + 1);
$producerOffset = strpos($schedulerSource, 'new QueueV4CleanProducer', (int) $oauthAbortOffset + 1);
$assert($oauthAbortOffset !== false && $stageResetOffset !== false && $producerOffset !== false
    && $oauthAbortOffset < $stageResetOffset && $stageResetOffset < $producerOffset,
    'oauth_stage_not_reset_before_producer');
$assert(str_contains($source, "'QUEUE_V4_CLEAN_OAUTH_PRECLAIM_FAILED'")
    && str_contains($source, 'knownRateLimitNextAttemptAt()')
    && str_contains($source, 'conservativeRateLimitNextSafeAt()'),
    'preclaim_or_canonical_429_containment_missing');
$normalCountOffset = strpos($source, '$after = $this->remoteAttemptCount($operation);');
$outerCatchOffset = strpos($source, '} catch (Throwable $error) {', (int) $normalCountOffset);
$assert($normalCountOffset !== false && $outerCatchOffset !== false && $normalCountOffset < $outerCatchOffset,
    'final_post_count_outside_throwable_containment');
$knownSuccessBody = substr(
    $source,
    (int) strpos($source, 'private function reconcileKnownSuccess'),
    (int) strpos($source, 'private function clearCommittedEscrowBestEffort')
        - (int) strpos($source, 'private function reconcileKnownSuccess')
);
$assert(strpos($knownSuccessBody, '$this->operations->complete') !== false
    && strpos($knownSuccessBody, '$this->clearCommittedEscrowBestEffort') !== false
    && strpos($knownSuccessBody, '$this->operations->complete')
        < strpos($knownSuccessBody, '$this->clearCommittedEscrowBestEffort'),
    'committed_generation_depended_on_escrow_cleanup');
$refreshSource = (string) file_get_contents($root . '/app/Services/OAuthTokenRefreshService.php');
$assert(str_contains($refreshSource, 'clearCommittedQueueRecoveryBestEffort')
    && str_contains($refreshSource, '} catch (Throwable $error) {')
    && str_contains($refreshSource, 'QUEUE_V4_OAUTH_ESCROW_CLEANUP_DEFERRED'),
    'committed_queue_escrow_cleanup_not_best_effort');

fwrite(STDOUT, 'QUEUE_V4_OAUTH_REAL_PATH_2384=PASS checks=' . $checks
    . ' real_meli_http=0 business_writes=0 raw_storage_touched=false' . PHP_EOL);
