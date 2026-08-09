<?php

declare(strict_types=1);

namespace App\Services;

use App\QueueCore\HistoricalBacklogSourceRegistry;
use App\QueueCore\HistoricalSourceClosureService;
use App\QueueCore\QueueEngineControlService;
use App\QueueCore\WebhookSpoolLifecycleService;
use PDO;
use RuntimeException;

/** Prepares data-plane rollback only; changing the release pointer remains a separate step. */
final class QueueCoreRollbackService
{
    /** @var \Closure():bool */
    private \Closure $automationStopped;

    /** @var \Closure(int):array{replayed:int,errors:int,remaining:int} */
    private \Closure $webhookReplay;

    /**
     * @param null|callable():bool $automationStopped
     * @param null|callable(int):array{replayed:int,errors:int,remaining:int} $webhookReplay
     */
    public function __construct(
        private readonly PDO $pdo,
        ?callable $automationStopped = null,
        ?callable $webhookReplay = null,
    ) {
        $this->automationStopped = $automationStopped !== null
            ? \Closure::fromCallable($automationStopped)
            : static fn (): bool => (new EmergencyControlService())->automationStopped();
        $this->webhookReplay = $webhookReplay !== null
            ? \Closure::fromCallable($webhookReplay)
            : function (int $limit): array {
                $spool = (new WebhookSpoolService())->replay($limit);
                $used = max(0, (int) ($spool['processed'] ?? 0) + (int) ($spool['quarantined'] ?? 0));
                $lifecycle = new WebhookSpoolLifecycleService($this->pdo);
                $remainingBudget = max(0, $limit - $used);
                $durable = $remainingBudget > 0
                    ? $lifecycle->replayUnresolvedToLegacy($remainingBudget)
                    : ['replayed' => 0, 'errors' => 0, 'remaining' => $lifecycle->unresolvedCount()];
                return [
                    'replayed' => $used + (int) $durable['replayed'],
                    'errors' => (int) ($spool['errors'] ?? 0) + (int) $durable['errors'],
                    'remaining' => (new WebhookSpoolService())->pendingCount()
                        + (int) $durable['remaining'],
                ];
            };
    }

    /** @return array{ok:bool,issues:list<string>,active_engine:string,generation:int,closed_sources:int,review_sources:int,unresolved_webhooks:int} */
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
        $unresolvedWebhooks = (new WebhookSpoolService())->pendingCount()
            + (new WebhookSpoolLifecycleService($this->pdo))->unresolvedCount();
        return [
            'ok' => $issues === [],
            'issues' => $issues,
            'active_engine' => $snapshot['active_engine'],
            'generation' => $snapshot['generation'],
            'closed_sources' => $closed,
            'review_sources' => $review,
            'unresolved_webhooks' => $unresolvedWebhooks,
        ];
    }

    /** @return array{ok:bool,status:string,restored:int,remaining:int,generation:int,webhooks_replayed:int,webhooks_remaining:int,webhook_errors:int} */
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
        $webhooks = ($this->webhookReplay)(max(1, min(500, $limit)));
        $closure = new HistoricalSourceClosureService($this->pdo, new HistoricalBacklogSourceRegistry());
        $restored = $closure->restoreClosed($limit);
        $remaining = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM queue_core_historical_receipts WHERE closure_state='closed'"
        )->fetchColumn();
        $review = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM queue_core_historical_receipts WHERE closure_state='review'"
        )->fetchColumn();
        $webhooksRemaining = max(0, $webhooks['remaining']);
        $webhookErrors = max(0, $webhooks['errors']);
        $ready = $remaining === 0 && $review === 0
            && $webhooksRemaining === 0 && $webhookErrors === 0;
        return [
            'ok' => $ready,
            'status' => $ready ? 'ready_for_code_rollback' : 'restore_incomplete',
            'restored' => $restored['restored'],
            'remaining' => $remaining,
            'generation' => $generation,
            'webhooks_replayed' => max(0, $webhooks['replayed']),
            'webhooks_remaining' => $webhooksRemaining,
            'webhook_errors' => $webhookErrors,
        ];
    }
}
