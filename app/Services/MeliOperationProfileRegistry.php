<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Contrato interno de carga. Las unidades de carga protegen PHP/MySQL y no
 * representan una cuota publicada por Mercado Libre.
 */
final class MeliOperationProfileRegistry
{
    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        return [
            'oauth' => $this->profile('Renovar autorización', 'Autorización', 1, 'light', 1, 1, 0, true, false),
            'order_exact' => $this->profile('Incorporar orden informada', 'Ventas', 1, 'medium', 5, 5, 0, true, true),
            'shipment_exact' => $this->profile('Actualizar envío', 'Logística', 2, 'medium', 5, 5, 0, true, true),
            'pack_exact' => $this->profile('Actualizar pack', 'Logística', 2, 'medium', 5, 5, 0, true, true),
            'orders_search' => $this->profile('Buscar órdenes', 'Ventas', 2, 'paginated', 1, 1, 0, true, true),
            'question_exact' => $this->profile('Consultar pregunta', 'Atención', 2, 'light', 5, 5, 0, true, false),
            'questions_search' => $this->profile('Buscar preguntas', 'Atención', 4, 'paginated', 1, 1, 5, true, false),
            'claim_exact' => $this->profile('Consultar reclamo', 'Atención', 4, 'medium', 3, 3, 3, true, true),
            'claims_search' => $this->profile('Buscar reclamos', 'Atención', 4, 'paginated', 1, 1, 5, true, true),
            'sales_audit' => $this->profile('Comprobar ventas', 'Auditorías', 5, 'paginated', 1, 1, 3, true, false),
            'billing_orders' => $this->profile('Consultar facturación de órdenes', 'Finanzas', 4, 'heavy', 10, 60, 10, true, false),
            'items_discovery' => $this->profile('Descubrir publicaciones', 'Productos', 6, 'cursor', 1, 1, 0, true, false, true),
            'item_detail' => $this->profile('Actualizar publicación', 'Productos', 6, 'heavy', 5, 5, 5, true, true),
            'item_description' => $this->profile('Descargar descripción', 'Productos', 8, 'very_heavy', 1, 3, 20, true, false),
            'item_stock' => $this->profile('Consultar inventario por origen', 'Productos', 5, 'medium', 2, 2, 5, true, false),
            'insights' => $this->profile('Actualizar rendimiento', 'Módulos', 8, 'heavy', 1, 1, 15, true, false),
            'growth' => $this->profile('Actualizar crecimiento', 'Módulos', 8, 'heavy', 1, 1, 15, true, false),
            'ads' => $this->profile('Actualizar Mercado Ads', 'Módulos', 8, 'heavy', 1, 1, 30, true, false),
            'local_financial' => $this->profile('Calcular información financiera', 'Finanzas', 4, 'local', 25, 100, 0, false, false),
            'local_maintenance' => $this->profile('Realizar mantenimiento local', 'Sistema', 8, 'local', 100, 500, 0, false, false),
            'unknown_read' => $this->profile('Consulta registrada', 'Mercado Libre', 7, 'heavy', 1, 1, 10, true, false),
        ];
    }

    /** @return array<string,mixed> */
    public function resolve(string $method, string $path, array $meta = []): array
    {
        $path = '/' . ltrim((string) (parse_url($path, PHP_URL_PATH) ?: $path), '/');
        $jobType = strtolower(trim((string) ($meta['job_type'] ?? '')));
        $key = match (true) {
            $path === '/oauth/token' => 'oauth',
            preg_match('~^/orders/\d+$~', $path) === 1 => 'order_exact',
            $path === '/orders/search' => 'orders_search',
            preg_match('~^/shipments/\d+$~', $path) === 1 => 'shipment_exact',
            preg_match('~^/packs/\d+$~', $path) === 1 => 'pack_exact',
            preg_match('~^/questions/\d+$~', $path) === 1 => 'question_exact',
            $path === '/questions/search' => 'questions_search',
            preg_match('~^/post-purchase/v1/claims/\d+$~', $path) === 1 => 'claim_exact',
            $path === '/post-purchase/v1/claims/search' => 'claims_search',
            $path === '/billing/integration/group/ML/order/details' => 'billing_orders',
            preg_match('~^/items/[A-Z]{2,4}\d+/description$~i', $path) === 1 => 'item_description',
            preg_match('~^/items/[A-Z]{2,4}\d+$~i', $path) === 1 => 'item_detail',
            preg_match('~^/users/\d+/items/search$~', $path) === 1 => 'items_discovery',
            preg_match('~^/user-products/[A-Z0-9_-]+/stock$~i', $path) === 1 => 'item_stock',
            $jobType === 'sales_audit' => 'sales_audit',
            str_contains($jobType, 'insight') => 'insights',
            str_contains($jobType, 'growth') => 'growth',
            str_contains($jobType, 'ads') => 'ads',
            default => 'unknown_read',
        };
        $profile = $this->all()[$key];
        $profile['key'] = $key;
        $profile['method'] = strtoupper($method);
        return $profile;
    }

    /** @return array<string,mixed> */
    public function get(string $key): array
    {
        $profiles = $this->all();
        $profile = $profiles[$key] ?? $profiles['unknown_read'];
        $profile['key'] = isset($profiles[$key]) ? $key : 'unknown_read';
        return $profile;
    }

    /** @return array<string,mixed> */
    private function profile(
        string $label,
        string $module,
        int $priority,
        string $load,
        int $initialBatch,
        int $maximumBatch,
        int $minimumPauseSeconds,
        bool $usesApi,
        bool $fanout,
        bool $continuousCursor = false
    ): array {
        $units = ['local' => 0, 'light' => 1, 'medium' => 2, 'paginated' => 3, 'cursor' => 4, 'heavy' => 5, 'very_heavy' => 8];
        return [
            'label' => $label,
            'module' => $module,
            'priority' => $priority,
            'load_class' => $load,
            'workload_units' => $units[$load] ?? 5,
            'initial_batch' => $initialBatch,
            'maximum_batch' => $maximumBatch,
            'minimum_pause_seconds' => $minimumPauseSeconds,
            'uses_api' => $usesApi,
            'fanout' => $fanout,
            'continuous_cursor' => $continuousCursor,
            'sample_threshold' => 20,
        ];
    }
}
