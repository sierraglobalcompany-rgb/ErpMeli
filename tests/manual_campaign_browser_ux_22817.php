<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$sessionView = (string) file_get_contents($root . '/app/Views/settings/manual_processing_session.php');
$itemsView = (string) file_get_contents($root . '/app/Views/settings/manual_processing_session_list.php');
$workView = (string) file_get_contents($root . '/app/Views/settings/automation_work.php');
$campaignService = (string) file_get_contents($root . '/app/Services/ManualCampaignService.php');
$script = (string) file_get_contents($root . '/public/assets/app.js');
$styles = (string) file_get_contents($root . '/public/assets/app.css');

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$check(str_contains($sessionView, 'data-processing-paused=')
    && str_contains($sessionView, 'data-continues-with-attention='),
    'El monitor no conserva el último estado comprobado de pausa/continuación.');
$check(str_contains($sessionView, 'Cron continúa con los demás trabajos.')
    && str_contains($sessionView, 'no cuentan como completados'),
    'Un error aislado sigue presentado como pausa o progreso aprobado.');
$check(!str_contains($sessionView, 'Procesamiento pausado'),
    'La campaña conserva el falso mensaje fijo Procesamiento pausado.');

$check(str_contains($itemsView, '$approved = (int) $row[\'completed_units\']')
    && !str_contains($itemsView, '$done = (int) $row[\'completed_units\'] +'),
    'La lista todavía suma errores u omitidos al progreso aprobado.');
$check(str_contains($itemsView, 'requieren revisión; no cuentan como completadas'),
    'La lista no explica por qué un error no aumenta el progreso.');
$check(str_contains($campaignService, '$leaseActive && $heartbeatActive')
    && str_contains($campaignService, "? 'En curso'")
    && str_contains($campaignService, ": 'Intento vencido'"),
    'En curso no exige lease y heartbeat vigentes.');

$check(str_contains($script, "Object.prototype.hasOwnProperty.call(session, 'completed_items')")
    || str_contains($script, "updateCounter('[data-manual-completed]', 'completed_items')"),
    'Un JSON parcial puede sustituir los completados existentes por cero.');
$check(str_contains($script, 'Se conserva el último estado comprobado.')
    && !str_contains($script, "catch (_) {\n      setState('waiting_launcher'"),
    'Un fallo de red todavía se convierte falsamente en lanzador detenido.');
$check(str_contains($script, "['ArrowLeft', 'ArrowRight', 'Home', 'End']")
    && str_contains($script, 'target?.focus()'),
    'Las pestañas del monitor no admiten navegación de teclado completa.');

foreach (['Qué pasó', 'Qué hará el ERP', 'Qué puede hacer ahora', '¿Llegó a Mercado Libre?', 'Ver detalles técnicos'] as $label) {
    $check(str_contains($workView, $label), 'El detalle exacto no muestra: ' . $label);
}
$check(str_contains($workView, 'expected_generation') && str_contains($workView, 'action_nonce'),
    'Las acciones exactas no muestran su fencing/idempotencia en el formulario.');
$check(str_contains($styles, '@media(max-width:420px)') && str_contains($styles, '.work-resolution-action .btn{width:100%'),
    'El detalle exacto no conserva un layout utilizable en móvil.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "PASS manual_campaign_browser_ux_22817\n");

