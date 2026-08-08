<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class ClaimSyncService
{
    private MeliApiClient $api;

    public function __construct(private readonly int $accountId)
    {
        $this->api = new MeliApiClient($accountId);
    }

    public function listLocal(array $filters = []): array
    {
        $accountIds = (new BusinessScopeContext())->accountIds();
        $requested = (int) ($filters['account_id'] ?? 0);
        if ($requested > 0) {
            $accountIds = in_array($requested, $accountIds, true) ? [$requested] : [];
        }
        $where = [];
        $params = [];
        if ($accountIds === []) {
            $where[] = '1=0';
        } else {
            $names = [];
            foreach ($accountIds as $index => $accountId) {
                $name = 'scope_account_' . $index;
                $names[] = ':' . $name;
                $params[$name] = $accountId;
            }
            $where[] = 'c.meli_account_id IN (' . implode(',', $names) . ')';
        }
        foreach (['type' => 'c.type', 'stage' => 'c.stage', 'status' => 'c.status', 'reason_id' => 'c.reason_id'] as $key => $column) {
            if (($filters[$key] ?? '') !== '') {
                $where[] = $column . '=:' . $key;
                $params[$key] = (string) $filters[$key];
            }
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['from'] ?? ''))) {
            $where[] = 'c.opened_at>=:from';
            $params['from'] = $filters['from'] . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['to'] ?? ''))) {
            $where[] = 'c.opened_at<=:to';
            $params['to'] = $filters['to'] . ' 23:59:59';
        }
        $stmt = Database::connection()->prepare(
            'SELECT c.*, a.account_name, o.external_order_id linked_order
             FROM meli_claims c
             JOIN meli_accounts a ON a.id=c.meli_account_id
             LEFT JOIN meli_orders o ON o.id=c.meli_order_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY COALESCE(c.opened_at,c.synced_at,c.created_at) DESC LIMIT 200'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Executes exactly one remote search page. Exact claim details are separate
     * work items so a page can never create an HTTP 1+N burst.
     *
     * @return array{claims:list<array<string,mixed>>,claim_ids:list<string>,offset:int,next_offset:?int,total:?int}
     */
    public function fetchOpenedPage(int $limit = 20, int $offset = 0): array
    {
        $path = '/post-purchase/v1/claims/search';
        if (!MeliEndpointRegistry::isConfirmed('GET', $path)) {
            throw new \RuntimeException('El endpoint de búsqueda de reclamos no está confirmado.');
        }
        $limit = max(1, min(20, $limit));
        $offset = max(0, $offset);
        $settings = new AppSettingsService();
        $params = ['status' => 'opened', 'limit' => $limit, 'offset' => $offset];
        if ($settings->bool('claims.use_player_filters', true)) {
            $sellerId = $this->sellerId();
            if ($sellerId > 0) {
                $params['players.user_id'] = $sellerId;
                $params['players.role'] = 'respondent';
            }
        }

        $page = $this->api->get(
            $path,
            $params,
            ['job_type' => 'claims_search_page', 'bulk' => true]
        );
        $claims = is_array($page['data'] ?? null)
            ? $page['data']
            : (is_array($page['claims'] ?? null) ? $page['claims'] : []);
        $claims = array_values(array_filter($claims, 'is_array'));
        $claimIds = [];
        foreach ($claims as $claim) {
            $claimId = trim((string) ($claim['id'] ?? $claim['claim_id'] ?? ''));
            if ($claimId !== '') {
                $claimIds[] = $claimId;
            }
        }
        $claimIds = array_values(array_unique($claimIds));
        $totalValue = $page['paging']['total'] ?? $page['total'] ?? null;
        $total = is_numeric($totalValue) ? max(0, (int) $totalValue) : null;
        $nextOffset = count($claims) === $limit && ($total === null || $offset + $limit < $total)
            ? $offset + $limit
            : null;

        return compact('claims', 'claimIds', 'offset', 'nextOffset', 'total') + [
            'claim_ids' => $claimIds,
            'next_offset' => $nextOffset,
        ];
    }

    /**
     * Legacy compatibility: one search request and local summary persistence.
     * Cron V3 schedules each exact claim as an independent remote work item.
     */
    public function syncOpened(int $limit = 20): int
    {
        $lock = new SyncLockService();
        $runs = new SyncRunService();
        $lockId = $lock->acquire($this->accountId, 'claims', null, null, 20);
        $runId = $runs->start($this->accountId, 'claims');
        $count = 0;
        try {
            $page = $this->fetchOpenedPage($limit, 0);
            foreach ($page['claims'] as $claim) {
                if ((int) ($claim['id'] ?? $claim['claim_id'] ?? 0) < 1) {
                    continue;
                }
                $claim['detail'] = null;
                $this->persist($claim);
                $count++;
            }
            $status = $page['next_offset'] === null ? 'complete' : 'partial';
            Database::connection()->prepare('INSERT INTO meli_sync_offsets (meli_account_id,sync_type,last_synced_at,status) VALUES (:account,"claims",NOW(),:status) ON DUPLICATE KEY UPDATE last_synced_at=NOW(),status=VALUES(status),error_message=NULL')
                ->execute(['account' => $this->accountId, 'status' => $status]);
            $runs->succeed($runId, $count);
            return $count;
        } catch (Throwable $e) {
            $safe = SafeErrorPresenter::report($e, 'No fue posible completar la sincronización de reclamos.', [
                'module' => 'claims',
                'account_id' => $this->accountId,
            ]);
            $runs->fail($runId, $count, $safe['message']);
            throw $e;
        } finally {
            $lock->release($lockId);
        }
    }

    public function syncClaimById(int|string $claimId): int
    {
        $path = '/post-purchase/v1/claims/' . rawurlencode((string) $claimId);
        if (!MeliEndpointRegistry::isConfirmed('GET', $path)) {
            throw new \RuntimeException('El endpoint exacto de reclamos no está confirmado.');
        }
        $detail = $this->api->get($path, [], ['job_type' => 'claims']);
        // El evento exige solamente el recurso actual confirmado. Los
        // subrecursos opcionales permanecen detrás de capability checks.
        $detail['detail'] = null;
        return $this->persist($detail);
    }

    private function persist(array $claim): int
    {
        $external = (int) ($claim['id'] ?? $claim['claim_id'] ?? 0);
        $resource = (string) ($claim['resource'] ?? '');
        $externalOrder = $claim['order_id'] ?? null;
        if (!$externalOrder && preg_match('~/orders/(\d+)~', $resource, $m)) {
            $externalOrder = $m[1];
        }
        $orderId = null;
        if ($externalOrder) {
            $stmt = Database::connection()->prepare('SELECT id FROM meli_orders WHERE meli_account_id=:account AND external_order_id=:external LIMIT 1');
            $stmt->execute(['account' => $this->accountId, 'external' => $externalOrder]);
            $orderId = $stmt->fetchColumn() ?: null;
        }
        $stmt = Database::connection()->prepare(
            'INSERT INTO meli_claims (meli_account_id,external_claim_id,external_order_id,meli_order_id,type,stage,status,reason_id,reason_name,resource,opened_at,closed_at,raw_json,detail_json,synced_at)
             VALUES (:account,:external,:order_external,:order_id,:type,:stage,:status,:reason_id,:reason_name,:resource,:opened,:closed,:raw,:detail,NOW())
             ON DUPLICATE KEY UPDATE external_order_id=VALUES(external_order_id),meli_order_id=VALUES(meli_order_id),type=VALUES(type),stage=VALUES(stage),status=VALUES(status),reason_id=VALUES(reason_id),reason_name=VALUES(reason_name),resource=VALUES(resource),opened_at=VALUES(opened_at),closed_at=VALUES(closed_at),raw_json=VALUES(raw_json),detail_json=VALUES(detail_json),synced_at=NOW(),id=LAST_INSERT_ID(id)'
        );
        $stmt->execute([
            'account' => $this->accountId,
            'external' => $external,
            'order_external' => $externalOrder,
            'order_id' => $orderId,
            'type' => $claim['type'] ?? null,
            'stage' => $claim['stage'] ?? null,
            'status' => $claim['status'] ?? null,
            'reason_id' => $claim['reason_id'] ?? ($claim['reason']['id'] ?? null),
            'reason_name' => $claim['reason_name'] ?? ($claim['reason']['name'] ?? null),
            'resource' => $resource ?: null,
            'opened' => $this->date($claim['date_created'] ?? $claim['opened_at'] ?? null),
            'closed' => $this->date($claim['date_closed'] ?? $claim['closed_at'] ?? null),
            'raw' => json_encode($claim, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'detail' => json_encode($claim['detail'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    private function date(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }
        return (new DateTimeImmutable((string) $value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function sellerId(): int
    {
        $stmt = Database::connection()->prepare('SELECT meli_user_id FROM meli_accounts WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $this->accountId]);
        return (int) $stmt->fetchColumn();
    }
}
