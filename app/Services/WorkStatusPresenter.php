<?php

declare(strict_types=1);

namespace App\Services;

final class WorkStatusPresenter
{
    /** @return array{label:string,tone:string,icon:string} */
    public function present(string $status): array
    {
        return match ($status) {
            'completed' => ['label' => 'Completado', 'tone' => 'green', 'icon' => '✓'],
            'running' => ['label' => 'Ejecutándose', 'tone' => 'blue', 'icon' => '↻'],
            'waiting_budget' => ['label' => 'Esperando presupuesto', 'tone' => 'amber', 'icon' => '◷'],
            'scheduled' => ['label' => 'Programado para después', 'tone' => 'amber', 'icon' => '◷'],
            'retry' => ['label' => 'Se reintentará', 'tone' => 'amber', 'icon' => '↻'],
            'error' => ['label' => 'Requiere atención', 'tone' => 'red', 'icon' => '!'],
            'paused' => ['label' => 'Pausado', 'tone' => 'gray', 'icon' => 'Ⅱ'],
            default => ['label' => 'En espera', 'tone' => 'gray', 'icon' => '•'],
        };
    }
}
