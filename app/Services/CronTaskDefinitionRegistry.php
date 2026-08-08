<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Metadatos compartidos por el orquestador y su simulación.
 * Los callbacks permanecen exclusivamente en el job CLI.
 */
final class CronTaskDefinitionRegistry
{
    private CronBatchPolicyService $batchPolicy;

    public function __construct(?CronBatchPolicyService $batchPolicy = null)
    {
        $this->batchPolicy = $batchPolicy ?? new CronBatchPolicyService();
    }

    /**
     * @return list<array{key:string,api:bool,interval:int,priority:int,lane:string,unit_singular:string,unit_plural:string,batch_limit:?int,batch_label:string,batch_configured:?int,batch_effective:?int,batch_limit_reason:string}>
     */
    public function all(): array
    {
        return [
            $this->definition('notification_spool', false, 30, 10, 'local', 'evento', 'eventos', 20, 'Hasta 20 eventos por turno'),
            $this->definition('notification_backfill', false, 60, 20, 'local', 'evento', 'eventos', 50, 'Hasta 50 eventos por turno'),
            $this->definition('notification_fallback', true, 30, 30, 'urgent', 'recurso', 'recursos', 1, '1 recurso urgente por turno'),
            $this->definition('orders_sync', true, 30, 40, 'urgent', 'venta', 'ventas', 1, '1 venta por turno'),
            $this->definition('manual_campaign', true, 60, 45, 'directed', 'trabajo', 'trabajos', null, 'Ventana dirigida de 15 segundos'),
            $this->definition('order_enrichment', true, 60, 50, 'normal', 'orden', 'órdenes', 1, '1 orden por turno'),
            $this->definition('questions', true, 120, 60, 'normal', 'consulta', 'consultas', 1, '1 consulta por turno'),
            $this->definition('financial_recalc', true, 60, 70, 'normal', 'orden', 'órdenes', 5, 'Hasta 5 órdenes por turno'),
            $this->definition('sale_financial_reconciliation', true, 60, 75, 'normal', 'venta', 'ventas', 1, '1 venta por turno'),
            $this->definition('sales_repair', true, 120, 80, 'normal', 'venta', 'ventas', 5, 'Hasta 5 ventas por turno'),
            $this->definition('sale_pack_reconciliation', true, 60, 85, 'normal', 'pack', 'packs', 1, '1 pack por turno'),
            $this->definition('sales_audit', true, 60, 90, 'normal', 'página', 'páginas', 2, 'Hasta 2 páginas por turno'),
            $this->definition('sales_fiscal', true, 60, 95, 'normal', 'venta', 'ventas', 1, '1 venta por turno'),
            $this->definition('catalog_descriptions', true, 60, 110, 'normal', 'descripción', 'descripciones', 2, 'Hasta 2 descripciones por turno'),
            $this->definition('items_sync', true, 120, 120, 'normal', 'producto', 'productos', 2, 'Hasta 2 productos por turno'),
            $this->definition('module_jobs', true, 300, 130, 'normal', 'trabajo', 'trabajos', 2, 'Hasta 2 trabajos por turno'),
            $this->definition('recurring_sync', false, 300, 140, 'local', 'sincronización', 'sincronizaciones', null, 'Límite propio de la sincronización'),
            $this->definition('order_date_repair', false, 120, 150, 'local', 'orden', 'órdenes', 5, 'Hasta 5 órdenes por turno'),
            $this->definition('operational_maintenance', false, 300, 160, 'local', 'registro', 'registros', 100, 'Hasta 100 registros por turno'),
            $this->definition('monthly_report_maintenance', false, 86400, 165, 'local', 'reporte', 'reportes', null, 'Comprobación diaria'),
        ];
    }

    /** @return array{key:string,api:bool,interval:int,priority:int,lane:string,unit_singular:string,unit_plural:string,batch_limit:?int,batch_label:string,batch_configured:?int,batch_effective:?int,batch_limit_reason:string} */
    private function definition(
        string $key,
        bool $api,
        int $interval,
        int $priority,
        string $lane,
        string $singular,
        string $plural,
        ?int $batchLimit,
        string $batchLabel
    ): array {
        $policy = $this->batchPolicy->policy($key);
        return compact('key', 'api', 'interval', 'priority', 'lane') + [
            'unit_singular' => $singular,
            'unit_plural' => $plural,
            // Los argumentos históricos se conservan en la firma durante la
            // transición, pero la única verdad visible y ejecutable es policy.
            'batch_limit' => $policy['effective'],
            'batch_label' => $policy['label'],
            'batch_configured' => $policy['configured'],
            'batch_effective' => $policy['effective'],
            'batch_limit_reason' => $policy['limit_reason'],
        ];
    }
}
