<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Autoridad única de los tamaños de lote del lanzador principal.
 *
 * No regula el ritmo HTTP: solamente limita cuántas unidades puede entregar
 * cada callback en un turno. El job CLI, el registro y las lecturas humanas
 * deben consultar esta misma política para no anunciar límites distintos.
 */
final class CronBatchPolicyService
{
    /** @var array<string,string|null>|null */
    private ?array $configuredValues = null;

    public function __construct(private readonly AppSettingsService $settings = new AppSettingsService())
    {
    }

    /**
     * @return array{queue_key:string,configured:?int,effective:?int,safe_cap:?int,setting:?string,limit_reason:string,label:string}
     */
    public function policy(string $queueKey): array
    {
        $definition = $this->definitions()[$queueKey] ?? null;
        if ($definition === null) {
            return [
                'queue_key' => $queueKey,
                'configured' => 1,
                'effective' => 1,
                'safe_cap' => 1,
                'setting' => null,
                'limit_reason' => 'safe_default',
                'label' => '1 recurso por turno (límite seguro)',
            ];
        }

        if (($definition['mode'] ?? 'count') === 'delegated') {
            return [
                'queue_key' => $queueKey,
                'configured' => null,
                'effective' => null,
                'safe_cap' => null,
                'setting' => null,
                'limit_reason' => 'callback_deadline',
                'label' => (string) $definition['label'],
            ];
        }

        $default = max(1, (int) $definition['default']);
        $cap = max(1, (int) $definition['cap']);
        $setting = isset($definition['setting']) ? (string) $definition['setting'] : null;
        $configured = $setting !== null
            ? (is_numeric($this->configuredValues()[$setting] ?? null) ? (int) $this->configuredValues()[$setting] : $default)
            : $default;
        $effective = max(1, min($cap, $configured));
        $reason = $setting === null
            ? 'fixed_safe_limit'
            : ($configured < 1 ? 'minimum_one' : ($configured > $cap ? 'safe_cap' : 'configured'));

        return [
            'queue_key' => $queueKey,
            'configured' => $configured,
            'effective' => $effective,
            'safe_cap' => $cap,
            'setting' => $setting,
            'limit_reason' => $reason,
            'label' => $this->label($effective, (string) $definition['singular'], (string) $definition['plural'], $reason),
        ];
    }

    public function limit(string $queueKey): int
    {
        return max(1, (int) ($this->policy($queueKey)['effective'] ?? 1));
    }

    /** @return array<string,array<string,mixed>> */
    private function definitions(): array
    {
        return [
            'operational_maintenance' => $this->count('cron.maintenance_limit', 100, 500, 'registro', 'registros'),
            'monthly_report_maintenance' => $this->fixed(1, 'reporte', 'reportes'),
            'notification_spool' => $this->count('cron.spool_batch_limit', 20, 100, 'evento', 'eventos'),
            'notification_backfill' => $this->count('cron.notification_backfill_limit', 50, 500, 'evento', 'eventos'),
            'notification_fallback' => $this->count('cron.notification_batch_limit', 5, 20, 'recurso', 'recursos'),
            'recurring_sync' => $this->delegated('La sincronización conserva su checkpoint y su límite propio'),
            'orders_sync' => $this->count('cron.orders_chunk_limit', 1, 5, 'venta', 'ventas'),
            'manual_campaign' => $this->delegated('Ventana dirigida cercada por deadline y ritmo API'),
            'order_enrichment' => $this->count('cron.order_enrichment_batch_limit', 5, 20, 'orden', 'órdenes'),
            'questions' => $this->fixed(1, 'consulta', 'consultas'),
            'financial_recalc' => $this->count('cron.financial_local_limit', 5, 25, 'orden', 'órdenes'),
            'sale_financial_reconciliation' => $this->count('cron.financial_reconciliation_batch_limit', 3, 10, 'venta', 'ventas'),
            'sales_repair' => $this->count('cron.repairs_limit', 5, 25, 'venta', 'ventas'),
            'sale_pack_reconciliation' => $this->count('cron.pack_reconciliation_batch_limit', 5, 10, 'pack', 'packs'),
            'sales_audit' => $this->count('sales_audit.exact_pages_per_cycle', 2, 10, 'página', 'páginas'),
            'sales_fiscal' => $this->fixed(1, 'venta', 'ventas'),
            'catalog_descriptions' => $this->count('cron.descriptions_limit', 2, 10, 'descripción', 'descripciones'),
            'items_sync' => $this->count('cron.products_limit', 2, 25, 'producto', 'productos'),
            'module_jobs' => $this->fixed(2, 'trabajo', 'trabajos'),
            'order_date_repair' => $this->count('cron.repairs_limit', 5, 25, 'orden', 'órdenes'),
        ];
    }

    /** @return array<string,string|null> */
    private function configuredValues(): array
    {
        if ($this->configuredValues !== null) {
            return $this->configuredValues;
        }
        $defaults = [];
        foreach ($this->definitions() as $definition) {
            if (isset($definition['setting'])) {
                $defaults[(string) $definition['setting']] = (string) (int) $definition['default'];
            }
        }
        return $this->configuredValues = $this->settings->getMany($defaults);
    }

    /** @return array<string,mixed> */
    private function count(string $setting, int $default, int $cap, string $singular, string $plural): array
    {
        return compact('setting', 'default', 'cap', 'singular', 'plural') + ['mode' => 'count'];
    }

    /** @return array<string,mixed> */
    private function fixed(int $limit, string $singular, string $plural): array
    {
        return ['mode' => 'count', 'default' => $limit, 'cap' => $limit, 'singular' => $singular, 'plural' => $plural];
    }

    /** @return array<string,mixed> */
    private function delegated(string $label): array
    {
        return ['mode' => 'delegated', 'label' => $label];
    }

    private function label(int $limit, string $singular, string $plural, string $reason): string
    {
        $unit = $limit === 1 ? $singular : $plural;
        return 'Hasta ' . $limit . ' ' . $unit . ' por turno'
            . ($reason === 'safe_cap' ? ' (tope seguro aplicado)' : '');
    }
}
