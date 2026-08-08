<?php

declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/app/Services/ClaimSyncService.php');
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$searchStart = strpos($source, 'public function fetchOpenedPage');
$legacyStart = strpos($source, 'public function syncOpened');
$exactStart = strpos($source, 'public function syncClaimById');
$check($searchStart !== false && $legacyStart !== false && $exactStart !== false, 'Faltan los contratos separados de reclamos.');
if (is_int($searchStart) && is_int($legacyStart)) {
    $searchBody = substr($source, $searchStart, $legacyStart - $searchStart);
    $check(substr_count($searchBody, '$this->api->get(') === 1, 'Una pagina de reclamos debe ejecutar exactamente un HTTP.');
    $check(str_contains($searchBody, 'min(20, $limit)'), 'La pagina de reclamos debe limitarse a 20 recursos.');
}
if (is_int($legacyStart) && is_int($exactStart)) {
    $legacyBody = substr($source, $legacyStart, $exactStart - $legacyStart);
    $check(!str_contains($legacyBody, '/post-purchase/v1/claims/'), 'El flujo legado no debe volver a ejecutar 1+N detalles.');
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "OK claims_unit_work_2290\n";
