<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/app/Services/CronOperationalReadService.php');
$registry = (string) file_get_contents($root . '/app/Services/CronTaskDefinitionRegistry.php');
$view = (string) file_get_contents($root . '/app/Views/settings/cron_shell.php');
$js = (string) file_get_contents($root . '/public/assets/app.js');

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$check(str_contains($service, '$this->latestRun(false)'), 'El ciclo actual debe consultarse separado del último ciclo cerrado.');
$check(str_contains($service, '$this->latestRun(true)'), 'El último ciclo visible debe estar terminado.');
$check(str_contains($service, 'finished_at IS NOT NULL AND status<>"running"'), 'Un ciclo activo no debe reemplazar el resumen del último ciclo cerrado.');
$check(str_contains($service, "\$key === 'notification_spool'\n                    ? null"), 'El spool no debe enlazar a una cola SQL vacía.');
$check(str_contains($registry, "'Hasta 20 eventos por turno'"), 'El límite del lote de spool debe explicarse.');
$check(str_contains($service, "'minimum_batches'"), 'El read model debe estimar turnos mínimos sin prometer una ETA.');
$check(str_contains($view, '<th>Pendientes</th>') && str_contains($view, '<th>Finalizados/h</th>'), 'La interfaz debe separar backlog de throughput observado.');
$check(str_contains($view, '<th>Salidas HTTP/h</th>') && str_contains($view, '<th>ETA</th>'), 'La tabla debe separar transportes reales y estimación.');
$check(str_contains($js, 'task.pending_label') && str_contains($js, 'task.finalized_last_hour') && str_contains($js, 'task.eta_label'), 'El navegador debe renderizar las etiquetas humanas del servidor.');
$check(!str_contains($js, "link.href = task.url || '#'"), 'No se deben fabricar enlaces vacíos para tareas sin detalle navegable.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "PASS cron_read_model_ux_22810\n");
