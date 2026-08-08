<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$syncController = (string) file_get_contents($root . '/app/Controllers/SyncController.php');
$recurring = (string) file_get_contents($root . '/app/Services/RecurringSyncService.php');
$check(substr_count($syncController, 'new BusinessScopeContext())->accountIds(') >= 2, 'Reglas recurrentes web deben resolver cuentas autorizadas.');
$check(str_contains($recurring, 'public function rules(array $accountIds)') && str_contains($recurring, 'WHERE a.id IN ('), 'Lectura recurrente debe filtrar cuentas en SQL.');
$check(str_contains($recurring, '!isset($allowed[$accountId])'), 'Guardado recurrente debe descartar account_id ajenos.');

$notificationController = (string) file_get_contents($root . '/app/Controllers/NotificationController.php');
$workItems = (string) file_get_contents($root . '/app/Services/NotificationWorkItemService.php');
$webhooks = (string) file_get_contents($root . '/app/Services/WebhookService.php');
$check(str_contains($notificationController, 'notificationMutationScope()'), 'Pausa y reanudación deben validar la cuenta solicitada.');
$check(substr_count($notificationController, '$this->authorizedAccountIds()') >= 4, 'Mutaciones de notificación deben recibir el alcance autorizado.');
$check(str_contains($notificationController, 'JOIN companies c ON c.id=a.company_id') && str_contains($notificationController, 'WHERE a.id IN ('), 'Selector de notificaciones debe listar solo cuentas autorizadas.');
$check(substr_count($workItems, 'meli_account_id IN (') >= 3, 'Pausa, reanudación y retry deben filtrar cuentas en SQL.');
$check(substr_count($webhooks, 'meli_account_id IN (') >= 3, 'Leer, descartar y reencolar deben filtrar cuentas en SQL.');

$settings = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$workerRecovery = (string) file_get_contents($root . '/app/Services/NotificationWorkerRecoveryService.php');
$collation = (string) file_get_contents($root . '/app/Services/NotificationCollationRecoveryService.php');
$remediation = (string) file_get_contents($root . '/app/Services/WorkRemediationService.php');
$check(substr_count($settings, 'new BusinessScopeContext())->accountIds(') >= 5, 'POST de Cron debe resolver alcance antes de mutar.');
$check(str_contains($workerRecovery, 'w.meli_account_id IN ('), 'Recuperación de conflictos debe filtrar candidatos por cuenta.');
$check(
    str_contains($remediation, 'public function groups(?int $userId = null)')
        && str_contains($remediation, '(new BusinessScopeContext())->accountIds($userId)')
        && !str_contains($remediation, 'recoverKnownErrors('),
    'El centro de intervención debe ser read-only, scoped y no ejecutar remediaciones grupales.'
);
$check(str_contains($collation, 'assertRunAuthorized') && str_contains($collation, 'runIsAuthorized'), 'Recuperación de collation debe rechazar corridas de otros tenants.');
$check(str_contains($settings, 'foreach ($accountIds as $accountId)') && str_contains($settings, 'reprogramOverdue($accountId, $runAt)'), 'Reprogramación web no debe usar account_id=0 global.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL {$failure}\n");
    }
    exit(1);
}

echo "PASS web_mutation_scope_22812\n";
