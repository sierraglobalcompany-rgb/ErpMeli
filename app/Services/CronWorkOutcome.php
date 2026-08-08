<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Traduce resultados heterogéneos de colas sin perder la causa entregada por
 * el servicio de dominio.
 */
final class CronWorkOutcome
{
    private const STATES = [
        'completed', 'partial', 'empty', 'deferred', 'waiting_rhythm',
        'waiting_budget', 'waiting_api', 'waiting_schedule', 'waiting_source',
        'waiting_deadline', 'waiting_guard', 'waiting_lock', 'retry_scheduled',
        'retry', 'repairable', 'action_required', 'remote_result_uncertain',
        'skipped_expected', 'failed',
    ];

    private const OPERATIONAL_STATES = [
        'ready',
        'running',
        'waiting_automation',
        'waiting_rhythm',
        'waiting_api',
        'waiting_budget',
        'waiting_lock',
        'waiting_schedule',
        'waiting_deadline',
        'waiting_source',
        'retry',
        'repairable',
        'action_required',
        'remote_result_uncertain',
        'skipped_expected',
        'completed',
        'failed',
    ];

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public static function normalize(array $payload, int $processed, int $errors): array
    {
        $explicit = strtolower(trim((string) ($payload['status'] ?? '')));
        $reason = strtolower(trim((string) ($payload['stop_reason'] ?? '')));

        $status = match (true) {
            $reason === 'api_rhythm' || $reason === 'policy_delay' => 'waiting_rhythm',
            $reason === 'api_limit' => 'waiting_rhythm',
            $reason === 'api_budget' || !empty($payload['skipped_by_api_budget']) => 'waiting_budget',
            $reason === 'api_guard' || $reason === 'manual_pause' => 'waiting_guard',
            $reason === 'locked' || $reason === 'lease_active' => 'waiting_lock',
            $reason === 'retry' || $reason === 'backoff' => 'retry',
            $reason === 'billing_error' || $reason === 'db_connection' => 'retry',
            $reason === 'time_budget' || $reason === 'lane_deadline' || $reason === 'deadline' => 'waiting_deadline',
            in_array($explicit, self::STATES, true) => $explicit,
            $explicit === 'complete' || $explicit === 'success' => 'completed',
            $explicit === 'error' || $explicit === 'failed' => 'failed',
            $errors > 0 && $processed > 0 => 'partial',
            $errors > 0 => 'failed',
            $processed > 0 => 'completed',
            default => 'empty',
        };

        $normalizedErrors = self::isWaiting($status) ? 0 : max(0, $errors);
        return array_merge($payload, [
            'status' => $status,
            'processed' => max(0, $processed),
            'errors' => $normalizedErrors,
            'deferred' => self::isWaiting($status)
                ? max(1, (int) ($payload['deferred'] ?? 0))
                : max(0, (int) ($payload['deferred'] ?? 0)),
        ]);
    }

    public static function traceState(string $status): string
    {
        return in_array($status, self::STATES, true) ? $status : 'failed';
    }

    public static function operationalState(string $status, ?string $reason = null): string
    {
        $status = strtolower(trim($status));
        $reason = strtolower(trim((string) $reason));
        return match (true) {
            $status === 'ready' || $status === 'running' || $status === 'completed' => $status,
            $status === 'partial' || $status === 'empty' => 'ready',
            $status === 'action_required' || $status === 'repairable' => $status,
            $status === 'remote_result_uncertain' => 'remote_result_uncertain',
            $status === 'skipped_expected' => 'skipped_expected',
            $status === 'waiting_rhythm' || $reason === 'api_rhythm' || $reason === 'policy_delay' => 'waiting_rhythm',
            $reason === 'api_limit' => 'waiting_rhythm',
            $status === 'waiting_budget' || $reason === 'api_budget' => 'waiting_budget',
            $status === 'waiting_lock' || $reason === 'locked' || $reason === 'lease_active' => 'waiting_lock',
            $status === 'retry' || $status === 'retry_scheduled' || $status === 'deferred'
                || in_array($reason, ['backoff', 'billing_error', 'db_connection'], true) => 'waiting_schedule',
            $status === 'waiting_deadline' || $reason === 'time_budget' || $reason === 'lane_deadline' => 'waiting_deadline',
            $status === 'waiting_source' || $reason === 'waiting_source' || $reason === 'source_paused' => 'waiting_schedule',
            $status === 'waiting_api' => 'waiting_api',
            $status === 'waiting_guard' && in_array($reason, ['api_guard', 'app_blocked', 'manual_pause'], true) => 'waiting_api',
            $status === 'waiting_guard' && $reason === 'manual_automation_stop' => 'waiting_automation',
            $status === 'failed' => 'failed',
            default => 'failed',
        };
    }

    /** @return list<string> */
    public static function operationalStates(): array
    {
        return self::OPERATIONAL_STATES;
    }

    public static function isWaiting(string $status): bool
    {
        return in_array($status, [
            'deferred', 'waiting_rhythm', 'waiting_budget', 'waiting_api',
            'waiting_schedule', 'waiting_deadline', 'waiting_source', 'waiting_guard',
            'waiting_lock', 'retry', 'retry_scheduled',
        ], true);
    }

    public static function requiresAction(string $status): bool
    {
        return in_array(strtolower(trim($status)), [
            'repairable', 'action_required', 'remote_result_uncertain', 'failed',
        ], true);
    }

    public static function countsAsFailure(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['action_required', 'failed'], true);
    }
}
