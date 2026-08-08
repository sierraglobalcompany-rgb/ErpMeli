<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$planner = (string) file_get_contents($root . '/app/Services/CronExecutionPlanner.php');
$lanes = (string) file_get_contents($root . '/app/Services/CronLaneBudgetService.php');
$coordinator = (string) file_get_contents($root . '/app/Services/CronWorkCoordinator.php');
$entrypoint = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
$campaign = (string) file_get_contents($root . '/app/Services/ManualCampaignService.php');
$manifest = json_decode((string) file_get_contents($root . '/resources/runtime-manifest.json'), true);

$check(!str_contains($planner, 'closedForDeadline'), 'El planner no debe cerrar todo el minuto por una cola que no cabe.');
$check(str_contains($planner, '$this->exhausted[$key] = true;')
    && str_contains($planner, 'not_started_deadline'), 'Un deadline debe agotar solo esa función y dejar trazabilidad.');
$check(str_contains($planner, 'notStartedReasons'), 'Falta exponer causas no iniciadas para diagnóstico.');
$check(str_contains($lanes, 'cron.directed_min_start_seconds'), 'El carril dirigido debe usar margen mínimo de entrada.');
$check(str_contains($lanes, 'worker conserva el guard rail real'), 'La decisión exacta de ventana debe quedar en el worker de campaña.');
$check(str_contains($coordinator, 'startedFromUsefulWork'), 'started debe derivarse de trabajo útil, no del claim.');
$check(!str_contains($entrypoint, '$workRuns->started($workRunId, $key);' . PHP_EOL . '        $result = $coordinator->run('),
    'El entrypoint no puede marcar started antes de ejecutar el callback.');
$check(str_contains($entrypoint, "if (max(0, (int) (\$result['started'] ?? 0)) > 0)"),
    'La bitácora solo debe marcar started cuando el resultado lo confirme.');
$check(str_contains($campaign, 'hasRecentCampaignEvent'), 'La campaña debe deduplicar eventos repetitivos waiting_source/action_required.');
$check(str_contains($campaign, 'idx_campaign_event_dedupe') === false, 'El índice pertenece a la migración, no al código PHP.');
$check(is_array($manifest) && version_compare((string) ($manifest['version'] ?? ''), '2.28.59', '>='), 'El manifiesto debe preservar 2.28.59 o posterior.');
$check((int) ($manifest['minimum_migration'] ?? '') >= 239, 'La migración mínima debe ser 239 o posterior.');

foreach (range(220, 238) as $number) {
    $files = glob($root . '/database/migrations/' . $number . '_*.sql') ?: [];
    $check(count($files) === 1, 'Debe existir exactamente una migración ' . $number . '.');
}

$check(count(glob($root . '/database/migrations/239_*.sql') ?: []) === 1, 'Debe existir exactamente una migración 239.');

if ($failures !== []) {
    fwrite(STDERR, "FAIL cron_rebuilt_scheduler_22848\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "PASS cron_rebuilt_scheduler_22848\n";
