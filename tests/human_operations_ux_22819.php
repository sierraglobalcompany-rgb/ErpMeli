<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Services/UiLabelPresenter.php';

use App\Services\UiLabelPresenter;

$root = dirname(__DIR__);
$cron = (string) file_get_contents($root . '/app/Views/settings/cron_shell.php');
$sync = (string) file_get_contents($root . '/app/Views/sync/index.php');
$events = (string) file_get_contents($root . '/app/Views/notifications/events.php');
$financial = (string) file_get_contents($root . '/app/Views/financial_recalc/index.php');
$backups = (string) file_get_contents($root . '/app/Views/settings/backups.php');
$maintenance = (string) file_get_contents($root . '/app/Views/settings/database_maintenance.php');
$users = (string) file_get_contents($root . '/app/Views/users/index.php');
$migrationDiagnostics = (string) file_get_contents($root . '/app/Views/settings/migration_diagnostics.php');
$styles = (string) file_get_contents($root . '/public/assets/ux.css');
$performance = (string) file_get_contents($root . '/public/assets/performance.js');

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$check(str_contains($cron, 'Qué está pasando')
    && str_contains($cron, 'Qué hará el ERP')
    && str_contains($cron, 'Qué puede hacer ahora'),
    'Cron no utiliza el patrón operativo humano completo.');
$check(str_contains($sync, 'safeOperationMessage') && str_contains($events, 'safeOperationMessage'),
    'Sincronización y eventos no sanitizan mensajes técnicos antes de mostrarlos.');
$check(!str_contains($financial, 'Reintentar financieros fallidos'),
    'Finanzas conserva una acción web masiva de reintento.');
$check(str_contains($financial, 'Qué hará el ERP') && str_contains($financial, 'Grupos pendientes'),
    'Finanzas no diferencia el trabajo agrupado del siguiente paso.');
$check(str_contains($backups, 'backup-history-table')
    && str_contains($backups, 'data-responsive="cards"')
    && str_contains($backups, 'data-label="Acciones"')
    && str_contains($backups, 'backup-row-actions'),
    'Copias no aplica el contrato responsive al historial y sus acciones.');
$check(str_contains($maintenance, 'Saneamiento lógico') && str_contains($maintenance, 'Recuperación física'),
    'Saneamiento no explica la diferencia entre eliminación lógica y reorganización física.');
$check(str_contains($users, 'Revise') && str_contains($users, 'no modificará estos accesos automáticamente'),
    'Usuarios no ofrece revisión administrativa sin mutación automática.');
$check(str_contains($styles, '.operation-explainer')
    && str_contains($styles, '.backup-history-wrap{width:100%;max-width:100%;overflow-x:auto')
    && str_contains($styles, '.page-content>*:not(.sr-only)')
    && str_contains($styles, '.backup-row-actions'),
    'Faltan patrones visuales compartidos o protección móvil de Copias.');
$check(str_contains($performance, 'sectionLabels') && str_contains($performance, 'safeError'),
    'Las secciones progresivas no identifican el área que falló o exponen detalles técnicos.');
$check(!str_contains($migrationDiagnostics, '<th>SQLSTATE</th>')
    && !str_contains($migrationDiagnostics, "event['sqlstate']")
    && str_contains($migrationDiagnostics, 'Referencia segura'),
    'Diagnóstico de migraciones todavía expone códigos internos en HTML.');

$technical = "SQLSTATE[HY000]: General error: 1267 Illegal mix of collations in /home/private/app.php";
$safe = UiLabelPresenter::safeOperationMessage($technical, 'ERR-LOCAL-123');
$check(!str_contains($safe, 'SQLSTATE') && !str_contains($safe, '/home/') && str_contains($safe, 'ERR-LOCAL-123'),
    'El presentador humano no filtra información técnica o pierde la referencia diagnóstica.');
$check(UiLabelPresenter::status('waiting_deadline') === 'Esperando el próximo ciclo',
    'waiting_deadline no se traduce como espera automática.');
$check(UiLabelPresenter::status('remote_result_uncertain') === 'Resultado remoto por confirmar',
    'El resultado remoto incierto no se presenta de forma humana.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "PASS human_operations_ux_22819\n");
