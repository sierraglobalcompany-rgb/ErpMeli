<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\View;

final class ContextHelpPresenter
{
    public static function button(string $key): string
    {
        $help = (new ContextHelpRepository())->get($key);
        if ($help === null || !(new AppSettingsService())->bool('ui.context_help_enabled', true)) {
            return '';
        }
        $body = implode("\n", [
            'Qué hace: ' . $help['what'],
            'Cuándo usarlo: ' . $help['when'],
            'Datos: ' . $help['data'],
            'Mercado Libre: ' . $help['remote'],
            'Impacto: ' . $help['impact'],
            'Cancelación: ' . $help['cancel'],
            'Riesgo: ' . $help['risk'],
            'Tiempo: ' . $help['time'],
        ]);
        return '<button type="button" class="context-help-button"'
            . ' aria-label="Ayuda: ' . View::e($help['title']) . '"'
            . ' data-context-help data-help-title="' . View::e($help['title']) . '"'
            . ' data-help-text="' . View::e($body) . '">?</button>';
    }
}
