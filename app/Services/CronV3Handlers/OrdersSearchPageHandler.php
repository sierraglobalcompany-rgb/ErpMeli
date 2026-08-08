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
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

final class OrdersSearchPageHandler implements CronV3WorkHandler
{
    /** @var Closure(WorkEnvelope,int,int,string,string):array<string,mixed> */
    private readonly Closure $fetchPage;

    /** @var Closure(WorkEnvelope):mixed */
    private readonly Closure $enqueue;

    /**
     * @param null|callable(WorkEnvelope,int,int,string,string):array<string,mixed> $fetchPage
     * @param null|callable(WorkEnvelope):mixed $enqueue
     */
    public function __construct(?callable $fetchPage = null, ?callable $enqueue = null)
    {
        $this->fetchPage = $fetchPage !== null
            ? Closure::fromCallable($fetchPage)
            : static function (WorkEnvelope $work, int $limit, int $offset, string $from, string $to): array {
                $stmt = Database::connection()->prepare(
                    'SELECT meli_user_id FROM meli_accounts WHERE id=? AND company_id=? LIMIT 1'
                );
                $stmt->execute([$work->meliAccountId, $work->companyId]);
                $sellerId = (int) $stmt->fetchColumn();
                if ($sellerId < 1) {
                    throw new InvalidArgumentException('La cuenta no pertenece a la empresa del trabajo.');
                }

                return (new MeliApiClient($work->meliAccountId))->get('/orders/search', [
                    'seller' => $sellerId,
                    'order.date_created.from' => $from,
                    'order.date_created.to' => $to,
                    'sort' => 'date_desc',
                    'offset' => $offset,
                    'limit' => $limit,
                ], ['job_type' => 'orders_sync', 'source' => 'cron_v3_remote', 'bulk' => true]);
            };
        $this->enqueue = $enqueue !== null
            ? Closure::fromCallable($enqueue)
            : static fn (WorkEnvelope $child): array => CronV3::enqueue($child);
    }

    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $limit = max(1, min(20, (int) ($work->payload['limit'] ?? 20)));
        $offset = max(0, (int) ($work->payload['offset'] ?? 0));
        $from = $this->date((string) ($work->payload['date_from'] ?? $work->payload['from'] ?? ''), 'date_from');
        $to = $this->date((string) ($work->payload['date_to'] ?? $work->payload['to'] ?? ''), 'date_to');
        if (strtotime($from) >= strtotime($to)) {
            throw new InvalidArgumentException('orders_search_page requiere un rango semiabierto válido.');
        }

        $page = $context->logicalRemoteCall(
            fn (): array => ($this->fetchPage)($work, $limit, $offset, $from, $to)
        );
        $results = is_array($page['results'] ?? null) ? $page['results'] : [];
        $ids = [];
        foreach ($results as $row) {
            $id = trim((string) (is_array($row) ? ($row['id'] ?? '') : $row));
            if ($id === '' || preg_match('/^[0-9]+$/', $id) !== 1) {
                throw new InvalidArgumentException('La página de órdenes contiene un identificador inválido.');
            }
            $ids[$id] = true;
        }

        foreach (array_keys($ids) as $id) {
            ($this->enqueue)(WorkEnvelope::create(
                $work->companyId,
                $work->meliAccountId,
                'order_exact',
                'remote',
                'order:' . $id,
                'order:' . $id . ':from:' . $work->inputVersion,
                ['external_order_id' => $id],
                $work->sourceRef ?? ('orders-page:' . $offset),
                $work->priority
            ));
        }

        $total = max(0, (int) ($page['paging']['total'] ?? 0));
        $nextOffset = $offset + count($results);
        if ($results !== [] && ($total === 0 || $nextOffset < $total)) {
            ($this->enqueue)(WorkEnvelope::create(
                $work->companyId,
                $work->meliAccountId,
                'orders_search_page',
                'remote',
                'orders-page:' . $from . ':' . $to . ':' . $nextOffset,
                'orders-page:' . $nextOffset . ':from:' . $work->inputVersion,
                $this->nextPayload($work, [
                    'date_from' => $from,
                    'date_to' => $to,
                    'limit' => $limit,
                    'offset' => $nextOffset,
                ]),
                $work->sourceRef ?? 'orders-search',
                $work->priority
            ));
        } else {
            $nextOffset = null;
        }

        return WorkResult::completed([
            'offset' => $offset,
            'order_count' => count($ids),
            'next_offset' => $nextOffset,
        ]);
    }

    private function date(string $value, string $field): string
    {
        try {
            return (new DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (\Throwable) {
            throw new InvalidArgumentException('orders_search_page requiere ' . $field . ' ISO-8601.');
        }
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
