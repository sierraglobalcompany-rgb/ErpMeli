<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$policy = (string) file_get_contents($root . '/app/Services/CronBatchPolicyService.php');
$registry = (string) file_get_contents($root . '/app/Services/CronTaskDefinitionRegistry.php');
$cron = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
$runService = (string) file_get_contents($root . '/app/Services/WorkQueueRunService.php');
$readModel = (string) file_get_contents($root . '/app/Services/CronOperationalReadService.php');
$view = (string) file_get_contents($root . '/app/Views/settings/cron_shell.php');
$javascript = (string) file_get_contents($root . '/public/assets/app.js');
$css = (string) file_get_contents($root . '/public/assets/app.css');
$migration = (string) file_get_contents($root . '/database/migrations/205_cron_observed_human_monitor_2_28_25.sql');

foreach ([
    "'notification_fallback' => \$this->count('cron.notification_batch_limit', 5, 20",
    "'order_enrichment' => \$this->count('cron.order_enrichment_batch_limit', 5, 20",
    "'sale_pack_reconciliation' => \$this->count('cron.pack_reconciliation_batch_limit', 5, 10",
    "'sale_financial_reconciliation' => \$this->count('cron.financial_reconciliation_batch_limit', 3, 10",
] as $contract) {
    $check(str_contains($policy, $contract), 'Falta la política efectiva: ' . $contract);
}
$check(str_contains($registry, '$this->batchPolicy->policy($key)'), 'El registro visible no consume la política de lotes.');
$check(str_contains($policy, '$this->settings->getMany($defaults)'), 'La política debe cargar todos los límites en una sola consulta.');
$check(str_contains($cron, 'CronBatchPolicyService($settings)') && substr_count($cron, '$batchPolicy->limit(') >= 10, 'Los callbacks CLI no comparten la política visible.');

foreach (['batch_configured', 'batch_effective', 'batch_executed', 'batch_limit_reason'] as $column) {
    $check(str_contains($migration, $column), 'La migración no persiste ' . $column . '.');
    $check(str_contains($runService, $column), 'La ejecución no registra ' . $column . '.');
    $check(str_contains($readModel, "'{$column}'"), 'El read model no publica ' . $column . '.');
}

$check(substr_count($readModel, '$this->markPartial();') >= 5, 'Los fallos de métricas todavía pueden convertirse en ceros autoritativos.');
$check(str_contains($readModel, "'hour_metrics_state'"), 'El estado de métricas horarias no está tipado.');
$check(str_contains($view, "isset(\$workload['remote_calls_last_hour'])"), 'El primer HTML todavía convierte una métrica desconocida en cero.');
$check(str_contains($javascript, 'mergeDefined') && str_contains($javascript, 'overviewFailures') && str_contains($javascript, 'taskFailures'), 'Polling parcial o backoff independiente incompletos.');
$check(str_contains($javascript, "dataset.label = 'Pendientes'") && str_contains($javascript, 'batch_summary_label'), 'La tabla no ofrece etiquetas móviles o explicación del lote.');
$check(!str_contains($css, '.cron-task-table{min-width:780px}'), 'Cron todavía obliga una tabla de 780px en móvil.');
$check(str_contains($css, '.cron-task-table td[data-label]::before'), 'Cron no transforma filas en lectura móvil etiquetada.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "PASS cron_observed_human_monitor_22825\n");
