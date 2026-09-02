<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';

use App\Services\AutomationHumanLanguageService;

$terms = AutomationHumanLanguageService::primaryTerms();

k1b_assert(($terms['capacity_unit'] ?? '') === 'llamadas API físicas', 'Primary capacity unit must be physical API calls.');
k1b_assert(AutomationHumanLanguageService::callCapacityLabel() === 'Llamadas API físicas por ciclo', 'Call capacity label must be human and call-centric.');
k1b_assert(AutomationHumanLanguageService::manualAvailableLabel() === 'Pendientes disponibles ahora', 'Manual available queue must be presented as pending work.');
k1b_assert(
    AutomationHumanLanguageService::verifiedDataLabel('01/09 10:00') === 'Datos verificados · última actualización 01/09 10:00',
    'Verified data label must hide implementation-source names.'
);

echo "STATUS=PASS K1C_AUTOMATION_UI_TERMS\n";
