<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$scheduler = $read('app/QueueV4Clean/QueueV4CleanScheduler.php');
$supervisor = $read('app/QueueV4Clean/QueueV4CleanOAuthSupervisor.php');
$worker = $read('app/QueueV4Clean/QueueV4CleanWorker.php');
$sales = $read('app/Services/SalesAuditRunService.php');
$producer = $read('app/QueueV4Clean/QueueV4CleanProducer.php');
$recovery = $read('app/QueueV4Clean/QueueV4CleanUncertainReadRecoveryService.php');
$stage = $read('app/QueueV4Clean/QueueV4CleanSalesAuditStage.php');
$client = $read('app/Services/MeliApiClient.php');

$assert(str_contains($supervisor, 'MAX_PHYSICAL_POSTS_PER_RUN = 1'), 'oauth_supervisor_post_cap_missing');
$assert(str_contains($supervisor, 'refreshOAuthToken()'), 'oauth_supervisor_does_not_own_refresh');
$assert(strpos($scheduler, 'QueueV4CleanOAuthSupervisor') < strpos($scheduler, 'QueueV4CleanSalesAuditStage'),
    'oauth_supervisor_must_precede_sales_audit');
$assert(str_contains($scheduler, "'status' => 'sales_audit_invariant_blocked'"),
    'sales_invariant_scheduler_status_missing');
$assert(strpos($scheduler, "(\$salesAudit['abort_scheduler'] ?? false) === true")
    < strpos($scheduler, 'new QueueV4CleanProducer'), 'sales_abort_does_not_precede_producer');
$assert(str_contains($scheduler, "'producer' => ['skipped' => true]")
    && str_contains($scheduler, "'worker' => ['skipped' => true]"), 'sales_abort_does_not_skip_remote_stages');

$assert(strpos($worker, 'catch (OAuthRefreshRequiredException)') < strpos($worker, 'catch (RuntimeException'),
    'worker_oauth_is_a_functional_failure');
$assert(str_contains($worker, 'deferWithoutAttemptPenalty'), 'worker_oauth_attempt_refund_missing');
$assert(!str_contains($worker, 'JOIN meli_tokens'), 'commercial_fifo_was_prefiltered_by_oauth');

$assert(str_contains($stage, 'processDue(1, $deadline)'), 'sales_stage_is_not_one_page_bounded');
$assert(str_contains($sales, 'oauth.token_expiry_skew_seconds')
    && str_contains($sales, 'max(30, min(600') && str_contains($sales, "'oauth_refresh_required'"),
    'sales_oauth_business_policy_missing');
$salesOAuthCatch = strpos($sales, 'catch (OAuthRefreshRequiredException $error)');
$salesGenericCatch = $salesOAuthCatch === false ? false : strpos($sales, 'catch (Throwable $error)', $salesOAuthCatch);
$assert($salesOAuthCatch !== false && $salesGenericCatch !== false && $salesOAuthCatch < $salesGenericCatch,
    'sales_oauth_defensive_catch_order_invalid');
$assert(str_contains($sales, "j.remote_dispatch_state='NOT_DISPATCHED'")
    && str_contains($sales, 'j.last_http_status IS NULL')
    && str_contains($sales, 'lease_generation=j.lease_generation'),
    'sales_oauth_dispatch_or_generation_fence_missing');
$assert(str_contains($sales, "state IN ('SCHEDULED','RUNNING','WAITING')")
    && str_contains($sales, 'company_id=? AND meli_account_id=?'), 'oauth_next_attempt_tenant_authority_missing');
$assert(str_contains($sales, 'GREATEST(j.attempts-1,0)')
    && !str_contains($sales, 'oauth.auto_refresh_lead_seconds'), 'sales_oauth_attempt_or_skew_policy_invalid');
$assert(str_contains($sales, 'LEGACY_OAUTH_ERROR_CLASS')
    && str_contains($sales, 'misclassified_oauth_repaired'), 'legacy_false_error_repair_missing');

$assert(!str_contains($producer, 'MeliApiClient') && !preg_match('/->(?:get|request)\s*\(/', $producer),
    'producer_gained_direct_remote_transport');
$assert(!str_contains($recovery, 'MeliApiClient') && !preg_match('/->(?:get|request)\s*\(/', $recovery),
    'uncertain_recovery_gained_direct_remote_transport');
$assert(str_contains($client, 'throw new OAuthRefreshRequiredException($this->accountId)'),
    'meli_client_business_oauth_boundary_missing');

echo 'QUEUE_V4_OAUTH_ECOSYSTEM_2387=PASS checks=' . $checks
    . ' producer_remote_http=0 recovery_remote_http=0' . PHP_EOL;
