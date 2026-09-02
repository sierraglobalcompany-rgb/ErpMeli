<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';

$manual = file_get_contents(__DIR__ . '/../app/Views/settings/manual_processing.php');
$singleStep = file_get_contents(__DIR__ . '/../app/Services/ManualSingleStepService.php');

k1b_assert(is_string($manual) && is_string($singleStep), 'Manual files must be readable.');
k1b_assert(str_contains($manual, 'Pendientes disponibles ahora'), 'Manual must lead with pending work available now.');
k1b_assert(str_contains($manual, 'Máximo de llamadas API'), 'Manual must expose API call capacity.');
k1b_assert(str_contains($manual, 'Límite de llamadas API'), 'Manual preview must expose API call limit.');
k1b_assert(str_contains($singleStep, 'Pendientes disponibles: llamadas API solicitadas'), 'Manual result message must be call-centric.');

foreach (['Cola disponible', 'Trabajos procesados', 'Alcance del trabajo', 'Límite elegido'] as $forbidden) {
    k1b_assert(!str_contains($manual, $forbidden), "Manual primary copy still contains {$forbidden}");
}

echo "STATUS=PASS K1C_MANUAL_PROCESSING_CALL_CAPACITY\n";
