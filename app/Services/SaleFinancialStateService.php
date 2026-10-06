<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Autoridad local del estado financiero actual de una venta.
 *
 * Las proyecciones y evidencias son locales. Este servicio nunca usa el
 * transporte de Mercado Libre.
 */
final class SaleFinancialStateService
{
    public function available(): bool
    {
        $schema = new SchemaInspectorService();
        return $schema->hasTable('sale_financial_state')
            && $schema->hasTable('sale_financial_evidence');
    }

    /** @return array<string,mixed> */
    public function projectOrder(int $orderId): array
    {
        $scope = $this->scopeForOrder($orderId);
        (new OrderFinancialService())->recalculateByOrderId($orderId);

        $state = $this->projectSale(
            (int) $scope['company_id'],
            (int) $scope['meli_account_id'],
            (string) $scope['sale_key']
        );
        try {
            (new CronV3ProducerService())->financialLocalProjection(
                (int) $scope['company_id'],
                (int) $scope['meli_account_id'],
                $orderId,
                (string) $scope['sale_key'],
                (string) ($state['input_version'] ?? '')
            );
        } catch (Throwable $error) {
            Logger::write('warning', 'La proyección local terminó, pero no pudo registrarse en Cron V3.', [
                'company_id' => (int) $scope['company_id'],
                'account_id' => (int) $scope['meli_account_id'],
                'order_id' => $orderId,
                'error' => SafeErrorPresenter::message($error, 'Registro Cron V3 pendiente.'),
            ]);
        }

        return $state;
    }

    /**
     * Queue Core local projection with an explicit tenant fence.
     *
     * Unlike projectOrder(), this path deliberately does not publish Cron V3
     * work. Official Billing remains a separate, disabled-by-default remote
     * capability and monthly closes remain immutable.
     *
     * @return array<string,mixed>
     */
    public function projectOrderForQueueCore(int $companyId, int $accountId, int $orderId): array
    {
        $scope = $this->scopeForOrder($orderId);
        if ((int) $scope['company_id'] !== $companyId
            || (int) $scope['meli_account_id'] !== $accountId) {
            throw new RuntimeException('La orden no pertenece al alcance Queue Core indicado.');
        }
        (new OrderFinancialService())->recalculateByOrderId($orderId);
        return $this->projectSale($companyId, $accountId, (string) $scope['sale_key']);
    }

    /** @return array<string,mixed> */
    public function projectSale(int $companyId, int $accountId, string $saleKey): array
    {
        $this->assertScope($companyId, $accountId, $saleKey);
        if (!$this->available()) {
            throw new RuntimeException('Falta ejecutar la migración de Finanzas V3.');
        }

        $orders = $this->orders($companyId, $accountId, $saleKey);
        if ($orders === []) {
            throw new RuntimeException('La venta no tiene órdenes dentro de la empresa y cuenta indicadas.');
        }
        $snapshot = $this->inputSnapshot($accountId, $orders);
        $inputVersion = self::inputVersionFromSnapshot($snapshot);
        $orderIds = array_map(static fn(array $row): int => (int) $row['id'], $orders);
        $financialRows = $this->orderFinancials($companyId, $accountId, $orderIds);
        $products = 0.0;
        $provisionalNet = 0.0;
        $projectedOrders = [];
        foreach ($financialRows as $row) {
            $products += (float) ($row['product_sold_amount'] ?? 0);
            $localNet = $row['local_estimated_net_amount'] ?? $row['ml_net_amount'] ?? null;
            if ($localNet !== null) {
                $provisionalNet += (float) $localNet;
                $projectedOrders[(int) $row['meli_order_id']] = true;
            }
        }

        $missingFlags = [];
        $commercialComplete = true;
        foreach ($orders as $order) {
            if ((string) ($order['status'] ?? '') === '' || (float) ($order['paid_amount'] ?? 0) <= 0) {
                $commercialComplete = false;
            }
        }
        if (!$commercialComplete) {
            $missingFlags[] = 'commercial_incomplete';
        }
        if (count($projectedOrders) !== count($orders)) {
            $missingFlags[] = 'local_projection_missing';
        }

        $logistics = $this->logisticsStatus($accountId, $orders);
        if (in_array($logistics, ['missing', 'partial', 'review'], true)) {
            $missingFlags[] = 'logistics_' . $logistics;
        }
        $provisionalStatus = $projectedOrders === []
            ? 'missing'
            : (count($projectedOrders) === count($orders) ? 'complete' : 'partial');
        $commercialStatus = $commercialComplete ? 'complete' : 'partial';
        $closeId = $this->closedMonthId($companyId, $accountId, $orders);
        $externalSaleId = substr($saleKey, 2);
        $identityType = str_starts_with($saleKey, 'P:') ? 'pack' : 'order';
        $currency = (string) ($orders[0]['currency_id'] ?? 'COP');

        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $currentStmt = $pdo->prepare(
                'SELECT * FROM sale_financial_state
                 WHERE company_id=? AND meli_account_id=? AND sale_key=? FOR UPDATE'
            );
            $currentStmt->execute([$companyId, $accountId, $saleKey]);
            $current = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $sameVersion = is_array($current)
                && hash_equals((string) $current['input_version'], $inputVersion);
            $officialStatus = $sameVersion ? (string) $current['official_status'] : 'missing';
            $officialNet = $sameVersion ? $current['official_net_amount'] : null;
            $officialCapture = $sameVersion ? $current['official_capture_id'] : null;
            $officialAt = $sameVersion ? $current['official_at'] : null;
            $closeImpact = $closeId === null
                ? 'open'
                : ($sameVersion ? 'closed_unchanged' : 'closed_late_evidence');

            $pdo->prepare(
                'INSERT INTO sale_financial_state
                    (company_id,meli_account_id,sale_key,external_sale_id,identity_type,input_version,
                     currency_id,commercial_status,logistics_status,provisional_status,official_status,
                     products_amount,provisional_net_amount,official_net_amount,official_capture_id,
                     missing_flags_json,close_impact,sales_control_close_id,projected_at,official_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),?)
                 ON DUPLICATE KEY UPDATE
                    external_sale_id=VALUES(external_sale_id),identity_type=VALUES(identity_type),
                    input_version=VALUES(input_version),currency_id=VALUES(currency_id),
                    commercial_status=VALUES(commercial_status),logistics_status=VALUES(logistics_status),
                    provisional_status=VALUES(provisional_status),official_status=VALUES(official_status),
                    products_amount=VALUES(products_amount),provisional_net_amount=VALUES(provisional_net_amount),
                    official_net_amount=VALUES(official_net_amount),official_capture_id=VALUES(official_capture_id),
                    missing_flags_json=VALUES(missing_flags_json),close_impact=VALUES(close_impact),
                    sales_control_close_id=VALUES(sales_control_close_id),projected_at=UTC_TIMESTAMP(),
                    official_at=VALUES(official_at)'
            )->execute([
                $companyId, $accountId, $saleKey, $externalSaleId, $identityType, $inputVersion,
                $currency, $commercialStatus, $logistics, $provisionalStatus, $officialStatus,
                round($products, 2), $projectedOrders === [] ? null : round($provisionalNet, 2),
                $officialNet, $officialCapture,
                json_encode(array_values(array_unique($missingFlags)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $closeImpact, $closeId, $officialAt,
            ]);

            $evidence = [
                'orders' => array_map(static fn(array $order): string => (string) $order['external_order_id'], $orders),
                'commercial_status' => $commercialStatus,
                'logistics_status' => $logistics,
                'provisional_status' => $provisionalStatus,
                'products_amount' => round($products, 2),
                'provisional_net_amount' => $projectedOrders === [] ? null : round($provisionalNet, 2),
                'missing_flags' => array_values(array_unique($missingFlags)),
            ];
            $this->insertEvidence(
                $pdo,
                $companyId,
                $accountId,
                $saleKey,
                $inputVersion,
                'local_projection',
                $provisionalStatus,
                null,
                $evidence,
                $projectedOrders === [] ? null : round($provisionalNet, 2),
                null
            );
            if ($closeId !== null && !$sameVersion) {
                $this->insertEvidence(
                    $pdo,
                    $companyId,
                    $accountId,
                    $saleKey,
                    $inputVersion,
                    'late_close_discrepancy',
                    'review',
                    $closeId,
                    ['close_id' => $closeId, 'reason' => 'financial_input_changed_after_close'],
                    $projectedOrders === [] ? null : round($provisionalNet, 2),
                    null
                );
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        return $this->current($companyId, $accountId, $saleKey) + ['order_ids' => $orderIds];
    }

    /** @return array<string,mixed> */
    public function current(int $companyId, int $accountId, string $saleKey): array
    {
        if (!$this->available()) {
            return [];
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT * FROM sale_financial_state
             WHERE company_id=? AND meli_account_id=? AND sale_key=? LIMIT 1'
        );
        $stmt->execute([$companyId, $accountId, $saleKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    public function inputVersionForOrder(int $orderId): string
    {
        $scope = $this->scopeForOrder($orderId);
        $orders = $this->orders(
            (int) $scope['company_id'],
            (int) $scope['meli_account_id'],
            (string) $scope['sale_key']
        );
        return self::inputVersionFromSnapshot($this->inputSnapshot((int) $scope['meli_account_id'], $orders));
    }

    /**
     * Recomputes the canonical version from current source rows. With
     * $forUpdate=true this must be called inside the caller's transaction and
     * locks the order and snapshot rows so the transport authority compares a
     * stable snapshot at its final pre-dispatch fence.
     */
    public function currentInputVersionForSale(int $companyId, int $accountId, string $saleKey, bool $forUpdate = false): string
    {
        $this->assertScope($companyId, $accountId, $saleKey);
        if (!$this->available()) {
            throw new RuntimeException('Falta ejecutar la migración de Finanzas V3.');
        }
        if ($forUpdate && !Database::connectionFresh()->inTransaction()) {
            throw new RuntimeException('La lectura bloqueante de input_version requiere una transacción activa.');
        }

        $orders = $this->orders($companyId, $accountId, $saleKey, $forUpdate);
        if ($orders === []) {
            throw new RuntimeException('La venta no tiene órdenes dentro de la empresa y cuenta indicadas.');
        }
        return self::inputVersionFromSnapshot(
            $this->inputSnapshot($accountId, $orders, $forUpdate)
        );
    }

    /** @param array<string,mixed> $snapshot */
    public static function inputVersionFromSnapshot(array $snapshot): string
    {
        $canonical = self::canonicalize($snapshot);
        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * Persiste evidencia remota inmutable y solo actualiza el estado actual si
     * la captura todavía pertenece a la versión de entrada vigente.
     *
     * @param array<string,mixed> $job
     * @param array<string,mixed> $totals
     */
    public function recordBillingResult(
        PDO $pdo,
        array $job,
        int $captureId,
        array $totals,
        string $status,
        string $message,
        string $responseHash
    ): bool {
        $payload = [
            'capture_id' => $captureId,
            'status' => $status,
            'message' => mb_substr($message, 0, 500),
            'response_hash' => $responseHash,
            'totals' => array_diff_key($totals, ['allocations' => true]),
        ];
        $this->insertEvidence(
            $pdo,
            (int) $job['company_id'],
            (int) $job['meli_account_id'],
            (string) $job['sale_key'],
            (string) $job['input_version'],
            'billing_capture',
            $status,
            $captureId,
            $payload,
            null,
            $status === 'reconciled' ? (float) ($totals['net_amount'] ?? 0) : null
        );

        $current = $pdo->prepare(
            'SELECT id FROM sale_financial_state
             WHERE company_id=? AND meli_account_id=? AND sale_key=? AND input_version=?
             FOR UPDATE'
        );
        $current->execute([
            (int) $job['company_id'], (int) $job['meli_account_id'],
            (string) $job['sale_key'], (string) $job['input_version'],
        ]);
        $stateId = (int) ($current->fetchColumn() ?: 0);
        if ($stateId <= 0) {
            return false;
        }
        $officialStatus = match ($status) {
            'reconciled' => 'complete',
            'partial' => 'partial',
            'review' => 'review',
            default => 'error',
        };
        $pdo->prepare(
            'UPDATE sale_financial_state
             SET official_status=?,official_net_amount=?,unknown_concepts_amount=?,
                 official_capture_id=?,official_at=IF(?="complete",UTC_TIMESTAMP(),official_at)
             WHERE id=?'
        )->execute([
            $officialStatus,
            $status === 'reconciled' ? round((float) ($totals['net_amount'] ?? 0), 2) : null,
            round((float) ($totals['unknown_amount'] ?? 0), 2),
            $captureId,
            $officialStatus,
            $stateId,
        ]);
        return true;
    }

    /**
     * Persiste un checkpoint remoto de una sola orden de Billing. No cambia el
     * estado financiero actual de la venta; la publicación se hace cuando la
     * venta completa reúne todos sus checkpoints exactos.
     *
     * @param array<string,mixed> $job
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed> $metadata
     */
    public function recordBillingOrderCheckpoint(
        PDO $pdo,
        array $job,
        int $captureId,
        string $orderId,
        array $lines,
        string $status,
        string $message,
        string $responseHash,
        int $httpStatus,
        string $responseClass,
        array $metadata
    ): void {
        $payload = [
            'format' => 'billing_order_v2',
            'capture_id' => $captureId,
            'order_id' => $orderId,
            'status' => $status,
            'message' => mb_substr($message, 0, 500),
            'response_hash' => $responseHash,
            'http_status' => $httpStatus,
            'response_class' => $responseClass,
            'request_id' => (string) ($metadata['request_id'] ?? ''),
            'response_item_count' => (int) ($metadata['response_item_count'] ?? 0),
            'lines' => array_map(static fn (array $line): array => [
                'capture_id' => $captureId,
                'external_order_id' => (string) ($line['external_order_id'] ?? ''),
                'detail_id' => $line['detail_id'] ?? null,
                'line_group' => (string) ($line['line_group'] ?? 'other'),
                'line_type' => $line['line_type'] ?? null,
                'line_subtype' => $line['line_subtype'] ?? null,
                'description' => $line['description'] ?? null,
                'amount' => (float) ($line['amount'] ?? 0),
                'direction' => (string) ($line['direction'] ?? 'neutral'),
                'is_shared' => (int) ($line['is_shared'] ?? 0),
                'line_hash' => (string) ($line['line_hash'] ?? ''),
                'occurred_at' => $line['occurred_at'] ?? null,
            ], $lines),
        ];
        $this->insertEvidence(
            $pdo,
            (int) $job['company_id'],
            (int) $job['meli_account_id'],
            (string) $job['sale_key'],
            (string) $job['input_version'],
            'billing_capture',
            $status,
            $captureId,
            $payload,
            null,
            null
        );
    }

    /** @return array<string,mixed> */
    private function scopeForOrder(int $orderId): array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT a.company_id,o.meli_account_id,
                    CONCAT(IF(o.external_pack_id IS NULL,"O:","P:"),COALESCE(o.external_pack_id,o.external_order_id)) sale_key
             FROM meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id
             WHERE o.id=? LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('No se encontró la orden para proyectar su estado financiero.');
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function orders(int $companyId, int $accountId, string $saleKey, bool $forUpdate = false): array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT o.id,o.external_order_id,o.external_pack_id,o.external_shipping_id,o.status,
                    o.total_amount,o.paid_amount,o.currency_id,o.enrichment_status,o.date_created_local,
                    o.date_created
             FROM meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=?
             WHERE o.meli_account_id=?
               AND CONCAT(IF(o.external_pack_id IS NULL,"O:","P:"),COALESCE(o.external_pack_id,o.external_order_id))=?
             ORDER BY o.external_order_id,o.id' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$companyId, $accountId, $saleKey]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<array<string,mixed>> $orders @return array<string,mixed> */
    private function inputSnapshot(int $accountId, array $orders, bool $forUpdate = false): array
    {
        $orderIds = array_map(static fn(array $row): int => (int) $row['id'], $orders);
        if ($orderIds === []) {
            return ['orders' => [], 'items' => [], 'payments' => [], 'shipments' => []];
        }
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $params = array_merge([$accountId], $orderIds);
        $pdo = Database::connectionFresh();
        $items = $pdo->prepare(
            'SELECT meli_order_id,external_item_id,COALESCE(external_variation_id,0) external_variation_id,
                    quantity,unit_price,COALESCE(full_unit_price,0) full_unit_price,sale_fee
             FROM meli_order_items WHERE meli_account_id=? AND meli_order_id IN (' . $placeholders . ')
             ORDER BY meli_order_id,external_item_id,external_variation_id,id' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $items->execute($params);
        $payments = $pdo->prepare(
            'SELECT meli_order_id,external_payment_id,status,status_detail,transaction_amount,shipping_cost,
                    coupon_amount,total_paid_amount,marketplace_fee,date_approved_utc
             FROM meli_payments WHERE meli_account_id=? AND meli_order_id IN (' . $placeholders . ')
             ORDER BY meli_order_id,external_payment_id,id' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $payments->execute($params);
        $shippingIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): string => (string) ($row['external_shipping_id'] ?? ''),
            $orders
        ))));
        $shipments = [];
        if ($shippingIds !== []) {
            $shipmentStmt = $pdo->prepare(
                'SELECT external_shipment_id,status,substatus,logistic_type,gross_cost,seller_cost,buyer_cost,
                        discounts
                 FROM meli_shipments WHERE meli_account_id=? AND external_shipment_id IN ('
                . implode(',', array_fill(0, count($shippingIds), '?')) . ')
                 ORDER BY external_shipment_id,id' . ($forUpdate ? ' FOR UPDATE' : '')
            );
            $shipmentStmt->execute(array_merge([$accountId], $shippingIds));
            $shipments = $shipmentStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return [
            'orders' => $orders,
            'items' => $items->fetchAll(PDO::FETCH_ASSOC),
            'payments' => $payments->fetchAll(PDO::FETCH_ASSOC),
            'shipments' => $shipments,
        ];
    }

    /** @param list<int> $orderIds @return list<array<string,mixed>> */
    private function orderFinancials(int $companyId, int $accountId, array $orderIds): array
    {
        if ($orderIds === [] || !(new SchemaInspectorService())->hasTable('meli_order_financials')) {
            return [];
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT f.* FROM meli_order_financials f
             JOIN meli_orders o ON o.id=f.meli_order_id AND o.meli_account_id=f.meli_account_id
             JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=?
             WHERE f.meli_account_id=? AND f.meli_order_id IN ('
            . implode(',', array_fill(0, count($orderIds), '?')) . ')'
        );
        $stmt->execute(array_merge([$companyId, $accountId], $orderIds));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<array<string,mixed>> $orders */
    private function logisticsStatus(int $accountId, array $orders): string
    {
        $shippingIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): string => (string) ($row['external_shipping_id'] ?? ''),
            $orders
        ))));
        if ($shippingIds === []) {
            return 'not_required';
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT COUNT(DISTINCT external_shipment_id) FROM meli_shipments
             WHERE meli_account_id=? AND external_shipment_id IN ('
            . implode(',', array_fill(0, count($shippingIds), '?')) . ')'
        );
        $stmt->execute(array_merge([$accountId], $shippingIds));
        $found = (int) $stmt->fetchColumn();
        return $found === 0 ? 'missing' : ($found === count($shippingIds) ? 'complete' : 'partial');
    }

    /** @param list<array<string,mixed>> $orders */
    private function closedMonthId(int $companyId, int $accountId, array $orders): ?int
    {
        if (!(new SchemaInspectorService())->hasTable('sales_control_months')) {
            return null;
        }
        $date = (string) ($orders[0]['date_created_local'] ?? $orders[0]['date_created'] ?? '');
        if (preg_match('/^(\d{4})-(\d{2})-/', $date, $matches) !== 1) {
            return null;
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT current_close_id FROM sales_control_months
             WHERE company_id=? AND meli_account_id=? AND period_year=? AND period_month=?
               AND status="closed" AND current_close_id IS NOT NULL LIMIT 1'
        );
        $stmt->execute([$companyId, $accountId, (int) $matches[1], (int) $matches[2]]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        return $id > 0 ? $id : null;
    }

    /** @param array<string,mixed> $payload */
    private function insertEvidence(
        PDO $pdo,
        int $companyId,
        int $accountId,
        string $saleKey,
        string $inputVersion,
        string $type,
        string $status,
        ?int $sourceId,
        array $payload,
        ?float $provisionalNet,
        ?float $officialNet
    ): void {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($json)) {
            throw new RuntimeException('No fue posible serializar la evidencia financiera.');
        }
        $pdo->prepare(
            'INSERT IGNORE INTO sale_financial_evidence
                (company_id,meli_account_id,sale_key,input_version,evidence_type,evidence_status,
                 source_id,payload_hash,provisional_net_amount,official_net_amount,evidence_json)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $companyId, $accountId, $saleKey, $inputVersion, $type, mb_substr($status, 0, 40),
            $sourceId, hash('sha256', $json), $provisionalNet, $officialNet, $json,
        ]);
    }

    private function assertScope(int $companyId, int $accountId, string $saleKey): void
    {
        if ($companyId <= 0 || $accountId <= 0 || preg_match('/^[PO]:\d+$/', $saleKey) !== 1) {
            throw new RuntimeException('El estado financiero requiere empresa, cuenta y venta válidas.');
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT 1 FROM meli_accounts WHERE id=? AND company_id=? LIMIT 1'
        );
        $stmt->execute([$accountId, $companyId]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException('La cuenta no pertenece a la empresa indicada.');
        }
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::canonicalize($child);
        }
        return $value;
    }
}
