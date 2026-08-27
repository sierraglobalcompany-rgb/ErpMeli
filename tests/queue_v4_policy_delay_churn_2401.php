<?php

declare(strict_types=1);

$dsn = (string) (getenv('QUEUE_V4_CLEAN_TEST_DSN') ?: '');
if ($dsn === '') {
    fwrite(STDERR, "QUEUE_V4_CLEAN_TEST_DSN is required\n");
    exit(2);
}

$temporaryRoot = sys_get_temp_dir() . '/erp-qv4-churn-2401-' . bin2hex(random_bytes(5));
mkdir($temporaryRoot, 0700, true);
define('ERP_INSTALLATION_ROOT', $temporaryRoot);
define('ERP_RELEASE_ROOT', dirname(__DIR__));
require dirname(__DIR__) . '/bootstrap.php';

set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, 'FAIL queue_v4_policy_delay_churn_2401 ' . $error->getMessage() . PHP_EOL);
    exit(1);
});

use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\ApiBudgetInfrastructureException;
use App\Services\ApiBudgetExhaustedException;
use App\Services\ApiManualPauseException;
use App\Services\ApiRhythmDeferredException;
use App\Services\CronDeadlineDeferredException;
use App\Services\ManualRemoteCallLimitException;
use App\Services\OAuthRefreshRequiredException;
use App\Services\QueueV4PreTransportDeferredException;

$pdo = new PDO(
    $dsn,
    (string) (getenv('QUEUE_V4_CLEAN_TEST_USER') ?: 'root'),
    (string) (getenv('QUEUE_V4_CLEAN_TEST_PASS') ?: ''),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
);
$pdo->exec("SET time_zone='+00:00'");
Database::setConnection($pdo);
putenv('ML_WRITE_ENABLED=false');

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$reset = static function () use ($pdo): void {
    $pdo->exec('DELETE FROM queue_v4_clean_attempts');
    $pdo->exec('DELETE FROM queue_v4_clean_runs');
    $pdo->exec('DELETE FROM queue_v4_clean_jobs');
    $pdo->exec(
        "UPDATE queue_v4_clean_control
         SET engine_state='ACTIVE',readiness_state='CERTIFIED',scheduler_enabled=1
         WHERE control_key='primary'"
    );
};

$accounts = $pdo->query('SELECT company_id,id FROM meli_accounts ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_ASSOC);
if (count($accounts) < 2) {
    $companyIdForFixture = (int) ($accounts[0]['company_id'] ?? $pdo->query('SELECT id FROM companies ORDER BY id LIMIT 1')->fetchColumn());
    if ($companyIdForFixture < 1) {
        $pdo->exec("INSERT INTO companies(name,status,created_at,updated_at) VALUES('QUEUE_V4_TEST_COMPANY',1,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $companyIdForFixture = (int) $pdo->lastInsertId();
    }
    $columns = $pdo->query("SHOW COLUMNS FROM meli_accounts LIKE 'site_id'")->fetch(PDO::FETCH_ASSOC);
    $insertSql = $columns === false
        ? "INSERT INTO meli_accounts(company_id,account_name,nickname,meli_user_id,status,token_expires_at,created_at,updated_at)
           VALUES(?,?,?,?, 'connected', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY), UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        : "INSERT INTO meli_accounts(company_id,account_name,nickname,meli_user_id,site_id,status,token_expires_at,created_at,updated_at)
           VALUES(?,?,?,?, 'MLC', 'connected', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY), UTC_TIMESTAMP(), UTC_TIMESTAMP())";
    $insertAccount = $pdo->prepare($insertSql);
    for ($index = count($accounts); $index < 2; $index++) {
        $insertAccount->execute([
            $companyIdForFixture,
            'QUEUE_V4_TEST_ACCOUNT_' . $index,
            'QUEUE_V4_TEST_ACCOUNT_' . $index,
            240100100 + $index,
        ]);
    }
    $accounts = $pdo->query('SELECT company_id,id FROM meli_accounts ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_ASSOC);
}
$assert(count($accounts) >= 2, 'two_account_fixture_missing');
$companyId = (int) $accounts[0]['company_id'];
$accountId = (int) $accounts[0]['id'];
$companyIdB = (int) $accounts[1]['company_id'];
$accountIdB = (int) $accounts[1]['id'];
$repository = new QueueV4CleanRepository($pdo);

$seed = static function (string $prefix, ?int $overrideCompanyId = null, ?int $overrideAccountId = null) use ($repository, $companyId, $accountId): array {
    $ids = [];
    for ($index = 1; $index <= 3; $index++) {
        $ids[] = $repository->enqueue(
            $overrideCompanyId ?? $companyId,
            $overrideAccountId ?? $accountId,
            'order_exact',
            $prefix . '-' . $index,
            $prefix . ':order:' . $index,
            ['order_id' => $prefix . '-' . $index],
            3
        );
    }
    return $ids;
};

$assertOnlyOneClaimed = static function (Throwable $defer, string $label) use ($reset, $seed, $pdo, $repository, $assert): void {
    $reset();
    $ids = $seed($label);
    $worker = new QueueV4CleanWorker($pdo, $repository, null, null, static function () use ($defer): void {
        throw $defer;
    });
    $summary = $worker->run('test', 3, 10);
    $assert((int) $summary['claimed'] === 1, $label . '_claimed_more_than_one');
    $assert((int) $summary['deferred'] === 1, $label . '_deferred_more_than_one');
    $rows = $pdo->query(
        'SELECT state,attempt_count,last_error_class FROM queue_v4_clean_jobs ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($rows) === 3, $label . '_fixture_row_count');
    $assert((string) $rows[0]['state'] === 'waiting', $label . '_first_not_waiting');
    $assert((int) $rows[0]['attempt_count'] === 0, $label . '_first_attempt_penalized');
    $assert((string) $rows[1]['state'] === 'ready' && (string) $rows[2]['state'] === 'ready', $label . '_later_jobs_churned');
    $assert((int) $pdo->query("SELECT jobs_deferred FROM queue_v4_clean_runs WHERE id=" . (int) $summary['run_id'])->fetchColumn() === 1, $label . '_run_deferred_count');
};

$assertOnlyOneClaimed(
    new ApiRhythmDeferredException('permit busy', gmdate('Y-m-d H:i:s', time() + 2), 'rhythm_permit_busy'),
    'rhythm_permit_busy'
);
$assertOnlyOneClaimed(
    new ApiRhythmDeferredException('default rhythm', gmdate('Y-m-d H:i:s', time() + 2), 'rhythm'),
    'default_rhythm'
);
$assertOnlyOneClaimed(
    new ApiRhythmDeferredException('global window', gmdate('Y-m-d H:i:s', time() + 60), 'rhythm_global_window'),
    'rhythm_global_window'
);
$assertOnlyOneClaimed(
    new ApiRhythmDeferredException('penalty state unavailable', gmdate('Y-m-d H:i:s', time() + 60), 'rhythm_penalty_state_unavailable'),
    'rhythm_penalty_state_unavailable'
);
$assertOnlyOneClaimed(
    new ApiRhythmDeferredException('unknown future scope', gmdate('Y-m-d H:i:s', time() + 60), 'future_unknown_scope'),
    'future_unknown_scope'
);
$assertOnlyOneClaimed(
    new CronDeadlineDeferredException('deadline', gmdate('Y-m-d H:i:s', time() + 60)),
    'cron_deadline'
);
$assertOnlyOneClaimed(
    new ApiBudgetInfrastructureException('budget authority unavailable'),
    'budget_infrastructure'
);
$assertOnlyOneClaimed(
    new ApiManualPauseException('app', null, gmdate('Y-m-d H:i:s', time() + 60), 'manual app pause'),
    'manual_app_pause'
);
$assertOnlyOneClaimed(
    new ManualRemoteCallLimitException('manual burst', gmdate('Y-m-d H:i:s', time() + 60)),
    'manual_burst'
);
$assertOnlyOneClaimed(
    new ApiBudgetExhaustedException('app budget exhausted', gmdate('Y-m-d H:i:s', time() + 60), [
        ['scope' => 'app', 'scope_key' => 'app:*'],
    ]),
    'app_budget'
);
$assertOnlyOneClaimed(
    new ApiBudgetExhaustedException('unknown budget exhausted', gmdate('Y-m-d H:i:s', time() + 60), [
        ['scope' => 'future_budget_scope', 'scope_key' => 'future:*'],
    ]),
    'unknown_budget'
);
$assertOnlyOneClaimed(
    new ApiBudgetExhaustedException('budget exhausted', gmdate('Y-m-d H:i:s', time() + 60)),
    'budget_without_scope'
);
$assertOnlyOneClaimed(
    new QueueV4PreTransportDeferredException(gmdate('Y-m-d H:i:s', time() + 60)),
    'pretransport'
);

$reset();
$repository->enqueue($companyId, $accountId, 'order_exact', 'budget-account-a', 'budget-account:a', ['order_id' => 'budget-account-a'], 3);
$repository->enqueue($companyIdB, $accountIdB, 'order_exact', 'budget-account-b', 'budget-account:b', ['order_id' => 'budget-account-b'], 3);
$accountBudgetWorker = new QueueV4CleanWorker($pdo, $repository, null, null, static function (array $job) use ($accountId): void {
    if ((int) $job['meli_account_id'] === $accountId) {
        throw new ApiBudgetExhaustedException(
            'account budget exhausted',
            gmdate('Y-m-d H:i:s', time() + 60),
            [['scope' => 'account', 'scope_key' => 'account:' . $accountId]]
        );
    }
});
$accountBudgetSummary = $accountBudgetWorker->run('test', 2, 10);
$assert((int) $accountBudgetSummary['claimed'] === 2, 'account_budget_starved_cross_account_work');
$assert((int) $accountBudgetSummary['completed'] === 1, 'account_budget_independent_account_not_completed');
$assert((int) $accountBudgetSummary['deferred'] === 1, 'account_budget_deferred_count_invalid');

$reset();
$endpointBudgetX = $repository->enqueue(
    $companyId,
    $accountId,
    'order_exact',
    'budget-endpoint-x',
    'budget-endpoint:x',
    ['order_id' => 'budget-endpoint-x', 'endpoint' => 'X'],
    3
);
$endpointBudgetY = $repository->enqueue(
    $companyId,
    $accountId,
    'order_exact',
    'budget-endpoint-y',
    'budget-endpoint:y',
    ['order_id' => 'budget-endpoint-y', 'endpoint' => 'Y'],
    3
);
$endpointBudgetWorker = new QueueV4CleanWorker($pdo, $repository, null, null, static function (array $job): void {
    $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
    if (($payload['endpoint'] ?? '') === 'X') {
        throw new ApiBudgetExhaustedException(
            'endpoint budget exhausted',
            gmdate('Y-m-d H:i:s', time() + 60),
            [['scope' => 'endpoint', 'scope_key' => 'endpoint:/orders/x']]
        );
    }
});
$endpointBudgetSummary = $endpointBudgetWorker->run('test', 2, 10);
$assert((int) $endpointBudgetSummary['claimed'] === 2, 'endpoint_budget_starved_independent_endpoint_work');
$assert((int) $endpointBudgetSummary['completed'] === 1, 'endpoint_budget_independent_work_not_completed');
$assert((int) $endpointBudgetSummary['deferred'] === 1, 'endpoint_budget_deferred_count_invalid');
$endpointBudgetRows = $pdo->query('SELECT id,state,attempt_count FROM queue_v4_clean_jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$assert((int) $endpointBudgetRows[0]['id'] === $endpointBudgetX && (string) $endpointBudgetRows[0]['state'] === 'waiting', 'endpoint_budget_x_not_waiting');
$assert((int) $endpointBudgetRows[0]['attempt_count'] === 0, 'endpoint_budget_x_attempt_penalized');
$assert((int) $endpointBudgetRows[1]['id'] === $endpointBudgetY && (string) $endpointBudgetRows[1]['state'] === 'completed', 'endpoint_budget_y_not_completed');

$reset();
$clusteredEndpointIds = [];
foreach (['x1', 'x2', 'x3', 'y1'] as $suffix) {
    $endpoint = str_starts_with($suffix, 'x') ? 'X' : 'Y';
    $clusteredEndpointIds[$suffix] = $repository->enqueue(
        $companyId,
        $accountId,
        'order_exact',
        'clustered-endpoint-' . $suffix,
        'clustered-endpoint:' . $suffix,
        ['order_id' => 'clustered-endpoint-' . $suffix, 'endpoint' => $endpoint],
        3
    );
}
$clusteredEndpointWorker = new QueueV4CleanWorker($pdo, $repository, null, null, static function (array $job): void {
    $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
    if (($payload['endpoint'] ?? '') === 'X') {
        throw new ApiBudgetExhaustedException(
            'endpoint X remains blocked',
            gmdate('Y-m-d H:i:s', time() + 60),
            [['scope' => 'endpoint', 'scope_key' => 'endpoint:/orders/x']]
        );
    }
});
$clusteredEndpointFirst = $clusteredEndpointWorker->run('test', 2, 10);
$assert((int) $clusteredEndpointFirst['claimed'] === 2, 'clustered_endpoint_first_cycle_claim_count');
$assert((int) $clusteredEndpointFirst['completed'] === 0, 'clustered_endpoint_x_false_success_first_cycle');
$assert((int) $clusteredEndpointFirst['deferred'] === 2, 'clustered_endpoint_first_cycle_deferred_count');
$clusteredEndpointSecond = $clusteredEndpointWorker->run('test', 2, 10);
$assert((int) $clusteredEndpointSecond['claimed'] === 2, 'clustered_endpoint_second_cycle_claim_count');
$assert((int) $clusteredEndpointSecond['completed'] === 1, 'clustered_endpoint_y_not_eventually_completed');
$assert((int) $clusteredEndpointSecond['deferred'] === 1, 'clustered_endpoint_second_cycle_deferred_count');
$clusteredRows = $pdo->query('SELECT resource_id,state,attempt_count FROM queue_v4_clean_jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$clusteredByResource = [];
foreach ($clusteredRows as $row) {
    $clusteredByResource[(string) $row['resource_id']] = $row;
}
foreach (['clustered-endpoint-x1', 'clustered-endpoint-x2', 'clustered-endpoint-x3'] as $resourceId) {
    $assert((string) ($clusteredByResource[$resourceId]['state'] ?? '') === 'waiting', $resourceId . '_did_not_remain_blocked');
    $assert((int) ($clusteredByResource[$resourceId]['attempt_count'] ?? -1) === 0, $resourceId . '_attempt_penalized');
}
$assert((string) ($clusteredByResource['clustered-endpoint-y1']['state'] ?? '') === 'completed', 'clustered_endpoint_y_final_state_not_completed');

$reset();
$repository->enqueue($companyId, $accountId, 'order_exact', 'budget-job-a', 'budget-job:a', ['order_id' => 'budget-job-a'], 3);
$repository->enqueue($companyId, $accountId, 'fresh_orders_discovery', null, 'budget-job:b', ['from' => '2026-01-01', 'to' => '2026-01-02'], 3);
$jobTypeBudgetCalls = 0;
$jobTypeBudgetWorker = new QueueV4CleanWorker($pdo, $repository, null, null, static function () use (&$jobTypeBudgetCalls): void {
    $jobTypeBudgetCalls++;
    if ($jobTypeBudgetCalls === 1) {
        throw new ApiBudgetExhaustedException(
            'job type budget exhausted',
            gmdate('Y-m-d H:i:s', time() + 60),
            [['scope' => 'job_type', 'scope_key' => 'job:order_exact']]
        );
    }
});
$jobTypeBudgetSummary = $jobTypeBudgetWorker->run('test', 2, 10);
$assert((int) $jobTypeBudgetSummary['claimed'] === 2, 'job_type_budget_starved_independent_job_type');
$assert((int) $jobTypeBudgetSummary['completed'] === 1, 'job_type_budget_independent_work_not_completed');
$assert((int) $jobTypeBudgetSummary['deferred'] === 1, 'job_type_budget_deferred_count_invalid');

$reset();
$repository->enqueue($companyId, $accountId, 'order_exact', 'manual-pause-a', 'manual-pause:a', ['order_id' => 'manual-pause-a'], 3);
$repository->enqueue($companyIdB, $accountIdB, 'order_exact', 'manual-pause-b', 'manual-pause:b', ['order_id' => 'manual-pause-b'], 3);
$manualAccountPauseWorker = new QueueV4CleanWorker($pdo, $repository, null, null, static function (array $job) use ($accountId): void {
    if ((int) $job['meli_account_id'] === $accountId) {
        throw new ApiManualPauseException(
            'account',
            $accountId,
            gmdate('Y-m-d H:i:s', time() + 60),
            'manual account pause'
        );
    }
});
$manualAccountPauseSummary = $manualAccountPauseWorker->run('test', 2, 10);
$assert((int) $manualAccountPauseSummary['claimed'] === 2, 'manual_account_pause_starved_cross_account_work');
$assert((int) $manualAccountPauseSummary['completed'] === 1, 'manual_account_pause_independent_account_not_completed');
$assert((int) $manualAccountPauseSummary['deferred'] === 1, 'manual_account_pause_deferred_count_invalid');

$reset();
$completedHandlerCalls = 0;
$ids = $seed('endpoint_scope');
$endpointScopedWorker = new QueueV4CleanWorker($pdo, $repository, null, null, static function () use (&$completedHandlerCalls): void {
    $completedHandlerCalls++;
    if ($completedHandlerCalls === 1) {
        throw new ApiRhythmDeferredException(
            'billing endpoint deferred',
            gmdate('Y-m-d H:i:s', time() + 900),
            'billing_429_backoff'
        );
    }
});
$endpointSummary = $endpointScopedWorker->run('test', 2, 10);
$assert((int) $endpointSummary['claimed'] === 2, 'endpoint_scope_starved_independent_work');
$assert((int) $endpointSummary['completed'] === 1, 'endpoint_scope_independent_work_not_completed');
$assert((int) $endpointSummary['deferred'] === 1, 'endpoint_scope_deferred_count_invalid');

$reset();
$retryAfterCalls = 0;
$seed('retry_after_scope');
$retryAfterWorker = new QueueV4CleanWorker($pdo, $repository, null, null, static function () use (&$retryAfterCalls): void {
    $retryAfterCalls++;
    if ($retryAfterCalls === 1) {
        throw new ApiRhythmDeferredException(
            'retry after scoped',
            gmdate('Y-m-d H:i:s', time() + 5400),
            'retry_after',
            true
        );
    }
});
$retryAfterSummary = $retryAfterWorker->run('test', 2, 10);
$assert((int) $retryAfterSummary['claimed'] === 2, 'retry_after_scope_starved_independent_work');
$assert((int) $retryAfterSummary['completed'] === 1, 'retry_after_scope_independent_work_not_completed');
$assert((int) $retryAfterSummary['deferred'] === 1, 'retry_after_scope_deferred_count_invalid');
$retryAfterRow = $pdo->query('SELECT available_at FROM queue_v4_clean_jobs ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$assert((strtotime((string) $retryAfterRow['available_at'] . ' UTC') ?: 0) >= time() + 5390, 'retry_after_authority_not_preserved');

$reset();
$remoteBackoffNextSafeAt = gmdate('Y-m-d H:i:s', time() + 300);
$remoteBackoffA = $repository->enqueue($companyId, $accountId, 'order_exact', 'remote-backoff-a', 'remote-backoff:a', ['order_id' => 'remote-backoff-a'], 3);
$remoteBackoffB = $repository->enqueue($companyIdB, $accountIdB, 'order_exact', 'remote-backoff-b', 'remote-backoff:b', ['order_id' => 'remote-backoff-b'], 3);
$remoteBackoffWorker = new QueueV4CleanWorker($pdo, $repository, null, null, static function (array $job) use ($accountId, $remoteBackoffNextSafeAt): void {
    if ((int) $job['meli_account_id'] === $accountId) {
        throw new ApiRhythmDeferredException(
            'remote backoff endpoint scoped',
            $remoteBackoffNextSafeAt,
            'remote_backoff',
            true
        );
    }
});
$remoteBackoffSummary = $remoteBackoffWorker->run('test', 2, 10);
$assert((int) $remoteBackoffSummary['claimed'] === 2, 'remote_backoff_starved_cross_account_work');
$assert((int) $remoteBackoffSummary['completed'] === 1, 'remote_backoff_account_b_not_completed');
$assert((int) $remoteBackoffSummary['deferred'] === 1, 'remote_backoff_deferred_count_invalid');
$remoteBackoffRows = $pdo->query('SELECT id,state,attempt_count,available_at FROM queue_v4_clean_jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$assert((int) $remoteBackoffRows[0]['id'] === $remoteBackoffA && (string) $remoteBackoffRows[0]['state'] === 'waiting', 'remote_backoff_a_not_waiting');
$assert((int) $remoteBackoffRows[0]['attempt_count'] === 0, 'remote_backoff_a_attempt_penalized');
$assert(abs((strtotime((string) $remoteBackoffRows[0]['available_at'] . ' UTC') ?: 0) - (strtotime($remoteBackoffNextSafeAt . ' UTC') ?: PHP_INT_MAX)) <= 1, 'remote_backoff_next_safe_at_not_preserved');
$assert((int) $remoteBackoffRows[1]['id'] === $remoteBackoffB && (string) $remoteBackoffRows[1]['state'] === 'completed', 'remote_backoff_b_not_completed');

$reset();
$repository->enqueue($companyId, $accountId, 'order_exact', 'account-a', 'account-scope:a', ['order_id' => 'account-a'], 3);
$repository->enqueue($companyIdB, $accountIdB, 'order_exact', 'account-b', 'account-scope:b', ['order_id' => 'account-b'], 3);
$accountScopeWorker = new QueueV4CleanWorker($pdo, $repository, null, null, static function (array $job) use ($accountId): void {
    if ((int) $job['meli_account_id'] === $accountId) {
        throw new ApiRhythmDeferredException(
            'account A reduced',
            gmdate('Y-m-d H:i:s', time() + 120),
            'rhythm_account_reduced'
        );
    }
});
$accountScopeSummary = $accountScopeWorker->run('test', 2, 10);
$assert((int) $accountScopeSummary['claimed'] === 2, 'account_scope_starved_cross_account_work');
$assert((int) $accountScopeSummary['completed'] === 1, 'account_scope_account_b_not_completed');
$assert((int) $accountScopeSummary['deferred'] === 1, 'account_scope_deferred_count_invalid');

$reset();
$pdo->exec(
    "CREATE TEMPORARY TABLE IF NOT EXISTS oauth_refresh_operations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        company_id BIGINT UNSIGNED NOT NULL,
        meli_account_id BIGINT UNSIGNED NOT NULL,
        state ENUM('SCHEDULED','RUNNING','WAITING','COMPLETED','FAILED') NOT NULL,
        next_attempt_at DATETIME(3) NOT NULL
    ) ENGINE=InnoDB"
);
$pdo->exec('DELETE FROM oauth_refresh_operations');
$oauthInsert = $pdo->prepare(
    "INSERT INTO oauth_refresh_operations (company_id,meli_account_id,state,next_attempt_at)
     VALUES (?,?,'WAITING',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 60 SECOND))"
);
$oauthInsert->execute([$companyId, $accountId]);
$repository->enqueue($companyId, $accountId, 'order_exact', 'oauth-a', 'oauth-scope:a', ['order_id' => 'oauth-a'], 3);
$repository->enqueue($companyIdB, $accountIdB, 'order_exact', 'oauth-b', 'oauth-scope:b', ['order_id' => 'oauth-b'], 3);
$oauthWorker = new QueueV4CleanWorker($pdo, $repository, null, null, static function (array $job) use ($accountId): void {
    if ((int) $job['meli_account_id'] === $accountId) {
        throw new OAuthRefreshRequiredException($accountId);
    }
});
$oauthSummary = $oauthWorker->run('test', 2, 10);
$assert((int) $oauthSummary['claimed'] === 2, 'oauth_scope_starved_cross_account_work');
$assert((int) $oauthSummary['completed'] === 1, 'oauth_scope_account_b_not_completed');
$assert((int) $oauthSummary['deferred'] === 1, 'oauth_scope_deferred_count_invalid');

$reset();
$sourceKey = 'K2401:' . bin2hex(random_bytes(4));
$sourceInsert = $pdo->prepare(
    "INSERT INTO sale_financial_reconciliation_jobs
     (company_id,meli_account_id,order_id,input_version,status,attempts,next_run_at,created_at,updated_at)
     VALUES (?,?,?,?, 'retry',0,DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 DAY),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))"
);
$sourceInsert->execute([
    $companyId,
    $accountId,
    $sourceKey,
    hash('sha256', $sourceKey),
]);
$sourceId = (int) $pdo->lastInsertId();
$domainJobInsert = $pdo->prepare(
    "INSERT INTO queue_v4_clean_jobs
     (company_id,meli_account_id,job_type,resource_id,idempotency_key,state,available_at,payload_json,max_attempts,created_at,updated_at)
     VALUES (?,?, 'domain_exact', ?, ?, 'ready', UTC_TIMESTAMP(3), ?, 3, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))"
);
$domainJobInsert->execute([
    $companyId,
    $accountId,
    (string) $sourceId,
    'source:' . $sourceId,
    json_encode(['capability' => 'financial_reconciliation', 'source_id' => $sourceId], JSON_THROW_ON_ERROR),
]);
$domainJobId = (int) $pdo->lastInsertId();
$fakeFinance = new class {
    public function processDomainExactBatch(array $sourceIds, int $companyId, int $accountId): array
    {
        return ['outcomes' => []];
    }
};
$domainWorker = new QueueV4CleanWorker($pdo, $repository, null, null, null, null, static fn () => $fakeFinance);
$domainSummary = $domainWorker->run('test', 3, 10);
$domainRow = $pdo->query('SELECT state,attempt_count,available_at FROM queue_v4_clean_jobs WHERE id=' . $domainJobId)->fetch(PDO::FETCH_ASSOC);
$assert((int) $domainSummary['claimed'] === 1, 'stale_domain_source_not_claimed_once');
$assert((string) $domainRow['state'] === 'waiting', 'stale_domain_source_not_waiting');
$assert((int) $domainRow['attempt_count'] === 0, 'stale_domain_source_attempt_penalized');
$assert((strtotime((string) $domainRow['available_at'] . ' UTC') ?: 0) >= time() + 890, 'stale_domain_source_available_too_soon');
$secondDomainSummary = $domainWorker->run('test', 3, 10);
$assert((int) $secondDomainSummary['claimed'] === 0, 'stale_domain_source_reselected_before_available_at');

echo 'PASS queue_v4_policy_delay_churn_2401 checks=' . $checks . PHP_EOL;
