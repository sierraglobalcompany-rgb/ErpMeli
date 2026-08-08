<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Parser explícito del contrato de billing. No conserva payloads completos.
 */
final class SaleBillingParser
{
    /**
     * @param array<string,mixed> $response
     * @param list<string> $knownOrderIds
     * @return list<array<string,mixed>>
     */
    public function parse(array $response, array $knownOrderIds): array
    {
        $known = array_fill_keys(array_map('strval', $knownOrderIds), true);
        $lines = [];
        $this->walk($response, null, null, $known, $lines);
        $unique = [];
        foreach ($lines as $line) {
            $hash = (string) $line['line_hash'];
            $unique[$hash] = $line;
        }
        return array_values($unique);
    }

    /** @param array<string,bool> $known @param list<array<string,mixed>> $lines */
    private function walk(
        mixed $node,
        ?string $orderId,
        ?string $contextType,
        array $known,
        array &$lines
    ): void
    {
        if (!is_array($node)) {
            return;
        }
        if (array_is_list($node)) {
            foreach ($node as $child) {
                $this->walk($child, $orderId, $contextType, $known, $lines);
            }
            return;
        }
        $currentOrder = $this->orderId($node, $known) ?? $orderId;
        $amount = $this->amount($node);
        $type = $this->text($node, ['detail_type', 'detailType', 'type']) ?? $contextType;
        $subtype = $this->text($node, ['detail_sub_type', 'detail_subtype', 'detailSubtype', 'subtype']);
        $description = $this->text($node, ['transaction_detail', 'description', 'concept', 'title', 'name']);
        $hasFinancialChildren = $this->hasFinancialChildren($node);
        if (!$this->isCancelled($node)
            && !$hasFinancialChildren
            && $amount !== null
            && ($type !== null || $subtype !== null || $description !== null)) {
            $classification = $this->classify((string) $type, (string) $subtype, (string) $description);
            $detailId = $this->text($node, ['detail_id', 'id', 'charge_id', 'movement_id', 'transaction_id']);
            $shared = in_array($classification['group'], ['shipping', 'tax', 'discount', 'credit', 'adjustment'], true)
                && $currentOrder === null;
            $hashMaterial = $detailId !== null
                ? 'id|' . ($shared ? 'shared' : (string) $currentOrder) . '|' . $detailId
                : implode('|', [
                    $shared ? 'shared' : (string) $currentOrder,
                    $classification['group'],
                    (string) $type,
                    (string) $subtype,
                    (string) $description,
                    number_format(abs($amount), 2, '.', ''),
                ]);
            $lines[] = [
                'external_order_id' => $currentOrder,
                'detail_id' => $detailId,
                'line_group' => $classification['group'],
                'line_type' => $type,
                'line_subtype' => $subtype,
                'description' => $description ?: $classification['label'],
                'amount' => round(abs($amount), 2),
                'direction' => $classification['direction'],
                'is_shared' => $shared ? 1 : 0,
                'line_hash' => hash('sha256', $hashMaterial),
                'occurred_at' => $this->date($node),
            ];
        }
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $this->walk(
                    $value,
                    $currentOrder,
                    $this->contextType((string) $key) ?? $contextType,
                    $known,
                    $lines
                );
            }
        }
    }

    /** @param array<string,mixed> $node @param array<string,bool> $known */
    private function orderId(array $node, array $known): ?string
    {
        foreach (['order_id', 'orderId', 'external_order_id'] as $key) {
            $value = isset($node[$key]) ? (string) $node[$key] : '';
            if ($value !== '' && isset($known[$value])) {
                return $value;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $node */
    private function amount(array $node): ?float
    {
        foreach (['detail_amount', 'amount', 'value'] as $key) {
            if (isset($node[$key]) && is_numeric($node[$key])) {
                return (float) $node[$key];
            }
        }
        if ($this->text($node, ['detail_type', 'detailType', 'detail_sub_type', 'detail_subtype']) !== null) {
            foreach (['total_amount', 'net_amount'] as $key) {
                if (isset($node[$key]) && is_numeric($node[$key])) {
                    return (float) $node[$key];
                }
            }
        }
        return null;
    }

    /** @param array<string,mixed> $node */
    private function hasFinancialChildren(array $node): bool
    {
        foreach ($node as $value) {
            if (!is_array($value)) {
                continue;
            }
            $children = array_is_list($value) ? $value : [$value];
            foreach ($children as $child) {
                if (!is_array($child)) {
                    continue;
                }
                if ($this->amount($child) !== null
                    && $this->text($child, [
                        'detail_type', 'detailType', 'detail_sub_type', 'detail_subtype',
                        'transaction_detail', 'description', 'concept',
                    ]) !== null) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param array<string,mixed> $node */
    private function isCancelled(array $node): bool
    {
        $status = strtolower((string) ($this->text($node, [
            'status', 'detail_status', 'transaction_status', 'movement_status',
        ]) ?? ''));
        return in_array($status, [
            'cancelled', 'canceled', 'annulled', 'void', 'voided', 'reversed',
            'inactive', 'rejected',
        ], true);
    }

    private function contextType(string $key): ?string
    {
        return match (strtolower($key)) {
            'sales_info', 'sale_info', 'sale_fee', 'sale_fees' => 'sale_fee',
            'shipping_info', 'shipment_info', 'shipping' => 'shipping',
            'payment_info', 'payments_info', 'tax_info', 'withholding_info' => 'tax',
            'discount_info', 'discounts_info', 'discounts' => 'discount',
            'credit_info', 'credits_info', 'bonifications' => 'credit',
            default => null,
        };
    }

    /** @param array<string,mixed> $node @param list<string> $keys */
    private function text(array $node, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($node[$key]) && trim((string) $node[$key]) !== '') {
                return trim((string) $node[$key]);
            }
        }
        return null;
    }

    /** @return array{group:string,direction:string,label:string} */
    private function classify(string $type, string $subtype, string $description): array
    {
        $code = strtoupper(trim($type . ' ' . $subtype));
        $text = $this->normalize($description . ' ' . $code);
        $rules = [
            ['needles' => ['SALE_FEE', 'SELLING_FEE', 'CARGO POR VENTA', 'COMISION'], 'group' => 'sale_fee', 'direction' => 'debit', 'label' => 'Cargo por venta'],
            ['needles' => ['SHIPPING', 'ENVIO', 'MERCADO ENVIOS'], 'group' => 'shipping', 'direction' => 'debit', 'label' => 'Cargo de envío'],
            ['needles' => ['WITHHOLD', 'RETENTION', 'RETENCION', 'RETEIVA', 'TAX', 'IMPUESTO'], 'group' => 'tax', 'direction' => 'debit', 'label' => 'Impuesto o retención'],
            ['needles' => ['DISCOUNT', 'DESCUENTO'], 'group' => 'discount', 'direction' => 'credit', 'label' => 'Descuento'],
            ['needles' => ['BONIFICATION', 'BONIFICACION', 'CREDIT'], 'group' => 'credit', 'direction' => 'credit', 'label' => 'Crédito'],
            ['needles' => ['REFUND', 'DEVOLUCION', 'ADJUSTMENT', 'AJUSTE'], 'group' => 'adjustment', 'direction' => 'debit', 'label' => 'Ajuste'],
            ['needles' => ['PRODUCT', 'ITEM', 'PRODUCTO'], 'group' => 'product', 'direction' => 'neutral', 'label' => 'Producto'],
        ];
        foreach ($rules as $rule) {
            foreach ($rule['needles'] as $needle) {
                if (str_contains($text, $needle)) {
                    return ['group' => $rule['group'], 'direction' => $rule['direction'], 'label' => $rule['label']];
                }
            }
        }
        return ['group' => 'other', 'direction' => 'neutral', 'label' => 'Concepto no clasificado'];
    }

    private function normalize(string $value): string
    {
        return strtoupper(strtr($value, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
        ]));
    }

    /** @param array<string,mixed> $node */
    private function date(array $node): ?string
    {
        foreach (['date_created', 'date_approved', 'occurred_at', 'created_at'] as $key) {
            if (empty($node[$key])) {
                continue;
            }
            $timestamp = strtotime((string) $node[$key]);
            if ($timestamp !== false) {
                return gmdate('Y-m-d H:i:s', $timestamp);
            }
        }
        return null;
    }
}
