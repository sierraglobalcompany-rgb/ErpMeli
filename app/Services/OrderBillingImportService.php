<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class OrderBillingImportService
{
    private const ENDPOINT = '/billing/integration/group/ML/order/details';

    /**
     * @param list<int> $meliOrderIds
     * @return array<string,mixed>
     */
    public function importForOrderIds(
        int $companyId,
        int $accountId,
        array $meliOrderIds,
        bool $forceRefresh = false
    ): array
    {
        if ($companyId <= 0 || $accountId <= 0) {
            throw new \InvalidArgumentException('Billing exige empresa y cuenta explícitas.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $meliOrderIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return ['requested' => 0, 'imported_orders' => 0, 'billing_rows' => 0, 'skipped' => 0, 'errors' => 0];
        }

        $schema = new SchemaInspectorService();
        if (!$schema->hasTable('meli_order_billing_details') || !$schema->hasTable('meli_order_financials')) {
            throw new RuntimeException('Falta aplicar la migración financiera/billing.');
        }

        $orders = $this->orders($companyId, $accountId, $ids);
        if ($orders === []) {
            throw new \RuntimeException('Las órdenes solicitadas no pertenecen al alcance de empresa y cuenta indicado.');
        }
        if (count($orders) !== count($ids)) {
            throw new \RuntimeException('Una o más órdenes quedaron fuera del alcance autorizado de Billing.');
        }

        $settings = new AppSettingsService();
        $perRequest = max(1, min(60, $settings->int('financial_recalc.billing_order_ids_per_request', 20)));
        $pauseMs = max(0, min(10000, $settings->int('financial_recalc.pause_between_requests_ms', 800)));
        $reconnectBetweenSteps = $settings->bool('financial_recalc.reconnect_between_steps', true);
        $grouped = [];
        foreach ($orders as $order) {
            if (!$forceRefresh && $this->alreadyImported((int) $order['id'])) {
                $grouped[(int) $order['meli_account_id']][] = $order + ['_skip' => true];
                continue;
            }
            $grouped[(int) $order['meli_account_id']][] = $order;
        }

        $summary = ['requested' => count($ids), 'imported_orders' => 0, 'billing_rows' => 0, 'skipped' => 0, 'errors' => 0];
        foreach ($grouped as $accountId => $accountOrders) {
            foreach (array_chunk($accountOrders, $perRequest) as $chunk) {
                $toRequest = array_values(array_filter($chunk, static fn (array $order): bool => empty($order['_skip'])));
                $summary['skipped'] += count($chunk) - count($toRequest);
                if ($toRequest === []) {
                    continue;
                }
                $externalIds = array_map(static fn (array $order): string => (string) $order['external_order_id'], $toRequest);
                $response = (new MeliApiClient((int) $accountId))->get(
                    self::ENDPOINT,
                    ['order_ids' => implode(',', $externalIds)],
                    [
                        'job_type' => 'billing',
                        'bulk' => true,
                        'estimated_total' => count($externalIds),
                        // Contar órdenes únicas realmente presentes en la
                        // respuesta, no el tamaño solicitado del lote.
                        'response_count_strategy' => 'billing_orders',
                        'expected_resource_ids' => implode(',', $externalIds),
                    ]
                );
                if ($reconnectBetweenSteps) {
                    Database::connectionFresh();
                }
                $rowsByOrder = $this->extractBillingRows($response, $externalIds);
                foreach ($toRequest as $order) {
                    $external = (string) $order['external_order_id'];
                    $rows = $rowsByOrder[$external] ?? [];
                    if ($rows === []) {
                        $this->markUnavailable($order, 'Billing no devolvió líneas reconocibles para esta orden.');
                        $summary['skipped']++;
                        continue;
                    }
                    $stored = $this->storeRows($order, $rows);
                    $this->applySummary($order, $rows);
                    $summary['billing_rows'] += $stored;
                    $summary['imported_orders']++;
                }
                if ($pauseMs > 0) {
                    usleep($pauseMs * 1000);
                    if ($reconnectBetweenSteps) {
                        Database::connectionFresh();
                    }
                }
            }
        }

        return $summary;
    }

    /**
     * @param list<int> $ids
     * @return list<array<string,mixed>>
     */
    private function orders(int $companyId, int $accountId, array $ids): array
    {
        $rows = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = Database::connectionFresh()->prepare(
                'SELECT o.*,f.id AS financial_id
                 FROM meli_orders o
                 LEFT JOIN meli_order_financials f ON f.meli_order_id=o.id
                 JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=?
                 WHERE o.meli_account_id=? AND o.id IN (' . $placeholders . ')'
            );
            $stmt->execute(array_merge([$companyId, $accountId], $chunk));
            $rows = array_merge($rows, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        return $rows;
    }

    private function alreadyImported(int $orderId): bool
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT COUNT(*) FROM meli_order_billing_details
             WHERE meli_order_id=:order_id AND source_endpoint=:endpoint AND source_status="confirmed"'
        );
        $stmt->execute(['order_id' => $orderId, 'endpoint' => self::ENDPOINT]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @param array<string,mixed> $response
     * @param list<string> $externalIds
     * @return array<string,list<array<string,mixed>>>
     */
    private function extractBillingRows(array $response, array $externalIds): array
    {
        $known = array_fill_keys($externalIds, true);
        $rows = [];
        $this->walkBillingNode($response, null, $known, $rows);
        return $rows;
    }

    /**
     * @param mixed $node
     * @param array<string,bool> $known
     * @param array<string,list<array<string,mixed>>> $rows
     */
    private function walkBillingNode(mixed $node, ?string $currentOrderId, array $known, array &$rows): void
    {
        if (!is_array($node)) {
            return;
        }

        if ($this->isList($node)) {
            foreach ($node as $child) {
                $this->walkBillingNode($child, $currentOrderId, $known, $rows);
            }
            return;
        }

        $orderId = $this->findOrderId($node, $known) ?? $currentOrderId;
        if ($orderId !== null && $this->looksLikeBillingLine($node)) {
            $line = $this->normalizeLine($node, $orderId);
            if ($line !== null) {
                $rows[$orderId][] = $line;
            }
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->walkBillingNode($value, $orderId, $known, $rows);
            }
        }
    }

    /** @param array<string,mixed> $node */
    private function findOrderId(array $node, array $known): ?string
    {
        foreach (['order_id', 'orderId', 'external_order_id', 'externalOrderId', 'sale_id', 'saleId', 'id'] as $key) {
            if (isset($node[$key]) && isset($known[(string) $node[$key]])) {
                return (string) $node[$key];
            }
        }
        foreach (['order', 'sale'] as $parentKey) {
            if (isset($node[$parentKey]) && is_array($node[$parentKey])) {
                $found = $this->findOrderId($node[$parentKey], $known);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /** @param array<string,mixed> $node */
    private function looksLikeBillingLine(array $node): bool
    {
        $hasAmount = false;
        foreach (['detail_amount', 'amount', 'total_amount', 'totalAmount', 'value', 'net_amount', 'netAmount'] as $key) {
            if (isset($node[$key]) && is_numeric($node[$key])) {
                $hasAmount = true;
                break;
            }
        }
        if (!$hasAmount) {
            return false;
        }
        foreach (['description', 'concept', 'title', 'type', 'detail_type', 'detailType', 'charge_type', 'fee_type', 'name'] as $key) {
            if (isset($node[$key]) && trim((string) $node[$key]) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $node
     * @return array<string,mixed>|null
     */
    private function normalizeLine(array $node, string $orderId): ?array
    {
        $amount = $this->numericValue($node, ['detail_amount', 'amount', 'total_amount', 'totalAmount', 'value', 'net_amount', 'netAmount']);
        if ($amount === null) {
            return null;
        }
        $description = $this->stringValue($node, ['transaction_detail', 'description', 'concept', 'title', 'name', 'detail', 'label']) ?? '';
        $type = $this->stringValue($node, ['type', 'detail_type', 'detailType', 'charge_type', 'fee_type', 'category']) ?? '';
        $subtype = $this->stringValue($node, ['detail_sub_type', 'subtype', 'detail_subtype', 'detailSubtype', 'reason', 'sub_type']) ?? null;
        $classification = $this->classifyLine($description . ' ' . $type . ' ' . (string) $subtype);
        $detailId = $this->stringValue($node, ['detail_id', 'detailId', 'id', 'movement_id', 'charge_id', 'transaction_id']);
        return [
            'external_order_id' => $orderId,
            'detail_id' => $detailId,
            'external_payment_id' => $this->stringValue($node, ['payment_id', 'paymentId']),
            'external_document_id' => $this->stringValue($node, ['document_id', 'documentId', 'invoice_id']),
            'detail_type' => $type !== '' ? $type : $classification,
            'detail_subtype' => $subtype,
            'classification' => $classification,
            'description' => $description !== '' ? $description : $classification,
            'amount' => round((float) $amount, 2),
            'currency_id' => $this->stringValue($node, ['currency_id', 'currencyId', 'currency']),
            'occurred_at' => $this->dateValue($node, ['occurred_at', 'date_created', 'dateCreated', 'date', 'created_at']),
            'date_created' => $this->dateValue($node, ['date_created', 'dateCreated', 'created_at']),
            'date_approved' => $this->dateValue($node, ['date_approved', 'dateApproved', 'approved_at']),
            'raw_json' => json_encode(Logger::redact($node), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    private function classifyLine(string $text): string
    {
        $normalized = $this->normalizeText($text);
        if (str_contains($normalized, 'precio') || str_contains($normalized, 'producto') || str_contains($normalized, 'product')) {
            return 'product';
        }
        if (str_contains($normalized, 'cargo por venta') || str_contains($normalized, 'comision') || str_contains($normalized, 'commission') || str_contains($normalized, 'sale fee')) {
            return 'sale_fee';
        }
        if ((str_contains($normalized, 'pago') || str_contains($normalized, 'paid')) && (str_contains($normalized, 'envio') || str_contains($normalized, 'shipping')) && (str_contains($normalized, 'comprador') || str_contains($normalized, 'buyer'))) {
            return 'buyer_shipping_paid';
        }
        if ((str_contains($normalized, 'cobro') || str_contains($normalized, 'cargo') || str_contains($normalized, 'charge')) && (str_contains($normalized, 'envio') || str_contains($normalized, 'shipping'))) {
            return 'ml_shipping_charge';
        }
        if (str_contains($normalized, 'reteiva') || str_contains($normalized, 'rete iva')) {
            return 'tax_reteiva';
        }
        if (str_contains($normalized, 'fuente')) {
            return 'tax_retention_source';
        }
        if (str_contains($normalized, 'impuesto') || str_contains($normalized, 'iva') || str_contains($normalized, 'tax') || str_contains($normalized, 'retencion')) {
            return 'tax_other';
        }
        if (str_contains($normalized, 'bonificacion') || str_contains($normalized, 'bonification') || str_contains($normalized, 'compensacion')) {
            return 'bonification';
        }
        if (str_contains($normalized, 'devolucion') || str_contains($normalized, 'refund')) {
            return 'refund';
        }
        if (str_contains($normalized, 'descuento') || str_contains($normalized, 'discount')) {
            return 'discount';
        }
        if (str_contains($normalized, 'ajuste') || str_contains($normalized, 'adjustment')) {
            return 'adjustment';
        }
        return 'other';
    }

    /** @param array<string,mixed> $order */
    private function storeRows(array $order, array $rows): int
    {
        $financialId = (int) ($order['financial_id'] ?? 0);
        if ($financialId <= 0) {
            $financialId = $this->ensureFinancialRow($order);
        }
        $sql = 'INSERT INTO meli_order_billing_details
             (meli_account_id,meli_order_id,meli_order_financial_id,external_order_id,detail_id,external_payment_id,external_document_id,detail_type,detail_subtype,classification,detail_hash,description,amount,currency_id,occurred_at,date_created,date_approved,source_endpoint,source_status,raw_json)
             VALUES (:account,:order_id,:financial,:external,:detail_id,:payment,:document,:type,:subtype,:classification,:hash,:description,:amount,:currency,:occurred,:created,:approved,:endpoint,"confirmed",:raw)
             ON DUPLICATE KEY UPDATE amount=VALUES(amount),description=VALUES(description),classification=VALUES(classification),source_status="confirmed",raw_json=VALUES(raw_json),updated_at=CURRENT_TIMESTAMP';
        $stored = 0;
        foreach ($rows as $index => $row) {
            $hash = hash('sha256', implode('|', [
                (string) $order['meli_account_id'],
                (string) $order['id'],
                (string) ($row['detail_id'] ?? ''),
                (string) ($row['classification'] ?? ''),
                (string) ($row['description'] ?? ''),
                (string) ($row['amount'] ?? ''),
                (string) $index,
            ]));
            $params = [
                'account' => (int) $order['meli_account_id'],
                'order_id' => (int) $order['id'],
                'financial' => $financialId,
                'external' => (string) $order['external_order_id'],
                'detail_id' => $row['detail_id'] ?? ('billing-' . $index),
                'payment' => $row['external_payment_id'] ?? null,
                'document' => $row['external_document_id'] ?? null,
                'type' => $row['detail_type'] ?? $row['classification'],
                'subtype' => $row['detail_subtype'] ?? null,
                'classification' => $row['classification'],
                'hash' => $hash,
                'description' => mb_substr((string) ($row['description'] ?? ''), 0, 500),
                'amount' => (float) $row['amount'],
                'currency' => $row['currency_id'] ?? ($order['currency_id'] ?? null),
                'occurred' => $row['occurred_at'] ?? null,
                'created' => $row['date_created'] ?? null,
                'approved' => $row['date_approved'] ?? null,
                'endpoint' => self::ENDPOINT,
                'raw' => $row['raw_json'] ?? null,
            ];
            Database::executeWithReconnect(static function (PDO $pdo) use ($sql, $params): void {
                $pdo->prepare($sql)->execute($params);
            });
            $stored++;
        }
        return $stored;
    }

    /** @param array<string,mixed> $order */
    private function applySummary(array $order, array $rows): void
    {
        $summary = [
            'product' => 0.0,
            'sale_fee' => 0.0,
            'buyer_shipping_paid' => 0.0,
            'ml_shipping_charge' => 0.0,
            'tax_retention_source' => 0.0,
            'tax_reteiva' => 0.0,
            'tax_other' => 0.0,
            'bonification' => 0.0,
            'discount' => 0.0,
            'refund' => 0.0,
            'adjustment' => 0.0,
            'other' => 0.0,
        ];
        foreach ($rows as $row) {
            $classification = (string) ($row['classification'] ?? 'other');
            $amount = (float) ($row['amount'] ?? 0);
            $bucket = array_key_exists($classification, $summary) ? $classification : 'other';
            $summary[$bucket] += $amount;
        }

        $product = $summary['product'] !== 0.0 ? abs($summary['product']) : null;
        $saleFee = abs($summary['sale_fee']);
        $buyerShipping = abs($summary['buyer_shipping_paid']);
        $mlShipping = abs($summary['ml_shipping_charge']);
        $taxSource = abs($summary['tax_retention_source']);
        $taxReteiva = abs($summary['tax_reteiva']);
        $taxOther = abs($summary['tax_other']);
        $taxes = $taxSource + $taxReteiva + $taxOther;
        $bonifications = abs($summary['bonification']);
        $discounts = abs($summary['discount']);
        $adjustments = abs($summary['adjustment']) + abs($summary['refund']);
        $ambiguousAmount = abs($summary['other']);
        $hasAmbiguousLines = $ambiguousAmount > 0.009;

        $current = (new OrderFinancialService())->findByOrderId((int) $order['id']) ?? [];
        $productAmount = $product ?? (float) ($current['product_sold_amount'] ?? 0);
        $billingNet = $productAmount - $saleFee - $discounts + $buyerShipping - $mlShipping - $taxes + $bonifications - $adjustments;
        $localNet = (float) ($current['local_estimated_net_amount'] ?? ($current['ml_net_amount'] ?? 0));
        $difference = $localNet !== 0.0 ? $billingNet - $localNet : null;

        $columns = (new SchemaInspectorService())->columns('meli_order_financials');
        $sets = [
            'buyer_shipping_paid=:buyer_shipping',
            'ml_shipping_charge=:ml_shipping',
            'shipping_net_amount=:shipping_net',
            'sale_fee_amount=:sale_fee',
            'discount_amount=:discounts',
            'taxes_amount=:taxes_amount',
            'withholdings_amount=:withholdings_amount',
            'bonifications_amount=:bonifications',
            'adjustments_amount=:adjustments',
            'ml_net_amount=:billing_net_ml',
            'erp_calculated_net=:billing_net_erp',
            'difference_amount=:difference_amount',
            'billing_status="imported"',
            'reconciliation_status=:reconciliation_status',
            'source="billing_job"',
            'safe_message=:message',
            'last_billing_sync_at=UTC_TIMESTAMP()',
            'updated_at=CURRENT_TIMESTAMP',
        ];
        $params = [
            'buyer_shipping' => round($buyerShipping, 2),
            'ml_shipping' => round($mlShipping, 2),
            'shipping_net' => round($buyerShipping - $mlShipping, 2),
            'sale_fee' => round($saleFee, 2),
            'discounts' => round($discounts, 2),
            'taxes_amount' => round($taxes, 2),
            'withholdings_amount' => round($taxes, 2),
            'bonifications' => round($bonifications, 2),
            'adjustments' => round($adjustments, 2),
            'billing_net_ml' => round($billingNet, 2),
            'billing_net_erp' => round($billingNet, 2),
            'difference_amount' => $difference !== null ? round($difference, 2) : 0,
            'reconciliation_status' => $hasAmbiguousLines ? 'revision_manual' : 'matched',
            'message' => $hasAmbiguousLines
                ? 'Billing importado con líneas no clasificadas; neto queda en revisión manual.'
                : 'Billing importado y neto ML conciliado desde job financiero.',
            'order_id' => (int) $order['id'],
        ];
        if (isset($columns['tax_withholding_amount'])) {
            $sets[] = 'tax_withholding_amount=:tax_withholding';
            $sets[] = 'tax_retention_source_amount=:tax_source';
            $sets[] = 'tax_reteiva_amount=:tax_reteiva';
            $sets[] = 'tax_other_amount=:tax_other';
            $params['tax_withholding'] = round($taxes, 2);
            $params['tax_source'] = round($taxSource, 2);
            $params['tax_reteiva'] = round($taxReteiva, 2);
            $params['tax_other'] = round($taxOther, 2);
        }
        if (isset($columns['local_estimated_net_amount'])) {
            $sets[] = 'local_estimated_net_amount=COALESCE(local_estimated_net_amount, :local_net)';
            $sets[] = 'billing_reconciled_net_amount=:billing_reconciled_net';
            $sets[] = 'reconciliation_difference=:reconciliation_difference';
            $sets[] = 'financial_source="billing_order_details"';
            $sets[] = 'billing_import_status=:billing_import_status';
            $sets[] = 'billing_imported_at=UTC_TIMESTAMP()';
            $sets[] = 'raw_billing_summary_json=:raw_billing';
            $params['local_net'] = round($localNet, 2);
            $params['billing_reconciled_net'] = round($billingNet, 2);
            $params['reconciliation_difference'] = $difference !== null ? round($difference, 2) : 0;
            $params['raw_billing'] = json_encode(Logger::redact($summary), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $params['billing_import_status'] = $hasAmbiguousLines ? 'revision_manual' : 'imported';
        }
        if ($product !== null) {
            $sets[] = 'product_sold_amount=:product';
            $params['product'] = round($productAmount, 2);
        }
        $sql = 'UPDATE meli_order_financials SET ' . implode(',', $sets) . ' WHERE meli_order_id=:order_id';
        Database::executeWithReconnect(static function (PDO $pdo) use ($sql, $params): void {
            $pdo->prepare($sql)->execute($params);
        });
    }

    /** @param array<string,mixed> $order */
    private function markUnavailable(array $order, string $message): void
    {
        $columns = (new SchemaInspectorService())->columns('meli_order_financials');
        if (!isset($columns['billing_import_status'])) {
            return;
        }
        Database::executeWithReconnect(static function (PDO $pdo) use ($message, $order): void {
            $pdo->prepare(
                'UPDATE meli_order_financials
             SET billing_import_status="unavailable", financial_source="local_partial", safe_message=:message, updated_at=CURRENT_TIMESTAMP
             WHERE meli_order_id=:order_id'
            )->execute(['message' => mb_substr($message, 0, 500), 'order_id' => (int) $order['id']]);
        });
    }

    /** @param array<string,mixed> $order */
    private function ensureFinancialRow(array $order): int
    {
        (new OrderFinancialService())->recalculateByOrderId((int) $order['id']);
        $financial = (new OrderFinancialService())->findByOrderId((int) $order['id']);
        if (!$financial) {
            throw new RuntimeException('No se pudo crear resumen financiero base.');
        }
        return (int) $financial['id'];
    }

    /** @param array<string,mixed> $row */
    private function numericValue(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return (float) $row[$key];
            }
        }
        return null;
    }

    /** @param array<string,mixed> $row */
    private function stringValue(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                return (string) $row[$key];
            }
        }
        return null;
    }

    /** @param array<string,mixed> $row */
    private function dateValue(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!empty($row[$key])) {
                $ts = strtotime((string) $row[$key]);
                if ($ts !== false) {
                    return gmdate('Y-m-d H:i:s', $ts);
                }
            }
        }
        return null;
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $from = ['á','é','í','ó','ú','ñ','ü'];
        $to = ['a','e','i','o','u','n','u'];
        return str_replace($from, $to, $text);
    }

    private function isList(array $array): bool
    {
        return array_keys($array) === range(0, count($array) - 1);
    }
}
