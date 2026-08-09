<?php

declare(strict_types=1);

namespace App\Services;

final class ManualCampaignAdapterRegistry
{
    /** @return array<string,array<string,mixed>> */
    private function definitions(): array
    {
        return [
            'notification_fallback' => $this->d('notification', 'notification_fallback', 'order_exact', 'Ventas notificadas', true, true),
            'orders_sync' => $this->d('orders', 'orders_sync', 'orders_search', 'Órdenes', true, true),
            'items_sync' => $this->d('items', 'items_sync', 'item_detail', 'Productos', true, true),
            'catalog_descriptions' => $this->d('descriptions', 'catalog_descriptions', 'item_description', 'Descripciones', true, true),
            // El backfill abarca múltiples cuentas y eventos. No es interactivo
            // hasta que disponga de una identidad exacta por cuenta + evento.
            'notification_backfill' => $this->d('notification-backfill', 'notification_backfill', 'local_maintenance', 'Recuperación de notificaciones', false, false),
            'financial_recalc' => $this->d('financial', 'financial_recalc', 'local_financial', 'Finanzas', true, false),
            'sales_audit' => $this->d('sales-audit', 'sales_audit', 'sales_audit', 'Auditorías', true, true),
            'sales_repair' => $this->d('sales-repair', 'sales_repair', 'order_exact', 'Reparación de ventas', true, true),
            'order_enrichment' => $this->d('enrichment', 'order_enrichment', 'shipment_exact', 'Completar órdenes', true, true),
            'sale_pack_reconciliation' => $this->d('sale-pack', 'sale_pack_reconciliation', 'pack_exact', 'Reconstruir ventas', true, true),
            'sale_financial_reconciliation' => $this->d('sale-financial', 'sale_financial_reconciliation', 'billing_orders', 'Conciliar ventas', true, true),
            'questions' => $this->d('questions', 'questions', 'questions_search', 'Preguntas', false, true),
            // Un trabajo modular puede cambiar de endpoint según módulo, tipo y
            // checkpoint. Hasta disponer de un contrato exacto por etapa no se
            // anuncia como ejecutable interactivo.
            'module_jobs' => $this->d('modules', 'module_jobs', 'local_maintenance', 'Módulos', false, false),
            'order_date_repair' => $this->d('date-repair', 'order_date_repair', 'local_maintenance', 'Reparar fechas', false, false),
            'operational_maintenance' => $this->d('maintenance', 'operational_maintenance', 'local_maintenance', 'Mantenimiento local', false, false),
        ];
    }

    /** @return list<ManualCampaignAdapter> */
    public function all(): array
    {
        return array_map(
            static fn (array $definition): ManualCampaignAdapter => new RegisteredManualCampaignAdapter($definition),
            array_values($this->definitions())
        );
    }

    public function forQueue(string $queueKey): ?ManualCampaignAdapter
    {
        $definition = $this->definitions()[$queueKey] ?? null;
        return is_array($definition) ? new RegisteredManualCampaignAdapter($definition) : null;
    }

    /** @return array<string,mixed> */
    public function health(): array
    {
        $ready = [];
        $pending = [];
        foreach ($this->all() as $adapter) {
            if ($adapter->supportsExact()) {
                $ready[] = $adapter->queueKey();
            } else {
                $pending[] = $adapter->queueKey();
            }
        }
        return ['ready' => $ready, 'pending' => $pending, 'all_exact' => $pending === []];
    }

    /** @return array<string,mixed> */
    private function d(
        string $key,
        string $queue,
        string $operation,
        string $label,
        bool $exact,
        bool $usesApi
    ): array {
        return compact('key', 'queue', 'operation', 'label', 'exact', 'usesApi') + [
            'queue_key' => $queue,
            'operation_key' => $operation,
        ];
    }
}
