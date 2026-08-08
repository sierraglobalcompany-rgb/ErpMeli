<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class MeliEndpointRegistry
{
    /**
     * OAuth is a separate, explicitly documented technical contract. It is
     * deliberately not accepted by assertDocumented(), which protects the
     * business read client from arbitrary POST requests.
     */
    public static function assertOAuthTokenExchange(string $method, string $path): void
    {
        if (strtoupper($method) !== 'POST' || $path !== '/oauth/token') {
            throw new RuntimeException('Contrato OAuth remoto no permitido: ' . strtoupper($method) . ' ' . $path);
        }
    }

    /**
     * Estado operativo de endpoints GET permitidos por el ERP.
     *
     * - confirmed: lectura habilitada.
     * - disabled: documentado o conocido, pero bloqueado por política local.
     * - investigating/future: no debe ejecutarse hasta validación documental.
     *
     * @var list<array{pattern:string,status:string,notes:string,key?:string}>
     */
    private const GET_ENDPOINTS = [
        ['pattern' => '~^/users/me$~', 'status' => 'confirmed', 'key' => 'users_me', 'notes' => 'Validación OAuth/refresco de token.'],
        ['pattern' => '~^/users/\d+$~', 'status' => 'investigating', 'notes' => 'No figura como contrato confirmado en docs/mercadolibre_api_map.md.'],
        ['pattern' => '~^/orders/search$~', 'status' => 'confirmed', 'key' => 'orders_search', 'notes' => 'Órdenes y auditoría de ventas.'],
        ['pattern' => '~^/orders/billing-info/[A-Za-z0-9_-]+/[A-Za-z0-9_-]+$~', 'status' => 'confirmed', 'key' => 'order_billing_info', 'notes' => 'Datos fiscales modernos asociados a una orden. Solo lectura.'],
        ['pattern' => '~^/orders/\d+$~', 'status' => 'confirmed', 'key' => 'order_exact', 'notes' => 'Detalle puntual de orden.'],
        ['pattern' => '~^/packs/\d+$~', 'status' => 'confirmed', 'key' => 'pack_exact', 'notes' => 'Pack asociado a orden.'],
        ['pattern' => '~^/shipments/\d+$~', 'status' => 'confirmed', 'key' => 'shipment_exact', 'notes' => 'Detalle de envío.'],
        ['pattern' => '~^/payments/\d+$~', 'status' => 'disabled', 'notes' => 'Desactivado por defecto: pagos vienen embebidos en órdenes; Mercado Pago queda pendiente.'],
        ['pattern' => '~^/users/\d+/items/search$~', 'status' => 'confirmed', 'key' => 'items_discovery', 'notes' => 'Listado de publicaciones por vendedor.'],
        ['pattern' => '~^/items/[A-Z]{2,4}\d+$~', 'status' => 'confirmed', 'key' => 'item_exact', 'notes' => 'Detalle de publicación.'],
        ['pattern' => '~^/items/[A-Z]{2,4}\d+/description$~', 'status' => 'confirmed', 'key' => 'item_description', 'notes' => 'Descripción cacheada desde acción interna/job.'],
        ['pattern' => '~^/categories/[A-Z]{2,4}\d+$~', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/user-products/[A-Z0-9_-]+/stock$~', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/post-purchase/v1/claims/search$~', 'status' => 'confirmed', 'key' => 'claims_search', 'notes' => 'Búsqueda de reclamos solo lectura.'],
        ['pattern' => '~^/post-purchase/v1/claims/\d+$~', 'status' => 'confirmed', 'key' => 'claim_exact', 'notes' => 'Detalle básico de reclamo.'],
        ['pattern' => '~^/post-purchase/v1/claims/\d+/detail$~', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/post-purchase/v1/claims/\d+/(actions-history|status-history|affects-reputation)$~', 'status' => 'investigating', 'notes' => 'Subrecursos no usados/no confirmados para producción.'],
        ['pattern' => '~^/questions/search$~', 'status' => 'confirmed', 'key' => 'questions_search', 'notes' => 'Preguntas solo lectura con api_version documentada.'],
        ['pattern' => '~^/questions/\d+$~', 'status' => 'confirmed', 'key' => 'question_exact', 'notes' => 'Detalle puntual de pregunta solo lectura.'],
        ['pattern' => '~^/missed_feeds$~', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/billing/integration/group/ML/order/details$~', 'status' => 'confirmed', 'key' => 'billing_orders', 'notes' => 'Billing financiero por job/cola, nunca vista pública.'],
        ['pattern' => '~^/items/[A-Z]{2,4}\d+/sale_price$~', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/item/[A-Z]{2,4}\d+/performance$~', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/moderations/last_moderation/[A-Z]{2,4}\d+-ITM$~', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/items/[A-Z]{2,4}\d+/price_to_win$~', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/seller-promotions/users/\d+$~', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/seller-promotions/candidates/CANDIDATE-[A-Z]{2,4}\d+-\d+$~i', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/seller-promotions/offers/OFFER-[A-Z]{2,4}\d+-\d+$~i', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/users/\d+/items_visits$~', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/users/\d+/items_visits/time_window$~', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/trends/[A-Z]{3}$~', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/trends/[A-Z]{3}/[A-Z0-9_-]+$~', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/highlights/[A-Z]{3}/category/[A-Z0-9_-]+$~', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/advertising/advertisers$~', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/advertising/advertisers/\d+/product_ads/campaigns$~', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/advertising/advertisers/\d+/product_ads/ads$~', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/messages/packs/\d+/sellers/\d+$~', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/messages/unread$~', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/post-purchase/v2/claims/\d+/returns$~', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/reviews/item/[A-Z]{2,4}\d+$~', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/orders/\d+/feedback$~', 'status' => 'investigating', 'notes' => 'Pendiente de incorporar al mapa API local.'],
        ['pattern' => '~^/shipment_labels$~', 'status' => 'disabled', 'notes' => 'Etiquetas bloqueadas hasta confirmar el contrato oficial aplicable.'],
    ];

    public static function assertDocumented(string $method, string $path): void
    {
        self::contractKey($method, $path);
    }

    public static function contractKey(string $method, string $path): string
    {
        if (strtoupper($method) !== 'GET') {
            throw new RuntimeException('Método remoto no permitido por el mapa API local: ' . strtoupper($method));
        }
        foreach (self::GET_ENDPOINTS as $endpoint) {
            if (!preg_match($endpoint['pattern'], $path)) {
                continue;
            }
            if ($endpoint['status'] === 'disabled') {
                throw new RuntimeException('Endpoint desactivado por política ERP/API: ' . $path);
            }
            if (in_array($endpoint['status'], ['investigating', 'future'], true)) {
                throw new RuntimeException('Endpoint pendiente de validación documental: ' . $path);
            }
            return $endpoint['key'];
        }
        throw new RuntimeException('Endpoint no registrado en el mapa oficial: ' . $path);
    }

    public static function isConfirmed(string $method, string $path): bool
    {
        try {
            self::assertDocumented($method, $path);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @return list<array{method:string,pattern:string,status:string,notes:string,key?:string}>
     */
    public static function contracts(): array
    {
        $business = array_map(
            static fn (array $endpoint): array => ['method' => 'GET'] + $endpoint,
            self::GET_ENDPOINTS
        );
        $business[] = [
            'method' => 'POST',
            'pattern' => '~^/oauth/token$~',
            'status' => 'confirmed',
            'notes' => 'Intercambio técnico OAuth documentado; no es una mutación comercial.',
        ];
        return $business;
    }
}
