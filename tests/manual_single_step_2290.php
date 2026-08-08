<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$service = (string) file_get_contents($root . '/app/Services/ManualSingleStepService.php');
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
$check(!str_contains($startBody, 'ManualCampaignService())->start'), 'Procesar ahora no debe crear una campana persistente.');
$check(str_contains($startBody, 'ManualSingleStepService())->execute'), 'Procesar ahora debe ejecutar un paso exacto.');
$check(str_contains($service, "SELECT GET_LOCK(?,3)"), 'El paso manual debe serializar doble click y replay.');
$check(str_contains($service, "'source' => 'manual_campaign'"), 'El limite de un HTTP debe permanecer activo durante el paso.');
$check(str_contains($service, "microtime(true) + 25"), 'La peticion web debe tener un deadline corto y sin continuacion.');
$check(str_contains($view, 'Procesar un trabajo'), 'La interfaz debe describir la accion unitaria.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "OK manual_single_step_2290\n";
