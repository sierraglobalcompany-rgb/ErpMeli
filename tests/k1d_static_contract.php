<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';

$budget = file_get_contents(__DIR__ . '/../app/Services/AutomationCallBudgetService.php');
$job = file_get_contents(__DIR__ . '/../jobs/queue_v4_clean.php');
$worker = file_get_contents(__DIR__ . '/../app/QueueV4Clean/QueueV4CleanWorker.php');
$rhythm = file_get_contents(__DIR__ . '/../app/Services/ApiRhythmPolicyService.php');
$client = file_get_contents(__DIR__ . '/../app/Services/MeliApiClient.php');
$email = file_get_contents(__DIR__ . '/../app/Services/CriticalApiAlertEmailService.php');
$migration = file_get_contents(__DIR__ . '/../database/migrations/301_k1d_api_safety_2_40_1.sql');
$cronShell = file_get_contents(__DIR__ . '/../app/Views/settings/cron_shell.php');
$calibration = file_get_contents(__DIR__ . '/../app/Views/settings/api_workload.php');
$incidentsShell = file_get_contents(__DIR__ . '/../app/Views/settings/api_health_incidents_shell.php');
$parser = file_get_contents(__DIR__ . '/../app/Services/AutomationCliCapacityArgumentParser.php');

foreach (compact('budget', 'job', 'worker', 'rhythm', 'client', 'email', 'migration', 'cronShell', 'calibration', 'incidentsShell', 'parser') as $name => $content) {
    k1b_assert(is_string($content) && $content !== '', 'read_' . $name);
}

k1b_assert(str_contains($budget, "SETTING_KEY = 'automation.max_api_calls_per_cycle'"), 'budget_setting_key');
k1b_assert(str_contains($budget, 'public const DEFAULT = 1'), 'budget_default_1');
k1b_assert(str_contains($budget, 'public const MIN = 1'), 'budget_min_1');
k1b_assert(str_contains($budget, 'public const HARD_MAX = 15'), 'budget_hard_max_15');
k1b_assert(str_contains($budget, 'CLI_MAX_CALLS_OVERRIDE'), 'cli_override_source');
k1b_assert(str_contains($budget, 'LEGACY_MAX_JOBS_OVERRIDE'), 'legacy_override_source');
k1b_assert(str_contains($budget, 'ERP_SETTINGS'), 'settings_source');
k1b_assert(str_contains($budget, 'SAFE_DEFAULT'), 'safe_default_source');

k1b_assert(str_contains($job, "Database::useProfile('cli');") && strpos($job, "Database::useProfile('cli');") < strpos($job, '$budget ='), 'cli_profile_before_settings');
k1b_assert(str_contains($parser, 'dual_capacity_arguments') && str_contains($job, 'AutomationCliCapacityArgumentParser'), 'dual_capacity_fails_closed');
k1b_assert(str_contains($parser, 'invalid_capacity_argument') && str_contains($parser, "preg_match('/^\\d+$/"), 'invalid_cli_fails_closed');
k1b_assert(str_contains($job, "'control_unit' =") || str_contains($job, '$result[\'control_unit\'] = \'PHYSICAL_API_CALL\''), 'job_control_unit');

k1b_assert(str_contains($rhythm, 'SHARED_429_FALLBACK_SECONDS = 1800'), 'shared_429_fallback_1800');
k1b_assert(preg_match('/max\\(\\s*self::SHARED_429_FALLBACK_SECONDS,\\s*min\\(86400/s', $rhythm) === 1, 'shared_429_setting_cannot_reduce_default');
k1b_assert(str_contains($rhythm, 'openSharedRateLimitPause'), 'global_pause_method');
k1b_assert(str_contains($rhythm, 'completeRateLimitedKnownResult'), '429_known_result_finalizer');
k1b_assert(str_contains($rhythm, 'api.rhythm.shared_429_backoff_seconds'), 'shared_429_setting');
k1b_assert(str_contains($rhythm, 'ORDERS_SEARCH_LOCAL_CEILING = 3'), 'orders_search_ceiling_3');

k1b_assert(substr_count($worker, 'remote_429_global_pause') >= 3, 'worker_global_429_scope');
k1b_assert(str_contains($worker, '$endReason = \'remote_429_global_pause\';') && str_contains($worker, 'break;'), 'worker_429_breaks_cycle');
k1b_assert(str_contains($client, 'notifyCriticalApiIncident'), 'client_calls_email_alert');
k1b_assert(substr_count($client, 'remote_429_global_pause') >= 2, 'client_429_global_scope');
k1b_assert(str_contains($client, "\$meta['source']") && str_contains($client, "\$meta['job_type']") && str_contains($client, "\$meta['source_work_id']"), 'email_context_uses_canonical_meta');

k1b_assert(str_contains($email, 'api_critical_email_notifications'), 'email_ledger_table');
k1b_assert(str_contains($email, 'claimSendLease') && str_contains($email, 'rowCount() === 1'), 'email_atomic_claim');
k1b_assert(str_contains($email, 'fingerprint') && str_contains($email, 'cooldown_minutes'), 'email_dedupe_cooldown');
k1b_assert(str_contains($email, 'mail(') && str_contains($email, 'mailTransport'), 'email_mail_with_fake_transport');
k1b_assert(str_contains($email, 'sendTest'), 'email_test_method');
k1b_assert(!str_contains($email, "alerts.email.enabled', false") || strpos($email, 'public function sendTest') > strpos($email, "alerts.email.enabled', false"), 'email_test_independent_of_enabled_setting');
k1b_assert(!str_contains($email, 'access_token') && !str_contains($email, 'refresh_token') && !str_contains($email, 'client_secret'), 'email_no_secret_terms');
k1b_assert(str_contains($migration, 'api_critical_email_notifications'), 'migration_email_ledger');
k1b_assert(str_contains($migration, "'automation.max_api_calls_per_cycle', '1'"), 'migration_default_budget');
k1b_assert(!str_contains($migration, 'notify_scheduler_fatal') && !str_contains($migration, 'notify_recovery'), 'migration_no_unimplemented_email_settings');

k1b_assert(str_contains($cronShell, 'Automatización y seguridad API'), 'unified_module_name');
k1b_assert(!str_contains($cronShell, 'queue_v4_clean.php --runtime=45 --max-calls='), 'primary_cron_command_has_no_override');
k1b_assert(str_contains($calibration, 'Llamadas API por ciclo'), 'calibration_numeric_page');
k1b_assert(str_contains($calibration, 'automation_max_api_calls_per_cycle') && str_contains($calibration, '/settings/cron/call-budget'), 'calibration_dedicated_call_budget_form');
k1b_assert(str_contains($calibration, 'critical-email-test-form') && str_contains($calibration, 'rhythm-profile-form'), 'email_test_form_is_sibling');
k1b_assert(str_contains($incidentsShell, 'data-api-incidents-shell') && str_contains($incidentsShell, 'incidents.json'), 'incidents_async_shell');
k1b_assert(str_contains(file_get_contents(__DIR__ . '/../public/index.php'), '/settings/api-health/email-test'), 'email_test_route');
k1b_assert(str_contains(file_get_contents(__DIR__ . '/../public/index.php'), '/settings/cron/call-budget'), 'call_budget_route');

echo "STATUS=PASS K1D_STATIC_CONTRACT\n";
