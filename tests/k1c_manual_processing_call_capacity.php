<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';

$manual = file_get_contents(__DIR__ . '/../app/Views/settings/manual_processing.php');
$singleStep = file_get_contents(__DIR__ . '/../app/Services/ManualSingleStepService.php');
$settingsController = file_get_contents(__DIR__ . '/../app/Controllers/SettingsController.php');

k1b_assert(is_string($manual) && is_string($singleStep) && is_string($settingsController), 'Manual files must be readable.');
k1b_assert(str_contains($manual, 'Pendientes disponibles ahora'), 'Manual must lead with pending work available now.');
k1b_assert(str_contains($manual, 'Máximo de llamadas API'), 'Manual must expose API call capacity.');
k1b_assert(str_contains($manual, 'Límite de llamadas API'), 'Manual preview must expose API call limit.');
k1b_assert(str_contains($singleStep, 'Pendientes disponibles: llamadas API solicitadas'), 'Manual result message must be call-centric.');
k1b_assert(str_contains($manual, 'Pendientes disponibles atendidos'), 'Manual available queue result must use human pending-items copy.');
k1b_assert(!str_contains($settingsController, 'Cola disponible procesada.'), 'Manual available queue fallback flash must not use queue-centric copy.');

foreach ([
    'Cola disponible',
    'Cola disponible procesada',
    'Pausar cola',
    'Reanudar cola',
    'Cola única',
    'cola Webhook',
    'cola Webhook‑First',
    'cola automática',
    'Cola financiera activa',
    'procesar colas',
    'trabajos del cron',
    'Trabajos procesados',
    'Bloques por cron',
    'Recursos por lote',
    'Máximo por trabajo',
    'Descripciones por lote',
    'Pausa entre lotes',
    'Alcance del trabajo',
    'Límite elegido',
] as $forbidden) {
    k1b_assert(!str_contains($manual, $forbidden), "Manual primary copy still contains {$forbidden}");
}

echo "STATUS=PASS K1C_MANUAL_PROCESSING_CALL_CAPACITY\n";
