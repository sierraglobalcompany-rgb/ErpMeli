<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$service = (string) file_get_contents($root . '/app/Services/ManualSingleStepService.php');
$launcher = (string) file_get_contents($root . '/app/QueueCore/ManualQueueLauncher.php');
$view = (string) file_get_contents($root . '/app/Views/settings/manual_processing.php');
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$startAt = strpos($controller, 'public function manualProcessingStart');
$sessionItemsAt = strpos($controller, 'public function manualProcessingSessionItems');
$startBody = is_int($startAt) && is_int($sessionItemsAt)
    ? substr($controller, $startAt, $sessionItemsAt - $startAt)
    : '';

$check(!str_contains($startBody, 'ManualCampaignService())->start'), 'Procesar ahora no debe crear una campaña persistente.');
$check(str_contains($startBody, 'ManualSingleStepService())->executeMany'), 'Procesar ahora debe ejecutar la selección exacta con límite N.');
$check(str_contains($service, 'SELECT GET_LOCK(?,3)'), 'El paso manual debe serializar doble click y replay.');
$check(str_contains($service, 'ManualQueueLauncher'), 'El paso manual debe entrar por Queue Core.');
$check(str_contains($service, 'array_slice($rows, 0, $limit)'), 'El paso manual debe quedar ligado al preview exacto.');
$check(!str_contains($service, 'processExact($sourceId'), 'El launcher web no debe invocar handlers directamente.');
$check(str_contains($launcher, 'runExactBatch(array $items)'), 'El launcher debe ejecutar selección exacta bajo una sola autoridad.');
$check(substr_count($launcher, "->acquire('manual'") === 1, 'El launcher no debe adquirir un segundo lease global por lote.');
$check(str_contains($launcher, "'manual',\$jobId"), 'El runner debe quedar cercado al job exacto seleccionado.');
$check(str_contains($view, 'Procesar selección exacta'), 'La interfaz debe describir selección exacta.');
$check(str_contains($view, 'Sin campaña, sin sesión, sin continuación oculta'), 'La interfaz debe declarar no background.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "OK manual_single_step_2290\n";
