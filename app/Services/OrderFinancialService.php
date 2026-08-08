<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class OrderFinancialService
{
    public function findByOrderId(int $orderId): ?array
    {
        if (!(new SchemaInspectorService())->hasTable('meli_order_financials')) {
            return null;
        }
        $this->assertVisibleOrder($orderId);
        $stmt = Database::connection()->prepare('SELECT * FROM meli_order_financials WHERE meli_order_id=:order LIMIT 1');
        $stmt->execute(['order' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function detailsForOrder(int $orderId): array
    {
        if (!(new SchemaInspectorService())->hasTable('meli_order_billing_details')) {
            return [];
        }
        $this->assertVisibleOrder($orderId);
        $stmt = Database::connection()->prepare('SELECT * FROM meli_order_billing_details WHERE meli_order_id=:order ORDER BY source_status DESC, detail_type, id');
        $stmt->execute(['order' => $orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function queueOrder(int $orderId): void
    {
        $order = $this->order($orderId);
        $this->upsertFinancial($order, [
            'reconciliation_status' => 'queued',
            'billing_status' => 'pending',
            'source' => 'orders_summary',
            'financial_source' => 'local_partial',
            'billing_import_status' => 'pending',
            'safe_message' => 'Conciliacion financiera en cola. El job recalculara primero con datos locales y completara billing solo si falta.',
        ]);
        AuditService::record('queue_financial_reconciliation', 'meli_order_financials', 'meli_order', $orderId, (int) $order['meli_account_id'], null, []);
    }

    public function markManualReview(int $orderId, string $message = 'Revision manual solicitada.'): void
    {
        $order = $this->order($orderId);
        $this->upsertFinancial($order, [
            'reconciliation_status' => 'manual_review',
            'billing_status' => 'manual_review',
            'source' => 'manual_review',
            'financial_source' => 'manual_review',
            'safe_message' => mb_substr($message, 0, 500),
        ]);
        AuditService::record('manual_review_financial', 'meli_order_financials', 'meli_order', $orderId, (int) $order['meli_account_id'], null, ['message' => $message]);
    }

    public function recalculateByOrderId(int $orderId): array
    {
        $order = $this->order($orderId);
        $summary = $this->calculate($order);
        $this->persistSummary($order, $summary);
        return $summary;
    }

    public function processPending(int $limit = 25): array
    {
        if (!(new SchemaInspectorService())->hasTable('meli_order_financials')) {
            throw new RuntimeException('Falta ejecutar la migracion de conciliacion financiera.');
        }
        $limit = max(1, min(100, $limit));
        $stmt = Database::connection()->prepare(
            'SELECT f.meli_order_id
             FROM meli_order_financials f
             WHERE f.reconciliation_status IN ("pending","queued","error")
             ORDER BY FIELD(f.reconciliation_status,"queued","pending","error"), f.updated_at ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $processed = 0;
        $errors = 0;
        foreach ($ids as $orderId) {
            try {
                $this->recalculateByOrderId($orderId);
                $processed++;
            } catch (Throwable $e) {
                $errors++;
                $this->markError($orderId, $e->getMessage());
            }
        }
        return ['processed' => $processed, 'errors' => $errors, 'requested' => count($ids)];
    }

    private function calculate(array $order): array
    {
        $orderId = (int) $order['id'];
        $items = $this->fetchAll('SELECT oi.*, mi.id meli_item_id, ip.id internal_product_id, ip.manual_cost, l.conversion_factor
            FROM meli_order_items oi
            LEFT JOIN meli_items mi ON mi.meli_account_id=oi.meli_account_id AND mi.external_item_id=oi.external_item_id
            LEFT JOIN product_meli_links l ON l.meli_account_id=oi.meli_account_id
                AND l.meli_item_id=mi.id
                AND l.meli_variation_id=COALESCE(oi.external_variation_id,0)
                AND l.status="active"
            LEFT JOIN internal_products ip ON ip.id=l.internal_product_id AND ip.deleted_at IS NULL
            WHERE oi.meli_order_id=:order', ['order' => $orderId]);
        $payments = $this->fetchAll('SELECT * FROM meli_payments WHERE meli_order_id=:order', ['order' => $orderId]);
        $shipments = $this->fetchAll('SELECT * FROM meli_shipments WHERE meli_order_id=:order', ['order' => $orderId]);
        $currentFinancial = $this->findByOrderId($orderId) ?? [];
        $hasConfirmedBilling = $this->hasConfirmedBilling($orderId);
        $enrichmentStatus = (string) ($order['enrichment_status'] ?? 'complete');
        $enrichmentComplete = $enrichmentStatus === 'complete';

        $product = 0.0;
        $saleFee = 0.0;
        $internalCost = 0.0;
        $missingCost = 0;
        $missingLink = 0;
        foreach ($items as $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            $product += $quantity * (float) ($item['unit_price'] ?? 0);
            $saleFee += (float) ($item['sale_fee'] ?? 0);
            $hasLink = !empty($item['internal_product_id']);
            $cost = (float) ($item['manual_cost'] ?? 0);
            if (!$hasLink) {
                $missingLink++;
                $missingCost++;
                continue;
            }
            if ($cost <= 0) {
                $missingCost++;
                continue;
            }
            $internalCost += $quantity * (float) ($item['conversion_factor'] ?? 1) * $cost;
        }

        $approvedPayments = array_values(array_filter($payments, static fn (array $p): bool => (string) ($p['status'] ?? '') === 'approved'));
        $paymentRows = $approvedPayments ?: $payments;
        $buyerShipping = $this->sum($paymentRows, 'shipping_cost');
        $discounts = $this->sum($paymentRows, 'coupon_amount');
        $paymentTotal = $this->sum($paymentRows, 'total_paid_amount');
        $transactionTotal = $this->sum($paymentRows, 'transaction_amount');
        $buyerPaidTotal = max((float) ($order['paid_amount'] ?? 0), $paymentTotal, $transactionTotal, $product + $buyerShipping);

        $shippingCharge = 0.0;
        foreach ($shipments as $shipment) {
            $seller = (float) ($shipment['seller_cost'] ?? 0);
            $gross = (float) ($shipment['gross_cost'] ?? 0);
            $shippingCharge += $seller > 0 ? $seller : $gross;
        }

        $localEstimatedNet = $product - $saleFee - $discounts + ($buyerShipping - $shippingCharge);
        $taxWithholding = 0.0;
        $taxSource = 0.0;
        $taxReteiva = 0.0;
        $taxOther = 0.0;
        $bonifications = 0.0;
        $adjustments = 0.0;

        if ($hasConfirmedBilling) {
            $buyerShipping = (float) ($currentFinancial['buyer_shipping_paid'] ?? $buyerShipping);
            $shippingCharge = (float) ($currentFinancial['ml_shipping_charge'] ?? $shippingCharge);
            $taxWithholding = (float) ($currentFinancial['tax_withholding_amount'] ?? $currentFinancial['withholdings_amount'] ?? 0);
            $taxSource = (float) ($currentFinancial['tax_retention_source_amount'] ?? 0);
            $taxReteiva = (float) ($currentFinancial['tax_reteiva_amount'] ?? 0);
            $taxOther = (float) ($currentFinancial['tax_other_amount'] ?? 0);
            $bonifications = (float) ($currentFinancial['bonifications_amount'] ?? 0);
            $adjustments = (float) ($currentFinancial['adjustments_amount'] ?? 0);
        }

        $shippingNet = $buyerShipping - $shippingCharge;
        $mlNet = $hasConfirmedBilling
            ? (float) ($currentFinancial['billing_reconciled_net_amount'] ?? $currentFinancial['ml_net_amount'] ?? ($product - $saleFee - $discounts + $shippingNet - $taxWithholding + $bonifications - $adjustments))
            : $localEstimatedNet;

        $costComplete = $missingCost === 0 && count($items) > 0;
        $financialComplete = $costComplete && $enrichmentComplete;
        $profit = $financialComplete ? $mlNet - $internalCost : null;
        $margin = $profit !== null && $product > 0 ? ($profit / $product) * 100 : null;
        $rate = $product > 0 ? ($saleFee / $product) * 100 : null;

        $missingFlags = [];
        if (!$hasConfirmedBilling) {
            $missingFlags[] = 'missing_billing';
            if ($shippingCharge > 0 && $buyerShipping <= 0) {
                $missingFlags[] = 'missing_buyer_shipping';
            }
            $missingFlags[] = 'missing_taxes';
        }
        if ($missingCost > 0) {
            $missingFlags[] = 'missing_cost';
        }
        if ($missingLink > 0) {
            $missingFlags[] = 'missing_link';
        }
        if (!$enrichmentComplete) {
            $missingFlags[] = 'missing_enrichment';
        }
        $requiresBilling = !$hasConfirmedBilling && count(array_intersect($missingFlags, ['missing_billing', 'missing_buyer_shipping', 'missing_taxes'])) > 0;
        $status = $enrichmentComplete
            ? ($hasConfirmedBilling ? 'matched' : ($costComplete ? 'calculated' : 'pending'))
            : 'pending';
        $message = !$enrichmentComplete
            ? 'Cálculo provisional: faltan datos de envío o pack por enriquecer.'
            : ($hasConfirmedBilling
                ? 'Neto ML conciliado con billing importado.'
                : ($requiresBilling
                    ? 'Neto local parcial. Faltan datos de billing como envio comprador, impuestos o retenciones.'
                    : ($costComplete ? 'Conciliacion local calculada con datos guardados.' : 'Faltan vinculos o costos internos; utilidad pendiente.')));

        return [
            'product_sold_amount' => round($product, 2),
            'discount_amount' => round($discounts, 2),
            'buyer_shipping_paid' => round($buyerShipping, 2),
            'buyer_paid_total' => round($buyerPaidTotal, 2),
            'sale_fee_amount' => round($saleFee, 2),
            'sale_fee_rate' => $rate !== null ? round($rate, 4) : null,
            'ml_shipping_charge' => round($shippingCharge, 2),
            'shipping_net_amount' => round($shippingNet, 2),
            'other_charges_amount' => 0,
            'bonifications_amount' => round($bonifications, 2),
            'taxes_amount' => round($taxWithholding, 2),
            'withholdings_amount' => round($taxWithholding, 2),
            'tax_withholding_amount' => round($taxWithholding, 2),
            'tax_retention_source_amount' => round($taxSource, 2),
            'tax_reteiva_amount' => round($taxReteiva, 2),
            'tax_other_amount' => round($taxOther, 2),
            'adjustments_amount' => round($adjustments, 2),
            'ml_net_amount' => round($mlNet, 2),
            'local_estimated_net_amount' => round($localEstimatedNet, 2),
            'billing_reconciled_net_amount' => $hasConfirmedBilling ? round($mlNet, 2) : null,
            'reconciliation_difference' => $hasConfirmedBilling ? round($mlNet - $localEstimatedNet, 2) : null,
            'internal_cost_amount' => $costComplete ? round($internalCost, 2) : null,
            'packaging_cost_amount' => 0,
            'other_internal_costs_amount' => 0,
            'erp_profit_amount' => $profit !== null ? round($profit, 2) : null,
            'erp_margin_percent' => $margin !== null ? round($margin, 4) : null,
            'erp_calculated_net' => round($mlNet, 2),
            'difference_amount' => $hasConfirmedBilling ? round($mlNet - $localEstimatedNet, 2) : 0,
            'missing_cost_items_count' => $missingCost,
            'missing_link_items_count' => $missingLink,
            'billing_status' => $hasConfirmedBilling ? 'imported' : ($costComplete ? 'calculated' : 'pending'),
            'reconciliation_status' => $status,
            'source' => $hasConfirmedBilling ? 'billing_job' : 'local_recalculation',
            'financial_source' => $hasConfirmedBilling ? 'billing_order_details' : 'local_partial',
            'billing_import_status' => $hasConfirmedBilling ? 'imported' : ($requiresBilling ? 'billing_needed' : 'not_required'),
            'missing_flags' => $missingFlags,
            'requires_billing' => $requiresBilling,
            'financial_status' => !$enrichmentComplete
                ? 'enrichment_pending'
                : ($hasConfirmedBilling ? 'matched' : ($requiresBilling ? 'local_partial' : ($costComplete ? 'local_calculated' : 'missing_cost'))),
            'safe_message' => $message,
            'raw_summary_json' => json_encode([
                'items' => count($items),
                'payments' => count($payments),
                'shipments' => count($shipments),
                'confirmed_billing' => $hasConfirmedBilling,
                'enrichment_status' => $enrichmentStatus,
                'missing_flags' => $missingFlags,
                'unconfirmed_billing_calls' => false,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'details' => [
                ['type' => 'product', 'subtype' => 'items_total', 'description' => 'Producto vendido', 'amount' => round($product, 2)],
                ['type' => 'sale_fee', 'subtype' => 'order_items_sale_fee', 'description' => 'Comision/cargo por venta reportado en items', 'amount' => -round($saleFee, 2)],
                ['type' => 'shipping', 'subtype' => 'buyer_paid', 'description' => $hasConfirmedBilling ? 'Envio pagado por comprador' : 'Envio pagado por comprador (pendiente billing si aparece en $0)', 'amount' => round($buyerShipping, 2)],
                ['type' => 'shipping', 'subtype' => 'ml_charge', 'description' => 'Cargo Mercado Envios al vendedor', 'amount' => -round($shippingCharge, 2)],
                ['type' => 'tax', 'subtype' => 'retention_source', 'description' => 'Retencion impuesto a la fuente', 'amount' => -round($taxSource, 2)],
                ['type' => 'tax', 'subtype' => 'reteiva', 'description' => 'ReteIVA', 'amount' => -round($taxReteiva, 2)],
                ['type' => 'net', 'subtype' => $hasConfirmedBilling ? 'billing_reconciled' : 'local_partial', 'description' => $hasConfirmedBilling ? 'Neto ML conciliado' : 'Neto local parcial', 'amount' => round($mlNet, 2)],
            ],
        ];
    }

    private function persistSummary(array $order, array $summary): void
    {
        $pdo = Database::connection();
        $this->upsertFinancial($order, $summary);
        $this->updateSmartBillingColumns((int) $order['id'], $summary);
        $financial = $this->findByOrderId((int) $order['id']);
        if (!$financial || !(new SchemaInspectorService())->hasTable('meli_order_billing_details')) {
            return;
        }
        $pdo->prepare('DELETE FROM meli_order_billing_details WHERE meli_order_id=:order AND source_endpoint="local"')->execute(['order' => (int) $order['id']]);
        $detailStmt = $pdo->prepare(
            'INSERT INTO meli_order_billing_details
             (meli_account_id,meli_order_id,meli_order_financial_id,external_order_id,detail_id,detail_type,detail_subtype,description,amount,currency_id,source_endpoint,source_status,raw_json)
             VALUES (:account,:order,:financial,:external,:detail_id,:type,:subtype,:description,:amount,:currency,"local","local",:raw)'
        );
        foreach ($summary['details'] ?? [] as $index => $detail) {
            $detailStmt->execute([
                'account' => (int) $order['meli_account_id'],
                'order' => (int) $order['id'],
                'financial' => (int) $financial['id'],
                'external' => (string) $order['external_order_id'],
                'detail_id' => 'local-' . $index,
                'type' => $detail['type'],
                'subtype' => $detail['subtype'],
                'description' => $detail['description'],
                'amount' => $detail['amount'],
                'currency' => $order['currency_id'] ?? null,
                'raw' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    }

    private function upsertFinancial(array $order, array $values): void
    {
        if (!(new SchemaInspectorService())->hasTable('meli_order_financials')) {
            throw new RuntimeException('Falta ejecutar la migracion de conciliacion financiera.');
        }
        $defaults = [
            'product_sold_amount' => 0, 'discount_amount' => 0, 'buyer_shipping_paid' => 0, 'buyer_paid_total' => 0,
            'sale_fee_amount' => 0, 'sale_fee_rate' => null, 'ml_shipping_charge' => 0, 'shipping_net_amount' => 0,
            'other_charges_amount' => 0, 'bonifications_amount' => 0, 'taxes_amount' => 0, 'withholdings_amount' => 0,
            'adjustments_amount' => 0, 'ml_net_amount' => 0, 'internal_cost_amount' => null, 'packaging_cost_amount' => 0,
            'other_internal_costs_amount' => 0, 'erp_profit_amount' => null, 'erp_margin_percent' => null,
            'erp_calculated_net' => 0, 'difference_amount' => 0, 'missing_cost_items_count' => 0, 'missing_link_items_count' => 0,
            'billing_status' => 'pending', 'reconciliation_status' => 'pending', 'source' => 'orders_summary',
            'safe_message' => null, 'raw_summary_json' => null,
        ];
        $data = array_merge($defaults, $values);
        $sql = 'INSERT INTO meli_order_financials
            (meli_account_id,meli_order_id,external_order_id,currency_id,product_sold_amount,discount_amount,buyer_shipping_paid,buyer_paid_total,sale_fee_amount,sale_fee_rate,ml_shipping_charge,shipping_net_amount,other_charges_amount,bonifications_amount,taxes_amount,withholdings_amount,adjustments_amount,ml_net_amount,internal_cost_amount,packaging_cost_amount,other_internal_costs_amount,erp_profit_amount,erp_margin_percent,erp_calculated_net,difference_amount,missing_cost_items_count,missing_link_items_count,billing_status,reconciliation_status,source,safe_message,last_billing_sync_at,raw_summary_json)
            VALUES (:account,:order,:external,:currency,:product,:discount,:buyer_shipping,:buyer_paid,:sale_fee,:sale_rate,:ml_shipping,:shipping_net,:other_charges,:bonifications,:taxes,:withholdings,:adjustments,:ml_net,:internal_cost,:packaging,:other_internal,:profit,:margin,:erp_net,:difference,:missing_cost,:missing_link,:billing_status,:reconciliation_status,:source,:message,UTC_TIMESTAMP(),:raw)
            ON DUPLICATE KEY UPDATE product_sold_amount=VALUES(product_sold_amount),discount_amount=VALUES(discount_amount),buyer_shipping_paid=VALUES(buyer_shipping_paid),buyer_paid_total=VALUES(buyer_paid_total),sale_fee_amount=VALUES(sale_fee_amount),sale_fee_rate=VALUES(sale_fee_rate),ml_shipping_charge=VALUES(ml_shipping_charge),shipping_net_amount=VALUES(shipping_net_amount),other_charges_amount=VALUES(other_charges_amount),bonifications_amount=VALUES(bonifications_amount),taxes_amount=VALUES(taxes_amount),withholdings_amount=VALUES(withholdings_amount),adjustments_amount=VALUES(adjustments_amount),ml_net_amount=VALUES(ml_net_amount),internal_cost_amount=VALUES(internal_cost_amount),packaging_cost_amount=VALUES(packaging_cost_amount),other_internal_costs_amount=VALUES(other_internal_costs_amount),erp_profit_amount=VALUES(erp_profit_amount),erp_margin_percent=VALUES(erp_margin_percent),erp_calculated_net=VALUES(erp_calculated_net),difference_amount=VALUES(difference_amount),missing_cost_items_count=VALUES(missing_cost_items_count),missing_link_items_count=VALUES(missing_link_items_count),billing_status=VALUES(billing_status),reconciliation_status=VALUES(reconciliation_status),source=VALUES(source),safe_message=VALUES(safe_message),last_billing_sync_at=UTC_TIMESTAMP(),raw_summary_json=VALUES(raw_summary_json)';
        Database::connection()->prepare($sql)->execute([
            'account' => (int) $order['meli_account_id'],
            'order' => (int) $order['id'],
            'external' => (string) $order['external_order_id'],
            'currency' => $order['currency_id'] ?? null,
            'product' => $data['product_sold_amount'],
            'discount' => $data['discount_amount'],
            'buyer_shipping' => $data['buyer_shipping_paid'],
            'buyer_paid' => $data['buyer_paid_total'],
            'sale_fee' => $data['sale_fee_amount'],
            'sale_rate' => $data['sale_fee_rate'],
            'ml_shipping' => $data['ml_shipping_charge'],
            'shipping_net' => $data['shipping_net_amount'],
            'other_charges' => $data['other_charges_amount'],
            'bonifications' => $data['bonifications_amount'],
            'taxes' => $data['taxes_amount'],
            'withholdings' => $data['withholdings_amount'],
            'adjustments' => $data['adjustments_amount'],
            'ml_net' => $data['ml_net_amount'],
            'internal_cost' => $data['internal_cost_amount'],
            'packaging' => $data['packaging_cost_amount'],
            'other_internal' => $data['other_internal_costs_amount'],
            'profit' => $data['erp_profit_amount'],
            'margin' => $data['erp_margin_percent'],
            'erp_net' => $data['erp_calculated_net'],
            'difference' => $data['difference_amount'],
            'missing_cost' => $data['missing_cost_items_count'],
            'missing_link' => $data['missing_link_items_count'],
            'billing_status' => $data['billing_status'],
            'reconciliation_status' => $data['reconciliation_status'],
            'source' => $data['source'],
            'message' => $data['safe_message'],
            'raw' => $data['raw_summary_json'],
        ]);
    }

    private function updateSmartBillingColumns(int $orderId, array $summary): void
    {
        $columns = (new SchemaInspectorService())->columns('meli_order_financials');
        if (!isset($columns['local_estimated_net_amount'])) {
            return;
        }
        $sets = [
            'tax_withholding_amount=:tax_withholding',
            'tax_retention_source_amount=:tax_source',
            'tax_reteiva_amount=:tax_reteiva',
            'tax_other_amount=:tax_other',
            'local_estimated_net_amount=:local_net',
            'billing_reconciled_net_amount=:billing_net',
            'reconciliation_difference=:reconciliation_difference',
            'financial_source=:financial_source',
            'billing_import_status=:billing_import_status',
        ];
        $params = [
            'tax_withholding' => $summary['tax_withholding_amount'] ?? 0,
            'tax_source' => $summary['tax_retention_source_amount'] ?? 0,
            'tax_reteiva' => $summary['tax_reteiva_amount'] ?? 0,
            'tax_other' => $summary['tax_other_amount'] ?? 0,
            'local_net' => $summary['local_estimated_net_amount'] ?? null,
            'billing_net' => $summary['billing_reconciled_net_amount'] ?? null,
            'reconciliation_difference' => $summary['reconciliation_difference'] ?? null,
            'financial_source' => $summary['financial_source'] ?? 'local_partial',
            'billing_import_status' => $summary['billing_import_status'] ?? 'pending',
            'order' => $orderId,
        ];
        if (($summary['billing_import_status'] ?? '') === 'imported') {
            $sets[] = 'billing_imported_at=COALESCE(billing_imported_at,UTC_TIMESTAMP())';
        }
        Database::connection()->prepare('UPDATE meli_order_financials SET ' . implode(',', $sets) . ' WHERE meli_order_id=:order')->execute($params);
    }

    private function hasConfirmedBilling(int $orderId): bool
    {
        if (!(new SchemaInspectorService())->hasTable('meli_order_billing_details')) {
            return false;
        }
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM meli_order_billing_details
             WHERE meli_order_id=:order AND source_status="confirmed" AND source_endpoint <> "local"'
        );
        $stmt->execute(['order' => $orderId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function markError(int $orderId, string $message): void
    {
        try {
            $order = $this->order($orderId);
            $this->upsertFinancial($order, [
                'billing_status' => 'error',
                'reconciliation_status' => 'error',
                'source' => 'billing_job',
                'financial_source' => 'error',
                'billing_import_status' => 'error',
                'safe_message' => mb_substr($message, 0, 500),
            ]);
        } catch (Throwable) {
            Logger::write('error', 'No se pudo marcar error financiero de orden.', ['order_id' => $orderId, 'error' => $message]);
        }
    }

    private function order(int $orderId): array
    {
        $scope = PHP_SAPI === 'cli'
            ? ['sql' => '1=1', 'params' => []]
            : (new BusinessScopeContext())->accountPredicate('meli_account_id');
        $stmt = Database::connection()->prepare(
            'SELECT * FROM meli_orders WHERE id=? AND ' . $scope['sql'] . ' LIMIT 1'
        );
        $stmt->execute(array_merge([$orderId], $scope['params']));
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new RuntimeException('Orden no encontrada.');
        }
        return $order;
    }

    private function assertVisibleOrder(int $orderId): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        (new SaleReadService())->identityForOrder($orderId);
    }

    private function fetchAll(string $sql, array $params): array
    {
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function sum(array $rows, string $column): float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            $total += (float) ($row[$column] ?? 0);
        }
        return $total;
    }
}
