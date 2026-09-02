<?php

declare(strict_types=1);

namespace App\Services;

final class AutomationHumanLanguageService
{
    /**
     * Operator-facing vocabulary for the call-centric automation center.
     *
     * Technical queue words may still appear in diagnostics, logs, tests and
     * documentation, but the primary operator UI should explain capacity as
     * physical API calls and outcomes as human work states.
     *
     * @return array<string, string>
     */
    public static function primaryTerms(): array
    {
        return [
            'capacity_unit' => 'llamadas API físicas',
            'ready' => 'pendientes disponibles',
            'waiting' => 'pendientes en espera',
            'review' => 'pendientes por revisar',
            'running' => 'en atención',
            'completed' => 'completados',
            'deferred' => 'aplazados',
            'manual_scope' => 'alcance del procesamiento',
            'automation_center' => 'centro de automatización',
            'technical_diagnostics' => 'diagnóstico técnico',
        ];
    }

    public static function callCapacityLabel(): string
    {
        return 'Llamadas API físicas por ciclo';
    }

    public static function manualAvailableLabel(): string
    {
        return 'Pendientes disponibles ahora';
    }

    public static function verifiedDataLabel(?string $checkedAtLabel): string
    {
        $suffix = trim((string) $checkedAtLabel);

        return $suffix === ''
            ? 'Datos verificados'
            : 'Datos verificados · última actualización ' . $suffix;
    }
}
