<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static fn(string $path): string => (string) file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));

$rhythm = $read('app/Views/settings/api_workload.php');
$cron = $read('app/Views/settings/cron_shell.php');
$history = $read('app/Views/settings/automation_history.php');
$run = $read('app/Views/settings/automation_run.php');
$campaign = $read('app/Views/settings/manual_processing_session.php');
$js = $read('public/assets/app.js');
$css = $read('public/assets/app.css');
$migration = $read('database/migrations/213_human_rhythm_capacity_certification_2_28_33.sql');

foreach ([
    "'conservative' => ['Conservador', 10",
    "'balanced' => ['Equilibrado', 20",
    "'fast' => ['Rápido', 30",
    "'maximum' => ['Máximo controlado', 40",
] as $profileContract) {
    $check(str_contains($rhythm, $profileContract), 'Falta el perfil: ' . $profileContract);
}
$check(str_contains($rhythm, 'Observado · 15 min') && str_contains($rhythm, 'Observado · 60 min'), 'La vista no separa las ventanas observadas.');
$check(str_contains($rhythm, 'Recursos aceptados por HTTP') && str_contains($rhythm, 'Solo incluye respuestas con conteo certificado') && str_contains($rhythm, 'Una salida HTTP puede recibir uno o varios recursos'), 'La vista todavía confunde HTTP con datos o finalizaciones.');
$check(str_contains($rhythm, 'Techo teórico:') && str_contains(strtolower($rhythm), 'el ritmo real puede ser menor'), 'La vista debe distinguir techo y rendimiento real.');
$check(str_contains($rhythm, 'name="profile"') && str_contains($rhythm, 'name="adaptive_enabled"'), 'El formulario perdió el perfil o la rampa adaptativa.');
$check(!str_contains($rhythm, 'name="calls_per_block"'), 'La interfaz humana no debe presentar el contrato legado por bloque.');

$check(str_contains($cron, 'funciones reclamadas') && str_contains($cron, 'recursos finalizados'), 'Cron no explicita las unidades de sus contadores.');
$check(str_contains($history, 'Avanzó con aplazamientos') && str_contains($history, 'transportes HTTP iniciados'), 'El historial no distingue avance mixto o transportes.');
$check(str_contains($run, 'funciones reclamadas') && str_contains($run, 'recursos finalizados'), 'El detalle del ciclo sigue mezclando unidades.');
$check(str_contains($campaign, 'HTTP confirmados') && str_contains($campaign, 'transportes HTTP en la ventana actual'), 'La campaña sigue llamando consultas a los transportes.');
$check(str_contains($js, 'funciones reclamadas') && str_contains($js, 'data-rhythm-preview-target'), 'El refresco del navegador no conserva el lenguaje humano.');
$check(str_contains($css, '.rhythm-profile-grid-four') && str_contains($css, '.rhythm-selection-preview'), 'Faltan estilos responsive para los cuatro perfiles.');
$check(str_contains($rhythm, 'rhythm-profile-fieldset') && str_contains($rhythm, '<legend>Perfil de velocidad HTTP</legend>'), 'Los perfiles no tienen un grupo accesible para lectores de pantalla.');
$check(str_contains($rhythm, 'todas las cuentas autorizadas'), 'Las métricas globales no explican su alcance.');
$check(str_contains($migration, "('app.version','2.28.33'") && str_contains($migration, 'INSERT IGNORE INTO app_settings'), 'La migración no versiona o puede sobrescribir defaults.');

if ($failures !== []) {
    fwrite(STDERR, "FAIL cron_human_rhythm_capacity_22833\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "PASS cron_human_rhythm_capacity_22833\n";
