<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\AppPaths;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Services\ApiGuardService;
use App\Services\ApiHealthOverviewService;
use App\Services\ApiHealthService;
use App\Services\ApiHealthTechnicalService;
use App\Services\ApiManualPauseService;
use App\Services\ApiErrorSummaryService;
use App\Services\BusinessScopeContext;
use App\Services\AppSettingsService;
use App\Services\CapacityChangeGuard;
use App\Services\CapacityPolicyService;
use App\Services\CriticalApiAlertEmailService;
use App\Services\CronHealthService;
use App\Services\ReleaseIntegrityService;
use App\Services\DiagnosticService;
use App\Services\MeliApiKnowledgeService;
use App\Services\MigrationDiagnosticService;
use App\Services\MigrationTraceService;
use App\Services\DateTimePresenter;
use App\Services\SyncCenterService;
use App\Services\TimeDiagnosticsService;
use App\Services\ReadModelCacheService;
use DateTimeImmutable;
use DateTimeZone;

final class SettingsController
{
    public function apiWorkload(): void
    {
        $this->requireAdminPermanent();
        $this->redirect('/settings/cron/rhythm');
    }

    public function saveApiWorkload(): void
    {
        $this->requireAdminPermanent();
        $this->assertSameOrigin();
        Csrf::validate($_POST['_token'] ?? null);
        $ceiling = max(1, min(59, (int) ($_POST['api_pacing_ceiling_rpm'] ?? 20)));
        $settings = new AppSettingsService();
        $settings->set('api.pacing.ceiling_rpm', (string) $ceiling, 'api_pacing');
        $settings->set('api.pacing.enabled', isset($_POST['api_pacing_enabled']) ? '1' : '0', 'api_pacing');
        $settings->set('api.pacing.adaptive_enabled', isset($_POST['api_pacing_adaptive_enabled']) ? '1' : '0', 'api_pacing');
        Session::flash('success', 'Ritmo máximo de consultas actualizado. Los presupuestos de seguridad continúan prevaleciendo.');
        $this->redirect('/settings/api-workload');
    }

    public function cronRhythm(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        $rhythm = (new \App\Services\ApiRhythmPolicyService())->preview();
        $snapshot = $this->queueV4RhythmSnapshot();
        $this->applyQueueV4RhythmSnapshot($rhythm, $snapshot);
        $increaseGate = $this->queueV4RhythmIncreaseGate($snapshot);
        if (empty($increaseGate['allowed'])) {
            $rhythm['increase_blocker'] = $increaseGate['message'];
        }
        $rhythm['recent_rate_limit_incidents'] = $this->recentRateLimitIncidents();
        $capacity = (new CapacityPolicyService())->snapshot('automation');
        View::render('settings/api_workload', compact('rhythm', 'capacity'));
    }

    public function saveCronRhythm(): void
    {
        $this->requireAdminPermanent();
        $this->assertSameOrigin();
        Csrf::validate($_POST['_token'] ?? null);
        $profile = trim((string) ($_POST['profile'] ?? 'fast'));
        $presets = [
            'conservative' => 10,
            'balanced' => 20,
            'fast' => 30,
            'maximum' => 40,
            'custom' => max(1, min(300, (int) ($_POST['custom_target_http_per_minute'] ?? 40))),
            // Alias del formulario 2.28.15–2.28.30. La vista nueva utilizará
            // maximum, pero una instalación actualizada no cambia de perfil.
            'recovery' => 40,
        ];
        if (isset($presets[$profile])) {
            $target = $presets[$profile];
        } else {
            $profile = 'fast';
            $target = 30;
        }
        $settings = new AppSettingsService();
        $billingBackoff = $this->billing429BackoffMinutesFromPost();
        $previousBillingInterval = (new \App\Services\ApiRhythmPolicyService($settings))->billingMinIntervalSeconds();
        $billingInterval = $this->billingMinIntervalSecondsFromPost($settings);
        $previousProfile = (string) $settings->get('api.rhythm.profile', '');
        $previousTarget = $settings->int('api.rhythm.target_http_per_minute', $target);
        $previousCurrent = $settings->int('api.rhythm.current_adaptive_limit', min(15, $target));
        $previousAdaptive = $settings->bool('api.rhythm.adaptive_enabled', true);
        $adaptiveEnabled = isset($_POST['adaptive_enabled']);
        if ($target > $previousTarget || $billingInterval < $previousBillingInterval) {
            $gate = $this->queueV4RhythmIncreaseGate();
            if (empty($gate['allowed'])) {
                Session::flash('error', 'No se subió el ritmo: ' . $gate['message']);
                $this->redirect('/settings/cron/rhythm');
            }
        }
        $settings->set('api.rhythm.mode', $profile, 'api_rhythm');
        $settings->set('api.rhythm.profile', $profile, 'api_rhythm');
        $settings->set('api.rhythm.target_http_per_minute', (string) $target, 'api_rhythm');
        $settings->set('api.rhythm.minimum_interval_ms', '1000', 'api_rhythm');
        $settings->set('api.rhythm.billing_min_interval_seconds', (string) $billingInterval, 'api_rhythm');
        $settings->set('api.rhythm.rolling_window_seconds', '60', 'api_rhythm');
        // Al reducir, el nuevo límite entra inmediatamente. Al aumentar se
        // conserva el nivel actual y la rampa exige evidencia antes de subir.
        $current = min($target, $previousCurrent);
        $settings->set('api.rhythm.current_adaptive_limit', (string) $current, 'api_rhythm');
        $settings->set('api.rhythm.calls_per_block', (string) $target, 'api_rhythm');
        $settings->set('api.rhythm.interval_ms', '1000', 'api_rhythm');
        $settings->set('api.rhythm.block_pause_ms', '0', 'api_rhythm');
        $settings->set('api.rhythm.adaptive_enabled', $adaptiveEnabled ? '1' : '0', 'api_rhythm');
        $this->persistBilling429Backoff($settings, $billingBackoff);
        if ($profile === 'custom') {
            $steps = $this->sanitizeRampSteps((string) ($_POST['custom_ramp_steps'] ?? ''), $target);
            $settings->set('api.rhythm.ramp_steps', implode(',', $steps), 'api_rhythm');
            $settings->set('api.rhythm.ramp_evaluation_minutes', (string) max(5, min(1440, (int) ($_POST['ramp_evaluation_minutes'] ?? 1440))), 'api_rhythm');
            $settings->set('api.rhythm.ramp_min_known_responses', (string) max(1, min(10000, (int) ($_POST['ramp_min_known_responses'] ?? 60))), 'api_rhythm');
            $settings->set('api.rhythm.ramp_max_429', (string) max(0, min(100, (int) ($_POST['ramp_max_429'] ?? 0))), 'api_rhythm');
            $settings->set('api.rhythm.ramp_max_lease_lost', (string) max(0, min(100, (int) ($_POST['ramp_max_lease_lost'] ?? 0))), 'api_rhythm');
            $settings->set('api.rhythm.ramp_max_duplicates', (string) max(0, min(100, (int) ($_POST['ramp_max_duplicates'] ?? 0))), 'api_rhythm');
            $settings->set('api.rhythm.ramp_p95_http_ms', (string) max(500, min(60000, (int) ($_POST['ramp_p95_http_ms'] ?? 5000))), 'api_rhythm');
            $settings->set('api.rhythm.ramp_require_drainage', isset($_POST['ramp_require_drainage']) ? '1' : '0', 'api_rhythm');
        }
        if ($profile !== 'custom') {
            $settings->set('api.rhythm.ramp_steps', implode(',', $this->sanitizeRampSteps('', $target)), 'api_rhythm');
        }
        if ($previousProfile !== $profile || $previousTarget !== $target
            || $previousCurrent !== $current || $previousAdaptive !== $adaptiveEnabled) {
            $now = gmdate('Y-m-d H:i:s');
            // Cambiar perfil o nivel inicia una ventana estable nueva. Ninguna
            // respuesta del perfil anterior puede autorizar la próxima rampa.
            $settings->set('api.rhythm.current_level_started_at', $now, 'api_rhythm');
            $settings->set('api.rhythm.last_ramp_evaluation_at', $now, 'api_rhythm');
        }
        Session::flash('success', 'Ritmo guardado. Los límites de Mercado Libre, cuenta, endpoint y presupuesto siguen prevaleciendo.');
        $this->redirect('/settings/cron/rhythm');
    }

    private function billingMinIntervalSecondsFromPost(AppSettingsService $settings): int
    {
        $raw = $_POST['billing_min_interval_seconds']
            ?? $settings->get('api.rhythm.billing_min_interval_seconds', '300')
            ?? '300';
        try {
            return (int) \App\Services\ApiRhythmPolicyService::normalizeBillingMinIntervalSeconds($raw);
        } catch (\InvalidArgumentException $error) {
            throw new \App\Core\HttpException(422, $error->getMessage());
        }
    }

    public function saveCronCallBudget(): void
    {
        $this->saveCapacity('automation');
    }

    public function saveManualCallBudget(): void
    {
        $this->saveCapacity('manual');
    }

    private function saveCapacity(string $module): void
    {
        $this->requireAdminPermanent();
        $this->assertSameOrigin();
        Csrf::validate($_POST['_token'] ?? null);
        $returnPath = $module === 'automation' ? '/settings/cron/rhythm' : '/settings/manual-processing';
        $action = $module === 'automation' ? '/settings/cron/call-budget' : '/settings/manual-processing/call-budget';
        $sessionKey = 'capacity_proposal_' . $module;
        $intent = $_POST['capacity_action'] ?? 'prepare';
        if ($intent === 'cancel') {
            Session::forget($sessionKey);
            Session::flash('success', 'Cambio cancelado. La capacidad guardada no cambió.');
            $this->redirect($returnPath);
        }
        $policy = new CapacityPolicyService();
        if ($intent === 'confirm') {
            $proposal = Session::get($sessionKey);
            if (!is_array($proposal) || ($proposal['module'] ?? '') !== $module
                || (int) ($proposal['user_id'] ?? 0) !== (int) Auth::id()
                || (int) ($proposal['expires_at'] ?? 0) < time()
                || !is_string($_POST['confirmation_nonce'] ?? null)
                || !hash_equals((string) ($proposal['nonce'] ?? ''), $_POST['confirmation_nonce'])) {
                throw new \App\Core\HttpException(409, 'La confirmación venció. Revise y confirme nuevamente la capacidad.');
            }
            $capacityGuard = new CapacityChangeGuard();
            $capacityGuard->assertGlobalAuthorization();
            Session::forget($sessionKey);
            try {
                $policy->save($module, $proposal['current'], $proposal['ceiling'], $proposal['revision'], fn (): array => $capacityGuard->increaseGate());
                Session::flash('success', 'Capacidad guardada. No se inició ningún procesamiento.');
            } catch (\Throwable $error) {
                Session::flash('error', \App\Services\SafeErrorPresenter::message($error, 'No se guardó la capacidad. Recargue y revise los valores actuales.'));
            }
            $this->redirect($returnPath);
        }
        if ($intent !== 'prepare') {
            throw new \App\Core\HttpException(422, 'Acción de capacidad inválida.');
        }
        (new CapacityChangeGuard())->assertGlobalAuthorization();
        $before = $policy->snapshot($module);
        $currentKey = $module === 'automation' ? 'automation_max_api_calls_per_cycle' : 'manual_api_calls_per_step';
        try {
            ['current' => $current, 'ceiling' => $ceiling] = $policy->validatePair(
                $_POST[$currentKey] ?? null,
                $_POST[$module . '_api_calls_ceiling'] ?? $before['ceiling']
            );
        } catch (\InvalidArgumentException $error) {
            throw new \App\Core\HttpException(422, $error->getMessage());
        }
        $revision = $_POST['capacity_revision'] ?? $before['revision'];
        if (!is_string($revision) || !hash_equals($before['revision'], $revision)) {
            throw new \App\Core\HttpException(409, 'La capacidad cambió. Recargue y confirme los valores actuales.');
        }
        $proposal = [
            'module' => $module, 'user_id' => (int) Auth::id(), 'before' => $before,
            'current' => $current, 'ceiling' => $ceiling, 'revision' => $revision,
            'nonce' => bin2hex(random_bytes(24)), 'expires_at' => time() + 600,
        ];
        Session::put($sessionKey, $proposal);
        View::render('settings/capacity_confirmation', compact('module', 'action', 'returnPath', 'proposal'));
    }

    /** @return array{1:int,2:int,3:int,4:int} */
    private function billing429BackoffMinutesFromPost(): array
    {
        return $this->normalizePostedBilling429Backoff([
            $_POST['billing_429_backoff_1_minutes'] ?? 30,
            $_POST['billing_429_backoff_2_minutes'] ?? 120,
            $_POST['billing_429_backoff_3_minutes'] ?? 360,
            $_POST['billing_429_backoff_max_minutes'] ?? 720,
        ]);
    }

    /** @param list<mixed> $values @return array{1:int,2:int,3:int,4:int} */
    private function normalizePostedBilling429Backoff(array $values): array
    {
        foreach ($values as $value) {
            if (!is_numeric($value) || (int) $value < 5 || (int) $value > 720) {
                throw new \App\Core\HttpException(422, 'Cada pausa Billing 429 debe estar entre 5 y 720 minutos.');
            }
        }
        return \App\Services\ApiRhythmPolicyService::normalizeBilling429BackoffMinutes($values);
    }

    /** @return array{1:int,2:int,3:int,4:int}|null */
    private function billing429BackoffMinutesFromGeneralPost(AppSettingsService $settings): ?array
    {
        $keys = [
            'api.rhythm.billing_429_backoff_1_minutes' => 30,
            'api.rhythm.billing_429_backoff_2_minutes' => 120,
            'api.rhythm.billing_429_backoff_3_minutes' => 360,
            'api.rhythm.billing_429_backoff_max_minutes' => 720,
        ];
        $submitted = false;
        $values = [];
        foreach ($keys as $key => $default) {
            $postKey = str_replace('.', '_', $key);
            $submitted = $submitted || array_key_exists($postKey, $_POST);
            $values[] = $_POST[$postKey] ?? $settings->get($key, (string) $default) ?? (string) $default;
        }
        return $submitted ? $this->normalizePostedBilling429Backoff($values) : null;
    }

    /** @param array{1:int,2:int,3:int,4:int} $billingBackoff */
    private function persistBilling429Backoff(AppSettingsService $settings, array $billingBackoff): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $settings->set('api.rhythm.billing_429_backoff_1_minutes', (string) $billingBackoff[1], 'api_rhythm');
            $settings->set('api.rhythm.billing_429_backoff_2_minutes', (string) $billingBackoff[2], 'api_rhythm');
            $settings->set('api.rhythm.billing_429_backoff_3_minutes', (string) $billingBackoff[3], 'api_rhythm');
            $settings->set('api.rhythm.billing_429_backoff_max_minutes', (string) $billingBackoff[4], 'api_rhythm');
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return list<int> */
    private function sanitizeRampSteps(string $raw, int $target): array
    {
        $steps = [];
        foreach (preg_split('/[^0-9]+/', $raw) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            $value = max(1, min(300, (int) $part));
            if ($value <= $target) {
                $steps[$value] = true;
            }
        }
        if ($steps === []) {
            $steps[min(15, $target)] = true;
        }
        $steps[$target] = true;
        $values = array_keys($steps);
        sort($values);
        return $values;
    }

    /** @return array{allowed:bool,message:string} */
    private function queueV4RhythmIncreaseGate(?array $snapshot = null): array
    {
        return (new CapacityChangeGuard())->increaseGate();
    }

    public function cronRhythmPreview(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        try {
            $preview = (new \App\Services\ApiRhythmPolicyService())->preview();
            $snapshot = $this->queueV4RhythmSnapshot();
            $this->applyQueueV4RhythmSnapshot($preview, $snapshot);
            $increaseGate = $this->queueV4RhythmIncreaseGate($snapshot);
            if (empty($increaseGate['allowed'])) {
                $preview['increase_blocker'] = $increaseGate['message'];
            }
            $preview['recent_rate_limit_incidents'] = $this->recentRateLimitIncidents();
            $this->json(['ok' => true, 'preview' => $preview]);
        } catch (\App\Core\HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            http_response_code(503);
            $this->json(['ok' => false, 'message' => 'No se pudo calcular el ritmo efectivo. No se modificó la configuración.']);
        }
    }

    /** @return array<string,mixed> */
    private function queueV4RhythmSnapshot(): array
    {
        $access = (new \App\Services\ApiHealthAccessScope())->snapshot();
        return (new \App\QueueV4Clean\QueueV4CleanHealthSnapshotService(
            Database::connectionFresh()
        ))->snapshot(null, $access['company_ids'], $access['account_ids']);
    }

    /** @param array<string,mixed> $rhythm @param array<string,mixed> $snapshot */
    private function applyQueueV4RhythmSnapshot(array &$rhythm, array $snapshot): void
    {
        $totals = is_array($snapshot['totals'] ?? null) ? $snapshot['totals'] : [];
        $rhythm['operational_backlog'] = max(0,
            (int) ($totals['ready'] ?? 0)
            + (int) ($totals['running'] ?? 0)
            + (int) ($totals['waiting'] ?? 0)
        );
        $rhythm['review_backlog'] = max(0, (int) ($totals['review'] ?? 0));
        $rhythm['completed_last_hour'] = max(0, (int) ($totals['completed_last_hour'] ?? 0));
        $rhythm['backlog_measured_at'] = $snapshot['measured_at'] ?? null;
        $rhythm['backlog_protocol'] = $snapshot['snapshot_state'] ?? 'unavailable';
        $rhythm['queue_v4_state'] = $snapshot['state'] ?? 'unavailable';
    }

    public function index(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        // El Centro es navegación, no un diagnóstico. Las lecturas profundas
        // permanecen en sus pantallas para que una cola grande no bloquee aquí.
        $diagnostic = [
            'ml_write_enabled' => Env::bool('ML_WRITE_ENABLED', false) ? 'true' : 'false',
            'file_version' => \App\Services\AppVersionService::fileVersion(),
            'installed_version' => (new \App\Services\AppVersionService())->installedVersion(),
        ];
        $cron = [
            'state' => 'deferred',
            'label' => 'Estado disponible en Automatización',
            'message' => 'Abra Cron para comprobar la última señal y las colas sin retrasar este centro.',
        ];
        $safety = (new \App\Services\SystemSafetyStatusService())->status();
        $manualStopped = $safety['api'] === 'stopped' || $safety['automation'] === 'stopped';
        $manualEngine = [
            'ready' => !$manualStopped,
            'state' => $manualStopped ? 'maintenance' : 'directed_cli',
        ];
        View::render('settings/index', compact('diagnostic', 'cron', 'manualEngine'));
    }

    /** @return list<array<string,mixed>> */
    private function recentRateLimitIncidents(): array
    {
        try {
            return array_slice((new ApiHealthService())->incidents([
                'hours' => 24,
                'origin' => 'remote',
                'http_status' => 429,
            ], 5), 0, 5);
        } catch (\Throwable) {
            return [];
        }
    }

    public function save(): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        if (isset($_POST['questions_sync_enabled']) || isset($_POST['questions_endpoint_confirmed'])) {
            throw new \App\Core\HttpException(
                410,
                'La sincronización general de preguntas está retirada. No se cambió la configuración.'
            );
        }
        $settings = new AppSettingsService();
        $generalBillingBackoff = $this->billing429BackoffMinutesFromGeneralPost($settings);
        foreach ([
            'sync.max_manual_range_days' => 'sync',
            'sync.pause_between_pages_ms' => 'sync',
            'sync.backoff_429_seconds' => 'sync',
            'sync.backoff_5xx_seconds' => 'sync',
            'sync.chunk_parts' => 'sync',
            'sync.continuation_delay_minutes' => 'sync',
            'sync.diagnostic_sample_days' => 'sync',
            'sync.monitor_refresh_seconds' => 'sync',
            'sync.default_enqueue_delay_minutes' => 'sync',
            'sync.overdue_reschedule_default_minutes' => 'sync',
            'api.guard.max_429_per_window' => 'api_guard',
            'api.guard.max_403_per_window' => 'api_guard',
            'api.guard.window_minutes' => 'api_guard',
            'api.guard.cooldown_minutes' => 'api_guard',
            'api.guard.max_retry_attempts' => 'api_guard',
            'api.guard.max_401_per_window' => 'api_guard',
            'api.guard.max_400_per_window' => 'api_guard',
            'api.guard.max_unknown_400_per_window' => 'api_guard',
            'api.guard.max_5xx_per_window' => 'api_guard',
            'api.guard.app_blocked_cooldown_minutes' => 'api_guard',
            'api.guard.unauthorized_scopes_global_pause_minutes' => 'api_guard',
            'api.budget.global_requests_per_15m' => 'api_guard',
            'api.budget.account_requests_per_15m' => 'api_guard',
            'api.budget.endpoint_requests_per_15m' => 'api_guard',
            'api.budget.job_type_requests_per_15m' => 'api_guard',
            'api.budget.web_request_api_limit' => 'api_guard',
            'api.logs.request_retention_days' => 'api_guard',
            'api.logs.error_retention_days' => 'api_guard',
            'api.logs.raw_retention_days' => 'api_guard',
            'api.guard.jitter_min_ms' => 'api_guard',
            'api.guard.jitter_max_ms' => 'api_guard',
            'api.rhythm.billing_429_backoff_1_minutes' => 'api_rhythm',
            'api.rhythm.billing_429_backoff_2_minutes' => 'api_rhythm',
            'api.rhythm.billing_429_backoff_3_minutes' => 'api_rhythm',
            'api.rhythm.billing_429_backoff_max_minutes' => 'api_rhythm',
            'notifications.pause_between_requests_ms' => 'notifications',
            'notifications.max_retries' => 'notifications',
            'notifications.cooldown_429_minutes' => 'notifications',
            'notifications.cooldown_403_minutes' => 'notifications',
            'financial_recalc.pause_between_requests_ms' => 'financial_recalc',
        ] as $key => $group) {
            $postKey = str_replace('.', '_', $key);
            if (isset($_POST[$postKey])) {
                if (str_starts_with($key, 'api.rhythm.billing_429_backoff_')) {
                    continue;
                }
                $value = max(0, (int) $_POST[$postKey]);
                if ($key === 'sync.default_enqueue_delay_minutes' && !in_array($value, [0, 5, 30, 60], true)) {
                    $value = 5;
                }
                if ($key === 'sync.overdue_reschedule_default_minutes' && !in_array($value, [0, 5, 10, 20, 30], true)) {
                    $value = 5;
                }
                $settings->set($key, (string) $value, $group);
            }
        }
        if ($generalBillingBackoff !== null) {
            $this->persistBilling429Backoff($settings, $generalBillingBackoff);
        }
        if (isset($_POST['sync_chunk_mode'])) {
            $mode = in_array($_POST['sync_chunk_mode'], ['daily', 'weekly', 'parts'], true) ? (string) $_POST['sync_chunk_mode'] : 'daily';
            $settings->set('sync.chunk_mode', $mode, 'sync');
        }
        if (isset($_POST['app_timezone']) && in_array((string) $_POST['app_timezone'], timezone_identifiers_list(), true)) {
            $settings->set('app.timezone', (string) $_POST['app_timezone'], 'general');
        }
        $settings->set('sync.manual_process_enabled', isset($_POST['sync_manual_process_enabled']) ? '1' : '0', 'sync');
        $settings->set('sync.allow_custom_schedule', isset($_POST['sync_allow_custom_schedule']) ? '1' : '0', 'sync');
        $settings->set('sync.manual_overlay_enabled', isset($_POST['sync_manual_overlay_enabled']) ? '1' : '0', 'sync');
        $settings->set('api.guard.enabled', isset($_POST['api_guard_enabled']) ? '1' : '0', 'api_guard');
        $settings->set('api.budget.enabled', isset($_POST['api_budget_enabled']) ? '1' : '0', 'api_guard');
        $settings->set('api.cron.priority_budget_enabled', isset($_POST['api_cron_priority_budget_enabled']) ? '1' : '0', 'api_guard');
        $settings->set('questions.sync_enabled', '0', 'questions');
        $settings->set('questions.endpoint_confirmed', '0', 'questions');
        $settings->set('questions.email_enabled', isset($_POST['questions_email_enabled']) ? '1' : '0', 'questions');
        if (isset($_POST['questions_email_to'])) {
            $settings->set('questions.email_to', trim((string) $_POST['questions_email_to']), 'questions');
        }
        $settings->set('notifications.enabled', isset($_POST['notifications_enabled']) ? '1' : '0', 'notifications');
        $settings->set('notifications.safe_mode', isset($_POST['notifications_safe_mode']) ? '1' : '0', 'notifications');
        $settings->set('notifications.missed_feeds_enabled', isset($_POST['notifications_missed_feeds_enabled']) ? '1' : '0', 'notifications');
        $settings->set('notifications.show_bell', isset($_POST['notifications_show_bell']) ? '1' : '0', 'notifications');
        $settings->set('notifications.show_health', isset($_POST['notifications_show_health']) ? '1' : '0', 'notifications');
        $settings->set('financial_recalc.enabled', isset($_POST['financial_recalc_enabled']) ? '1' : '0', 'financial_recalc');
        $settings->set('financial_recalc.use_billing_order_details', isset($_POST['financial_recalc_use_billing_order_details']) ? '1' : '0', 'financial_recalc');
        $settings->set('financial_recalc.auto_billing_for_missing', isset($_POST['financial_recalc_auto_billing_for_missing']) ? '1' : '0', 'financial_recalc');
        $settings->set('financial_recalc.safe_mode', isset($_POST['financial_recalc_safe_mode']) ? '1' : '0', 'financial_recalc');
        $settings->set('financial_recalc.reconnect_between_steps', isset($_POST['financial_recalc_reconnect_between_steps']) ? '1' : '0', 'financial_recalc');
        $settings->set('update.enabled', isset($_POST['update_enabled']) ? '1' : '0', 'update');
        Session::flash('success', 'Configuración guardada.');
        $this->redirect('/settings');
    }

    public function cron(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        $safety = (new \App\Services\SystemSafetyStatusService())->status();
        // El primer HTML nunca construye los read models pesados. El navegador
        // muestra el shell inmediatamente y carga overview/colas por separado.
        $overview = [
            'ok' => true,
            'snapshot_state' => 'partial',
            'state' => 'loading',
            'state_label' => 'Comprobando Cron',
            'state_message' => 'El panel está cargando el último snapshot sin crear trabajos.',
            'now' => null,
            'last_run' => null,
            'next' => [],
            'workload' => [],
            'history' => [],
        ];
        View::render('settings/cron_shell', compact('safety', 'overview'));
    }

    public function queueV4DiagnosticStatus(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        try {
            $this->json((new \App\Services\QueueV4DiagnosticBundleService())->status());
        } catch (\Throwable $error) {
            http_response_code(500);
            $this->json([
                'ok' => false,
                'error' => 'queue_diagnostic_status_failed',
                'class' => get_class($error),
            ]);
        }
    }

    public function queueV4DiagnosticDebug(): void
    {
        $this->requireAdminPermanent();
        $this->assertSameOrigin();
        Csrf::validate($_POST['_token'] ?? null);
        $minutes = max(0, min(60, (int) ($_POST['minutes'] ?? 0)));
        if (!in_array($minutes, [0, 15, 30, 60], true)) {
            $minutes = 0;
        }
        (new \App\Services\QueueV4DiagnosticBundleService())->setDebugMinutes($minutes);
        Session::flash('success', $minutes > 0 ? 'Debug extendido de Queue activado temporalmente.' : 'Debug extendido de Queue apagado.');
        $this->redirect('/settings/cron#queue-v4-diagnostic');
    }

    public function queueV4DiagnosticGenerate(): void
    {
        $this->requireAdminPermanent();
        $this->assertSameOrigin();
        Csrf::validate($_POST['_token'] ?? null);
        $result = (new \App\Services\QueueV4DiagnosticBundleService())->generateBundle();
        $bundle = is_array($result['bundle'] ?? null) ? $result['bundle'] : [];
        $url = (string) ($bundle['signed_url'] ?? '');
        Session::flash('success', $url !== '' ? 'Paquete diagnóstico generado. El enlace temporal quedó disponible por 30 minutos.' : 'Paquete diagnóstico generado.');
        $this->redirect('/settings/cron#queue-v4-diagnostic');
    }

    public function queueV4DiagnosticDownload(): void
    {
        $token = (string) ($_GET['token'] ?? '');
        $download = (new \App\Services\QueueV4DiagnosticBundleService())->resolveDownloadToken($token);
        if ($download === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'El enlace diagnóstico no existe o expiró.';
            return;
        }
        header('Content-Type: application/zip');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('X-Queue-Diagnostic-SHA256: ' . $download['sha256']);
        header('Content-Length: ' . (string) $download['bytes']);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $download['filename']) . '"');
        readfile($download['path']);
        exit;
    }

    public function queueV4CleanStatus(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        try {
            $this->json((new \App\QueueV4Clean\QueueV4CleanReadinessService(
                Database::connectionFresh(),
            ))->snapshot());
        } catch (\Throwable) {
            http_response_code(503);
            $this->json([
                'ok' => false,
                'state' => 'NOT_READY',
                'message' => 'Queue V4 no está disponible. No se modificó ninguna cola.',
            ]);
        }
    }

    /** Read-only direct transport evidence for the Cron risk card. */
    public function cronApiRisks(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        $summary = (new \App\Services\CronApiRiskSummaryService())->snapshot();
        if (($summary['ok'] ?? false) !== true) {
            http_response_code(503);
        }
        $this->json($summary);
    }

    public function queueV4CleanReadiness(): void
    {
        $this->queueV4CleanMutation('readiness');
    }

    public function queueV4CleanActivate(): void
    {
        $this->queueV4CleanMutation('activate');
    }

    public function queueV4CleanStop(): void
    {
        $this->queueV4CleanMutation('stop');
    }

    private function queueV4CleanMutation(string $action): void
    {
        $deadline = microtime(true) + 45.0;
        $this->requireAdminPermanent();
        $this->assertSameOrigin();
        Csrf::validate($_POST['_token'] ?? null);
        try {
            $operation = $action === 'readiness' ? ($_POST['action'] ?? null) : $action;
            if (!is_string($operation) || !in_array($operation, ['prepare', 'check', 'cancel', 'activate', 'stop'], true)
                || ($action === 'readiness' && !in_array($operation, ['prepare', 'check', 'cancel'], true))
                || array_diff(array_keys($_POST), ['_token', 'admin_password', 'action', 'run_id', 'run_token', 'step_no']) !== []
            ) {
                throw new \RuntimeException('Vuelva a preparar la comprobación; la solicitud no es válida.');
            }
            $runId = 0;
            $runToken = '';
            $stepNo = 0;
            if (in_array($operation, ['check', 'cancel', 'activate'], true)) {
                $rawRun = $_POST['run_id'] ?? null;
                $runToken = $_POST['run_token'] ?? '';
                if ((!is_string($rawRun) && !is_int($rawRun))
                    || preg_match('/^[1-9][0-9]*$/D', (string) $rawRun) !== 1
                    || filter_var($rawRun, FILTER_VALIDATE_INT) === false
                    || !is_string($runToken)
                    || (!($operation === 'cancel' && $runToken === '') && preg_match('/^[a-f0-9]{64}$/D', $runToken) !== 1)
                ) {
                    throw new \RuntimeException('Vuelva a preparar la comprobación; su identificación no es válida.');
                }
                $runId = (int) $rawRun;
            }
            if ($operation === 'check') {
                $rawStep = $_POST['step_no'] ?? null;
                if ((!is_string($rawStep) && !is_int($rawStep)) || preg_match('/^[1-3]$/D', (string) $rawStep) !== 1) {
                    throw new \RuntimeException('La comprobación solicitada no es válida.');
                }
                $stepNo = (int) $rawStep;
            }
            $password = $_POST['admin_password'] ?? '';
            if (!is_string($password)) {
                throw new \RuntimeException('La confirmación administrativa no es válida.');
            }
            $reauth = new \App\Services\AdministrativeReauthenticationService();
            if (in_array($operation, ['check', 'cancel'], true)) {
                // A password may refresh an expired confirmation or permit explicit orphan cancellation.
                // It never extends the readiness manifest TTL.
                if ($password !== '') {
                    $reauth->requirePassword($password);
                }
                $reauth->requireRecent(600);
            } else {
                $reauth->requirePassword($password);
            }
            $result = \App\Services\CronDeadlineContext::within($deadline, static function () use ($operation, $runId, $runToken, $stepNo): array {
                $pdo = Database::connectionFresh();
                $actorId = (int) Auth::id();
                return match ($operation) {
                    'prepare' => (new \App\QueueV4Clean\QueueV4CleanReadinessService($pdo))->prepare($actorId),
                    'check' => (new \App\QueueV4Clean\QueueV4CleanReadinessService($pdo))->check($actorId, $runId, $runToken, $stepNo),
                    'cancel' => (new \App\QueueV4Clean\QueueV4CleanReadinessService($pdo))->cancel($actorId, $runId, $runToken),
                    'activate' => (new \App\QueueV4Clean\QueueV4CleanControlService($pdo))->activate($actorId, $runId, $runToken),
                    'stop' => (new \App\QueueV4Clean\QueueV4CleanControlService($pdo))->stop($actorId),
                };
            });
            $this->json($result);
        } catch (\Throwable $error) {
            http_response_code(409);
            $this->json([
                'ok' => false,
                'message' => \App\Services\SafeErrorPresenter::message(
                    $error,
                    'Queue V4 bloqueó la operación sin cambiar el motor.',
                    ['module' => 'queue_v4_clean', 'action' => $action],
                ),
            ]);
        }
    }

    public function cronSection(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        try {
            $service = new \App\Services\CronOperationalReadService();
            $tasks = $service->tasks();
            $snapshotState = $service->snapshotState();
            if ($snapshotState === 'complete' && $tasks === []) {
                $snapshotState = 'authoritative_empty';
            }
            $this->json([
                'ok' => true,
                'snapshot_state' => $snapshotState,
                'authoritative' => in_array($snapshotState, ['complete', 'authoritative_empty'], true),
                'overview' => $service->overview($tasks),
                'tasks' => $tasks,
            ]);
        } catch (\App\Core\HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            http_response_code(503);
            $this->json([
                'ok' => false,
                'snapshot_state' => 'unavailable',
                'authoritative' => false,
                'message' => 'No se pudo actualizar Cron. Se conserva el último estado visible.',
            ]);
        }
    }

    public function cronOverview(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        try {
            $snapshot = $this->queueV4OperationalSnapshot();
            $runtime = (array) ($snapshot['runtime'] ?? []);
            $totals = (array) ($snapshot['totals'] ?? []);
            $this->json([
                'ok' => true,
                'snapshot_state' => 'complete',
                'authoritative' => true,
                'state' => (string) ($snapshot['state'] ?? 'attention'),
                'state_label' => (string) ($snapshot['state_label'] ?? 'Queue V4 requiere revisión'),
                'last_signal_label' => (string) ($runtime['last_scheduler_at'] ?? 'sin señal'),
                'workload' => [
                    'pending' => (int) ($totals['ready'] ?? 0) + (int) ($totals['running'] ?? 0) + (int) ($totals['waiting'] ?? 0),
                    'remote_calls_last_hour' => (int) ($totals['http_last_hour'] ?? 0),
                    'finalized_last_hour' => (int) ($totals['completed_last_hour'] ?? 0),
                    'trend_label' => 'Backlog operativo Queue V4; Review se informa por separado.',
                ],
                'last_run' => null,
                'now' => null,
                'next' => [],
                'history' => [],
                'runtime' => $runtime,
                'legacy_state_consulted' => false,
            ]);
        } catch (\App\Core\HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            http_response_code(503);
            $this->json([
                'ok' => false,
                'snapshot_state' => 'unavailable',
                'authoritative' => false,
                'message' => 'No se pudo actualizar el ciclo. Se conserva el último estado visible.',
            ]);
        }
    }

    public function cronOperationalSnapshot(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        try {
            $snapshot = $this->queueV4OperationalSnapshot();
            $this->json($snapshot + [
                'ok' => true,
                'snapshot_state' => 'complete',
                'authoritative' => true,
                'queues' => [],
                'legacy_state_consulted' => false,
            ]);
        } catch (\App\Core\HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            http_response_code(503);
            $this->json([
                'ok' => false,
                'snapshot_state' => 'unavailable',
                'read_only' => true,
                'message' => 'No se pudo leer el snapshot operativo. No se modificó ninguna cola.',
            ]);
        }
    }

    public function cronTasks(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        try {
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = max(10, min(50, (int) ($_GET['per_page'] ?? 50)));
            $this->json((new \App\Services\CronOperationalReadService())->queueTasksPage($page, $perPage));
        } catch (\App\Core\HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            http_response_code(503);
            $this->json([
                'ok' => false,
                'snapshot_state' => 'unavailable',
                'authoritative' => false,
                'message' => 'No se pudo actualizar el listado. Se conservan los datos anteriores.',
            ]);
        }
    }

    public function cronQueues(): void
    {
        $this->cronTasks();
    }

    /** @return array<string,mixed> */
    private function queueV4OperationalSnapshot(): array
    {
        $access = (new \App\Services\ApiHealthAccessScope())->snapshot();
        return (new \App\QueueV4Clean\QueueV4CleanHealthSnapshotService(
            Database::connectionFresh()
        ))->snapshot(null, (array) $access['company_ids'], (array) $access['account_ids']);
    }

    public function cronDoctor(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        try {
            $this->json((new \App\Services\CronDoctorService())->snapshot('web'));
        } catch (\App\Core\HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            http_response_code(503);
            $this->json([
                'ok' => false,
                'snapshot_state' => 'unavailable',
                'read_only' => true,
                'message' => 'No se pudo construir el diagnóstico de Cron. No se modificó ninguna cola.',
            ]);
        }
    }

    public function cronRun(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        $run = (new \App\Services\WorkQueueRunService())->detail(trim((string) ($_GET['token'] ?? '')));
        if ($run === null) {
            throw new \App\Core\HttpException(404, 'No se encontró el ciclo solicitado.');
        }
        View::render('settings/automation_run', compact('run'));
    }

    public function cronRunJson(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        $run = (new \App\Services\WorkQueueRunService())->detail(trim((string) ($_GET['token'] ?? '')));
        if ($run === null) {
            http_response_code(404);
            $this->json(['ok' => false, 'message' => 'No se encontró el ciclo solicitado.']);
            return;
        }
        $this->json(['ok' => true, 'run' => $run]);
    }

    public function cronHistoryJson(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        try {
            if (($_GET['archive'] ?? '') !== 'legacy') {
                $history = (new \App\Services\WorkQueueRunService())->v4HistoryPage(
                    max(1, (int) ($_GET['page'] ?? 1)), 50
                );
                $this->json(['ok'=>true, 'read_only'=>true, 'snapshot_state'=>'partial',
                    'rows'=>$history['runs'], 'source_engine'=>'queue_v4_clean',
                    'physical_total_certainty'=>'UNKNOWN']);
                return;
            }
            $snapshot = (new \App\Services\CronV3OperationalSnapshotService())->snapshot();
            $overview = is_array($snapshot['legacy_overview'] ?? null) ? $snapshot['legacy_overview'] : [];
            $history = is_array($overview['history'] ?? null) ? $overview['history'] : [];
            $legacyNeedsDiagnosis = 0;
            $rows = [];
            foreach ($history as $row) {
                $state = (string) ($row['state'] ?? $row['result'] ?? '');
                $diagnostic = strtolower(implode(' ', array_map(
                    static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                    [
                        $row['diagnostic_code'] ?? '',
                        $row['diagnostic'] ?? '',
                        $row['reason'] ?? '',
                        $row['safe_error_message'] ?? '',
                        $row['safe_message'] ?? '',
                        $row['message'] ?? '',
                        $row['error_message'] ?? '',
                        $row['state_label'] ?? '',
                        $row['human_classification'] ?? '',
                    ]
                )));
                $row['source_engine'] = 'legacy_v2_before_v3_cutover';
                $row['source_label'] = 'Legacy antes del corte V3';
                if (str_contains($diagnostic, 'legacy_needs_diagnosis')) {
                    $legacyNeedsDiagnosis++;
                    continue;
                }
                $hasAttention = in_array($state, ['attention', 'error', 'failed', 'review'], true)
                    || str_contains(strtolower((string) ($row['state_label'] ?? '')), 'atenci');
                if ($hasAttention && empty($row['url']) && !empty($row['token'])) {
                    $row['url'] = '/settings/cron/run?token=' . rawurlencode((string) $row['token']);
                }
                $hasExactLink = !empty($row['url']) || (!empty($row['queue_key']) && !empty($row['source_id']));
                if ($hasAttention && $hasExactLink) {
                    $row['human_classification'] = 'Necesita revisión accionable';
                } elseif ($hasAttention) {
                    $row['human_classification'] = 'Legacy antes del corte V3';
                } else {
                    $row['human_classification'] = (int) ($row['remote_calls'] ?? $row['http_calls'] ?? 0) > 0
                        ? 'Avanzó con HTTP'
                        : 'Avanzó localmente';
                }
                $rows[] = $row;
            }
            $intervention = [];
            if ($legacyNeedsDiagnosis > 0) {
                $intervention[] = [
                    'group' => 'legacy_needs_diagnosis',
                    'label' => $legacyNeedsDiagnosis . ' eventos legacy requieren diagnóstico local',
                    'message' => 'Son registros anteriores al corte V3; no se repetirán como errores nuevos de cada ciclo.',
                    'action_label' => 'Diagnosticar localmente',
                    'url' => '/settings/cron/queue?group=attention&resolution=legacy_needs_diagnosis',
                ];
            }
            $this->json([
                'ok' => true,
                'snapshot_state' => $snapshot['snapshot_state'] ?? 'partial',
                'read_only' => true,
                'rows' => $rows,
                'intervention_groups' => $intervention,
                'drainage' => $snapshot['drainage'] ?? null,
                'cursor' => null,
                'next_cursor' => null,
                'measured_at' => $snapshot['measured_at'] ?? gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\App\Core\HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            http_response_code(503);
            $this->json([
                'ok' => false,
                'snapshot_state' => 'unavailable',
                'read_only' => true,
                'message' => 'No se pudo leer el historial de Cron. No se modificó ninguna cola.',
            ]);
        }
    }

    public function automationNext(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        $preview = (new \App\Services\WorkSchedulerService())->preview();
        View::render('settings/automation_next', compact('preview'));
    }

    public function automationWork(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        $queueKey = trim((string) ($_GET['queue_key'] ?? ''));
        $sourceId = trim((string) ($_GET['source_id'] ?? ''));
        if ($queueKey === '' || $sourceId === '' || strlen($queueKey) > 80 || strlen($sourceId) > 100) {
            throw new \App\Core\HttpException(404, 'No se encontró el trabajo solicitado.');
        }
        $work = (new \App\Services\WorkQueueProjectionService())->find($queueKey, $sourceId);
        if ($work === null) {
            throw new \App\Core\HttpException(404, 'El trabajo ya no está disponible o cambió de estado.');
        }
        if (!empty($work['meli_account_id'])) {
            (new BusinessScopeContext())->account(
                (int) $work['meli_account_id'],
                (int) ($work['company_id'] ?? 0),
                (int) Auth::id()
            );
        }
        $resolution = (new \App\Services\ExactWorkRemediationService())->context($work, (int) Auth::id());
        View::render('settings/automation_work', compact('work', 'resolution'));
    }

    public function automationWorkRemediate(): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        $this->assertSameOrigin();
        $queueKey = preg_replace('/[^a-z0-9_]/', '', (string) ($_POST['queue_key'] ?? '')) ?: '';
        $sourceId = preg_replace('/[^a-zA-Z0-9:_-]/', '', (string) ($_POST['source_id'] ?? '')) ?: '';
        $wantsJson = str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
        try {
            $result = (new \App\Services\ExactWorkRemediationService())->remediate($_POST, (int) Auth::id());
            if ($wantsJson) {
                $this->json(['ok' => true] + $result);
                return;
            }
            Session::flash('success', $result['message']);
        } catch (\App\Core\HttpException $error) {
            if ($wantsJson) {
                http_response_code($error->status);
                $this->json(['ok' => false, 'message' => $error->publicMessage]);
                return;
            }
            Session::flash('error', $error->publicMessage);
        } catch (\Throwable $error) {
            $message = \App\Services\SafeErrorPresenter::message(
                $error,
                'No fue posible aplicar la acción exacta. No se consultó Mercado Libre.',
                ['module' => 'exact_work_remediation', 'queue_key' => $queueKey]
            );
            if ($wantsJson) {
                http_response_code(500);
                $this->json(['ok' => false, 'message' => $message]);
                return;
            }
            Session::flash('error', $message);
        }
        $this->redirect('/settings/cron/work?' . http_build_query([
            'queue_key' => $queueKey,
            'source_id' => $sourceId,
        ]));
    }

    public function automationQueue(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        $rawGroup = (string) ($_GET['group'] ?? 'attention');
        $filters = [
            'account_id' => max(0, (int) ($_GET['account_id'] ?? 0)),
            'queue_key' => trim((string) ($_GET['type'] ?? $_GET['queue_key'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'mode' => in_array((string) ($_GET['mode'] ?? ''), ['api', 'local'], true) ? (string) $_GET['mode'] : '',
            'resolution' => in_array((string) ($_GET['resolution'] ?? ''), [
                'legacy_needs_diagnosis', 'retryable_local', 'expected_absence', 'remote_result_uncertain',
            ], true) ? (string) $_GET['resolution'] : '',
            'page' => max(1, (int) ($_GET['page'] ?? 1)),
            'per_page' => in_array((int) ($_GET['per_page'] ?? 50), [25, 50, 100], true) ? (int) $_GET['per_page'] : 50,
            'group' => in_array($rawGroup, ['running','attention','waiting','upcoming','completed','all'], true)
                ? $rawGroup
                : 'attention',
        ];
        if ($rawGroup === 'legacy_needs_diagnosis') {
            $filters['group'] = 'attention';
            $filters['resolution'] = 'legacy_needs_diagnosis';
        }
        if ($filters['status'] !== '') {
            $filters['group'] = 'all';
        }
        $queue = (new \App\Services\WorkQueueProjectionService())->page($filters);
        $accounts = (new \App\Services\SyncCenterService())->accounts();
        $definitions = (new \App\Services\WorkQueueRegistry())->definitionsByKey();
        View::render('settings/automation_queue', compact('queue', 'filters', 'accounts', 'definitions'));
    }

    public function cronV3Parked(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        $scope = new BusinessScopeContext();
        $predicate = $scope->accountPredicate('w.meli_account_id');
        $status = trim((string) ($_GET['status'] ?? ''));
        $allowed = ['waiting_identity', 'waiting_capability', 'review', 'dead', 'waiting_rate', 'waiting_budget', 'waiting_api'];
        if (!in_array($status, $allowed, true)) {
            $status = '';
        }
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(25, min(100, (int) ($_GET['per_page'] ?? 50)));
        $where = [$predicate['sql'], "w.status IN ('waiting_identity','waiting_capability','review','dead','waiting_rate','waiting_budget','waiting_api')"];
        $params = $predicate['params'];
        if ($status !== '') {
            $where[] = 'w.status=?';
            $params[] = $status;
        }
        $pdo = Database::connectionFresh();
        $count = $pdo->prepare('SELECT COUNT(*) FROM cron_v3_work w WHERE ' . implode(' AND ', $where));
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $sql = 'SELECT w.id,w.company_id,w.meli_account_id,w.work_type,w.lane,w.status,
                       w.source_ref,w.last_error_code,w.available_at,w.created_at,w.updated_at,
                       a.account_name
                FROM cron_v3_work w
                JOIN meli_accounts a ON a.id=w.meli_account_id AND a.company_id=w.company_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY w.status,w.id ASC
                LIMIT ' . (($page - 1) * $perPage) . ',' . $perPage;
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $parked = [
            'rows' => $statement->fetchAll(\PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'status' => $status,
        ];
        View::render('settings/cron_v3_parked', compact('parked'));
    }

    public function automationHistory(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $requestedPerPage = (int) ($_GET['per_page'] ?? 50);
        $perPage = in_array($requestedPerPage, [25, 50, 100], true) ? $requestedPerPage : 50;
        if (($_GET['archive'] ?? '') !== 'legacy') {
            $history = (new \App\Services\WorkQueueRunService())->v4HistoryPage($page, $perPage);
            View::render('settings/calls_history', compact('history', 'page', 'perPage'));
            return;
        }
        $history = (new \App\Services\WorkQueueRunService())->historyPage($page, $perPage);
        $runs = $history['runs'];
        $total = $history['total'];
        $legacyNeedsDiagnosis = 0;
        foreach ($runs as $run) {
            foreach ((array) ($run['items'] ?? []) as $item) {
                if (!empty($item['legacy_needs_diagnosis'])) {
                    $legacyNeedsDiagnosis++;
                }
            }
        }
        View::render('settings/automation_history', compact('runs', 'total', 'page', 'perPage', 'legacyNeedsDiagnosis'));
    }

    public function automationDiagnostics(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        $summary = (new \App\Services\WorkQueueProjectionService())->summary();
        $availability = (new \App\Services\CronWorkAvailabilityService())->snapshot();
        $definitions = (new \App\Services\WorkQueueRegistry())->definitionsByKey();
        $adapterHealth = (new \App\Services\WorkQueueAdapterHealthService())->all();
        View::render('settings/automation_diagnostics', compact('summary', 'availability', 'definitions', 'adapterHealth'));
    }

    public function automationAttention(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        $groups = (new \App\Services\WorkRemediationService())->groups((int) Auth::id());
        View::render('settings/automation_attention', compact('groups'));
    }

    public function automationRemediate(): void
    {
        $this->requireAdminPermanent();
        throw new \App\Core\HttpException(410, 'La corrección por grupos fue retirada. Abra un trabajo exacto desde el centro de intervención.');
    }

    public function manualProcessing(): void
    {
        $this->requireAdminPermanent();
        $userId = (int) Auth::id();
        $previewToken = trim((string) ($_GET['preview'] ?? ''));
        $scope = trim((string) ($_GET['scope'] ?? 'available_queue'));
        $accountId = max(0, (int) ($_GET['account_id'] ?? 0));
        $origin = preg_replace('/[^a-z_]/', '', (string) ($_GET['origin'] ?? 'manual_center')) ?: 'manual_center';
        $originContext = [
            'account_id' => $accountId,
            'year' => max(0, (int) ($_GET['year'] ?? 0)),
            'month' => max(0, (int) ($_GET['month'] ?? 0)),
            'date_from' => trim((string) ($_GET['date_from'] ?? '')),
            'date_to' => trim((string) ($_GET['date_to'] ?? '')),
        ];
        $safetyStatus = (new \App\Services\SystemSafetyStatusService())->status();
        $emergencyStop = $safetyStatus['api'] === 'stopped' || $safetyStatus['automation'] === 'stopped';
        $scope = \App\Services\ManualCampaignPreviewService::assertScope($scope);
        $capacity = (new CapacityPolicyService())->snapshot('manual');
        $schema = new \App\Services\SchemaInspectorService();
        $campaignReady = $schema->missingRequirements(
            \App\Services\ManualCampaignPreviewService::schemaRequirements($scope)
        ) === [];
        $preview = null;
        if ($previewToken !== '' && $campaignReady) {
            try {
                $preview = (new \App\Services\ManualCampaignPreviewService())->load($previewToken, $userId);
                $configuration = (array) ($preview['configuration'] ?? []);
                $scope = (string) ($configuration['scope'] ?? $scope);
                $accountId = (int) ($configuration['account_id'] ?? $accountId);
            } catch (\Throwable $error) {
                Session::flash('error', \App\Services\SafeErrorPresenter::message(
                    $error,
                    'El cálculo ya no está disponible. Vuelva a calcular los trabajos.'
                ));
            }
        }
        $manualAccountLabel = 'Todas las cuentas autorizadas';
        if ($accountId > 0) {
            try {
                $account = (new BusinessScopeContext())->account($accountId, 0, $userId);
                $manualAccountLabel = trim((string) ($account['account_name'] ?? '')) ?: 'Cuenta autorizada';
            } catch (\Throwable) {
                $manualAccountLabel = 'Cuenta autorizada';
            }
        }
        $availableQueueCount = null;
        if ($campaignReady && !$emergencyStop) {
            try {
                $scopeContext = new BusinessScopeContext();
                $authorizedAccountIds = $accountId > 0
                    ? [(int) $scopeContext->account($accountId, 0, $userId)['id']]
                    : $scopeContext->accountIds($userId);
                $availableQueueCount = 0; // A certified empty authorized scope.
                if ($authorizedAccountIds !== []) {
                    $availableQueueCount = (new \App\QueueV4Clean\QueueV4CleanRepository(Database::connection()))
                        ->eligibleCount($authorizedAccountIds, $accountId ?: null);
                }
            } catch (\Throwable) {
                $availableQueueCount = null;
            }
        }
        $manualResult = Session::get('manual_processing_result');
        Session::forget('manual_processing_result');
        $manualAvailableQueueResult = Session::get('manual_available_queue_result');
        Session::forget('manual_available_queue_result');
        $this->releaseReadOnlySession();
        $activeSession = null;
        $smartDrainReady = false;
        $smartDrain = ['ok' => false, 'state' => 'RETIRED_BLOCKED', 'session' => null];
        $engine = [
            'ready' => $campaignReady && !$emergencyStop,
            'state' => $emergencyStop ? 'maintenance' : ($campaignReady ? 'directed_cli_ready' : 'installation_incomplete'),
            'message' => $emergencyStop
                ? 'Mercado Libre está bloqueado por mantenimiento. Las campañas existentes conservan todo su progreso.'
                : ($campaignReady
                    ? 'Procesar ahora ejecuta una confirmación exacta por vez; la automatización V4 sigue separada.'
                    : 'Complete la actualización para habilitar el procesamiento manual.'),
        ];
        $workerCommand = '';
        View::render('settings/manual_processing', compact(
            'scope',
            'capacity',
            'preview',
            'workerCommand',
            'activeSession',
            'engine',
            'campaignReady',
            'accountId',
            'manualAccountLabel',
            'manualResult',
            'manualAvailableQueueResult',
            'availableQueueCount',
            'origin',
            'originContext',
            'emergencyStop',
            'smartDrainReady',
            'smartDrain'
        ));
    }

    public function manualProcessingPreview(): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        $this->assertSameOrigin();
        $safetyStatus = (new \App\Services\SystemSafetyStatusService())->status();
        if ($safetyStatus['api'] === 'stopped' || $safetyStatus['automation'] === 'stopped') {
            Session::flash('warning', 'El procesamiento está en mantenimiento. No se creó ni calculó una campaña nueva.');
            $this->redirect('/settings/manual-processing');
        }
        try {
            $this->assertCallsOnlyManualPost();
            $accountId = max(0, (int) ($_POST['account_id'] ?? 0));
            $requestedScope = \App\Services\ManualCampaignPreviewService::assertScope(
                trim((string) ($_POST['scope'] ?? 'available_queue'))
            );
            $capacity = (new CapacityPolicyService())->snapshot('manual');
            $requestedCalls = (new CapacityPolicyService())->validatePair(
                $_POST['physical_api_call_budget'] ?? null, $capacity['current']
            )['current'];
            if (!is_string($_POST['capacity_revision'] ?? null)
                || !hash_equals($capacity['revision'], $_POST['capacity_revision'])) {
                throw new \App\Core\HttpException(409, 'La capacidad cambió. Vuelva a previsualizar.');
            }
            $preview = (new \App\Services\ManualCampaignPreviewService())->create(
                (int) Auth::id(),
                [
                    'scope' => $requestedScope,
                    'account_id' => $accountId,
                    'physical_api_call_budget' => $requestedCalls,
                    'capacity_revision' => $capacity['revision'],
                ]
            );
            Session::flash(
                'success',
                (int) ($preview['eligible_jobs'] ?? 0) > 0
                    ? 'Cálculo terminado. Revise los trabajos antes de comenzar.'
                    : 'Cálculo terminado. No hay trabajos seguros disponibles ahora.'
            );
            $query = http_build_query([
                'preview' => (string) $preview['preview_token'],
                'scope' => (string) ($preview['configuration']['scope'] ?? $requestedScope),
                'origin' => preg_replace('/[^a-z_]/', '', (string) ($_POST['origin'] ?? 'manual_center')) ?: 'manual_center',
                'account_id' => $accountId ?: null,
            ]);
            $this->redirect('/settings/manual-processing?' . $query . '#resultado-calculo');
        } catch (\Throwable $error) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message(
                $error,
                'No fue posible calcular los trabajos disponibles.',
                ['module' => 'manual_campaign_preview']
            ));
            $this->redirect('/settings/manual-processing');
        }
    }

    public function manualProcessingExcluded(): void
    {
        $this->requireAdminPermanent();
        $previewToken = trim((string) ($_GET['preview'] ?? ''));
        $presenter = new \App\Services\ManualCampaignExclusionPresenter();
        $state = $presenter->normalizeState(trim((string) ($_GET['state'] ?? 'automatic_only')));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = in_array((int) ($_GET['per_page'] ?? 50), [25, 50, 100], true)
            ? (int) $_GET['per_page']
            : 50;
        $this->releaseReadOnlySession();
        try {
            $preview = (new \App\Services\ManualCampaignPreviewService())->loadForReview(
                $previewToken,
                (int) Auth::id()
            );
        } catch (\Throwable $error) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message(
                $error,
                'No fue posible abrir los trabajos de ese cálculo.'
            ));
            $this->redirect('/settings/manual-processing');
        }
        $excludedJobs = (array) ($preview['excluded_jobs'] ?? []);
        if ($excludedJobs !== [] && array_filter(
            $excludedJobs,
            static fn (array $item): bool => !array_key_exists('state', $item)
        ) !== []) {
            Session::flash('info', 'Este cálculo pertenece al formato anterior. Calcule de nuevo para abrir cada trabajo con su causa y solución.');
            $configuration = (array) ($preview['configuration'] ?? []);
            $this->redirect('/settings/manual-processing?' . http_build_query([
                'scope' => (string) ($configuration['scope'] ?? 'recommended'),
                'account_id' => (int) ($configuration['account_id'] ?? 0) ?: null,
            ]));
        }
        $all = array_values(array_filter(
            $excludedJobs,
            fn (array $item): bool => $presenter->normalizeState(
                (string) ($item['state'] ?? 'automatic_only')
            ) === $state
        ));
        $total = count($all);
        $items = array_map(
            static fn (array $item): array => $presenter->item($item),
            array_slice($all, ($page - 1) * $perPage, $perPage)
        );
        $definitions = $presenter->definitions();
        $groups = array_map(
            fn (array $group): array => $presenter->group($group, $previewToken),
            (array) ($preview['excluded_summary'] ?? [])
        );
        View::render('settings/manual_processing_excluded', compact(
            'preview',
            'previewToken',
            'state',
            'page',
            'perPage',
            'total',
            'items',
            'definitions',
            'groups'
        ));
    }

    public function manualProcessingStart(): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        $this->assertSameOrigin();
        $safetyStatus = (new \App\Services\SystemSafetyStatusService())->status();
        if ($safetyStatus['api'] === 'stopped' || $safetyStatus['automation'] === 'stopped') {
            Session::flash('warning', 'El procesamiento está en mantenimiento. No se creó una campaña nueva.');
            $this->redirect('/settings/manual-processing');
        }
        try {
            $this->assertCallsOnlyManualPost();
            $limit = (new CapacityPolicyService())->validatePair(
                $_POST['physical_api_call_budget'] ?? null,
                CapacityPolicyService::TECHNICAL_MAX
            )['current'];
            $result = (new \App\Services\ManualSingleStepService())->executePreview(
                trim((string) ($_POST['preview_token'] ?? '')),
                (int) Auth::id(),
                $limit
            );
            $status = (string) ($result['status'] ?? 'unknown');
            if (!empty($result['manual_available_queue'])) {
                Session::put('manual_available_queue_result', $result);
                Session::flash(
                    $status !== 'completed' ? 'warning' : 'success',
                    (string) ($result['message'] ?? 'La petición terminó; revise el resultado y la evidencia de llamadas.')
                );
                $this->redirect('/settings/manual-processing?scope=available_queue#resultado-proceso');
            }
            $selected = (int) ($result['selected_count'] ?? 1);
            $resultRows = array_values(array_filter(
                (array) ($result['results'] ?? []),
                static fn($row): bool => is_array($row)
            ));
            $completed = 0;
            $waiting = 0;
            $review = 0;
            foreach ($resultRows as $row) {
                $rowState = (string) ($row['status'] ?? '');
                if ($rowState === 'completed') {
                    $completed++;
                } elseif (in_array($rowState, ['deferred', 'waiting', 'retry_wait', 'waiting_oauth', 'pending', 'claimed', 'running'], true)) {
                    $waiting++;
                } elseif ($rowState !== 'not_started') {
                    $review++;
                }
            }
            $attended = (int) ($result['processed_count'] ?? count($resultRows));
            Session::put('manual_processing_result', [
                'processed' => $attended,
                'completed' => $completed,
                'waiting' => $waiting,
                'review' => $review,
                'not_started' => max(0, (int) ($result['not_processed_count'] ?? $selected - $attended)),
                'configured_api_calls' => $result['configured_api_calls'] ?? null,
                'requested_api_calls' => $result['requested_api_calls'] ?? $limit,
                'effective_api_calls' => $result['effective_api_calls'] ?? null,
                'api_calls_used' => $result['api_calls_used'] ?? $result['physical_http_calls'] ?? null,
                'physical_http_calls' => array_key_exists('physical_http_calls', $result)
                    ? $result['physical_http_calls'] : (($result['evidence_state'] ?? '') === 'CERTIFIED' ? ($result['api_calls_used'] ?? null) : null),
                'known_physical_calls' => $result['known_physical_calls'] ?? null,
                'unresolved_reservations' => $result['unresolved_reservations'] ?? null,
                'api_calls_remaining' => $result['api_calls_remaining'] ?? null,
                'evidence_state' => $result['evidence_state'] ?? 'UNKNOWN',
                'stop_reason' => $result['stop_reason'] ?? $status,
                'next_allowed_at' => $result['next_allowed_at'] ?? null,
            ]);
            Session::flash(
                $status !== 'completed' ? 'warning' : 'success',
                (string) ($result['message'] ?? 'La petición terminó; revise lo atendido, lo pendiente y la evidencia de llamadas.')
            );
            $this->redirect('/settings/manual-processing');
        } catch (\Throwable $error) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message(
                $error,
                'No fue posible procesar el trabajo exacto.',
                ['module' => 'manual_processing']
            ));
            $scope = preg_replace('/[^a-z_]/', '', (string) ($_POST['scope'] ?? 'recommended'));
            $this->redirect('/settings/manual-processing?scope=' . ($scope ?: 'recommended'));
        }
    }

    private function assertCallsOnlyManualPost(): void
    {
        foreach (['process_limit', 'block_size', 'interval_seconds', 'block_pause_seconds', 'max_blocks', 'max_duration_minutes'] as $retired) {
            if (array_key_exists($retired, $_POST)) {
                throw new \App\Core\HttpException(422, 'El formulario anterior está retirado. Recargue y vuelva a calcular usando llamadas API.');
            }
        }
        foreach (['year', 'month', 'date_from', 'date_to'] as $unsupportedFilter) {
            if (!empty($_POST[$unsupportedFilter])) {
                throw new \App\Core\HttpException(422, 'Este cálculo no admite filtros de periodo. Recargue y revise la selección exacta antes de confirmar.');
            }
        }
    }

    public function recoverKnownNotificationErrors(): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        $this->assertSameOrigin();
        try {
            $accountIds = (new BusinessScopeContext())->accountIds((int) Auth::id());
            $result = (new \App\Services\NotificationWorkerRecoveryService())->recoverKnownErrors(null, true, $accountIds);
            Session::flash(
                'success',
                (int) $result['recovered'] . ' recursos afectados por el conflicto transaccional fueron reprogramados. '
                . (int) $result['remaining'] . ' quedan por revisar en próximos lotes.'
            );
        } catch (\Throwable $error) {
            Session::flash(
                'error',
                \App\Services\SafeErrorPresenter::message(
                    $error,
                    'No fue posible ejecutar la recuperación selectiva del worker.'
                )
            );
        }
        $this->redirect('/settings/cron');
    }

    public function createNotificationRecoveryCanary(): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        $this->assertSameOrigin();
        try {
            $accountIds = (new BusinessScopeContext())->accountIds((int) Auth::id());
            $result = (new \App\Services\NotificationCollationRecoveryService())->createCanary((int) (Auth::id() ?? 0), $accountIds);
            Session::flash('success', (string) ($result['safe_message'] ?? $result['message'] ?? 'Canario preparado.'));
        } catch (\Throwable $error) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message(
                $error,
                'No fue posible preparar el canario de recuperación.'
            ));
        }
        $this->redirect('/settings/cron');
    }

    public function startNotificationCollationRecovery(): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        $this->assertSameOrigin();
        try {
            $runId = max(0, (int) ($_POST['run_id'] ?? 0));
            $accountIds = (new BusinessScopeContext())->accountIds((int) Auth::id());
            $result = (new \App\Services\NotificationCollationRecoveryService())->startFull($runId > 0 ? $runId : null, $accountIds);
            Session::flash('success', (string) ($result['safe_message'] ?? 'Recuperación gradual iniciada.'));
        } catch (\Throwable $error) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message(
                $error,
                'No fue posible iniciar la recuperación gradual.'
            ));
        }
        $this->redirect('/settings/cron');
    }

    public function pauseNotificationCollationRecovery(): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        $this->assertSameOrigin();
        try {
            $runId = max(0, (int) ($_POST['run_id'] ?? 0));
            $accountIds = (new BusinessScopeContext())->accountIds((int) Auth::id());
            $result = (new \App\Services\NotificationCollationRecoveryService())->pause($runId > 0 ? $runId : null, $accountIds);
            Session::flash('success', (string) ($result['safe_message'] ?? 'Recuperación pausada.'));
        } catch (\Throwable $error) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message(
                $error,
                'No fue posible pausar la recuperación.'
            ));
        }
        $this->redirect('/settings/cron');
    }

    public function notificationCollationRecoveryStatus(): void
    {
        $this->requireAdminPermanent();
        $runId = max(0, (int) ($_GET['run_id'] ?? 0));
        $accountIds = (new BusinessScopeContext())->accountIds((int) Auth::id());
        $this->json([
            'ok' => true,
            'recovery' => (new \App\Services\NotificationCollationRecoveryService())->status($runId > 0 ? $runId : null, $accountIds),
            'database_session' => Database::sessionCharacterSet(),
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    public function rescheduleOverdue(): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        $this->assertSameOrigin();
        try {
            $runAt = $this->scheduledRunAt();
            $accountIds = (new BusinessScopeContext())->accountIds((int) Auth::id());
            $count = 0;
            foreach ($accountIds as $accountId) {
                $count += (new SyncCenterService())->reprogramOverdue($accountId, $runAt);
            }
            Session::flash('success', $count . ' bloques vencidos reprogramados para ' . $runAt->format('Y-m-d H:i') . ' ' . DateTimePresenter::timezone() . '.');
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/settings/cron');
    }

    public function testCron(): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        $this->assertSameOrigin();
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || (($_POST['_json'] ?? '') === '1');
        $started = microtime(true);
        try {
            $summary = (new \App\Services\CronHealthService())->quickReadOnlyPreflight($started);
            $message = 'Preflight read-only correcto: instalación, Queue V4, heartbeat y OAuth están disponibles. '
                . 'No se creó trabajo ni se ejecutó Cron.';
            if ($wantsJson) {
                $this->json([
                    'ok' => true,
                    'message' => $message,
                    'summary' => $summary,
                    'cron' => (new \App\Services\AutomationRuntimeStatusService())->status(),
                ]);
                return;
            }
            Session::flash('success', $message);
        } catch (\Throwable $e) {
            $diagnostic = \App\Services\SafeErrorPresenter::report(
                $e,
                'La prueba local de Cron no pudo completarse.',
                ['component' => 'queue_v4_clean', 'operation' => 'read_only_preflight']
            );
            $safeMessage = \App\Services\SafeErrorPresenter::message(
                $e,
                'La prueba del cron no pudo completarse.'
            );
            if ($wantsJson) {
                http_response_code(500);
                $this->json(['ok' => false, 'message' => $safeMessage]);
                return;
            }
            Session::flash('error', $safeMessage);
        }
        $this->redirect('/settings/cron');
    }

    public function cronStatus(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        $runtime = (new \App\Services\AutomationRuntimeStatusService())->status();
        // Incluso el diagnóstico administrativo debe usar un alcance explícito.
        // `null` significaría "sin filtro" para servicios heredados.
        $accountIds = (new BusinessScopeContext())->accountIds((int) Auth::id());
        $payload = [
            'ok' => true,
            'cron' => $runtime,
            'runtime' => $runtime,
            'notifications' => [
                'state' => $runtime['state'],
                'label' => 'Dentro del lanzador único',
                'message' => 'Ventas y notificaciones utilizan el mismo lanzador general.',
            ],
            'probe' => ['state' => 'read_only', 'message' => 'La comprobación web usa el preflight de Queue V4.'],
            'processing' => (new \App\Services\NotificationWorkItemService())->summary($accountIds),
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'details_included' => false,
        ];
        // Los diagnósticos pesados solo se leen cuando el administrador los
        // solicita expresamente. El polling normal debe ser pequeño y rápido.
        if ((string) ($_GET['details'] ?? '') === '1') {
            $payload['collation_recovery'] = (new \App\Services\NotificationCollationRecoveryService())->status(null, $accountIds);
            $payload['database_session'] = Database::sessionCharacterSet();
            $payload['release_integrity'] = $this->cronReleaseIntegritySummary(true);
            $payload['details_included'] = true;
        }
        $this->json($payload);
    }

    public function cronIntegrityStatus(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        $this->json([
            'ok' => true,
            'release_integrity' => $this->cronReleaseIntegritySummary(true),
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /** @return array<string,mixed> */
    private function cronReleaseIntegritySummary(bool $checkDatabase): array
    {
        return (new ReleaseIntegrityService())->inspect($checkDatabase);
    }

    public function logs(): void
    {
        $this->requireAdminPermanent();
        $groups = (new ApiErrorSummaryService())->recentGrouped();
        View::render('settings/api_logs', compact('groups'));
    }

    public function apiHealth(): void
    {
        $this->requireAdminPermanent();
        $hours = $this->apiHealthHours();
        $accountId = max(0, (int) ($_GET['account_id'] ?? 0));
        $this->releaseReadOnlySession();
        View::render('settings/api_health_shell', compact('hours', 'accountId'));
    }

    public function apiHealthSection(): void
    {
        $this->requireAdminPermanent();
        $hours = $this->apiHealthHours();
        $accountId = max(0, (int) ($_GET['account_id'] ?? 0));
        $this->releaseReadOnlySession();
        try {
            $overview = (new ApiHealthOverviewService())->overview($hours, $accountId ?: null);
            $apiHealthPartial = true;
            View::render('settings/api_health', compact('overview', 'apiHealthPartial'), false);
        } catch (\App\Core\HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            http_response_code(503);
            echo '<section class="alert warning" data-api-health-section-error>'
                . '<strong>No se pudo comprobar Salud API.</strong> '
                . 'El ERP conserva el último estado y puede reintentar esta sección. '
                . '<button class="btn" type="button" data-api-health-section-retry>Reintentar</button>'
                . '</section>';
        }
    }

    public function apiHealthOverviewJson(): void
    {
        $this->requireAdminPermanent();
        $hours = $this->apiHealthHours();
        $accountId = max(0, (int) ($_GET['account_id'] ?? 0));
        $this->releaseReadOnlySession();
        try {
            $overview = (new ApiHealthOverviewService())->overview($hours, $accountId ?: null);
            $snapshotState = (string) ($overview['snapshot_state'] ?? 'unavailable');
            if ($snapshotState === 'unavailable') {
                http_response_code(503);
            }
            $this->json([
                'ok' => $snapshotState !== 'unavailable',
                'protocol' => $snapshotState,
                'snapshot_state' => $snapshotState,
                'authoritative' => (bool) ($overview['authoritative'] ?? false),
                'data_availability' => $overview['data_availability'] ?? ['available' => false],
                'status' => $overview['status'] ?? [],
                'queries' => $overview['queries'] ?? [],
                'accounts' => $overview['accounts'] ?? [],
                'incidents' => $overview['incidents'] ?? [],
                'protection' => $overview['protection'] ?? [],
                'system_safety' => $overview['system_safety'] ?? [],
                'freshness' => $overview['freshness'] ?? [],
                'automation' => $overview['automation_evidence'] ?? [],
                'checked_at' => $overview['checked_at'] ?? null,
            ]);
        } catch (\App\Core\HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            http_response_code(503);
            $this->json([
                'ok' => false,
                'snapshot_state' => 'unavailable',
                'data_availability' => ['available' => false, 'label' => 'No se pudo comprobar'],
                'message' => 'No se pudo actualizar Salud API. Se conserva el estado visible.',
            ]);
        }
    }

    public function apiHealthOperationalSnapshot(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        try {
            $access = (new \App\Services\ApiHealthAccessScope())->snapshot();
            $snapshot = (new \App\QueueV4Clean\QueueV4CleanHealthSnapshotService(
                \App\Core\Database::connectionFresh()
            ))->snapshot(null, $access['company_ids'], $access['account_ids']);
            $state = (string) ($snapshot['snapshot_state'] ?? 'unavailable');
            if ($state === 'unavailable') {
                http_response_code(503);
            }
            $this->json([
                'ok' => $state !== 'unavailable',
                'snapshot_state' => $state,
                'read_only' => true,
                'measured_at' => $snapshot['measured_at'] ?? gmdate('Y-m-d H:i:s'),
                'mercado_libre' => [
                    'state' => 'separate_api_health',
                    'label' => 'Evidencia remota separada',
                    'message' => 'La disponibilidad remota se conserva en Salud API; este bloque usa exclusivamente Queue V4.',
                ],
                'automation' => [
                    'state' => $snapshot['state'] ?? 'unavailable',
                    'label' => $snapshot['state_label'] ?? 'No se pudo comprobar',
                    'message' => $snapshot['state_message'] ?? '',
                ],
                'backlog' => ($snapshot['totals'] ?? []) + ['sales_audit' => $snapshot['sales_audit'] ?? []],
                'runtime' => $snapshot['runtime'] ?? [],
            ]);
        } catch (\App\Core\HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            http_response_code(503);
            $this->json([
                'ok' => false,
                'snapshot_state' => 'unavailable',
                'read_only' => true,
                'message' => 'No se pudo comprobar automatización/backlog. No se consultó Mercado Libre.',
            ]);
        }
    }

    public function apiHealthProtectionJson(): void
    {
        $this->requireAdminPermanent();
        $hours = $this->apiHealthHours();
        $accountId = max(0, (int) ($_GET['account_id'] ?? 0));
        $this->releaseReadOnlySession();
        try {
            $overview = (new ApiHealthOverviewService())->overview($hours, $accountId ?: null);
            $snapshotState = (string) ($overview['snapshot_state'] ?? 'unavailable');
            if ($snapshotState === 'unavailable') {
                http_response_code(503);
            }
            $this->json([
                'ok' => $snapshotState !== 'unavailable',
                'protocol' => $snapshotState,
                'snapshot_state' => $snapshotState,
                'authoritative' => (bool) ($overview['authoritative'] ?? false),
                'data_availability' => $overview['data_availability'] ?? ['available' => false],
                'account_id' => $accountId ?: null,
                'protection' => $overview['protection'] ?? [],
                'system_safety' => $overview['system_safety'] ?? [],
                'checked_at' => $overview['checked_at'] ?? null,
            ]);
        } catch (\App\Core\HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            http_response_code(503);
            $this->json([
                'ok' => false,
                'protocol' => 'unavailable',
                'snapshot_state' => 'unavailable',
                'authoritative' => false,
                'data_availability' => ['available' => false, 'label' => 'No se pudo comprobar'],
                'message' => 'No se pudo actualizar la protección. Se conserva el estado visible.',
            ]);
        }
    }

    public function apiHealthAccounts(): void
    {
        $this->requireAdminPermanent();
        $hours = $this->apiHealthHours();
        $accountId = max(0, (int) ($_GET['account_id'] ?? 0));
        $state = trim((string) ($_GET['state'] ?? ''));
        $this->releaseReadOnlySession();
        $overview = (new ApiHealthOverviewService())->overview($hours, $accountId ?: null);
        $accounts = $overview['accounts']['rows'] ?? [];
        if (in_array($state, ['available', 'aging', 'unverified', 'attention', 'paused', 'disconnected'], true)) {
            $accounts = array_values(array_filter($accounts, static fn(array $row): bool => ($row['state'] ?? '') === $state));
        }
        View::render('settings/api_health_accounts', compact('overview', 'accounts', 'state'));
    }

    public function apiHealthIncidents(): void
    {
        $this->requireAdminPermanent();
        $filters = [
            'hours' => max(1, min(720, (int) ($_GET['hours'] ?? 24))),
            'account_id' => max(0, (int) ($_GET['account_id'] ?? 0)),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'origin' => trim((string) ($_GET['origin'] ?? '')),
            'severity' => trim((string) ($_GET['severity'] ?? '')),
            'operation' => trim((string) ($_GET['operation'] ?? '')),
            'http_status' => max(0, (int) ($_GET['http_status'] ?? 0)),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(10, min(100, (int) ($_GET['per_page'] ?? 50)));
        $this->releaseReadOnlySession();
        if ((string) ($_GET['full'] ?? '') !== '1') {
            $query = http_build_query($filters + ['page' => $page, 'per_page' => $perPage]);
            $apiHealthSection = 'incidents';
            $apiHealthHours = (int) $filters['hours'];
            $apiHealthCheckedAt = gmdate('Y-m-d H:i:s');
            View::render('settings/api_health_incidents_shell', compact('query', 'apiHealthSection', 'apiHealthHours', 'apiHealthCheckedAt'));
            return;
        }
        $service = new ApiHealthService();
        try {
            if (method_exists($service, 'incidentPage')) {
                $incidentPage = $service->incidentPage($filters, $perPage, ($page - 1) * $perPage);
            } else {
                $rows = $service->incidents($filters, $perPage);
                $incidentPage = [
                    'rows' => $rows,
                    'total' => count($rows),
                    'protocol' => $service->dataAvailable() ? 'partial_legacy_reader' : 'unavailable',
                ];
            }
        } catch (\Throwable) {
            $incidentPage = ['rows' => [], 'total' => 0, 'protocol' => 'unavailable'];
        }
        if (!$service->dataAvailable() || (string) ($incidentPage['protocol'] ?? '') === 'unavailable') {
            try {
                $freshness = method_exists($service, 'incidentReadModelFreshness')
                    ? $service->incidentReadModelFreshness()
                    : ['current' => false];
            } catch (\Throwable) {
                $freshness = ['current' => false];
            }
            $incidentUnavailableMessage = !$freshness['current']
                ? 'El catálogo de incidentes se está actualizando y no está al día. No se mostrará como vacío hasta completar la sincronización local.'
                : 'No se pudo comprobar el catálogo de incidentes. Recargue la página para intentarlo nuevamente.';
            $incidents = [];
            $total = 0;
            $pages = 1;
            $incidentReadMode = 'unavailable_summary_only';
            try {
                $accounts = $service->accounts();
            } catch (\Throwable) {
                $accounts = [];
            }
            $remote429WindowSummary = $this->remote429WindowSummary((int) $filters['account_id']);
            View::render('settings/api_health_incidents', compact('incidents', 'accounts', 'filters', 'page', 'pages', 'perPage', 'total', 'incidentReadMode', 'incidentUnavailableMessage', 'remote429WindowSummary'));
            return;
        }
        $incidents = $incidentPage['rows'];
        $total = $incidentPage['total'];
        $pages = max(1, (int) ceil($total / $perPage));
        try {
            $accounts = $service->accounts();
        } catch (\Throwable) {
            $accounts = [];
        }
        $incidentReadMode = (string) ($incidentPage['protocol'] ?? 'complete');
        $remote429WindowSummary = $this->remote429WindowSummary((int) $filters['account_id']);
        View::render('settings/api_health_incidents', compact('incidents', 'accounts', 'filters', 'page', 'pages', 'perPage', 'total', 'incidentReadMode', 'remote429WindowSummary'));
    }

    public function apiHealthIncidentShow(): void
    {
        $this->requireAdminPermanent();
        $key = trim((string) ($_GET['key'] ?? ''));
        $this->releaseReadOnlySession();
        $service = new ApiHealthService();
        $incident = $service->incident($key);
        if (!$service->dataAvailable()) {
            http_response_code(503);
            View::render('errors/500', [
                'errorMessage' => 'No se pudo comprobar el incidente. Recargue la página para intentarlo nuevamente.',
                'errorReference' => '',
            ]);
            return;
        }
        if ($incident === null) {
            http_response_code(404);
            View::render('errors/404', ['message' => 'El incidente solicitado no existe o ya no está disponible.']);
            return;
        }
        $resolution = (new \App\Services\IncidentActionResolver())->resolve($incident);
        View::render('settings/api_health_incident_show', compact('incident', 'resolution'));
    }

    public function apiHealthIncidentsStatus(): void
    {
        $this->apiHealthIncidentsJson();
    }

    public function sendCriticalApiAlertTestEmail(): void
    {
        $this->requireAdminPermanent();
        $this->assertSameOrigin();
        Csrf::validate($_POST['_token'] ?? null);
        $result = (new CriticalApiAlertEmailService())->sendTest();
        Session::flash(
            !empty($result['sent']) ? 'success' : 'error',
            !empty($result['sent'])
                ? 'Email de prueba enviado.'
                : 'No se envió el email de prueba: ' . (string) ($result['status'] ?? 'sin detalle') . '.'
        );
        $this->redirect('/settings/api-health');
    }

    public function saveCriticalApiAlertSettings(): void
    {
        $this->requireAdminPermanent();
        $this->assertSameOrigin();
        Csrf::validate($_POST['_token'] ?? null);

        $settings = new AppSettingsService();
        $to = trim((string) ($_POST['alerts_email_to'] ?? ''));
        $cooldown = max(5, min(1440, (int) ($_POST['alerts_email_cooldown_minutes'] ?? 60)));
        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            Session::flash('error', 'El correo de alertas críticas no tiene un formato válido.');
            $this->redirect('/settings/api-health');
        }

        $settings->set('alerts.email.enabled', isset($_POST['alerts_email_enabled']) ? '1' : '0', 'alerts');
        $settings->set('alerts.email.to', $to, 'alerts');
        $settings->set('alerts.email.cooldown_minutes', (string) $cooldown, 'alerts');
        $settings->set('alerts.email.notify_429', isset($_POST['alerts_email_notify_429']) ? '1' : '0', 'alerts');
        $settings->set('alerts.email.notify_auth', isset($_POST['alerts_email_notify_auth']) ? '1' : '0', 'alerts');
        Session::flash('success', 'Alertas críticas guardadas en Salud y alertas.');
        $this->redirect('/settings/api-health');
    }

    public function apiHealthIncidentsJson(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        $started = microtime(true);
        $filters = [
            'hours' => max(1, min(720, (int) ($_GET['hours'] ?? 24))),
            'account_id' => max(0, (int) ($_GET['account_id'] ?? 0)),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'origin' => trim((string) ($_GET['origin'] ?? '')),
            'severity' => trim((string) ($_GET['severity'] ?? '')),
            'operation' => trim((string) ($_GET['operation'] ?? '')),
            'http_status' => max(0, (int) ($_GET['http_status'] ?? 0)),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(10, min(100, (int) ($_GET['per_page'] ?? 50)));
        $service = new ApiHealthService();
        $accountId = (int) $filters['account_id'] > 0 ? (int) $filters['account_id'] : null;
        try {
            $incidentPage = $service->incidentPage($filters, $perPage, ($page - 1) * $perPage);
            $incidentsAvailable = $service->dataAvailable();
            $summary = (new ApiHealthService())->summary((int) $filters['hours'], $accountId);
        } catch (\Throwable $error) {
            header('Server-Timing: api_incidents;dur=' . number_format((microtime(true) - $started) * 1000, 1, '.', ''));
            http_response_code(503);
            $this->json([
                'ok' => false,
                'protocol' => 'unavailable',
                'snapshot_state' => 'unavailable',
                'authoritative' => false,
                'summary' => ['error' => 'incident_json_unavailable'],
                'incidents' => [],
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => 0,
                    'pages' => 1,
                    'truncated' => false,
                ],
                'message' => 'No se pudo cargar incidentes en segundo plano. No se informa como cero.',
                'measured_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            return;
        }
        if (isset($summary['budget']['windows'])) {
            $summary['budget']['windows'] = [];
        }
        if (!$incidentsAvailable) {
            http_response_code(503);
        }
        header('Server-Timing: api_incidents;dur=' . number_format((microtime(true) - $started) * 1000, 1, '.', ''));
        $this->json([
            'ok' => $incidentsAvailable,
            'protocol' => $incidentPage['protocol'],
            'snapshot_state' => $incidentPage['protocol'],
            'authoritative' => in_array($incidentPage['protocol'], ['complete', 'authoritative_empty'], true),
            'summary' => $summary,
            'incidents' => $incidentPage['rows'],
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $incidentPage['total'],
                'pages' => max(1, (int) ceil($incidentPage['total'] / $perPage)),
                'truncated' => $incidentPage['truncated'],
            ],
            'measured_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    public function apiHealthIncidentShowJson(): void
    {
        $this->requireAdminPermanent();
        $key = trim((string) ($_GET['key'] ?? ''));
        $this->releaseReadOnlySession();
        $service = new ApiHealthService();
        $incident = $service->incident($key);
        if (!$service->dataAvailable()) {
            http_response_code(503);
            $this->json([
                'ok' => false,
                'protocol' => 'unavailable',
                'snapshot_state' => 'unavailable',
                'authoritative' => false,
                'message' => 'No se pudo comprobar el incidente. Se conserva el estado visible.',
            ]);
            return;
        }
        if ($incident === null) {
            http_response_code(404);
            $this->json([
                'ok' => false,
                'protocol' => 'authoritative_empty',
                'snapshot_state' => 'authoritative_empty',
                'authoritative' => true,
                'message' => 'El incidente solicitado no existe o no está disponible para este alcance.',
            ]);
            return;
        }
        $this->json([
            'ok' => true,
            'protocol' => 'complete',
            'snapshot_state' => 'complete',
            'authoritative' => true,
            'incident' => $incident,
            'resolution' => (new \App\Services\IncidentActionResolver())->resolve($incident),
            'measured_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    public function apiHealthProtection(): void
    {
        $this->requireAdminPermanent();
        $hours = $this->apiHealthHours();
        $this->releaseReadOnlySession();
        $overview = (new ApiHealthOverviewService())->overview($hours);
        $technical = (new ApiHealthTechnicalService())->technical(['per_page' => 100]);
        $accounts = $overview['accounts']['rows'] ?? [];
        View::render('settings/api_health_protection', compact('overview', 'technical', 'accounts'));
    }

    public function apiHealthTechnical(): void
    {
        $this->requireAdminPermanent();
        $filters = [
            'account_id' => max(0, (int) ($_GET['account_id'] ?? 0)),
            'operation' => trim((string) ($_GET['operation'] ?? '')),
            'state' => trim((string) ($_GET['state'] ?? '')),
            'page' => max(1, (int) ($_GET['page'] ?? 1)),
            'per_page' => (int) ($_GET['per_page'] ?? 50),
        ];
        $this->releaseReadOnlySession();
        $technical = (new ApiHealthTechnicalService())->technical($filters);
        $accounts = (new ApiHealthService())->accounts();
        View::render('settings/api_health_technical', compact('technical', 'accounts'));
    }

    public function apiDocs(): void
    {
        $this->requireAdminPermanent();
        $root = dirname(__DIR__, 2);
        $warnings = [];
        try {
            $endpoints = (new MeliApiKnowledgeService())->allEndpoints($root);
        } catch (\Throwable) {
            $endpoints = [];
            $warnings[] = 'El índice de endpoints no se pudo leer. Las demás secciones siguen disponibles.';
        }
        $coverage = [];
        $risks = [];
        $usage = [];
        foreach (['coverage.json' => 'coverage', 'risks.json' => 'risks', 'erp-usage.json' => 'usage'] as $file => $variable) {
            try {
                $$variable = $this->readApiKnowledgeJson($file);
            } catch (\Throwable) {
                $$variable = [];
                $warnings[] = 'No se pudo leer ' . $file . '.';
            }
        }
        $contractsAvailable = is_file($root . '/resources/mercadolibre-api/generated/endpoints.json');
        $source = $root . '/resources/mercadolibre-api/source';
        $sourceAvailable = is_dir($source) && (glob($source . '/*') ?: []) !== [];
        $canRegenerate = $sourceAvailable && is_writable($root . '/resources/mercadolibre-api/generated');
        $generatedAt = $contractsAvailable ? gmdate('c', (int) filemtime($root . '/resources/mercadolibre-api/generated/endpoints.json')) : null;
        View::render('settings/api_docs', compact('endpoints', 'coverage', 'risks', 'usage', 'contractsAvailable', 'sourceAvailable', 'canRegenerate', 'generatedAt', 'warnings'));
    }

    public function apiDocsEndpoint(): void
    {
        $this->requireAdminPermanent();
        $root = dirname(__DIR__, 2);
        $path = trim((string) ($_GET['path'] ?? ''));
        $endpoint = $path !== '' ? (new MeliApiKnowledgeService())->queryEndpoint($root, $path) : [];
        View::render('settings/api_docs_endpoint', compact('path', 'endpoint'));
    }

    public function regenerateApiDocs(): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        $root = dirname(__DIR__, 2);
        $source = $root . '/resources/mercadolibre-api/source';
        if (!is_dir($source) || (glob($source . '/*') ?: []) === []) {
            Session::flash('warning', 'Las fuentes documentales no están instaladas. Los contratos compactos continúan disponibles en modo lectura.');
            $this->redirect('/settings/api-docs');
        }
        try {
            $summary = (new MeliApiKnowledgeService())->generate($root, $source);
            Session::flash('success', 'Contratos API regenerados: ' . (int) ($summary['endpoint_count'] ?? 0) . ' endpoints, ' . (int) ($summary['field_count'] ?? 0) . ' variables.');
        } catch (\Throwable) {
            Session::flash('error', 'No fue posible regenerar los contratos. Revise el diagnóstico técnico.');
        }
        $this->redirect('/settings/api-docs');
    }

    public function closeApiCircuit(): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        $this->assertSameOrigin();
        $id = (int) ($_POST['id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? 'Cierre manual desde Salud API'));
        $this->assertApiCircuitAuthorized($id);
        if ($id > 0 && (new ApiGuardService())->closeCircuit($id, $reason)) {
            (new ReadModelCacheService())->clear();
            Session::flash('success', 'Circuit breaker cerrado manualmente.');
        } else {
            Session::flash('error', 'No se encontró el circuito solicitado.');
        }
        $this->redirect('/settings/api-health');
    }

    public function pauseApiAccount(): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        $this->assertSameOrigin();
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $minutes = max(5, min(1440, (int) ($_POST['cooldown_minutes'] ?? 60)));
        $reason = trim((string) ($_POST['reason'] ?? 'Pausa manual de cuenta ML'));
        if ($accountId <= 0) {
            Session::flash('error', 'Seleccione una cuenta Mercado Libre.');
        } else {
            (new BusinessScopeContext())->account($accountId);
            (new ApiGuardService())->pauseAccount($accountId, $minutes, $reason);
            (new ReadModelCacheService())->clear();
            Session::flash('success', 'Cuenta pausada para consultas API durante ' . $minutes . ' minutos.');
        }
        $this->redirect('/settings/api-health');
    }

    public function pauseApi(): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        $this->assertSameOrigin();
        $accountId = max(0, (int) ($_POST['account_id'] ?? 0));
        $duration = trim((string) ($_POST['duration'] ?? '60'));
        $minutes = $duration === 'indefinite' ? null : (int) $duration;
        try {
            $accountIds = $accountId > 0
                ? [(int) (new BusinessScopeContext())->account($accountId)['id']]
                : (new BusinessScopeContext())->accountIds((int) Auth::id());
            if ($accountIds === []) {
                throw new \RuntimeException('No hay cuentas autorizadas para pausar.');
            }
            foreach ($accountIds as $authorizedAccountId) {
                (new ApiManualPauseService())->pause($authorizedAccountId, $minutes, trim((string) ($_POST['reason'] ?? '')), Auth::id());
            }
            Session::flash('success', count($accountIds) === 1 ? 'La cuenta quedó pausada.' : 'Las cuentas autorizadas quedaron pausadas.');
        } catch (\Throwable) {
            Session::flash('error', 'No fue posible aplicar la pausa. Revise la migración y el diagnóstico.');
        }
        $this->redirect('/settings/api-health');
    }

    public function resumeApi(): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        $this->assertSameOrigin();
        $pauseService = new ApiManualPauseService();
        $pauseId = (int) ($_POST['pause_id'] ?? 0);
        $pause = $pauseService->findActive($pauseId);
        if (!is_array($pause) || !in_array((string) ($pause['scope'] ?? ''), ['account', 'app'], true)) {
            throw new \App\Core\HttpException(404, 'No se encontró la pausa solicitada.');
        }
        if (($pause['scope'] ?? '') === 'account') {
            $accountId = (int) ($pause['meli_account_id'] ?? 0);
            if ($accountId <= 0) {
                throw new \App\Core\HttpException(404, 'No se encontró la pausa solicitada.');
            }
            (new BusinessScopeContext())->account($accountId);
        } elseif (!(new \App\Services\ApiHealthAccessScope())->snapshot()['application']) {
            throw new \App\Core\HttpException(404, 'No se encontró la pausa solicitada.');
        }
        $resumed = $pauseService->resume(
            $pauseId,
            trim((string) ($_POST['reason'] ?? '')),
            Auth::id()
        );
        Session::flash($resumed ? 'success' : 'warning', $resumed ? 'Las consultas de ese alcance fueron reanudadas.' : 'La pausa ya no estaba activa.');
        $this->redirect('/settings/api-health');
    }

    public function apiPauseStatus(): void
    {
        $this->requireAdminPermanent();
        $accountIds = (new BusinessScopeContext())->accountIds((int) Auth::id());
        $this->json(['ok' => true, 'pause' => (new ApiManualPauseService())->summary($accountIds), 'generated_at' => gmdate('c')]);
    }

    public function acknowledgeApiIncident(): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        $this->assertSameOrigin();
        $key = trim((string) ($_POST['incident_key'] ?? ''));
        if (preg_match('/^[a-f0-9]{64}$/', $key) !== 1) {
            Session::flash('error', 'El incidente indicado no es válido.');
            $this->redirect('/settings/api-health/incidents');
        }
        $scopeKey = trim((string) ($_POST['scope_key'] ?? ''));
        if ($scopeKey === '' || preg_match('/^(?:application|(?:account|company):[1-9][0-9]*)$/', $scopeKey) !== 1) {
            throw new \App\Core\HttpException(404, 'No se encontró el alcance del incidente solicitado.');
        }
        (new \App\Services\ApiIncidentAcknowledgementService())->acknowledge(
            $key,
            (int) Auth::id(),
            trim((string) ($_POST['note'] ?? 'Revisado por administración')),
            $scopeKey
        );
        (new ReadModelCacheService())->clear();
        Session::flash('success', 'Ese alcance del incidente quedó marcado como revisado sin eliminar su historial.');
        $this->redirect('/settings/api-health/incidents/show?key=' . rawurlencode($key));
    }

    public function reactivateApiAccount(): void
    {
        $this->requireAdminPermanent();
        Csrf::validate($_POST['_token'] ?? null);
        $this->assertSameOrigin();
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? 'Reactivación manual de cuenta ML'));
        if ($accountId <= 0) {
            Session::flash('error', 'Seleccione una cuenta Mercado Libre.');
        } else {
            (new BusinessScopeContext())->account($accountId);
            $count = (new ApiGuardService())->reactivateAccount($accountId, $reason);
            (new ReadModelCacheService())->clear();
            Session::flash('success', 'Cuenta reactivada. Circuitos cerrados: ' . $count . '.');
        }
        $this->redirect('/settings/api-health');
    }

    public function exportApiHealth(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        $rows = (new ApiHealthService())->exportRows((int) ($_GET['hours'] ?? 24));
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="api-health-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['created_at', 'account_name', 'method', 'endpoint_path', 'http_status', 'error_type', 'error_code', 'retry_after_seconds', 'attempt', 'was_blocked', 'outcome_class', 'transport_class', 'reached_remote', 'actionable', 'risk_signal', 'incident_key', 'safe_message'], ',', '"', '', "\n");
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['created_at'] ?? '',
                $row['account_name'] ?? '',
                $row['method'] ?? '',
                $row['endpoint_path'] ?? '',
                $row['http_status'] ?? '',
                $row['error_type'] ?? '',
                $row['error_code'] ?? '',
                $row['retry_after_seconds'] ?? '',
                $row['attempt'] ?? '',
                $row['was_blocked'] ?? '',
                $row['outcome_class'] ?? '',
                $row['transport_class'] ?? '',
                $row['reached_remote'] ?? '',
                $row['actionable'] ?? '',
                $row['risk_signal'] ?? '',
                $row['incident_key'] ?? '',
                $row['safe_message'] ?? '',
            ], ',', '"', '', "\n");
        }
        exit;
    }

    public function diagnostics(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        $safety = (new \App\Services\SystemSafetyStatusService())->status();
        View::render('settings/diagnostics_shell', compact('safety'));
    }

    public function supportDiagnostic(): void
    {
        $this->requireAdminPermanent();
        $this->releaseReadOnlySession();
        $reference = trim((string) ($_GET['reference'] ?? ''));
        $diagnostic = null;
        if ($reference !== '') {
            $diagnostic = (new \App\Services\SupportDiagnosticLookupService())->find($reference);
        }
        View::render('settings/support_diagnostic', compact('reference', 'diagnostic'));
    }

    public function diagnosticsSection(): void
    {
        $this->requireAdminPermanent();
        $async = new \App\Services\AsyncSectionService();
        $async->releaseSession();
        $startedAt = microtime(true);
        try {
            $cached = (new ReadModelCacheService())->rememberArray(
                'system-diagnostic',
                \App\Services\AppVersionService::fileVersion(),
                60,
                static fn(): array => (new DiagnosticService())->summary()
            );
            $async->render(
                'system-diagnostic',
                'settings/diagnostics',
                ['diagnostic' => $cached['value'], 'embedded' => true],
                ['cache' => $cached['cache']],
                $startedAt
            );
        } catch (\Throwable $error) {
            $async->failure('system-diagnostic', $error);
        }
    }

    public function migrationDiagnostics(): void
    {
        $this->requireAdminPermanent();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 50;
        $service = new MigrationDiagnosticService();
        $migrationDiagnostic = $service->summary();
        $migrationDiagnostic['events'] = $service->events($perPage, ($page - 1) * $perPage);
        $migrationDiagnostic['event_page'] = $page;
        $migrationDiagnostic['event_per_page'] = $perPage;
        View::render('settings/migration_diagnostics', compact('migrationDiagnostic'));
    }

    public function migrationDiagnosticsExport(): void
    {
        $this->requireAdminPermanent();
        try {
            $payload = (new MigrationDiagnosticService())->export(200);
            $json = json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="erp-meli-migration-diagnostic-' . gmdate('Ymd-His') . '.json"');
            header('X-Content-Type-Options: nosniff');
            echo $json;
        } catch (\Throwable $e) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'message' => MigrationTraceService::safeMessage($e) ?? 'No fue posible exportar el diagnóstico.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }

    public function migrationDiagnosticsStatus(): void
    {
        $this->requireAdminPermanent();
        try {
            $summary = (new MigrationDiagnosticService())->summary();
            $this->json(['ok' => true, 'diagnostic' => $summary]);
        } catch (\Throwable $e) {
            http_response_code(500);
            $this->json([
                'ok' => false,
                'message' => MigrationTraceService::safeMessage($e) ?? 'No fue posible consultar el diagnóstico.',
            ]);
        }
    }

    private function requireAdminPermanent(): void
    {
        Auth::requireRole('admin');
        if (Auth::isTemporary()) {
            http_response_code(403);
            exit('Los accesos temporales no pueden entrar a Configuración.');
        }
    }

    private function apiHealthHours(): int
    {
        $hours = (int) ($_GET['hours'] ?? (new AppSettingsService())->int('api.health.default_period_hours', 24));
        return in_array($hours, [1, 24, 168, 720], true) ? $hours : 24;
    }

    private function remote429WindowSummary(int $accountId = 0): array
    {
        $empty = [
            '60m' => 0,
            '24h' => 0,
            '7d' => 0,
            '30d' => 0,
            'last_event' => null,
            'source_label' => 'NO CERTIFICADO',
        ];

        try {
            $schema = new \App\Services\SchemaInspectorService();
            if (!$schema->tableExists('api_request_logs')) {
                return $empty;
            }

            $scope = (new \App\Services\ApiHealthAccessScope())->predicate(
                'l',
                'remote429_incident_windows',
                $accountId > 0 ? $accountId : null
            );
            $where = $scope['sql']
                . ' AND COALESCE(l.reached_remote,0)=1'
                . ' AND l.http_status=429'
                . ' AND l.created_at >= UTC_TIMESTAMP() - INTERVAL 720 HOUR';

            $stmt = Database::connection()->prepare(
                'SELECT '
                . 'SUM(CASE WHEN l.created_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR THEN 1 ELSE 0 END) AS c_60m, '
                . 'SUM(CASE WHEN l.created_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR THEN 1 ELSE 0 END) AS c_24h, '
                . 'SUM(CASE WHEN l.created_at >= UTC_TIMESTAMP() - INTERVAL 168 HOUR THEN 1 ELSE 0 END) AS c_7d, '
                . 'COUNT(*) AS c_30d, '
                . 'MAX(l.created_at) AS last_event '
                . 'FROM api_request_logs l WHERE ' . $where
            );
            $stmt->execute($scope['params']);
            $row = $stmt->fetch() ?: [];

            return [
                '60m' => (int) ($row['c_60m'] ?? 0),
                '24h' => (int) ($row['c_24h'] ?? 0),
                '7d' => (int) ($row['c_7d'] ?? 0),
                '30d' => (int) ($row['c_30d'] ?? 0),
                'last_event' => $row['last_event'] ?? null,
                'source_label' => 'CERTIFICADO · api_request_logs directo',
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    private function assertApiCircuitAuthorized(int $circuitId): void
    {
        if ($circuitId <= 0) {
            throw new \App\Core\HttpException(404, 'No se encontró el circuito solicitado.');
        }
        $stmt = Database::connection()->prepare('SELECT meli_account_id FROM api_circuit_breakers WHERE id=? LIMIT 1');
        $stmt->execute([$circuitId]);
        $accountId = $stmt->fetchColumn();
        if ($accountId === false) {
            throw new \App\Core\HttpException(404, 'No se encontró el circuito solicitado.');
        }
        // Los circuitos de aplicación son visibles y administrables únicamente
        // desde una sesión administrativa permanente; el llamador ya aplicó
        // requireAdminPermanent().
        if ($accountId === null || (int) $accountId <= 0) {
            if (!(new \App\Services\ApiHealthAccessScope())->snapshot()['application']) {
                throw new \App\Core\HttpException(404, 'No se encontró el circuito solicitado.');
            }
            return;
        }
        (new BusinessScopeContext())->account((int) $accountId);
    }

    private function releaseReadOnlySession(): void
    {
        Csrf::token();
        if (Session::get('_flash', []) === []) {
            Session::closeReadOnly();
        }
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }

    private function json(array $payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    private function assertSameOrigin(): void
    {
        \App\Core\SameOriginGuard::assertRequest(true);
    }

    /**
     * @return array<string,mixed>|list<array<string,mixed>>
     */
    private function readApiKnowledgeJson(string $file): array
    {
        $path = dirname(__DIR__, 2) . '/resources/mercadolibre-api/generated/' . $file;
        if (!is_file($path)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($path), true);
        return is_array($json) ? $json : [];
    }

    private function scheduledRunAt(): DateTimeImmutable
    {
        $timezone = new DateTimeZone(DateTimePresenter::timezone());
        $now = new DateTimeImmutable('now', $timezone);
        $mode = (string) ($_POST['schedule_mode'] ?? 'delay');
        if ($mode === 'now') {
            return $now;
        }
        if ($mode === 'custom') {
            $raw = trim((string) ($_POST['schedule_at'] ?? ''));
            if ($raw === '') {
                throw new \RuntimeException('Seleccione fecha y hora para reprogramar.');
            }
            $runAt = new DateTimeImmutable($raw, $timezone);
            if ($runAt < $now->modify('-1 minute')) {
                throw new \RuntimeException('La fecha programada no puede estar en el pasado.');
            }
            return $runAt;
        }
        $minutes = (int) ($_POST['schedule_delay_minutes'] ?? (new \App\Services\SyncSettingsService())->overdueRescheduleDefaultMinutes());
        $minutes = in_array($minutes, [0, 5, 10, 20, 30], true) ? $minutes : 5;
        return $now->modify('+' . $minutes . ' minutes');
    }
}
