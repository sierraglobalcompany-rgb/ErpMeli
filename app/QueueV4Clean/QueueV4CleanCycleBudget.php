<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use App\Services\ApiBudgetExhaustedException;
use App\Services\ApiExecutionMetadataContext;
use App\Services\CronDeadlineContext;
use RuntimeException;

/** Existing physical HTTP budget, owned by one automatic or manual outer invocation. */
final class QueueV4CleanCycleBudget
{
    /**
     * Absolute safety fuse only. The configured runtime unit is physical API
     * calls; this cap prevents an accidental unbounded value from becoming a
     * runaway loop. It is not a job budget.
     */
    public const CYCLE_HTTP_SAFETY_FUSE = 1000;

    private static ?int $limit = null;
    private static int $used = 0;
    private static ?string $owner = null;
    private static ?float $deadline = null;
    private static ?string $stoppedReason = null;
    /** @var array<string,array{source:string,state:string,identity:array}> */
    private static array $attempts = [];

    public static function start(int $limit, string $owner = 'automatic', ?float $deadline = null): void
    {
        if (self::$limit !== null) { throw new RuntimeException('physical_budget_already_owned'); }
        if (!in_array($owner, ['automatic','manual'], true)) { throw new RuntimeException('physical_budget_owner_invalid'); }
        self::$limit = max(1, min(self::CYCLE_HTTP_SAFETY_FUSE, $limit));
        self::$used = 0;
        self::$owner = $owner;
        self::$deadline = $deadline ?? CronDeadlineContext::deadline() ?? microtime(true) + 45.0;
        self::$stoppedReason = null;
        self::$attempts = [];
    }

    public static function clear(): void
    {
        OuterCronHttpReceipt::budgetClosed(self::snapshot());
        self::$limit = null;
        self::$used = 0;
        self::$owner = null;
        self::$deadline = null;
        self::$stoppedReason = null;
        self::$attempts = [];
    }

    public static function claim(?string $attemptId = null): void
    {
        $meta = ApiExecutionMetadataContext::current();
        self::reserve($attemptId ?? (string) ($meta['transport_request_id'] ?? ''), (string) ($meta['source'] ?? ''));
    }

    public static function assertActive(): void
    {
        if (self::$limit === null) { throw new RuntimeException('physical_budget_context_required'); }
        if (self::$stoppedReason !== null) { throw new RuntimeException('physical_budget_stopped:' . self::$stoppedReason); }
        CronDeadlineContext::assertCanStartRemote(1.0, self::$deadline);
    }

    public static function reserve(string $attemptId, string $source): void
    {
        self::assertActive();
        if (!preg_match('/^[a-f0-9]{40}$/D', $attemptId) || $source === '') { throw new RuntimeException('physical_budget_attempt_identity_required'); }
        if (isset(self::$attempts[$attemptId])) {
            if (self::$attempts[$attemptId] !== ['source'=>$source,'state'=>'reserved','identity'=>ApiExecutionMetadataContext::current()]) { throw new RuntimeException('physical_budget_attempt_reused'); }
            return;
        }
        if (self::$used >= self::$limit) {
            throw new ApiBudgetExhaustedException(
                'La ejecución agotó el presupuesto físico de llamadas.',
                gmdate('Y-m-d H:i:s', time() + 60),
                [['scope' => 'app', 'scope_key' => 'queue_v4_cycle_http_safety_fuse']]
            );
        }
        self::$used++;
        self::$attempts[$attemptId] = ['source'=>$source,'state'=>'reserved','identity'=>ApiExecutionMetadataContext::current()];
    }

    public static function enteringTransport(string $attemptId): void
    {
        self::assertActive();
        if ((self::$attempts[$attemptId]['state'] ?? '') !== 'reserved'
            || self::$attempts[$attemptId]['identity'] !== ApiExecutionMetadataContext::current()) { throw new RuntimeException('physical_budget_reservation_required'); }
        self::$attempts[$attemptId]['state'] = 'sent';
    }

    /**
     * Billing's durable authority is its last rejecting fence before cURL.
     * Validate all process-local budget gates before that DB commit, then
     * perform only this non-rejecting state transition immediately afterward.
     */
    public static function assertCanEnterTransport(string $attemptId): void
    {
        self::assertActive();
        if ((self::$attempts[$attemptId]['state'] ?? '') !== 'reserved'
            || self::$attempts[$attemptId]['identity'] !== ApiExecutionMetadataContext::current()) {
            throw new RuntimeException('physical_budget_reservation_required');
        }
    }

    /** This is bookkeeping only; all rejecting gates must already have passed. */
    public static function enteringTransportAfterFinalFence(string $attemptId): void
    {
        if ((self::$attempts[$attemptId]['state'] ?? '') !== 'reserved'
            || self::$attempts[$attemptId]['identity'] !== ApiExecutionMetadataContext::current()) {
            // A process-local invariant violation is not a reason to stop
            // between Financial's committed dispatch and curl_exec(). Keep
            // the dispatch conservative and let the physical attempt proceed.
            self::$stoppedReason ??= 'physical_budget_state_lost_after_final_fence';
            return;
        }
        self::$attempts[$attemptId]['state'] = 'sent';
    }

    public static function releaseBeforeTransport(?string $attemptId = null): bool
    {
        $attemptId ??= (string) (ApiExecutionMetadataContext::current()['transport_request_id'] ?? '');
        if (self::$limit === null || $attemptId === '' || (self::$attempts[$attemptId]['state'] ?? '') !== 'reserved'
            || self::$attempts[$attemptId]['identity'] !== ApiExecutionMetadataContext::current()) { return false; }
        self::$attempts[$attemptId]['state'] = 'cancelled';
        self::$used--;
        OuterCronHttpReceipt::released($attemptId);
        return true;
    }

    public static function stop(string $reason): void
    {
        if (self::$limit !== null) { self::$stoppedReason ??= $reason; }
    }

    public static function exhausted(): bool
    {
        return self::$limit !== null && (self::$used >= self::$limit || self::$stoppedReason !== null
            || (self::$deadline !== null && microtime(true) >= self::$deadline));
    }

    public static function remaining(): int
    {
        if (self::$limit === null) {
            return 0;
        }

        return max(0, self::$limit - self::$used);
    }

    /** @return array{limit:int,used:int,remaining:int,owner:?string,deadline:?float,stopped_reason:?string,physical_http_calls:?int,physical_http_calls_certainty:string,known_physical_calls:int,unresolved_reservations:int} */
    public static function snapshot(): array
    {
        $limit = self::$limit ?? 0;
        $sent = 0;
        $unresolved = 0;
        foreach (self::$attempts as $attempt) {
            if ($attempt['state'] === 'sent') { $sent++; }
            elseif ($attempt['state'] === 'reserved') { $unresolved++; }
        }
        return ['limit' => $limit, 'used' => self::$used, 'remaining' => max(0, $limit - self::$used),
            'owner'=>self::$owner,'deadline'=>self::$deadline,'stopped_reason'=>self::$stoppedReason,
            'physical_http_calls'=>$unresolved > 0 ? null : $sent,
            'physical_http_calls_certainty'=>$unresolved > 0 ? 'UNKNOWN' : 'CERTIFIED',
            'known_physical_calls'=>$sent,'unresolved_reservations'=>$unresolved];
    }
}
