<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$sync = (string) file_get_contents($root . '/app/Services/SyncCenterService.php');
$check(str_contains($sync, 'private function webAccountScope()'), 'Sync debe resolver un alcance cerrado para peticiones web.');
$check(str_contains($sync, "if (\$scope === [])") && str_contains($sync, "\$where[] = '1=0'"), 'Sync sin cuentas autorizadas debe cerrar el predicado.');
$check(str_contains($sync, 'private function assertChunkAccess('), 'Las mutaciones de bloques deben validar el recurso exacto.');
$check(str_contains($sync, 'private function assertBatchAccess('), 'Las mutaciones de planes deben validar el recurso exacto.');

$financial = (string) file_get_contents($root . '/app/Services/OrderFinancialRecalcJobService.php');
$check(str_contains($financial, 'private function resolveOrderAccount('), 'Finanzas debe derivar y validar la cuenta de las órdenes exactas.');
$check(str_contains($financial, 'Una o más órdenes no pertenecen al alcance autorizado.'), 'Finanzas debe rechazar órdenes ajenas sin revelarlas.');
$check(str_contains($financial, 'Seleccione un trabajo financiero exacto para reintentar.'), 'El reintento web financiero no debe liberar lotes globales.');
$check(str_contains($financial, 'SafeErrorPresenter::message('), 'Finanzas debe persistir mensajes seguros y no excepciones PDO crudas.');

$notifications = (string) file_get_contents($root . '/app/Services/NotificationWorkItemService.php');
$webhooks = (string) file_get_contents($root . '/app/Services/WebhookService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/NotificationController.php');
$check(str_contains($notifications, 'private function readScope('), 'Lecturas de trabajos de notificación deben recibir scope.');
$check(str_contains($webhooks, 'appendAllowedAccounts(') && str_contains($webhooks, "\$where[] = '1=0'"), 'Webhook sin cuentas autorizadas debe devolver cero filas.');
$check(str_contains($controller, 'Seleccione un trabajo exacto para reintentarlo.'), 'Notificaciones debe exigir un recurso exacto para reintentar.');
$check(str_contains($controller, 'La pausa masiva desde el navegador fue retirada.'), 'Notificaciones no debe pausar lotes completos desde HTTP.');
$check(str_contains($controller, 'El análisis histórico global desde web fue retirado.'), 'Backfill global no debe iniciarse desde HTTP.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL {$failure}\n");
    }
    exit(1);
}

echo "PASS tenant_scope_core_22818\n";
