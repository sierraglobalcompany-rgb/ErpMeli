<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Describe el nuevo camino comercial sin activar trabajo remoto por accidente.
 *
 * Esta clase es deliberadamente de solo lectura: la Fase 0 prepara banderas,
 * orden de implementación y mensajes de continuidad, pero no importa datos,
 * no consulta Mercado Libre y no crea jobs.
 */
final class CommercialPathReadinessService
{
    public const VERSION = '2.28.1';

    /**
     * @return list<array{key:string,label:string,setting:string,kind:string,description:string}>
     */
    public function phases(): array
    {
        return [
            [
                'key' => 'foundation',
                'label' => 'Fase 0 · base comercial segura',
                'setting' => 'commercial_path.phase0_foundation_ready',
                'kind' => 'local',
                'description' => 'Versionado, flags, contrato de endpoints y gates antes de tocar datos comerciales.',
            ],
            [
                'key' => 'coverage',
                'label' => 'Fase 1 · cobertura temporal correcta',
                'setting' => 'commercial_path.coverage_temporal_enabled',
                'kind' => 'read_model',
                'description' => 'Meses full, partial, outside o invalid sin certificar historial no demostrado.',
            ],
            [
                'key' => 'order_change_sweep',
                'label' => 'Fase 2 · barrido incremental por actualización',
                'setting' => 'commercial_path.order_change_sweep_enabled',
                'kind' => 'cli_remote_read',
                'description' => 'Recupera notificaciones perdidas con order.date_last_updated y checkpoints.',
            ],
            [
                'key' => 'rehydration',
                'label' => 'Fase 3 · rehidratación de órdenes incompletas',
                'setting' => 'commercial_path.rehydrate_existing_orders_enabled',
                'kind' => 'cli_remote_read',
                'description' => 'Completa órdenes heredadas sin duplicar ventas ni cruzar cuentas.',
            ],
            [
                'key' => 'financial_retry',
                'label' => 'Fase 4 · finanzas oficiales resilientes',
                'setting' => 'commercial_path.financial_retry_v2_enabled',
                'kind' => 'cli_remote_read',
                'description' => 'Nuevo ciclo de reintento, evidencia de billing y estados awaiting_remote/review.',
            ],
            [
                'key' => 'item_scroll',
                'label' => 'Fase 5 · publicaciones continuas',
                'setting' => 'commercial_path.item_scroll_continuity_enabled',
                'kind' => 'cli_remote_read',
                'description' => 'Continuidad de scroll_id sin bucles infinitos ni mezcla offset/cursor.',
            ],
            [
                'key' => 'pacing',
                'label' => 'Fase 6 · pacing y capacidad materializada',
                'setting' => 'commercial_path.materialized_pacing_enabled',
                'kind' => 'local_policy',
                'description' => 'La solicitud API lee política ya calculada; no recalcula percentiles en caliente.',
            ],
        ];
    }

    /**
     * @param array<string,string|null>|null $settings valores ya cargados, útil para pruebas.
     * @return array{
     *   version:string,
     *   remote_writes_allowed:bool,
     *   web_remote_transport_allowed:bool,
     *   api_map_required:bool,
     *   phases:list<array{key:string,label:string,setting:string,kind:string,description:string,enabled:bool}>,
     *   next_phase:?array{key:string,label:string,setting:string,kind:string,description:string,enabled:bool}
     * }
     */
    public function snapshot(?array $settings = null): array
    {
        $defaults = [
            'commercial_path.remote_writes_allowed' => '0',
            'commercial_path.web_views_remote_transport_allowed' => '0',
            'commercial_path.require_api_map_confirmed_endpoints' => '1',
        ];
        foreach ($this->phases() as $phase) {
            $defaults[$phase['setting']] = in_array($phase['key'], ['foundation', 'coverage'], true) ? '1' : '0';
        }

        $values = $settings ?? (new AppSettingsService())->getMany($defaults);
        $phases = [];
        $next = null;

        foreach ($this->phases() as $phase) {
            $enabled = $this->truthy($values[$phase['setting']] ?? $defaults[$phase['setting']] ?? '0');
            $row = $phase + ['enabled' => $enabled];
            $phases[] = $row;
            if ($next === null && !$enabled) {
                $next = $row;
            }
        }

        return [
            'version' => self::VERSION,
            'remote_writes_allowed' => $this->truthy($values['commercial_path.remote_writes_allowed'] ?? '0'),
            'web_remote_transport_allowed' => $this->truthy($values['commercial_path.web_views_remote_transport_allowed'] ?? '0'),
            'api_map_required' => $this->truthy($values['commercial_path.require_api_map_confirmed_endpoints'] ?? '1'),
            'phases' => $phases,
            'next_phase' => $next,
        ];
    }

    private function truthy(?string $value): bool
    {
        return filter_var($value ?? '0', FILTER_VALIDATE_BOOL);
    }
}
