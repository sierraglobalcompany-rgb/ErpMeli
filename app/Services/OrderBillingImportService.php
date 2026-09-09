<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Entrada legada retirada.
 *
 * Billing activo vive en SaleFinancialService y siempre envía un único
 * order_id por intento HTTP físico. Este servicio se conserva sólo como
 * barrera de compatibilidad para que invocaciones antiguas fallen cerradas sin
 * HTTP, sin enqueue y sin declarar trabajo procesado.
 */
final class OrderBillingImportService
{
    /**
     * @param list<int> $meliOrderIds
     * @return array{requested:int,imported_orders:int,billing_rows:int,skipped:int,errors:int}
     */
    public function importForOrderIds(
        int $companyId,
        int $accountId,
        array $meliOrderIds,
        bool $forceRefresh = false
    ): array {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $meliOrderIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return ['requested' => 0, 'imported_orders' => 0, 'billing_rows' => 0, 'skipped' => 0, 'errors' => 0];
        }

        throw new RuntimeException(
            'La importación Billing agrupada fue retirada. Use la conciliación financiera exacta: un order_id por llamada física.'
        );
    }
}
