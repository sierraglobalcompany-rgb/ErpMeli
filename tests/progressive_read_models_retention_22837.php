<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$cache = (string) file_get_contents($root . '/app/Services/ReadModelCacheService.php');
$coordination = (string) file_get_contents($root . '/app/Services/ExecutionCoordinationService.php');
$cronState = (string) file_get_contents($root . '/app/Services/CronTaskStateService.php');
$backlog = (string) file_get_contents($root . '/app/Services/CronBacklogSnapshotService.php');
$retention = (string) file_get_contents($root . '/app/Services/RetentionPolicyService.php');
$archive = (string) file_get_contents($root . '/app/Services/ColdArchiveService.php');
$maintenance = (string) file_get_contents($root . '/app/Services/DatabaseMaintenanceService.php');
$migration = (string) file_get_contents($root . '/database/migrations/217_progressive_read_models_retention_2_28_37.sql');

$check(
    str_contains($cache, "':refresh-lock'")
        && str_contains($cache, "'.refresh.lock'")
        && str_contains($cache, 'stale-error-file')
        && str_contains($cache, 'hit-after-wait-file'),
    'La caché debe cercar recomputaciones y conservar un valor stale ante fallos o concurrencia.'
);
$check(
    str_contains($coordination, 'reservedQueueKeysReadOnly')
        && str_contains($cronState, 'reservedQueueKeysReadOnly'),
    'El preview de Cron debe consultar reservas mediante un camino explícitamente read-only.'
);
$preview = explode('public function preview(', $cronState, 2)[1] ?? '';
$previewParts = explode("\n    public function ", $preview, 2);
$preview = $previewParts[0];
$check(
    !str_contains($preview, 'releaseAbandoned')
        && !preg_match('/\b(?:INSERT|UPDATE|DELETE)\b/i', $preview),
    'El preview utilizado por GET no puede reconciliar ni modificar sesiones.'
);
$check(
    str_contains($backlog, 'system_cron_backlog_run_totals')
        && str_contains($backlog, 'private function recordRunTotal')
        && str_contains($backlog, 'ORDER BY measured_at DESC,run_token DESC'),
    'Las tendencias deben consumir un total O(1) por ciclo, no reagrupar todos los snapshots.'
);
foreach (['cron_backlog_snapshots', 'cron_backlog_run_totals', 'manual_campaign_events'] as $dataset) {
    $check(str_contains($retention, "'{$dataset}'"), "Retención no registra {$dataset}.");
    $check(str_contains($archive, "'{$dataset}'"), "Archivo frío no registra {$dataset}.");
    $check(str_contains($maintenance, "'{$dataset}'"), "Saneamiento no recorre {$dataset}.");
}
foreach ([
    'system_cron_backlog_run_totals',
    'idx_cron_backlog_retention',
    'idx_work_run_items_latest_batch',
    'idx_manual_campaign_event_retention',
    'idx_work_projection_scope_status_queue',
] as $contract) {
    $check(str_contains($migration, $contract), "Migración 217 no incluye {$contract}.");
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "PASS progressive_read_models_retention_22837\n");
