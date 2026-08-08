<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Services\ClaimSyncService;
use App\Services\CronV3;
use App\Services\CronV3ExecutionContext;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Closure;
use InvalidArgumentException;

final class ClaimsSearchPageHandler implements CronV3WorkHandler
{
    /** @var Closure(WorkEnvelope,int,int):array<string,mixed> */
    private readonly Closure $fetchPage;

    /** @var Closure(WorkEnvelope):mixed */
    private readonly Closure $enqueue;

    /**
     * @param null|callable(WorkEnvelope,int,int):array<string,mixed> $fetchPage
     * @param null|callable(WorkEnvelope):mixed $enqueue
     */
    public function __construct(?callable $fetchPage = null, ?callable $enqueue = null)
    {
        $this->fetchPage = $fetchPage !== null
            ? Closure::fromCallable($fetchPage)
            : static fn (WorkEnvelope $work, int $limit, int $offset): array =>
                (new ClaimSyncService($work->meliAccountId))->fetchOpenedPage($limit, $offset);
        $this->enqueue = $enqueue !== null
            ? Closure::fromCallable($enqueue)
            : static fn (WorkEnvelope $child): array => CronV3::enqueue($child);
    }

    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $limit = max(1, min(20, (int) ($work->payload['limit'] ?? 20)));
        $offset = max(0, (int) ($work->payload['offset'] ?? 0));
        $page = $context->logicalRemoteCall(
            fn (): array => ($this->fetchPage)($work, $limit, $offset)
        );

        $claimIds = array_values(array_unique(array_map(
            static fn (mixed $id): string => trim((string) $id),
            (array) ($page['claim_ids'] ?? $page['claimIds'] ?? [])
        )));
        $enqueued = 0;
        foreach ($claimIds as $claimId) {
            if ($claimId === '' || preg_match('/^[0-9]+$/', $claimId) !== 1) {
                throw new InvalidArgumentException('La página de reclamos contiene un identificador inválido.');
            }
            ($this->enqueue)(WorkEnvelope::create(
                $work->companyId,
                $work->meliAccountId,
                'claim_exact',
                'remote',
                'claim:' . $claimId,
                'claim:' . $claimId . ':from:' . $work->inputVersion,
                ['claim_id' => $claimId],
                $work->sourceRef ?? ('claims-page:' . $offset),
                $work->priority
            ));
            $enqueued++;
        }

        $nextOffset = $page['next_offset'] ?? $page['nextOffset'] ?? null;
        if ($nextOffset !== null) {
            $nextOffset = max($offset + $limit, (int) $nextOffset);
            ($this->enqueue)(WorkEnvelope::create(
                $work->companyId,
                $work->meliAccountId,
                'claims_search_page',
                'remote',
                'claims-opened-page:' . $nextOffset,
                'claims-opened:' . $nextOffset . ':from:' . $work->inputVersion,
                ['limit' => $limit, 'offset' => $nextOffset],
                $work->sourceRef ?? 'claims-opened',
                $work->priority
            ));
        }

        return WorkResult::completed([
            'offset' => $offset,
            'claim_count' => count($claimIds),
            'claim_jobs_enqueued' => $enqueued,
            'next_offset' => $nextOffset,
        ]);
    }
}
