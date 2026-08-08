<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Core\Database;
use App\Services\CronV3;
use App\Services\CronV3ExecutionContext;
use App\Services\MeliApiClient;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Closure;
use InvalidArgumentException;

final class ItemsSearchPageHandler implements CronV3WorkHandler
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
            : static function (WorkEnvelope $work, int $limit, int $offset): array {
                $stmt = Database::connection()->prepare(
                    'SELECT meli_user_id FROM meli_accounts WHERE id=? AND company_id=? LIMIT 1'
                );
                $stmt->execute([$work->meliAccountId, $work->companyId]);
                $sellerId = (int) $stmt->fetchColumn();
                if ($sellerId < 1) {
                    throw new InvalidArgumentException('La cuenta no pertenece a la empresa del trabajo.');
                }

                return (new MeliApiClient($work->meliAccountId))->get(
                    '/users/' . $sellerId . '/items/search',
                    ['offset' => $offset, 'limit' => $limit],
                    ['job_type' => 'items_sync', 'source' => 'cron_v3_remote', 'bulk' => true]
                );
            };
        $this->enqueue = $enqueue !== null
            ? Closure::fromCallable($enqueue)
            : static fn (WorkEnvelope $child): array => CronV3::enqueue($child);
    }

    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $limit = max(1, min(20, (int) ($work->payload['limit'] ?? 20)));
        $offset = max(0, (int) ($work->payload['offset'] ?? 0));
        $page = $context->logicalRemoteCall(fn (): array => ($this->fetchPage)($work, $limit, $offset));
        $rows = is_array($page['results'] ?? null) ? $page['results'] : [];
        $ids = [];
        foreach ($rows as $row) {
            $id = strtoupper(trim((string) (is_array($row) ? ($row['id'] ?? '') : $row)));
            if ($id === '' || preg_match('/^[A-Z]{2,4}[0-9]+$/', $id) !== 1) {
                throw new InvalidArgumentException('La página de publicaciones contiene un identificador inválido.');
            }
            $ids[$id] = true;
        }

        foreach (array_keys($ids) as $id) {
            ($this->enqueue)(WorkEnvelope::create(
                $work->companyId,
                $work->meliAccountId,
                'item_exact',
                'remote',
                'item:' . $id,
                'item:' . $id . ':from:' . $work->inputVersion,
                ['external_item_id' => $id],
                $work->sourceRef ?? ('items-page:' . $offset),
                $work->priority
            ));
        }

        $total = max(0, (int) ($page['paging']['total'] ?? 0));
        $nextOffset = $offset + count($rows);
        if ($rows !== [] && ($total === 0 || $nextOffset < $total)) {
            ($this->enqueue)(WorkEnvelope::create(
                $work->companyId,
                $work->meliAccountId,
                'items_search_page',
                'remote',
                'items-page:' . $nextOffset,
                'items-page:' . $nextOffset . ':from:' . $work->inputVersion,
                $this->nextPayload($work, ['limit' => $limit, 'offset' => $nextOffset]),
                $work->sourceRef ?? 'items-search',
                $work->priority
            ));
        } else {
            $nextOffset = null;
        }

        return WorkResult::completed([
            'offset' => $offset,
            'item_count' => count($ids),
            'next_offset' => $nextOffset,
        ]);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function nextPayload(WorkEnvelope $work, array $payload): array
    {
        foreach (['legacy_queue', 'legacy_job_id', 'job_id', 'source_status', 'source_generation'] as $key) {
            if (array_key_exists($key, $work->payload) && !array_key_exists($key, $payload)) {
                $payload[$key] = $work->payload[$key];
            }
        }

        return $payload;
    }
}
