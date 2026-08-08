<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$cli = $read('app/Services/CronV3Cli.php');
$client = $read('app/Services/MeliApiClient.php');
$adapter = $read('app/Services/CronV3LegacyQueueAdapter.php');
$snapshot = $read('app/Services/CronV3OperationalSnapshotService.php');
$rhythm = $read('app/Views/settings/api_workload.php');
$cronShell = $read('app/Views/settings/cron_shell.php');
$controller = $read('app/Controllers/SettingsController.php');

foreach ([
    '269_cron_v3_single_rate_authority_2_34_0.sql',
    '270_cron_v3_fifo_producers_import_repair_2_34_0.sql',
    '271_cron_v3_complete_producers_2_34_0.sql',
    '272_cron_v3_operational_truth_snapshot_2_34_0.sql',
    '273_cron_v3_parking_action_center_2_34_0.sql',
] as $migration) {
    $check(is_file($root . '/database/migrations/' . $migration), 'Falta migración ' . $migration);
}

$check(str_contains($cli, 'CronV3RatePolicyService') && str_contains($cli, 'currentLimit($fallbackRateLimit)'), 'Cron V3 CLI no usa la autoridad persistida de ritmo.');
$check(str_contains($client, "'cron_v3_remote'") && str_contains($client, "'cron_v3_rate_gate'"), 'MeliApiClient no evita doble ritmo cuando la llamada viene de V3 remoto.');
$check(str_contains($adapter, '$checkpoint = 0;') && str_contains($adapter, 'reentrant_fifo') && str_contains($adapter, 'NOT EXISTS'), 'El importador legacy no quedó reentrante por FIFO/dedupe.');
$check(str_contains($adapter, 'waiting_identity') && str_contains($adapter, 'waiting_capability'), 'El parking lot no separa identidad/capacidad.');
$check(str_contains($snapshot, 'rate_policy') && str_contains($snapshot, 'V3 está drenando la fila lista'), 'Snapshot operacional no publica ritmo único o mensaje FIFO humano.');
$check(str_contains($controller, "cron_v3.rate_authority") && str_contains($controller, "api.rhythm"), 'Guardar ritmo no declara autoridad V3 persistida.');

foreach ([
    'Velocidad máxima deseada',
    'Cómo subir gradualmente',
    'Condiciones de seguridad',
    'Máximo que quiere permitir',
    'Subir por estos escalones',
    'Tiempo mínimo antes de subir',
    'Mínimo de respuestas exitosas',
    'Tiempo máximo normal por consulta',
    'Simulación antes de guardar',
    'Personalizado avanzado',
] as $humanText) {
    $check(str_contains($rhythm, $humanText), 'Falta texto humano en ritmo: ' . $humanText);
}

$check(!str_contains($rhythm, 'Rampa: Array'), 'La vista de ritmo todavía puede mostrar Rampa: Array.');
$check(str_contains($cronShell, 'Tomará el trabajo listo más antiguo; lo parqueado queda aparte'), 'Cron shell no explica FIFO/parqueados.');

if ($failures !== []) {
    fwrite(STDERR, "FAIL cron_v3_fifo_rate_human_2340\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "PASS cron_v3_fifo_rate_human_2340\n";
