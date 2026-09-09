<?php
declare(strict_types=1);

namespace App\Services {
    // Replace only the external policy read. The shared counter/deadline/helper are real.
    final class CapacityPolicyService {
        public static array $policy = ['current'=>55, 'ceiling'=>55];
        public static int $reads = 0;
        public static bool $fail = false;
        public function snapshot(string $module): array {
            if ($module !== 'manual') { throw new \RuntimeException('wrong_policy_module'); }
            self::$reads++;
            if (self::$fail) { throw new \RuntimeException('policy_unavailable'); }
            return self::$policy;
        }
    }
}
namespace {
    require __DIR__ . '/k1b_bootstrap.php';
    use App\QueueV4Clean\QueueV4CleanCycleBudget as B;
    use App\Services\CapacityPolicyService as Policy;
    use App\Services\CronDeadlineContext as Deadline;
    use App\Services\ManualPhysicalCallBudget as Manual;

    function technicalThrows(callable $fn, string $message): void {
        try { $fn(); } catch (RuntimeException $error) {
            k1b_assert(str_contains($error->getMessage(), $message), 'wrong_failure:' . $error->getMessage());
            return;
        }
        throw new RuntimeException('missing_failure:' . $message);
    }
    B::clear(); Deadline::clear();
    $started = microtime(true);
    $result = Manual::withinTechnical(1, static function () use ($started): string {
        $state = B::snapshot();
        k1b_assert($state['owner'] === 'manual' && $state['limit'] === 1, 'technical_call_gets_one_shared_slot');
        k1b_assert($state['deadline'] <= $started + 45.1 && Deadline::deadline() === $state['deadline'], 'technical_deadline_is_shared_and_at_most45');
        B::reserve(str_repeat('a', 40), 'manual_emergency_canary');
        B::enteringTransport(str_repeat('a', 40));
        try { B::reserve(str_repeat('b',40), 'manual_emergency_canary'); throw new LogicException('second_call_must_not_fit'); }
        catch (App\Services\ApiBudgetExhaustedException) {}
        return 'finished';
    });
    k1b_assert($result === 'finished' && B::snapshot()['limit'] === 0 && Deadline::deadline() === null, 'owned_context_is_cleared_after_success');
    technicalThrows(static fn () => Manual::withinTechnical(1, static fn () => throw new RuntimeException('callback_failure')), 'callback_failure');
    k1b_assert(B::snapshot()['limit'] === 0 && Deadline::deadline() === null, 'owned_context_is_cleared_after_failure');

    Policy::$policy = ['current'=>1, 'ceiling'=>55];
    $executed = false;
    technicalThrows(static fn () => Manual::withinTechnical(3, static function () use (&$executed): void { $executed = true; }), 'physical_budget_insufficient');
    k1b_assert(!$executed && B::snapshot()['limit'] === 0, 'three_account_readiness_does_not_partially_run_under_limit1');
    Policy::$policy = ['current'=>55, 'ceiling'=>2];
    technicalThrows(static fn () => Manual::withinTechnical(3, static fn () => null), 'physical_budget_insufficient');
    Policy::$policy = ['current'=>3, 'ceiling'=>55];
    Manual::withinTechnical(3, static function (): void {
        foreach (['c','d','e'] as $char) { B::reserve(str_repeat($char,40), 'queue_v4_clean_readiness'); B::enteringTransport(str_repeat($char,40)); }
        k1b_assert(B::snapshot()['used'] === 3 && B::remaining() === 0, 'three_readiness_accounts_share_one_counter');
    });

    B::start(4, 'automatic', microtime(true) + 12);
    B::reserve(str_repeat('f',40), 'queue_v4_clean'); B::enteringTransport(str_repeat('f',40));
    $before = B::snapshot(); $reads = Policy::$reads;
    Manual::withinTechnical(1, static function (): void {
        k1b_assert(B::snapshot()['owner'] === 'automatic' && B::snapshot()['used'] === 1, 'nested_call_does_not_reset_automatic_owner');
        k1b_assert(Deadline::deadline() <= B::snapshot()['deadline'], 'nested_scope_cannot_extend_parent_deadline');
        B::reserve(str_repeat('1',40), 'queue_v4_clean'); B::enteringTransport(str_repeat('1',40));
    });
    k1b_assert(B::snapshot()['used'] === 2 && B::snapshot()['limit'] === 4 && B::snapshot()['deadline'] === $before['deadline'], 'parent_budget_is_retained_and_charged');
    k1b_assert(Policy::$reads === $reads, 'nested_call_does_not_reload_or_replace_parent_capacity');
    technicalThrows(static fn () => Manual::withinTechnical(3, static fn () => null), 'physical_budget_insufficient');
    k1b_assert(B::snapshot()['used'] === 2, 'insufficient_nested_context_is_not_cleared');
    technicalThrows(static fn () => Manual::withinTechnical(1, static fn () => throw new RuntimeException('nested_failure')), 'nested_failure');
    k1b_assert(B::snapshot()['used'] === 2 && B::snapshot()['owner'] === 'automatic', 'nested_failure_keeps_parent_budget');
    B::stop('remote_429_global_pause');
    technicalThrows(static fn () => Manual::withinTechnical(1, static fn () => null), 'physical_budget_stopped:remote_429_global_pause');
    k1b_assert(B::snapshot()['stopped_reason'] === 'remote_429_global_pause', 'technical_call_cannot_reset_protected_stop');
    B::clear();
    Policy::$fail = true;
    technicalThrows(static fn () => Manual::withinTechnical(1, static fn () => null), 'policy_unavailable');
    k1b_assert(B::snapshot()['limit'] === 0 && Deadline::deadline() === null, 'policy_error_does_not_leave_scope');
    echo "CALLS_TECHNICAL_BUDGET_OK\n";
}
