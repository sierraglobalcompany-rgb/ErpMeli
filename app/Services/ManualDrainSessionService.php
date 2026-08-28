<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\QueueCore\QueueExecutionLease;
use App\QueueCore\QueueExecutionLeaseService;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use PDO;
use RuntimeException;
use Throwable;

final class ManualDrainSessionService
{
    private const LAUNCHER = 'manual';
    private const LEASE_SECONDS = 60;
    private const UI_STALE_SECONDS = 90;
    private const DEFAULT_MIN_DELAY_SECONDS = 15;
    private const DEFAULT_COOLDOWN_SECONDS = 30;
    private const DEFAULT_MAX_ITEMS = 1;

    /** @var null|\Closure(PDO,QueueV4CleanRepository):QueueV4CleanWorker */
    private ?\Closure $workerFactory;

    public function __construct(private ?PDO $pdo = null, ?callable $workerFactory = null)
    {
        $this->pdo ??= Database::connection();
        $this->workerFactory = $workerFactory !== null ? \Closure::fromCallable($workerFactory) : null;
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    public function start(int $userId, array $profile = []): array
    {
        $this->assertInstalled();
        $this->expireStaleSessions();
        $profile = $this->normalizeProfile($profile);
        if ((string) ($profile['selected_preview_token'] ?? '') === '') {
            return [
                'ok' => false,
                'code' => 'PREVIEW_TO_EXECUTION_BINDING_REQUIRED',
                'state' => 'OFF',
                'claimed' => 0,
                'remote_calls' => 0,
                'message' => 'Calcule una selección vigente antes de ejecutar. No se procesó trabajo global.',
            ];
        }
        try {
            $preview = $this->boundPreview($userId, $profile);
            $profile['selected_count'] = count((array) ($preview['rows'] ?? []));
        } catch (RuntimeException $error) {
            return [
                'ok' => false,
                'code' => (string) $error->getMessage(),
                'state' => 'OFF',
                'claimed' => 0,
                'remote_calls' => 0,
                'message' => 'La selección ya no coincide con la configuración visible. No se procesó trabajo global.',
            ];
        }
        $existing = $this->activeSessionForUser($userId);
        if ($existing !== null) {
            return ['ok' => true, 'state' => $this->projectState($existing), 'session' => $this->present($existing)];
        }

        $token = bin2hex(random_bytes(32));
        $lease = (new QueueExecutionLeaseService($this->pdo))->acquire(self::LAUNCHER, $token, self::LEASE_SECONDS);
        if ($lease === null) {
            return [
                'ok' => false,
                'code' => 'DRAIN_AUTHORITY_BUSY',
                'state' => 'ACQUIRING',
                'claimed' => 0,
                'remote_calls' => 0,
                'message' => 'Cron o otro drainer mantiene la autoridad global. No se reclamó trabajo.',
            ];
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO manual_drain_sessions
                 (session_token,owner_user_id,status_ui,drain_generation,lease_owner_hash,profile_snapshot_json,heartbeat_at,expires_at)
                 VALUES (?,?,"ACTIVE",?,?,?,UTC_TIMESTAMP(3),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ? SECOND))'
            );
            $stmt->execute([
                $token,
                $userId,
                $lease->generation,
                hash('sha256', $token),
                json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                self::LEASE_SECONDS,
            ]);
        } catch (Throwable $error) {
            (new QueueExecutionLeaseService($this->pdo))->release($lease);
            throw $error;
        }

        $row = $this->sessionByToken($token, true);
        return ['ok' => true, 'state' => 'ACTIVE', 'session' => $this->present($row ?? [])];
    }

    /** @return array<string,mixed> */
    public function heartbeat(int $userId, string $token, ?int $expectedGeneration = null): array
    {
        return $this->withSessionLock($userId, $token, function (array $row) use ($expectedGeneration): array {
            if (!$this->generationMatches($row, $expectedGeneration)) {
                return $this->generationMismatch($row);
            }
            if (!$this->heartbeatLease($row)) {
                return $this->markTerminal($row, 'EXPIRED', 'La autoridad global expiró antes del heartbeat.');
            }
            $stmt = $this->pdo->prepare(
                'UPDATE manual_drain_sessions
                 SET heartbeat_at=UTC_TIMESTAMP(3),expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ? SECOND),status_ui=IF(status_ui="EXPIRED","EXPIRED",status_ui)
                 WHERE id=?'
            );
            $stmt->execute([self::LEASE_SECONDS, (int) $row['id']]);
            return ['ok' => true, 'state' => $this->projectState($this->sessionByToken($token = (string) $row['session_token'], true) ?? $row), 'session' => $this->present($this->sessionByToken($token, true) ?? $row)];
        });
    }

    /** @return array<string,mixed> */
    public function pause(int $userId, string $token, ?int $expectedGeneration = null): array
    {
        return $this->setState($userId, $token, 'PAUSED', $expectedGeneration);
    }

    /** @return array<string,mixed> */
    public function resume(int $userId, string $token, ?int $expectedGeneration = null): array
    {
        return $this->setState($userId, $token, 'ACTIVE', $expectedGeneration);
    }

    /** @return array<string,mixed> */
    public function stop(int $userId, string $token, ?int $expectedGeneration = null): array
    {
        return $this->withSessionLock($userId, $token, function (array $row) use ($expectedGeneration): array {
            if (!$this->generationMatches($row, $expectedGeneration)) {
                return $this->generationMismatch($row);
            }
            $this->releaseLease($row);
            return $this->markTerminal($row, 'RELEASING', 'Sesión detenida por el operador.');
        });
    }

    /** @return array<string,mixed> */
    public function status(int $userId, string $token = '', ?int $expectedGeneration = null): array
    {
        $this->assertInstalled();
        $row = $token !== '' ? $this->sessionByToken($token, false) : $this->activeSessionForUser($userId);
        if ($row === null || (int) ($row['owner_user_id'] ?? 0) !== $userId) {
            return ['ok' => false, 'state' => 'OFF', 'session' => null];
        }
        if ($token !== '' && !$this->generationMatches($row, $expectedGeneration)) {
            return $this->generationMismatch($row);
        }
        return ['ok' => true, 'state' => $this->projectState($row), 'session' => $this->present($row)];
    }

    /** @return array<string,mixed> */
    public function step(int $userId, string $token, ?int $expectedGeneration = null): array
    {
        $this->assertInstalled();
        $lockName = 'manual_drain_step_' . hash('sha256', $token);
        if (!$this->getAdvisoryLock($lockName)) {
            return ['ok' => false, 'state' => 'ACTIVE', 'claimed' => 0, 'remote_calls' => 0, 'code' => 'STEP_ALREADY_RUNNING'];
        }
        try {
            $row = $this->prepareStep($userId, $token, $expectedGeneration);
            if (($row['ready'] ?? false) !== true) {
                return $row;
            }
            $profile = $this->profileFromRow($row);
            $remaining = max(0, (int) ($row['manual_burst_remaining'] ?? $this->burstRemaining($row, $profile)));
            $result = $this->runSelectedExactStep($userId, $row, $profile, $remaining);
            return $this->recordStepResult($row, $result);
        } finally {
            $this->releaseAdvisoryLock($lockName);
        }
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    public function profile(int $userId, string $token, ?int $expectedGeneration, array $profile): array
    {
        return $this->withSessionLock($userId, $token, function (array $row) use ($token, $expectedGeneration, $profile): array {
            if (!$this->generationMatches($row, $expectedGeneration)) {
                return $this->generationMismatch($row);
            }
            if (!in_array((string) ($row['status_ui'] ?? ''), ['ACTIVE', 'COOLING', 'RATE_LIMITED', 'PAUSED'], true)) {
                return ['ok' => false, 'state' => $this->projectState($row), 'claimed' => 0, 'remote_calls' => 0, 'session' => $this->present($row)];
            }
            if (!$this->heartbeatLease($row)) {
                return $this->markTerminal($row, 'EXPIRED', 'La autoridad global expiró antes de actualizar el perfil.');
            }
            $incoming = $profile;
            foreach (['preview_token', 'selected_preview_token', 'selected_scope', 'selected_account_id'] as $stickyField) {
                if (!array_key_exists($stickyField, $incoming)
                    || trim((string) ($incoming[$stickyField] ?? '')) === ''
                    || ($stickyField === 'selected_account_id' && (int) ($incoming[$stickyField] ?? 0) < 1)) {
                    unset($incoming[$stickyField]);
                }
            }
            $normalized = $this->normalizeProfile(array_merge($this->profileFromRow($row), $incoming));
            if ((string) ($normalized['selected_preview_token'] ?? '') === '') {
                return [
                    'ok' => false,
                    'state' => $this->projectState($row),
                    'claimed' => 0,
                    'remote_calls' => 0,
                    'code' => 'PREVIEW_TO_EXECUTION_BINDING_REQUIRED',
                    'message' => 'La sesión perdió su selección. Recalcule antes de continuar.',
                    'session' => $this->present($row),
                ];
            }
            $stmt = $this->pdo->prepare(
                'UPDATE manual_drain_sessions
                 SET profile_snapshot_json=?,profile_revision=profile_revision+1,
                     heartbeat_at=UTC_TIMESTAMP(3),expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ? SECOND)
                 WHERE id=? AND session_token=?'
            );
            $stmt->execute([
                json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                self::LEASE_SECONDS,
                (int) $row['id'],
                $token,
            ]);
            $fresh = $this->sessionByToken($token, true) ?? $row;
            return ['ok' => true, 'state' => $this->projectState($fresh), 'claimed' => 0, 'remote_calls' => 0, 'session' => $this->present($fresh)];
        });
    }

    /** @return array<string,mixed> */
    private function prepareStep(int $userId, string $token, ?int $expectedGeneration): array
    {
        $this->pdo->beginTransaction();
        try {
            $row = $this->sessionByToken($token, true);
            if ($row === null || (int) ($row['owner_user_id'] ?? 0) !== $userId) {
                $this->pdo->rollBack();
                return ['ok' => false, 'state' => 'OFF', 'claimed' => 0, 'remote_calls' => 0, 'code' => 'DRAIN_SESSION_NOT_FOUND'];
            }
            if (!$this->generationMatches($row, $expectedGeneration)) {
                $this->pdo->commit();
                return $this->generationMismatch($row);
            }
            $state = (string) ($row['status_ui'] ?? 'OFF');
            if ($state === 'PAUSED') {
                $this->pdo->commit();
                return ['ok' => true, 'state' => 'PAUSED', 'claimed' => 0, 'remote_calls' => 0, 'session' => $this->present($row)];
            }
            if (!in_array($state, ['ACTIVE', 'COOLING', 'RATE_LIMITED'], true)) {
                $this->pdo->commit();
                return ['ok' => false, 'state' => $this->projectState($row), 'claimed' => 0, 'remote_calls' => 0, 'session' => $this->present($row)];
            }
            if (!$this->heartbeatLease($row)) {
                $result = $this->markTerminal($row, 'EXPIRED', 'La autoridad global expiró antes del paso.');
                $this->pdo->commit();
                return $result;
            }
            $now = time();
            $profile = $this->profileFromRow($row);
            if (($profile['selected_preview_token'] ?? '') === '') {
                $this->pdo->commit();
                return [
                    'ok' => false,
                    'state' => $this->projectState($row),
                    'claimed' => 0,
                    'remote_calls' => 0,
                    'code' => 'PREVIEW_TO_EXECUTION_BINDING_REQUIRED',
                    'message' => 'Calcule una selección vigente antes de ejecutar. No se procesó trabajo global.',
                    'session' => $this->present($row),
                ];
            }
            $elapsedLimit = max(0, (int) ($profile['max_duration_seconds'] ?? 0));
            $startedAt = $this->timestampOrZero($row['started_at'] ?? null);
            if ($elapsedLimit > 0 && $startedAt > 0 && $startedAt + $elapsedLimit <= $now) {
                $this->updateStatus((int) $row['id'], 'PAUSED');
                $fresh = $this->sessionByToken($token, true) ?? $row;
                $this->pdo->commit();
                return [
                    'ok' => true,
                    'state' => 'PAUSED',
                    'claimed' => 0,
                    'remote_calls' => 0,
                    'code' => 'MANUAL_DURATION_LIMIT_REACHED',
                    'message' => 'Se alcanzó la duración máxima configurada.',
                    'session' => $this->present($fresh),
                ];
            }
            $maxItems = max(1, (int) ($profile['max_items'] ?? self::DEFAULT_MAX_ITEMS));
            if ((int) ($row['jobs_processed'] ?? 0) >= $maxItems) {
                $this->updateStatus((int) $row['id'], 'PAUSED');
                $fresh = $this->sessionByToken($token, true) ?? $row;
                $this->pdo->commit();
                return [
                    'ok' => true,
                    'state' => 'PAUSED',
                    'claimed' => 0,
                    'remote_calls' => 0,
                    'code' => 'MANUAL_ITEM_LIMIT_REACHED',
                    'message' => 'Se alcanzó el límite de trabajos configurado.',
                    'session' => $this->present($fresh),
                ];
            }
            $cooldownTs = $this->timestampOrZero($row['manual_cooldown_until'] ?? null);
            $intervalTs = $this->timestampOrZero($row['manual_interval_until'] ?? null);
            if (($cooldownTs > 0 && $cooldownTs <= $now) || ($intervalTs > 0 && $intervalTs <= $now)) {
                $this->clearExpiredManualGates((int) $row['id'], $cooldownTs > 0 && $cooldownTs <= $now, $intervalTs > 0 && $intervalTs <= $now);
                $row = $this->sessionByToken($token, true) ?? $row;
                $cooldownTs = $this->timestampOrZero($row['manual_cooldown_until'] ?? null);
                $intervalTs = $this->timestampOrZero($row['manual_interval_until'] ?? null);
            }
            $manualNext = max(
                $intervalTs,
                $cooldownTs,
            );
            if ($manualNext > $now) {
                $blockedState = $cooldownTs > $now ? 'COOLING' : ($state === 'RATE_LIMITED' ? 'RATE_LIMITED' : 'COOLING');
                $this->updateStatus((int) $row['id'], $blockedState);
                $fresh = $this->sessionByToken($token, true) ?? $row;
                $this->pdo->commit();
                return [
                    'ok' => true,
                    'state' => $blockedState,
                    'claimed' => 0,
                    'remote_calls' => 0,
                    'next_safe_at' => gmdate('Y-m-d H:i:s', $manualNext),
                    'session' => $this->present($fresh),
                ];
            }
            $remaining = $this->burstRemaining($row, $profile);
            if ($remaining <= 0) {
                $cooldownSeconds = max(0, (int) ($profile['cooldown_after_burst'] ?? self::DEFAULT_COOLDOWN_SECONDS));
                $next = $cooldownSeconds > 0 ? $now + $cooldownSeconds : $now;
                $this->startManualCooldown((int) $row['id'], $next);
                $fresh = $this->sessionByToken($token, true) ?? $row;
                $this->pdo->commit();
                return [
                    'ok' => true,
                    'state' => 'COOLING',
                    'claimed' => 0,
                    'remote_calls' => 0,
                    'next_safe_at' => gmdate('Y-m-d H:i:s', $next),
                    'session' => $this->present($fresh),
                ];
            }
            $this->pdo->commit();
            $row['ready'] = true;
            $row['manual_burst_remaining'] = $remaining;
            return $row;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function recordStepResult(array $row, array $result): array
    {
        $profile = $this->profileFromRow($row);
        $claimed = (int) ($result['claimed'] ?? 0);
        $completed = (int) ($result['completed'] ?? 0);
        $deferred = (int) ($result['deferred'] ?? 0);
        $remoteDelta = max(0, (int) ($result['physical_http_calls'] ?? $result['remote_calls'] ?? 0));
        $classification = strtolower(trim((string) ($result['classification'] ?? '')));
        $httpStatus = isset($result['http_status']) ? (int) $result['http_status'] : null;
        $autoDegraded = $this->isRemoteRateLimit($classification, $httpStatus)
            && (string) ($profile['429_action'] ?? 'pause_and_ask') === 'auto_degrade';
        if ($autoDegraded) {
            $profile = $this->degradedProfile($profile);
        }
        $nextState = $this->projectStepState($claimed, $completed, $deferred, $classification, $httpStatus, $profile);
        $delaySeconds = max(self::DEFAULT_MIN_DELAY_SECONDS, (int) ($profile['minimum_delay_between_remote_calls'] ?? self::DEFAULT_MIN_DELAY_SECONDS));
        $burstLimit = max(1, (int) ($profile['remote_calls_per_burst'] ?? 1));
        $burstBefore = max(0, (int) ($row['burst_remote_calls_current'] ?? 0));
        $burstRemainingBefore = max(0, (int) ($row['manual_burst_remaining'] ?? max(0, $burstLimit - $burstBefore)));
        $burstAfter = $burstBefore + $remoteDelta;
        $cooldownSeconds = $remoteDelta > 0 && $burstAfter >= $burstLimit
            ? max(0, (int) ($profile['cooldown_after_burst'] ?? self::DEFAULT_COOLDOWN_SECONDS))
            : 0;
        $manualIntervalUntil = $remoteDelta > 0 ? time() + $delaySeconds : 0;
        $manualCooldownUntil = $cooldownSeconds > 0 ? time() + $cooldownSeconds : 0;
        $canonicalNextSafe = $this->timestampOrZero($result['next_safe_at'] ?? null);
        $effectiveNextSafe = max($canonicalNextSafe, $manualIntervalUntil, $manualCooldownUntil);
        if ($nextState === 'ACTIVE' && $effectiveNextSafe > time()) {
            $nextState = 'COOLING';
        }
        $receipt = [
            'run_id' => (int) ($result['run_id'] ?? 0),
            'claimed' => $claimed,
            'completed' => $completed,
            'deferred' => $deferred,
            'remote_calls' => $remoteDelta,
            'physical_http_calls' => $remoteDelta,
            'classification' => $classification !== '' ? $classification : 'none',
            'http_status' => $httpStatus,
            'next_safe_at' => $canonicalNextSafe > 0 ? gmdate('Y-m-d H:i:s', $canonicalNextSafe) : null,
            'effective_next_safe_at' => $effectiveNextSafe > 0 ? gmdate('Y-m-d H:i:s', $effectiveNextSafe) : null,
            'burst_unit' => 'PHYSICAL_REMOTE_HTTP',
            'burst_limit' => $burstLimit,
            'burst_current_before' => $burstBefore,
            'burst_remaining_before' => $burstRemainingBefore,
            'burst_current_after' => $burstAfter,
            '429_action' => (string) ($profile['429_action'] ?? 'pause_and_ask'),
            'auto_degraded' => $autoDegraded,
            'bounded_5xx_preferences' => (string) ($profile['bounded_5xx_preferences'] ?? 'pause_and_ask'),
        ];
        if (isset($result['manual_next_preview_position'])) {
            $profile['next_preview_position'] = max(0, (int) $result['manual_next_preview_position']);
        }
        if (!empty($result['selected_count'])) {
            $profile['selected_count'] = max(0, (int) $result['selected_count']);
        }
        $mode = (string) ($profile['execution_mode'] ?? 'ONE_STEP');
        $maxItems = max(1, (int) ($profile['max_items'] ?? self::DEFAULT_MAX_ITEMS));
        $nextPosition = max(0, (int) ($profile['next_preview_position'] ?? 0));
        $selectedCount = max(0, (int) ($profile['selected_count'] ?? 0));
        if ($claimed > 0 && $mode === 'ONE_STEP') {
            $nextState = 'PAUSED';
        }
        if ($claimed > 0 && $mode === 'CONTINUE_AUTOMATICALLY') {
            $processedAfter = (int) ($row['jobs_processed'] ?? 0) + max(1, $claimed);
            if ($processedAfter >= $maxItems || ($selectedCount > 0 && $nextPosition >= $selectedCount)) {
                $nextState = 'PAUSED';
            }
        }
        $stmt = $this->pdo->prepare(
            'UPDATE manual_drain_sessions
             SET status_ui=?,heartbeat_at=UTC_TIMESTAMP(3),expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ? SECOND),
                 jobs_processed=jobs_processed+?,remote_calls_observed=remote_calls_observed+?,
                 burst_remote_calls_current=?,
                 last_completed_job_id=?,last_result_json=?,
                 profile_snapshot_json=?,
                 profile_revision=profile_revision+?,
                 manual_interval_until=?,
                 manual_cooldown_until=?
             WHERE id=? AND session_token=?'
        );
        $lastJobId = $this->lastCompletedJobId((int) ($result['run_id'] ?? 0));
        $stmt->execute([
            $nextState,
            self::LEASE_SECONDS,
            $claimed > 0 ? max(1, $completed) : 0,
            $remoteDelta,
            $burstAfter,
            $lastJobId > 0 ? $lastJobId : null,
            json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $autoDegraded ? 1 : 0,
            $effectiveNextSafe > 0 ? gmdate('Y-m-d H:i:s', $effectiveNextSafe) : null,
            $manualCooldownUntil > 0 ? gmdate('Y-m-d H:i:s', $manualCooldownUntil) : null,
            (int) $row['id'],
            (string) $row['session_token'],
        ]);
        $fresh = $this->sessionByToken((string) $row['session_token'], false) ?? $row;
        return [
            'ok' => true,
            'state' => $nextState,
            'claimed' => $claimed,
            'completed' => $completed,
            'deferred' => $deferred,
            'remote_calls' => $remoteDelta,
            'receipt' => $receipt,
            'next_safe_at' => $receipt['effective_next_safe_at'],
            'session' => $this->present($fresh),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private function runSelectedExactStep(int $userId, array $row, array $profile, int $remainingHttp): array
    {
        $previewToken = trim((string) ($profile['selected_preview_token'] ?? ''));
        if ($previewToken === '') {
            return [
                'claimed' => 0,
                'completed' => 0,
                'deferred' => 0,
                'physical_http_calls' => 0,
                'classification' => 'preview_to_execution_binding_required',
                'message' => 'Calcule una selección vigente antes de ejecutar.',
            ];
        }
        $position = max(0, (int) ($profile['next_preview_position'] ?? 0));
        $globalLease = $this->leaseFromRow($row);
        try {
            $result = ApiExecutionMetadataContext::run(
                [
                    'source' => 'manual',
                    'job_type' => 'manual_exact',
                    'manual_physical_http_burst_limit' => max(1, $remainingHttp),
                    'manual_next_safe_at' => null,
                    'manual_preview_token_hash' => hash('sha256', $previewToken),
                    'manual_preview_position' => $position,
                    'manual_selected_scope' => (string) ($profile['selected_scope'] ?? ''),
                    'manual_selected_account_id' => (int) ($profile['selected_account_id'] ?? 0),
                ],
                static fn (): array => (new ManualSingleStepService())->executeSelected(
                    $previewToken,
                    $userId,
                    $position,
                    false,
                    (string) ($profile['selected_scope'] ?? ''),
                    (int) ($profile['selected_account_id'] ?? 0),
                    $globalLease
                )
            );
        } catch (RuntimeException $error) {
            if (in_array((string) $error->getMessage(), ['NO_ELIGIBLE_SELECTED_WORK', 'SELECTION_BINDING_MISMATCH'], true)) {
                return [
                    'claimed' => 0,
                    'completed' => 0,
                    'deferred' => 0,
                    'physical_http_calls' => 0,
                    'classification' => strtolower((string) $error->getMessage()),
                    'message' => 'La selección ya no contiene trabajo elegible. No se sustituyó por trabajo global.',
                    'manual_next_preview_position' => $position,
                ];
            }
            throw $error;
        }

        $status = strtolower((string) ($result['status'] ?? 'completed'));
        $remoteCalls = max(0, (int) ($result['remote_dispatches'] ?? $result['physical_http_calls'] ?? 0));
        return [
            'run_id' => (int) ($result['queue_core_job_id'] ?? 0),
            'claimed' => 1,
            'completed' => $status === 'completed' ? 1 : 0,
            'deferred' => in_array($status, ['retry', 'retry_wait', 'waiting', 'deferred'], true) ? 1 : 0,
            'physical_http_calls' => $remoteCalls,
            'remote_calls' => $remoteCalls,
            'classification' => 'manual_exact:' . ($status !== '' ? $status : 'unknown'),
            'http_status' => $result['http_status'] ?? null,
            'message' => (string) ($result['message'] ?? 'Paso exacto procesado.'),
            'queue_key' => (string) ($result['queue_key'] ?? ''),
            'source_id' => (string) ($result['source_id'] ?? ''),
            'account_id' => (int) ($result['account_id'] ?? 0),
            'selected_count' => (int) ($result['selected_count'] ?? 0),
            'manual_selected_position' => $position,
            'manual_next_preview_position' => $position + 1,
        ];
    }

    private function assertInstalled(): void
    {
        if (!$this->hasTable('manual_drain_sessions')) {
            throw new RuntimeException('manual_drain_sessions_missing');
        }
    }

    private function hasTable(string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1'
            );
            $stmt->execute([$table]);
            return $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed>|null */
    private function activeSessionForUser(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM manual_drain_sessions
             WHERE owner_user_id=? AND status_ui IN ("ACQUIRING","ACTIVE","COOLING","RATE_LIMITED","PAUSED")
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function sessionByToken(string $token, bool $forUpdate): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $sql = 'SELECT * FROM manual_drain_sessions WHERE session_token=?';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param callable(array<string,mixed>):array<string,mixed> $callback
     * @return array<string,mixed>
     */
    private function withSessionLock(int $userId, string $token, callable $callback): array
    {
        $this->pdo->beginTransaction();
        try {
            $row = $this->sessionByToken($token, true);
            if ($row === null || (int) ($row['owner_user_id'] ?? 0) !== $userId) {
                $this->pdo->rollBack();
                return ['ok' => false, 'state' => 'OFF', 'claimed' => 0, 'remote_calls' => 0, 'code' => 'DRAIN_SESSION_NOT_FOUND'];
            }
            $result = $callback($row);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    private function setState(int $userId, string $token, string $state, ?int $expectedGeneration = null): array
    {
        return $this->withSessionLock($userId, $token, function (array $row) use ($state, $token, $expectedGeneration): array {
            if (!$this->generationMatches($row, $expectedGeneration)) {
                return $this->generationMismatch($row);
            }
            if (!in_array((string) ($row['status_ui'] ?? ''), ['ACTIVE', 'COOLING', 'RATE_LIMITED', 'PAUSED'], true)) {
                return ['ok' => false, 'state' => $this->projectState($row), 'session' => $this->present($row)];
            }
            if (!$this->heartbeatLease($row)) {
                return $this->markTerminal($row, 'EXPIRED', 'La autoridad global expiró.');
            }
            $this->updateStatus((int) $row['id'], $state);
            $fresh = $this->sessionByToken($token, true) ?? $row;
            return ['ok' => true, 'state' => $this->projectState($fresh), 'session' => $this->present($fresh)];
        });
    }

    private function heartbeatLease(array $row): bool
    {
        return (new QueueExecutionLeaseService($this->pdo))->heartbeat($this->leaseFromRow($row));
    }

    private function releaseLease(array $row): bool
    {
        return (new QueueExecutionLeaseService($this->pdo))->release($this->leaseFromRow($row));
    }

    private function leaseFromRow(array $row): QueueExecutionLease
    {
        return new QueueExecutionLease(
            self::LAUNCHER,
            (string) ($row['session_token'] ?? ''),
            (int) ($row['drain_generation'] ?? 0),
            self::LEASE_SECONDS,
        );
    }

    private function updateStatus(int $id, string $state): void
    {
        $stmt = $this->pdo->prepare('UPDATE manual_drain_sessions SET status_ui=?,updated_at=UTC_TIMESTAMP(3) WHERE id=?');
        $stmt->execute([$state, $id]);
    }

    private function burstRemaining(array $row, array $profile): int
    {
        $limit = max(1, min(60, (int) ($profile['remote_calls_per_burst'] ?? 1)));
        return max(0, $limit - max(0, (int) ($row['burst_remote_calls_current'] ?? 0)));
    }

    private function clearExpiredManualGates(int $id, bool $resetBurst, bool $clearInterval): void
    {
        $burstSql = $resetBurst ? 'burst_remote_calls_current=0,' : '';
        $intervalSql = $clearInterval ? 'manual_interval_until=NULL,' : '';
        $stmt = $this->pdo->prepare(
            'UPDATE manual_drain_sessions
             SET ' . $burstSql . $intervalSql . 'manual_cooldown_until=IF(?=1,NULL,manual_cooldown_until),
                 status_ui=IF(status_ui IN ("COOLING","RATE_LIMITED"),"ACTIVE",status_ui),
                 updated_at=UTC_TIMESTAMP(3)
             WHERE id=?'
        );
        $stmt->execute([$resetBurst ? 1 : 0, $id]);
    }

    private function startManualCooldown(int $id, int $next): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE manual_drain_sessions
             SET status_ui="COOLING",manual_cooldown_until=?,updated_at=UTC_TIMESTAMP(3)
             WHERE id=?'
        );
        $stmt->execute([gmdate('Y-m-d H:i:s', $next), $id]);
    }

    /** @return array<string,mixed> */
    private function markTerminal(array $row, string $state, string $message): array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE manual_drain_sessions
             SET status_ui=?,finished_at=UTC_TIMESTAMP(3),expires_at=NULL,last_result_json=?
             WHERE id=?'
        );
        $stmt->execute([
            $state,
            json_encode(['message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            (int) $row['id'],
        ]);
        $fresh = $this->sessionByToken((string) $row['session_token'], true) ?? $row;
        return ['ok' => false, 'state' => $state, 'claimed' => 0, 'remote_calls' => 0, 'message' => $message, 'session' => $this->present($fresh)];
    }

    private function expireStaleSessions(): void
    {
        $this->pdo->exec(
            'UPDATE manual_drain_sessions
             SET status_ui="EXPIRED",finished_at=UTC_TIMESTAMP(3)
             WHERE status_ui IN ("ACQUIRING","ACTIVE","COOLING","RATE_LIMITED","PAUSED","RELEASING")
               AND expires_at IS NOT NULL
               AND expires_at<UTC_TIMESTAMP(3)'
        );
    }

    /** @param array<string,mixed> $profile @return array<string,int|string> */
    private function normalizeProfile(array $profile): array
    {
        $action = (string) ($profile['429_action'] ?? 'pause_and_ask');
        $action = match ($action) {
            'pause' => 'pause_and_ask',
            'cooldown' => 'auto_degrade',
            default => $action,
        };
        $fiveXx = (string) ($profile['bounded_5xx_preferences'] ?? 'pause_and_ask');
        $fiveXx = $fiveXx === 'fail_closed' ? 'pause_and_ask' : $fiveXx;
        return [
            'selected_preview_token' => preg_match('/^[a-f0-9]{40}$/', (string) ($profile['preview_token'] ?? $profile['selected_preview_token'] ?? '')) === 1
                ? (string) ($profile['preview_token'] ?? $profile['selected_preview_token'])
                : '',
            'selected_scope' => preg_replace('/[^a-z_]/', '', (string) ($profile['selected_scope'] ?? 'recommended')) ?: 'recommended',
            'selected_account_id' => max(0, (int) ($profile['selected_account_id'] ?? 0)),
            'execution_mode' => in_array((string) ($profile['execution_mode'] ?? 'ONE_STEP'), ['ONE_STEP', 'CONTINUE_AUTOMATICALLY'], true)
                ? (string) ($profile['execution_mode'] ?? 'ONE_STEP')
                : 'ONE_STEP',
            'max_items' => max(1, min(10000, (int) ($profile['max_items'] ?? self::DEFAULT_MAX_ITEMS))),
            'max_duration_seconds' => max(0, min(604800, (int) ($profile['max_duration_seconds'] ?? 0))),
            'next_preview_position' => max(0, (int) ($profile['next_preview_position'] ?? 0)),
            'selected_count' => max(0, (int) ($profile['selected_count'] ?? 0)),
            'remote_calls_per_burst' => max(1, min(60, (int) ($profile['remote_calls_per_burst'] ?? 1))),
            'minimum_delay_between_remote_calls' => max(self::DEFAULT_MIN_DELAY_SECONDS, min(3600, (int) ($profile['minimum_delay_between_remote_calls'] ?? self::DEFAULT_MIN_DELAY_SECONDS))),
            'cooldown_after_burst' => max(0, min(3600, (int) ($profile['cooldown_after_burst'] ?? self::DEFAULT_COOLDOWN_SECONDS))),
            '429_action' => in_array($action, ['pause_and_ask', 'auto_degrade'], true) ? $action : 'pause_and_ask',
            'bounded_5xx_preferences' => in_array($fiveXx, ['pause_and_ask', 'cooldown'], true) ? $fiveXx : 'pause_and_ask',
        ];
    }

    /** @param array<string,mixed> $profile @return array<string,int|string> */
    private function degradedProfile(array $profile): array
    {
        return $this->normalizeProfile(array_merge($profile, [
            'remote_calls_per_burst' => 1,
            'minimum_delay_between_remote_calls' => max(60, (int) ($profile['minimum_delay_between_remote_calls'] ?? self::DEFAULT_MIN_DELAY_SECONDS)),
            'cooldown_after_burst' => max(300, (int) ($profile['cooldown_after_burst'] ?? self::DEFAULT_COOLDOWN_SECONDS)),
            '429_action' => $profile['429_action'] ?? 'auto_degrade',
            'bounded_5xx_preferences' => $profile['bounded_5xx_preferences'] ?? 'pause_and_ask',
        ]));
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    private function boundPreview(int $userId, array $profile): array
    {
        $previewToken = (string) ($profile['selected_preview_token'] ?? '');
        $preview = (new ManualCampaignPreviewService())->load($previewToken, $userId);
        $configuration = is_array($preview['configuration'] ?? null) ? $preview['configuration'] : [];
        $scope = (string) ($profile['selected_scope'] ?? '');
        $configuredScope = (string) ($configuration['scope'] ?? '');
        $accountId = (int) ($profile['selected_account_id'] ?? 0);
        $configuredAccountId = (int) ($configuration['account_id'] ?? 0);
        if ($scope === '' || $scope !== $configuredScope || $accountId !== $configuredAccountId) {
            throw new RuntimeException('SELECTION_BINDING_MISMATCH');
        }
        if (count((array) ($preview['rows'] ?? [])) < 1) {
            throw new RuntimeException('NO_ELIGIBLE_SELECTED_WORK');
        }
        return $preview;
    }

    /** @return array<string,mixed> */
    private function profileFromRow(array $row): array
    {
        try {
            $profile = json_decode((string) ($row['profile_snapshot_json'] ?? '{}'), true, 16, JSON_THROW_ON_ERROR);
            return is_array($profile) ? $profile : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function projectState(array $row): string
    {
        $state = (string) ($row['status_ui'] ?? 'OFF');
        if (in_array($state, ['ACTIVE', 'COOLING', 'RATE_LIMITED', 'PAUSED', 'ACQUIRING', 'RELEASING'], true)
            && $this->timestampOrZero($row['expires_at'] ?? null) < time()) {
            return 'EXPIRED';
        }
        return in_array($state, ['OFF', 'ACQUIRING', 'ACTIVE', 'COOLING', 'RATE_LIMITED', 'PAUSED', 'RELEASING', 'ABANDONED', 'EXPIRED'], true)
            ? $state
            : 'OFF';
    }

    /** @return array<string,mixed> */
    private function present(array $row): array
    {
        $last = [];
        try {
            $decoded = json_decode((string) ($row['last_result_json'] ?? '{}'), true, 16, JSON_THROW_ON_ERROR);
            $last = is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            $last = [];
        }
        return [
            'id' => (int) ($row['id'] ?? 0),
            'session_token' => (string) ($row['session_token'] ?? ''),
            'state' => $this->projectState($row),
            'drain_generation' => (int) ($row['drain_generation'] ?? 0),
            'profile_revision' => (int) ($row['profile_revision'] ?? 1),
            'profile' => $this->profileFromRow($row),
            'jobs_processed' => (int) ($row['jobs_processed'] ?? 0),
            'remote_calls_observed' => (int) ($row['remote_calls_observed'] ?? 0),
            'burst_remote_calls_current' => (int) ($row['burst_remote_calls_current'] ?? 0),
            'last_completed_job_id' => (int) ($row['last_completed_job_id'] ?? 0),
            'last_result' => $last,
            'manual_interval_until' => $row['manual_interval_until'] ?? null,
            'manual_cooldown_until' => $row['manual_cooldown_until'] ?? null,
            'heartbeat_at' => $row['heartbeat_at'] ?? null,
            'expires_at' => $row['expires_at'] ?? null,
            'ui_stale_seconds' => self::UI_STALE_SECONDS,
        ];
    }

    /**
     * @param array<string,mixed> $profile
     */
    private function projectStepState(
        int $claimed,
        int $completed,
        int $deferred,
        string $classification,
        ?int $httpStatus,
        array $profile,
    ): string {
        if ($claimed < 1) {
            return 'ACTIVE';
        }
        if ($this->isRemoteRateLimit($classification, $httpStatus)) {
            return (string) ($profile['429_action'] ?? 'pause_and_ask') === 'auto_degrade'
                ? 'COOLING'
                : 'RATE_LIMITED';
        }
        if ($httpStatus === 401 || $httpStatus === 403 || str_contains($classification, 'oauth_refresh_required')) {
            return 'PAUSED';
        }
        if ($httpStatus !== null && $httpStatus >= 500 && $httpStatus <= 599) {
            return (string) ($profile['bounded_5xx_preferences'] ?? 'pause_and_ask') === 'cooldown' ? 'COOLING' : 'PAUSED';
        }
        if (str_contains($classification, 'remote_result_uncertain')
            || str_contains($classification, 'review')
            || str_contains($classification, 'correction')) {
            return 'PAUSED';
        }
        if ($deferred > 0 && $completed === 0) {
            return 'COOLING';
        }
        return 'ACTIVE';
    }

    private function isRemoteRateLimit(string $classification, ?int $httpStatus): bool
    {
        return $httpStatus === 429
            || str_contains($classification, 'http_429')
            || str_contains($classification, 'billing_429_backoff');
    }

    private function timestampOrZero(mixed $value): int
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return 0;
        }
        $time = strtotime($raw . ' UTC');
        return $time === false ? 0 : $time;
    }

    private function generationMatches(array $row, ?int $expectedGeneration): bool
    {
        return $expectedGeneration === null || $expectedGeneration === (int) ($row['drain_generation'] ?? 0);
    }

    /** @return array<string,mixed> */
    private function generationMismatch(array $row): array
    {
        return [
            'ok' => false,
            'state' => $this->projectState($row),
            'claimed' => 0,
            'remote_calls' => 0,
            'code' => 'PROFILE_GENERATION_MISMATCH',
            'message' => 'La pestaña pertenece a una generación anterior. Recargue antes de continuar.',
            'session' => $this->present($row),
        ];
    }

    private function lastCompletedJobId(int $runId): int
    {
        if ($runId < 1) {
            return 0;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT job_id FROM queue_v4_clean_attempts
                 WHERE run_id=? AND outcome="completed"
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$runId]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private function getAdvisoryLock(string $name): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT GET_LOCK(?,0)');
            $stmt->execute([substr($name, 0, 64)]);
            return (int) $stmt->fetchColumn() === 1;
        } catch (Throwable) {
            return false;
        }
    }

    private function releaseAdvisoryLock(string $name): void
    {
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([substr($name, 0, 64)]);
        } catch (Throwable) {
        }
    }
}
