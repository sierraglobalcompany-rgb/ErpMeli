<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Throwable;

final class ResumableCampaignWorkerService
{
    /** @var array<string,float> */
    private array $operationReserveCache = [];
    /** @var array<int,int> */
    private array $minimumRequiredWindowCache = [];

    /** @return array<string,mixed> */
    public function run(?float $outerDeadline = null, ?string $cronRunToken = null): array
    {
        $schema = new SchemaInspectorService();
        if (!$schema->hasTable('system_execution_runs')
            || !$schema->hasColumn('manual_campaigns', 'last_approved_step')) {
            return ['processed' => 0, 'errors' => 0, 'status' => 'skipped', 'stop_reason' => 'migration_required'];
        }
        $settings = new AppSettingsService();
        if (!$settings->bool('manual_campaign.cli_directed_enabled', true)) {
            return ['processed' => 0, 'errors' => 0, 'status' => 'skipped', 'stop_reason' => 'feature_disabled'];
        }
        $campaign = $this->nextCampaign();
        if ($campaign === null) {
            return ['processed' => 0, 'errors' => 0, 'status' => 'empty', 'stop_reason' => 'no_due_work'];
        }
        $campaignService = new ManualCampaignService();
        $recovery = $campaignService->recoverInterruptedCampaignItem((int) $campaign['id']);
        if ($recovery['result'] === 'lease_active') {
            return [
                'processed' => 0,
                'completed' => 0,
                'errors' => 0,
                'deferred' => 1,
                'status' => 'deferred',
                'stop_reason' => 'lease_active',
                'campaign_id' => (int) $campaign['id'],
                'campaign_item_id' => $recovery['item_id'],
                'campaign_result' => 'deferred',
                'campaign_reason' => 'lease_active',
                'campaign_run_token' => $cronRunToken,
                'started' => 0,
                'not_started' => 1,
            ];
        }
        $campaign = $this->campaign((int) $campaign['id']) ?? $campaign;
        if ($schema->hasColumn('manual_campaigns', 'last_scheduler_planned_at')) {
            Database::connectionFresh()->prepare(
                'UPDATE manual_campaigns
                 SET last_scheduler_planned_at=UTC_TIMESTAMP(3),
                      last_scheduler_reason="directed_lane_reserved",
                      last_scheduler_run_token=?,
                      version_no=version_no+1
                 WHERE id=? AND status="active"'
            )->execute([$cronRunToken, (int) $campaign['id']]);
        }
        $windowMs = $this->windowMs($campaign, $outerDeadline);
        $windowPolicy = new CampaignExecutionWindowPolicyService($settings);
        $candidate = $this->nextFittingCandidate((int) $campaign['id'], $windowMs, $windowPolicy);
        $hasDueItem = $candidate !== null || $this->hasDueItem((int) $campaign['id']);
        if (!$hasDueItem) {
            return [
                'processed' => 0,
                'completed' => 0,
                'errors' => 0,
                'status' => 'empty',
                'stop_reason' => 'no_due_work',
                'campaign_id' => (int) $campaign['id'],
                'campaign_run_token' => $cronRunToken,
                'started' => 0,
                'not_started' => 1,
            ];
        }
        if ($candidate === null) {
            $this->markSchedulerResult((int) $campaign['id'], 'operation_window_too_short', null, 'La campaña esperará un ciclo con una ventana completa antes de iniciar otra consulta.');
            return [
                'processed' => 0,
                'inspected' => 0,
                'approved' => 0,
                'deferred' => 1,
                'errors' => 0,
                'status' => 'deferred',
                'stop_reason' => 'operation_window_too_short',
                'campaign_id' => (int) $campaign['id'],
                'campaign_result' => 'deferred',
                'campaign_reason' => 'operation_window_too_short',
                'campaign_run_token' => $cronRunToken,
                'started' => 0,
                'not_started' => 1,
                'window_ms' => $windowMs,
                'required_window_ms' => $this->minimumRequiredWindowMs((int) $campaign['id'], $windowPolicy),
            ];
        }
        $this->markCampaignCandidateSelected((int) $campaign['id'], $cronRunToken, (int) $candidate['id']);
        $journal = new ExecutionJournalService();
        $run = $journal->begin('manual_campaign', (int) $campaign['id'], $windowMs);
        // begin() reconcilia ejecuciones anteriores. Las respuestas remotas
        // inciertas se aíslan por recurso para que no pausen toda la campaña.
        $campaignService->isolateUncertainJournalResults((int) $campaign['id']);
        $campaign = $this->campaign((int) $campaign['id']) ?? $campaign;
        $worker = 'campaign:' . $run['token'];
        $this->markCampaignHeartbeat((int) $campaign['id']);
        $deadline = microtime(true) + ($windowMs / 1000);
        $inspected = 0;
        $completed = 0;
        $checkpointApproved = 0;
        $remoteCalls = 0;
        $deferred = 0;
        $errors = 0;
        $reason = 'no_progress_observed';
        $lastResult = null;
        $lastItem = null;
        $lastAttemptId = null;
        try {
            if ((new SchemaInspectorService())->hasColumn('manual_campaigns', 'last_scheduler_started_at')) {
                Database::connectionFresh()->prepare(
                    'UPDATE manual_campaigns
                     SET last_scheduler_started_at=UTC_TIMESTAMP(3),last_scheduler_run_token=?,last_scheduler_result="started"
                     WHERE id=? AND status="active"'
                )->execute([$cronRunToken, (int) $campaign['id']]);
            }
            $claimMisses = 0;
            while (true) {
                $remainingMs = max(0, (int) floor(($deadline - microtime(true)) * 1000));
                $candidate = $this->nextFittingCandidate((int) $campaign['id'], $remainingMs, $windowPolicy);
                if ($candidate === null) {
                    $reason = $this->hasDueItem((int) $campaign['id'])
                        ? 'operation_window_too_short'
                        : 'waiting_or_complete';
                    break;
                }
                $journal->heartbeat($run['id']);
                $this->markCampaignHeartbeat((int) $campaign['id']);
                $limit = (new ManualCampaignRhythmService())->enforceBeforeStep((int) $campaign['id']);
                if ($limit !== null) {
                    $reason = 'campaign_limit';
                    break;
                }
                $item = (new ManualCampaignService())->claimExact(
                    $worker,
                    (int) $campaign['id'],
                    (int) $candidate['id']
                );
                if (!is_array($item)) {
                    // El candidato cambió entre preview y claim. Releer sin
                    // contabilizar selección, inicio, intento ni progreso.
                    $freshCampaign = $this->campaign((int) $campaign['id']);
                    $nextActionAt = is_array($freshCampaign) && !empty($freshCampaign['next_action_at'])
                        ? (new SystemDatabaseUtcClock())->timestamp((string) $freshCampaign['next_action_at'])
                        : null;
                    if ($nextActionAt !== null && $nextActionAt > time()) {
                        $reason = 'waiting_schedule';
                        break;
                    }
                    $claimMisses++;
                    if ($claimMisses >= 5) {
                        $reason = 'candidate_changed';
                        break;
                    }
                    continue;
                }
                $this->markCampaignClaimed((int) $campaign['id'], $cronRunToken, (int) $item['id']);
                $claimMisses = 0;
                $inspected++;
                $lastItem = $item;
                if ($schema->hasColumn('manual_campaign_items', 'last_cron_run_token')) {
                    Database::connectionFresh()->prepare(
                        'UPDATE manual_campaign_items
                         SET last_cron_run_token=?,last_attempt_result="started",last_attempt_at=UTC_TIMESTAMP(3)
                         WHERE id=? AND lease_owner=? AND lease_generation=?'
                    )->execute([$cronRunToken, (int) $item['id'], (string) $item['lease_owner'], (int) $item['lease_generation']]);
                }
                $attemptId = $journal->reserve($run['id'], $item);
                $lastAttemptId = $attemptId;
                $sequence = max(1, (int) ($campaign['last_approved_step'] ?? 0) + $checkpointApproved + 1);
                $result = $this->processItem($item, $deadline, $journal, $attemptId);
                $lastResult = $result;
                $remoteCalls += max(0, $result->primaryCalls + $result->derivedCalls);
                $journal->response($attemptId, $result->primaryCalls, $result->derivedCalls, null, $result);
                (new ManualCampaignService())->complete($item, $result, [
                    'run_id' => $run['id'],
                    'attempt_id' => $attemptId,
                    'sequence' => $sequence,
                ]);
                $checkpointApproved++;
                if (in_array($result->status, ['completed', 'skipped'], true)) {
                    $completed++;
                } elseif ($result->status === 'deferred' || $result->status === 'retry') {
                    $deferred++;
                    $reason = $result->reason ?: ($result->nextEligibleAt ? 'item_future' : 'waiting_schedule');
                }
                if ($result->status === 'error') {
                    $errors++;
                    // El ítem queda terminal y visible para intervención, pero
                    // una cuenta con error no detiene los recursos independientes.
                    $reason = 'action_required_items';
                }
                $campaign = $this->campaign((int) $campaign['id']) ?? $campaign;
                $nextAt = !empty($campaign['next_action_at'])
                    ? (new SystemDatabaseUtcClock())->timestamp((string) $campaign['next_action_at'])
                    : null;
                if ($nextAt !== null && $nextAt > time() && !$this->hasDueItem((int) $campaign['id'])) {
                    // Una espera persistida se retoma en el siguiente minuto.
                    // Cron no consume su ventana haciendo sleep.
                    $reason = 'waiting_schedule';
                    break;
                }
            }
            if ($inspected === 0 && $completed === 0 && $deferred === 0 && $errors === 0) {
                $reason = $this->hasDueItem((int) $campaign['id'])
                    ? 'operation_window_too_short'
                    : 'no_due_work_after_refresh';
            }
            $journal->finish($run['id'], $run['started_at'], $reason);
            $observedLauncherSeconds = max(
                60,
                (new ManualCampaignReservationTtlService())->observedIntervalSeconds()
            );
            Database::connectionFresh()->prepare(
                'UPDATE manual_campaigns
                 SET last_clean_run_at=UTC_TIMESTAMP(3),worker_heartbeat_at=UTC_TIMESTAMP(),
                     next_launcher_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL '
                        . min(86400, $observedLauncherSeconds) . ' SECOND),
                     observed_safe_window_ms=LEAST(55000,COALESCE(observed_safe_window_ms,20000)+1000),
                     version_no=version_no+1
                 WHERE id=?'
            )->execute([(int) $campaign['id']]);
            $campaignReason = $lastResult instanceof CampaignItemResult
                ? ($lastResult->reason ?: ($lastResult->status === 'deferred' ? 'waiting_schedule' : $lastResult->status))
                : $reason;
            $this->markSchedulerResult(
                (int) $campaign['id'],
                $campaignReason,
                $lastResult?->nextEligibleAt,
                $lastResult?->message
            );
            return [
                'processed' => $completed,
                'inspected' => $inspected,
                'approved' => $checkpointApproved,
                'deferred' => $deferred,
                'errors' => $errors,
                'status' => $errors > 0 ? 'partial' : ($completed > 0 ? 'complete' : ($deferred > 0 ? 'deferred' : 'empty')),
                'stop_reason' => $reason,
                'campaign_id' => (int) $campaign['id'],
                'campaign_item_id' => is_array($lastItem) ? (int) ($lastItem['id'] ?? 0) : 0,
                'campaign_result' => $errors > 0 ? 'advanced_with_attention' : ($completed > 0 ? 'advanced' : ($deferred > 0 ? 'deferred' : 'skipped')),
                'campaign_reason' => $campaignReason,
                'campaign_next_at' => $lastResult?->nextEligibleAt,
                'campaign_message' => $lastResult?->message,
                'campaign_run_token' => $cronRunToken,
                'started' => 1,
                'completed' => $completed,
                'checkpoint_approved' => $checkpointApproved,
                'remote_calls' => $remoteCalls,
                'window_ms' => $windowMs,
                'required_window_ms' => $inspected === 0
                    ? $this->minimumRequiredWindowMs((int) $campaign['id'], $windowPolicy)
                    : null,
            ];
        } catch (Throwable $error) {
            $compensation = null;
            if (is_array($lastItem) && is_int($lastAttemptId) && $lastAttemptId > 0) {
                try {
                    $compensation = $campaignService->compensateInterruptedClaim($lastItem, $lastAttemptId);
                } catch (Throwable) {
                    // El diario conserva la ejecución para recuperación cercada
                    // en el siguiente ciclo. No se intenta una escritura sin lease.
                }
            }
            $safe = SafeErrorPresenter::report($error, 'La campaña se detuvo antes de iniciar otra consulta.', [
                'module' => 'manual_campaign_worker',
                'campaign_id' => (int) $campaign['id'],
                'run_id' => $run['id'],
            ]);
            $journal->fail($run['id'], $run['started_at'], $safe['message'], $safe['reference']);
            $compensatedAsRetry = (string) ($compensation['result'] ?? '') === 'retry';
            return [
                'processed' => $completed,
                'inspected' => $inspected,
                'approved' => $checkpointApproved,
                'checkpoint_approved' => $checkpointApproved,
                'completed' => $completed,
                'deferred' => $deferred + ($compensatedAsRetry ? 1 : 0),
                'errors' => $compensatedAsRetry ? 0 : 1,
                'status' => $compensatedAsRetry ? 'deferred' : 'error',
                'stop_reason' => $compensatedAsRetry ? 'interrupted_before_remote' : 'worker_error',
                'campaign_id' => (int) $campaign['id'],
                'campaign_item_id' => is_array($lastItem) ? (int) ($lastItem['id'] ?? 0) : 0,
                'campaign_result' => $compensatedAsRetry ? 'deferred' : 'advanced_with_attention',
                'campaign_reason' => (string) ($compensation['result'] ?? 'worker_error'),
                'reached_remote' => $compensation['reached_remote'] ?? null,
                'diagnostic_id' => $safe['reference'],
                'message' => $safe['message'],
                'campaign_run_token' => $cronRunToken,
                'started' => 1,
            ];
        }
    }

    /** @param array<string,mixed> $item */
    private function processItem(
        array $item,
        float $deadline,
        ExecutionJournalService $journal,
        int $attemptId
    ): CampaignItemResult {
        $adapter = (new ManualCampaignAdapterRegistry())->forQueue((string) $item['queue_key']);
        if (!$adapter instanceof ManualCampaignAdapter || !$adapter->supportsExact()) {
            return new CampaignItemResult('error', 'Este tipo de trabajo no tiene un adaptador exacto habilitado.');
        }
        $source = (new ManualCampaignSourceInspector())->inspect(
            (string) $item['queue_key'],
            (string) $item['source_id'],
            (int) ($item['meli_account_id'] ?? 0),
            (int) ($item['company_id'] ?? 0)
        );
        if (!$source->exists || $source->terminal) {
            return new CampaignItemResult('skipped', $source->message, 0, 0, 0, 1, null, null, $source->sourceState);
        }
        if (!$source->eligible) {
            return new CampaignItemResult(
                $source->sourceState === 'action_required' ? 'error' : 'deferred',
                $source->message,
                0,
                0,
                0,
                0,
                $source->nextEligibleAt ?: gmdate('Y-m-d H:i:s', time() + 60),
                null,
                $source->sourceState
            );
        }
        $journal->localStarted($attemptId);
        $context = new CampaignExecutionContext(
            (int) $item['manual_campaign_id'],
            (int) $item['id'],
            (int) ($item['company_id'] ?? 0),
            (string) $item['lease_owner'],
            (int) $item['lease_generation'],
            $deadline,
            1
        );
        $result = ApiExecutionMetadataContext::run(
            [
                'source' => 'manual_campaign',
                'job_type' => 'manual_campaign',
                'source_queue_key' => (string) $item['queue_key'],
                'source_work_id' => $item['id'] . ':' . $item['lease_generation'],
                'execution_attempt_id' => $attemptId,
                'execution_lease_generation' => (int) $item['lease_generation'],
            ],
            static fn (): CampaignItemResult => $adapter->processExact(
                (string) $item['source_id'],
                (int) ($item['meli_account_id'] ?? 0),
                $context
            )
        );
        $calls = (new ManualCampaignCallCounter())->summarize(
            (int) $item['id'],
            (int) $item['lease_generation']
        );
        return new CampaignItemResult(
            $result->status,
            $result->message,
            $result->processed,
            $calls['primary'],
            $calls['derived'],
            $result->avoidedCalls,
            $result->nextEligibleAt,
            $result->diagnosticId,
            $result->reason
        );
    }

    /** @return array<string,mixed>|null */
    private function nextCampaign(): ?array
    {
        $stmt = Database::connectionFresh()->query(
            'SELECT * FROM manual_campaigns
             WHERE execution_mode="directed_cli" AND status="active"
               AND (
                    next_action_at IS NULL OR next_action_at<=UTC_TIMESTAMP(3)
                    OR EXISTS (
                        SELECT 1 FROM manual_campaign_items due_item
                        WHERE due_item.manual_campaign_id=manual_campaigns.id
                          AND due_item.status IN ("pending","waiting","retry")
                          AND (due_item.next_eligible_at IS NULL OR due_item.next_eligible_at<=UTC_TIMESTAMP(3))
                          AND (due_item.lease_expires_at IS NULL OR due_item.lease_expires_at<UTC_TIMESTAMP(3))
                    )
               )
             ORDER BY created_at ASC,id ASC LIMIT 1'
        );
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function hasDueItem(int $campaignId): bool
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT 1 FROM manual_campaign_items
             WHERE manual_campaign_id=?
               AND status IN ("pending","waiting","retry")
               AND (next_eligible_at IS NULL OR next_eligible_at<=UTC_TIMESTAMP(3))
               AND (lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP(3))
             LIMIT 1'
        );
        $stmt->execute([$campaignId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return array<string,mixed>|null */
    private function campaign(int $id): ?array
    {
        $stmt = Database::connectionFresh()->prepare('SELECT * FROM manual_campaigns WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $campaign */
    private function windowMs(array $campaign, ?float $outerDeadline): int
    {
        $settings = new AppSettingsService();
        $observed = max(
            $settings->int('execution.minimum_safe_window_ms', 5000),
            (int) ($campaign['observed_safe_window_ms']
                ?? $settings->int('execution.default_observed_window_ms', 20000))
        );
        if ($outerDeadline === null) {
            return min(55000, $observed);
        }
        $remaining = max(0, (int) floor(($outerDeadline - microtime(true)) * 1000));
        return min($observed, $remaining);
    }

    /** @return array<string,mixed>|null */
    private function nextFittingCandidate(
        int $campaignId,
        int $windowMs,
        CampaignExecutionWindowPolicyService $policy
    ): ?array {
        $position = -1;
        $itemId = 0;
        $scanLimit = max(25, min(100, (new AppSettingsService())->int('manual_campaign.candidate_scan_limit', 100)));
        $scanned = 0;
        $minimumRequired = null;
        for ($page = 0; $page < (int) ceil($scanLimit / 50); $page++) {
            $stmt = Database::connectionFresh()->prepare(
                'SELECT i.id,i.operation_key,i.queue_key,i.source_id,
                        i.meli_account_id,i.company_id,i.position_no
                 FROM manual_campaign_items i
                 WHERE i.manual_campaign_id=?
                   AND i.status IN ("pending","waiting","retry")
                   AND (i.next_eligible_at IS NULL OR i.next_eligible_at<=UTC_TIMESTAMP(3))
                   AND (i.lease_expires_at IS NULL OR i.lease_expires_at<UTC_TIMESTAMP(3))
                   AND (i.position_no>? OR (i.position_no=? AND i.id>?))
                 ORDER BY i.position_no ASC,i.id ASC LIMIT 50'
            );
            $stmt->execute([$campaignId, $position, $position, $itemId]);
            $candidates = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $campaignService = new ManualCampaignService();
            $executable = [];
            foreach ($candidates as &$candidate) {
                $source = (new ManualCampaignSourceInspector())->inspect(
                    (string) ($candidate['queue_key'] ?? ''),
                    (string) ($candidate['source_id'] ?? ''),
                    (int) ($candidate['meli_account_id'] ?? 0),
                    (int) ($candidate['company_id'] ?? 0)
                );
                if (!$source->eligible && in_array($source->sourceState, ['paused', 'future', 'locked'], true)) {
                    $campaignService->markWaitingSourceBeforeClaim($campaignId, (int) $candidate['id'], $source);
                    continue;
                }
                if (!$source->eligible && in_array($source->sourceState, ['action_required', 'unsupported'], true)) {
                    $campaignService->markSourceAttentionBeforeClaim($campaignId, (int) $candidate['id'], $source);
                    continue;
                }
                if ($source->eligible) {
                    $campaignService->markSourceReadyBeforeClaim($campaignId, (int) $candidate['id']);
                }
                $candidate['reserve_seconds'] = $this->operationReserveSeconds(
                    (string) ($candidate['operation_key'] ?? ''),
                    isset($candidate['meli_account_id']) ? (int) $candidate['meli_account_id'] : null
                );
                $required = (int) ceil(((float) $candidate['reserve_seconds']) * 1000)
                    + $policy->guardMilliseconds();
                $minimumRequired = $minimumRequired === null ? $required : min($minimumRequired, $required);
                $executable[] = $candidate;
            }
            unset($candidate);
            $scanned += count($candidates);
            if ($minimumRequired !== null) {
                $this->minimumRequiredWindowCache[$campaignId] = $minimumRequired;
            }
            $fitting = CampaignExecutionWindowPolicyService::firstFittingCandidate(
                $executable,
                $windowMs,
                $policy->guardMilliseconds()
            );
            if ($fitting !== null) {
                return $fitting;
            }
            if (count($candidates) < 50 || $scanned >= $scanLimit) {
                return null;
            }
            $last = $candidates[array_key_last($candidates)];
            $position = (int) ($last['position_no'] ?? $position);
            $itemId = (int) ($last['id'] ?? $itemId);
        }
        return null;
    }

    private function minimumRequiredWindowMs(
        int $campaignId,
        CampaignExecutionWindowPolicyService $policy
    ): int {
        if (isset($this->minimumRequiredWindowCache[$campaignId])) {
            return $this->minimumRequiredWindowCache[$campaignId];
        }
        $candidate = $this->nextFittingCandidate($campaignId, 20000, $policy);
        if (isset($this->minimumRequiredWindowCache[$campaignId])) {
            return $this->minimumRequiredWindowCache[$campaignId];
        }
        $reserve = (float) ($candidate['reserve_seconds'] ?? 15.0);
        return (int) ceil($reserve * 1000) + $policy->guardMilliseconds();
    }

    private function operationReserveSeconds(string $operationKey, ?int $accountId): float
    {
        $cacheKey = $operationKey . ':' . ($accountId ?? 0);
        if (isset($this->operationReserveCache[$cacheKey])) {
            return $this->operationReserveCache[$cacheKey];
        }
        try {
            $percentiles = (new MeliOperationTelemetryService())->percentiles(168, $accountId);
            $p95 = (int) ($percentiles[$operationKey]['p95_duration_ms'] ?? 5000);
            return $this->operationReserveCache[$cacheKey] = max(2.0, min(15.0, ($p95 / 1000) + 2.0));
        } catch (Throwable) {
            return $this->operationReserveCache[$cacheKey] = 7.0;
        }
    }

    private function markCampaignCandidateSelected(int $campaignId, ?string $runToken, int $itemId): void
    {
        try {
            $schema = new SchemaInspectorService();
            if (!$schema->hasColumn('manual_campaigns', 'last_scheduler_selected_at')) {
                return;
            }
            Database::connectionFresh()->prepare(
                'UPDATE manual_campaigns
                 SET last_scheduler_selected_at=UTC_TIMESTAMP(3),last_scheduler_run_token=?,
                     last_scheduler_result="candidate_selected",last_scheduler_reason="candidate_fits_window",
                     blocking_item_id=?,version_no=version_no+1
                 WHERE id=? AND status="active"'
            )->execute([$runToken, $itemId, $campaignId]);
        } catch (Throwable) {
        }
    }

    private function markCampaignClaimed(int $campaignId, ?string $runToken, int $itemId): void
    {
        try {
            $schema = new SchemaInspectorService();
            if (!$schema->hasColumn('manual_campaigns', 'last_scheduler_claimed_at')) {
                return;
            }
            Database::connectionFresh()->prepare(
                'UPDATE manual_campaigns
                 SET last_scheduler_claimed_at=UTC_TIMESTAMP(3),last_scheduler_run_token=?,
                     last_scheduler_result="claimed",last_scheduler_reason="claim_acquired",
                     blocking_item_id=?,version_no=version_no+1
                 WHERE id=? AND status="active"'
            )->execute([$runToken, $itemId, $campaignId]);
        } catch (Throwable) {
        }
    }

    private function markCampaignHeartbeat(int $campaignId): void
    {
        $pdo = Database::connectionFresh();
        $pdo->prepare(
            'UPDATE manual_campaigns
             SET worker_heartbeat_at=UTC_TIMESTAMP(),last_engine_state="running",
                 version_no=version_no+1
             WHERE id=? AND status="active"'
        )->execute([$campaignId]);
        $ttl = (new ManualCampaignReservationTtlService())->seconds();
        $pdo->prepare(
            'UPDATE manual_campaign_reservations
             SET renewed_at=UTC_TIMESTAMP(3),
                 expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ' . $ttl . ' SECOND)
             WHERE manual_campaign_id=? AND status="active"'
        )->execute([$campaignId]);
    }

    private function markSchedulerResult(int $campaignId, string $reason, ?string $nextAt, ?string $message): void
    {
        try {
            $schema = new SchemaInspectorService();
            if (!$schema->hasColumn('manual_campaigns', 'last_scheduler_reason')) {
                return;
            }
            Database::connectionFresh()->prepare(
                'UPDATE manual_campaigns
                 SET last_scheduler_reason=?,last_scheduler_result=?,
                     safe_message=COALESCE(?,safe_message),
                     next_launcher_at=COALESCE(?,next_launcher_at),
                     version_no=version_no+1
                 WHERE id=? AND status="active"'
            )->execute([
                mb_substr($reason, 0, 80),
                $reason,
                $message !== null ? mb_substr($message, 0, 500) : null,
                $nextAt,
                $campaignId,
            ]);
        } catch (Throwable) {
            // La trazabilidad adicional no debe bloquear la campaña.
        }
    }
}
