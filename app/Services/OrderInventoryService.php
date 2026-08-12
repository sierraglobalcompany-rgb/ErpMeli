<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/** Proyección local idempotente de una orden ya persistida por Queue V4 Clean. */
final class OrderInventoryService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array{outcome:string,movements:int,reviews:int} */
    public function project(int $companyId, int $accountId, int $orderId): array
    {
        $order = $this->order($companyId, $accountId, $orderId);
        $status = mb_strtolower(trim((string) ($order['status'] ?? '')));
        $detail = mb_strtolower(trim((string) ($order['status_detail'] ?? '')));

        if ($status === 'cancelled' || $status === 'canceled') {
            return $this->reverse($order);
        }
        if (str_contains($status, 'refund') || str_contains($detail, 'refund')) {
            $this->review($order, null, 'PARTIAL_RETURN_AUTHORITY_REQUIRED', null, null, [
                'status' => $status,
                'status_detail' => $detail,
            ]);
            return ['outcome' => 'review', 'movements' => 0, 'reviews' => 1];
        }
        if ($status !== 'paid') {
            return ['outcome' => 'not_applicable', 'movements' => 0, 'reviews' => 0];
        }

        $warehouse = $this->defaultWarehouse($companyId);
        if ($warehouse === null) {
            $this->review($order, null, 'WAREHOUSE_NOT_CONFIGURED', null, null, []);
            return ['outcome' => 'review', 'movements' => 0, 'reviews' => 1];
        }
        $this->resolveReason($companyId, $accountId, $orderId, 'WAREHOUSE_NOT_CONFIGURED');

        $lines = $this->saleLines($companyId, $accountId, $orderId);
        $specs = [];
        $reviews = 0;
        foreach ($lines as $line) {
            if ((int) ($line['internal_product_id'] ?? 0) < 1) {
                $this->review($order, null, 'UNLINKED_PRODUCT', (string) $line['warehouse_quantity'], null, [
                    'external_item_id' => (string) $line['external_item_id'],
                    'external_variation_id' => $line['external_variation_id'],
                    'seller_sku' => $line['seller_sku'],
                    'active_link_count' => (int) ($line['active_link_count'] ?? 0),
                ], 'unlinked:' . hash('sha256', (string) $line['line_identity']));
                $reviews++;
                continue;
            }
            $productId = (int) $line['internal_product_id'];
            $specs[] = [
                'company_id' => $companyId,
                'warehouse_id' => (int) $warehouse['id'],
                'internal_product_id' => $productId,
                'meli_account_id' => $accountId,
                'movement_type' => 'sale_issue',
                'quantity' => (string) $line['warehouse_quantity'],
                'unit_cost' => '0',
                'reference_type' => 'meli_order',
                'reference_id' => (string) $order['external_order_id'],
                'idempotency_key' => 'ml-sale:' . $accountId . ':' . $order['external_order_id'] . ':' . $productId,
                'source' => 'queue_v4_clean',
                'reason' => 'Salida automática por venta pagada.',
            ];
        }

        try {
            $movements = (new InventoryLedgerService($this->pdo))->applyBatch($specs);
        } catch (InventoryInsufficientStock $e) {
            $this->review(
                $order,
                $e->productId,
                'INSUFFICIENT_STOCK',
                $e->required,
                $e->available,
                ['warehouse_id' => $e->warehouseId]
            );
            return ['outcome' => 'review', 'movements' => 0, 'reviews' => $reviews + 1];
        }

        foreach ($movements as $movement) {
            $this->resolveReviews($companyId, $accountId, $orderId, (int) $movement['internal_product_id']);
        }
        if (!array_filter($lines, static fn(array $line): bool => (int) ($line['internal_product_id'] ?? 0) < 1)) {
            $this->resolveReason($companyId, $accountId, $orderId, 'UNLINKED_PRODUCT');
        }
        return [
            'outcome' => ($movements === [] && $reviews > 0) ? 'review' : 'applied',
            'movements' => count($movements),
            'reviews' => $reviews,
        ];
    }

    /** @param array<string,mixed> $order */
    private function reverse(array $order): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.*
             FROM inventory_movements m
             WHERE m.company_id=? AND m.meli_account_id=?
               AND m.reference_type="meli_order" AND m.reference_id=?
               AND m.movement_type="sale_issue"
             ORDER BY m.warehouse_id,m.internal_product_id,m.id'
        );
        $stmt->execute([
            (int) $order['company_id'],
            (int) $order['meli_account_id'],
            (string) $order['external_order_id'],
        ]);
        $specs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $issue) {
            $specs[] = [
                'company_id' => (int) $issue['company_id'],
                'warehouse_id' => (int) $issue['warehouse_id'],
                'internal_product_id' => (int) $issue['internal_product_id'],
                'meli_account_id' => (int) $issue['meli_account_id'],
                'movement_type' => 'sale_reversal',
                'quantity' => ltrim((string) $issue['on_hand_delta'], '-'),
                'unit_cost' => (string) $issue['unit_cost'],
                'reference_type' => 'meli_order',
                'reference_id' => (string) $order['external_order_id'],
                'idempotency_key' => 'ml-reversal:' . $issue['id'],
                'reversal_of_movement_id' => (int) $issue['id'],
                'source' => 'queue_v4_clean',
                'reason' => 'Reversión automática por orden cancelada.',
            ];
        }
        $movements = (new InventoryLedgerService($this->pdo))->applyBatch($specs);
        return ['outcome' => 'reversed', 'movements' => count($movements), 'reviews' => 0];
    }

    /** @return array<string,mixed> */
    private function order(int $companyId, int $accountId, int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.id,o.meli_account_id,o.external_order_id,o.external_pack_id,o.status,o.status_detail,a.company_id
             FROM meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=?
             WHERE o.id=? AND o.meli_account_id=? LIMIT 1'
        );
        $stmt->execute([$companyId, $orderId, $accountId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($order)) {
            throw new RuntimeException('La orden local no pertenece a la empresa y cuenta indicadas.');
        }
        return $order;
    }

    /** @return array<string,mixed>|null */
    private function defaultWarehouse(int $companyId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id,company_id,code,name
             FROM inventory_warehouses
             WHERE company_id=? AND status="active" AND is_default=1
             ORDER BY id LIMIT 2'
        );
        $stmt->execute([$companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return count($rows) === 1 ? $rows[0] : null;
    }

    /** @return list<array<string,mixed>> */
    private function saleLines(int $companyId, int $accountId, int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT oi.external_item_id,oi.external_variation_id,MAX(oi.seller_sku) seller_sku,
                    l.internal_product_id,
                    CAST(SUM(oi.quantity*COALESCE(l.conversion_factor,1)) AS DECIMAL(20,6)) warehouse_quantity,
                    CONCAT(oi.external_item_id,":",COALESCE(oi.external_variation_id,0)) line_identity,
                    COALESCE(l.active_link_count,0) active_link_count
             FROM meli_order_items oi
             JOIN meli_orders o ON o.id=oi.meli_order_id AND o.meli_account_id=oi.meli_account_id
             JOIN meli_accounts a ON a.id=oi.meli_account_id AND a.company_id=?
             LEFT JOIN meli_items mi
               ON mi.meli_account_id=oi.meli_account_id AND mi.external_item_id=oi.external_item_id
             LEFT JOIN (
               SELECT meli_account_id,meli_item_id,meli_variation_id,
                      CASE WHEN COUNT(*)=1 THEN MAX(internal_product_id) ELSE NULL END internal_product_id,
                      CASE WHEN COUNT(*)=1 THEN MAX(conversion_factor) ELSE NULL END conversion_factor,
                      COUNT(*) active_link_count
               FROM product_meli_links
               WHERE status="active"
               GROUP BY meli_account_id,meli_item_id,meli_variation_id
             ) l ON l.meli_account_id=oi.meli_account_id
                AND l.meli_item_id=mi.id
                AND l.meli_variation_id=COALESCE(oi.external_variation_id,0)
             WHERE oi.meli_order_id=? AND oi.meli_account_id=?
             GROUP BY oi.external_item_id,oi.external_variation_id,l.internal_product_id,l.active_link_count
             ORDER BY oi.external_item_id,oi.external_variation_id,l.internal_product_id'
        );
        $stmt->execute([$companyId, $orderId, $accountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $order @param array<string,mixed> $context */
    private function review(
        array $order,
        ?int $productId,
        string $reason,
        ?string $required,
        ?string $available,
        array $context,
        string $suffix = ''
    ): void {
        $base = 'inventory-review:' . $order['meli_account_id'] . ':' . $order['external_order_id']
            . ':' . $reason . ':' . ($productId ?? 0);
        $key = mb_substr($base . ($suffix !== '' ? ':' . $suffix : ''), 0, 191);
        $stmt = $this->pdo->prepare(
            'INSERT INTO inventory_reviews
                (company_id,meli_account_id,meli_order_id,external_order_id,internal_product_id,
                 reason_code,state,required_quantity,available_quantity,context_json,idempotency_key)
             VALUES (?,?,?,?,?,?,"open",?,?,?,?)
             ON DUPLICATE KEY UPDATE
                state=IF(state="dismissed",state,"open"),required_quantity=VALUES(required_quantity),
                available_quantity=VALUES(available_quantity),context_json=VALUES(context_json),updated_at=UTC_TIMESTAMP(3)'
        );
        $stmt->execute([
            (int) $order['company_id'], (int) $order['meli_account_id'], (int) $order['id'],
            (string) $order['external_order_id'], $productId, $reason, $required, $available,
            json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $key,
        ]);
    }

    private function resolveReviews(int $companyId, int $accountId, int $orderId, int $productId): void
    {
        $this->pdo->prepare(
            'UPDATE inventory_reviews
             SET state="resolved",resolution="movement_applied",resolved_at=UTC_TIMESTAMP(3)
             WHERE company_id=? AND meli_account_id=? AND meli_order_id=?
               AND internal_product_id=? AND state="open"'
        )->execute([$companyId, $accountId, $orderId, $productId]);
    }

    private function resolveReason(int $companyId, int $accountId, int $orderId, string $reason): void
    {
        $this->pdo->prepare(
            'UPDATE inventory_reviews
             SET state="resolved",resolution="authority_now_satisfied",resolved_at=UTC_TIMESTAMP(3)
             WHERE company_id=? AND meli_account_id=? AND meli_order_id=?
               AND reason_code=? AND state="open"'
        )->execute([$companyId, $accountId, $orderId, $reason]);
    }
}
