<?php

declare(strict_types=1);

namespace App\Services;

use App\QueueCore\HistoricalBacklogSourceRegistry;
use App\QueueCore\HistoricalSourceClosureService;
use App\QueueCore\QueueEngineControlService;
use PDO;
use RuntimeException;

/** Prepares data-plane rollback only; changing the release pointer remains a separate step. */
final class QueueCoreRollbackService
{
    /** @var \Closure():bool */
    private \Closure $automationStopped;

    /** @param null|callable():bool $automationStopped */
    public function __construct(private readonly PDO $pdo, ?callable $automationStopped = null)
    {
        $this->automationStopped = $automationStopped !== null
            ? \Closure::fromCallable($automationStopped)
            : static fn (): bool => (new EmergencyControlService())->automationStopped();
    }

    /** @return array{ok:bool,issues:list<string>,active_engine:string,generation:int,closed_sources:int,review_sources:int} */
    public function preflight(): array
    {
        $engine = new QueueEngineControlService($this->pdo);
        $snapshot = $engine->snapshot();
        $issues = [];
        if (!(($this->automationStopped)())) {
            $issues[] = 'automation_not_stopped';
        }
        $leases = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM queue_core_execution_leases
             WHERE launcher IS NOT NULL AND expires_at>UTC_TIMESTAMP(3)'
        )->fetchColumn();
        if ($leases > 0) {
            $issues[] = 'runtime_lease_active';
        }
        $uncertain = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM queue_core_jobs
             WHERE dispatch_state='DISPATCHED_RESULT_UNCERTAIN'"
        )->fetchColumn();
        if ($uncertain > 0) {
            $issues[] = 'uncertain_dispatch_present';
        }
        $closed = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM queue_core_historical_receipts WHERE closure_state='closed'"
        )->fetchColumn();
        $review = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM queue_core_historical_receipts WHERE closure_state='review'"
        )->fetchColumn();
        if ($review > 0) {
            $issues[] = 'historical_source_review_present';
        }
        return [
            'ok' => $issues === [],
            'issues' => $issues,
            'active_engine' => $snapshot['active_engine'],
            'generation' => $snapshot['generation'],
            'closed_sources' => $closed,
            'review_sources' => $review,
        ];
    }

    /** @return array{ok:bool,status:string,restored:int,remaining:int,generation:int} */
    public function prepare(int $expectedGeneration, int $limit = 50): array
    {
        $preflight = $this->preflight();
        if (!$preflight['ok']) {
            throw new RuntimeException('Queue Core rollback preflight is blocked: ' . implode(',', $preflight['issues']));
        }
        $engine = new QueueEngineControlService($this->pdo);
        $generation = (int) $preflight['generation'];
        if ($preflight['active_engine'] !== 'disabled') {
            if ($generation !== $expectedGeneration) {
                throw new RuntimeException('Queue Core rollback generation is stale.');
            }
            $disabled = $engine->compareAndSwap('disabled', $expectedGeneration, 'queue_core_rollback');
            if (!$disabled['ok']) {
                throw new RuntimeException('Queue Core engine could not be disabled for rollback.');
            }
            $generation = (int) $disabled['generation'];
        }
        $this->pdo->exec(
            "UPDATE queue_core_historical_checkpoints
             SET enabled=0,state='disabled',generation=generation+1,last_error_class=NULL
             WHERE enabled=1"
        );
        $closure = new HistoricalSourceClosureService($this->pdo, new HistoricalBacklogSourceRegistry());
        $restored = $closure->restoreClosed($limit);
        $remaining = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM queue_core_historical_receipts WHERE closure_state='closed'"
        )->fetchColumn();
        $review = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM queue_core_historical_receipts WHERE closure_state='review'"
        )->fetchColumn();
        return [
            'ok' => $remaining === 0 && $review === 0,
            'status' => $remaining === 0 && $review === 0 ? 'ready_for_code_rollback' : 'restore_incomplete',
            'restored' => $restored['restored'],
            'remaining' => $remaining,
            'generation' => $generation,
        ];
    }
}
