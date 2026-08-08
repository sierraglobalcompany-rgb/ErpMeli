<?php

declare(strict_types=1);

namespace App\Services;

final class CatalogExportService
{
    public function stream(array $catalog, array $filters = [], bool $technical = false): never
    {
        $query = new CatalogQueryService();
        $filters['page'] = 1;
        $filters['per_page'] = 5000;
        $result = $query->privateItems($catalog, $filters);
        $filename = 'catalogo-' . preg_replace('/[^a-z0-9-]+/', '-', (string) $catalog['slug']) . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'wb');
        $headers = ['Fuente', 'Cuenta', 'Empresa', 'ID publicación ML', 'SKU', 'Producto', 'Categoría', 'Precio', 'Moneda', 'Stock total', 'Stock FULL', 'Stock no FULL/local', 'Stock sin detalle', 'Confianza stock', 'Métodos de envío', 'FULL', 'Estado', 'Vendidos', 'Fecha actualización', 'Link Mercado Libre', 'Visible en catálogo'];
        if ($technical) {
            $headers[] = 'ID técnico catálogo item';
            $headers[] = 'ID técnico meli_item';
        }
        fputcsv($out, $headers, ';', '"', '', "\n");
        foreach ($result['items'] as $item) {
            $shippingMethods = json_decode((string) ($item['shipping_methods_json'] ?? '[]'), true);
            $shippingLabels = is_array($shippingMethods) ? implode(', ', array_filter(array_map(static fn($row) => (string) ($row['label'] ?? ''), $shippingMethods))) : '';
            $row = [
                ($item['source_type'] ?? 'meli') === 'internal' ? 'Bodega interna' : 'Mercado Libre',
                $item['account_name'] ?? '',
                $item['company_name'] ?? '',
                $item['external_item_id'] ?? '',
                $item['sku_snapshot'] ?? '',
                $item['title_snapshot'] ?? '',
                $item['category_display_name'] ?? $item['category_name'] ?? 'Sin categoría',
                (string) (float) ($item['price_snapshot'] ?? 0),
                $item['currency_id'] ?? '',
                (string) (int) ($item['stock_available'] ?? 0),
                $item['stock_full'] === null ? '' : (string) (int) $item['stock_full'],
                $item['stock_non_full'] === null ? '' : (string) (int) $item['stock_non_full'],
                $item['stock_unknown'] === null ? '' : (string) (int) $item['stock_unknown'],
                $item['stock_detail_status'] ?? '',
                $shippingLabels,
                (int) ($item['is_full'] ?? 0) === 1 ? 'Sí' : 'No',
                $item['status'] ?? '',
                (string) (int) ($item['sold_quantity'] ?? 0),
                $item['source_updated_at'] ?? $item['updated_at'] ?? '',
                $item['permalink'] ?? '',
                (int) ($item['is_visible'] ?? 0) === 1 ? 'Sí' : 'No',
            ];
            if ($technical) {
                $row[] = (string) (int) ($item['id'] ?? 0);
                $row[] = (string) (int) ($item['meli_item_id'] ?? 0);
            }
            fputcsv($out, $row, ';', '"', '', "\n");
        }
        fclose($out);
        exit;
    }
}
