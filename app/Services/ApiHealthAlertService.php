<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;

final class ApiHealthAlertService
{
    /** @return array<string,mixed>|null */
    public function current(): ?array
    {
        try {
            if (!(new AppSettingsService())->bool('api.health.global_banner_enabled', true)) {
                return null;
            }
            $cached = (new ReadModelCacheService())->rememberArray(
                'api-health-alert',
                'admin-global',
                45,
                function (): array {
                    $health = (new ApiHealthService())->summary(24);
                    $pause = (new ApiManualPauseService())->summary();
                    $risk = (string) ($health['risk'] ?? 'low');
                    if (!empty($pause['active'])) {
                        return [
                            'visible' => true,
                            'level' => 'paused',
                            'title' => !empty($pause['global']) ? 'Consultas a Mercado Libre pausadas' : 'Una cuenta Mercado Libre está pausada',
                            'message' => 'Los eventos entrantes se guardan y los trabajos conservan su progreso.',
                        ];
                    }
                    if ($risk === 'critical') {
                        return ['visible' => true, 'level' => 'critical', 'title' => 'Alerta crítica de integración', 'message' => 'Mantenga pausadas las consultas y revise el incidente activo.'];
                    }
                    if (in_array($risk, ['high', 'medium'], true)) {
                        return ['visible' => true, 'level' => 'warning', 'title' => 'La integración necesita revisión', 'message' => 'Abra Salud API para conocer la causa y la acción recomendada.'];
                    }
                    return ['visible' => false, 'level' => 'healthy', 'title' => 'Integración disponible', 'message' => 'Sin señales actuales de bloqueo.'];
                }
            );
            $value = $cached['value'];
            return !empty($value['visible']) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }
}
