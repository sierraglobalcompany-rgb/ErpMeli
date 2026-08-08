<?php

declare(strict_types=1);

namespace App\Services;

final class HumanGlossary
{
    public static function label(string $value): string
    {
        return match (strtolower(trim($value))) {
            'queued', 'pending' => 'En espera',
            'deferred', 'scheduled' => 'Programado para después',
            'retry' => 'Se reintentará',
            'success', 'complete', 'completed' => 'Completado',
            'local_recalc' => 'Cálculo local',
            'cooldown' => 'Disponible de nuevo',
            'endpoint' => 'Operación de Mercado Libre',
            'job' => 'Trabajo',
            'lease' => 'Reserva temporal del trabajo',
            'running' => 'Ejecutándose',
            'error', 'failed' => 'Requiere atención',
            'paused' => 'Pausado',
            default => ucfirst(str_replace('_', ' ', $value)),
        };
    }
}
