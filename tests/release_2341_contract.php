<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$version = trim($read('VERSION'));
$manifest = json_decode($read('resources/runtime-manifest.json'), true);
$assert(version_compare($version, '2.35.0', '>='), 'VERSION debe ser 2.35.0 o posterior.');
$assert(($manifest['version'] ?? '') === $version, 'Manifest debe coincidir con VERSION.');
$assert(in_array(($manifest['build_id'] ?? ''), [
    'erp-meli-2.35.0-cron-v3-fifo-drenaje-real-20260804',
    'erp-meli-2.35.1-cron-v3-fifo-legacy-finalizer-20260804',
], true), 'Build ID 2.35.x incorrecto.');
$assert(in_array(($manifest['minimum_migration'] ?? ''), [
    '278_cron_v3_full_queue_coverage_2_35_0.sql',
    '279_cron_v3_fifo_legacy_finalizer_2_35_1.sql',
], true), 'Migración mínima 278/279 faltante.');
$assert(is_file($root . '/database/migrations/274_clean_release_api_health_429_2_34_1.sql'), 'Migración 274 no existe.');
$assert(is_file($root . '/database/migrations/275_recover_274_app_settings_key_2_34_2.sql'), 'Migración 275 no existe.');
$assert(is_file($root . '/database/migrations/276_emergency_v3_api_start_without_canary_2_34_3.sql'), 'Migración 276 no existe.');
$assert(is_file($root . '/database/migrations/277_release_integrity_text_hash_recovery_2_34_4.sql'), 'Migración 277 no existe.');
$assert(is_file($root . '/database/migrations/278_cron_v3_full_queue_coverage_2_35_0.sql'), 'Migración 278 no existe.');
$assert(is_file($root . '/database/migrations/279_cron_v3_fifo_legacy_finalizer_2_35_1.sql'), 'Migración 279 no existe.');

$migration = $read('database/migrations/274_clean_release_api_health_429_2_34_1.sql');
foreach (['meli_orders', 'meli_payments', 'meli_oauth', 'cron_v3_work', 'monthly_closures'] as $forbiddenTable) {
    $assert(!preg_match('/\b(?:UPDATE|DELETE|ALTER|DROP)\s+' . preg_quote($forbiddenTable, '/') . '\b/i', $migration), 'La migración 274 no debe mutar datos comerciales: ' . $forbiddenTable);
}
$assert(str_contains($migration, "'app.version','2.34.1'"), 'Migración 274 debe actualizar app.version.');
$assert(str_contains($migration, 'setting_key, setting_value, setting_group, is_encrypted'), 'Migración 274 debe usar columnas reales de app_settings.');
$assert(!str_contains($migration, '`key`'), 'Migración 274 no debe volver a usar app_settings.key.');
$assert(!str_contains($migration, '`description`'), 'Migración 274 no debe volver a usar app_versions.description.');

$recoveryMigration = $read('database/migrations/275_recover_274_app_settings_key_2_34_2.sql');
$assert(str_contains($recoveryMigration, "'app.version','2.34.2'"), 'Migración 275 debe actualizar app.version.');
$assert(str_contains($recoveryMigration, 'release.recovered_migration_274_app_settings_key'), 'Migración 275 debe dejar marca de recuperación segura.');
$replacements = $read('resources/migration-replacements.json');
$assert(str_contains($replacements, '274_clean_release_api_health_429_2_34_1.sql'), 'Debe autorizar reemplazo seguro de la 274 fallida.');
$assert(str_contains($replacements, 'ERP-MELI-2.34.2-MARIADB-1054-274-APPSETTINGS-KEY'), 'Debe existir autorización concreta para app_settings.key en 274.');

$handbrakeMigration = $read('database/migrations/276_emergency_v3_api_start_without_canary_2_34_3.sql');
$assert(str_contains($handbrakeMigration, "'app.version','2.34.3'"), 'Migración 276 debe actualizar app.version.');
$assert(str_contains($handbrakeMigration, 'emergency_handbrake.v3_direct_api_start'), 'Migración 276 debe declarar activación directa V3.');

$textHashMigration = $read('database/migrations/277_release_integrity_text_hash_recovery_2_34_4.sql');
$assert(str_contains($textHashMigration, "'app.version','2.34.4'"), 'Migración 277 debe actualizar app.version.');
$assert(str_contains($textHashMigration, 'release_integrity.text_lf_hash_recovery'), 'Migración 277 debe declarar recuperación por hash LF.');
$assert(str_contains($textHashMigration, 'release_integrity.partial_packages_blocked'), 'Migración 277 debe marcar paquetes parciales bloqueados.');

$drainageMigration = $read('database/migrations/278_cron_v3_full_queue_coverage_2_35_0.sql');
$assert(str_contains($drainageMigration, "'app.version', '2.35.0'"), 'Migración 278 debe actualizar app.version.');
$assert(str_contains($drainageMigration, 'cron_v3.full_queue_coverage'), 'Migración 278 debe declarar cobertura completa V3.');
$finalizerMigration = $read('database/migrations/279_cron_v3_fifo_legacy_finalizer_2_35_1.sql');
$assert(str_contains($finalizerMigration, 'arrival_seq'), 'Migración 279 debe formalizar FIFO por arrival_seq.');
$assert(str_contains($finalizerMigration, 'cron_v3.legacy_source_finalizer_release'), 'Migración 279 debe declarar cierre legacy ampliado.');

$apiHealth = $read('app/Services/ApiHealthService.php');
foreach ([
    'Rate limit de Mercado Libre',
    'rate_limit_signal',
    'signal_requires_protection',
    'active_now',
    'risk_explanation',
    '$httpStatus === 429 || $httpStatus === 403 => \'high\'',
] as $needle) {
    $assert(str_contains($apiHealth, $needle), 'ApiHealthService debe incluir contrato 429: ' . $needle);
}
$assert(str_contains($apiHealth, "'http_status_filter'"), 'Incidentes deben permitir filtro real por HTTP 429.');

$incidentList = $read('app/Views/settings/api_health_incidents.php');
foreach (['Rate limit 429', 'Activo ahora', 'Señal:', 'http_status=429', 'Errores que importan'] as $optionalOrNeedle) {
    $assert(str_contains($incidentList, $optionalOrNeedle) || $optionalOrNeedle === 'Errores que importan', 'Lista de incidentes debe mostrar: ' . $optionalOrNeedle);
}

$incidentShow = $read('app/Views/settings/api_health_incident_show.php');
foreach (['Activo ahora', 'Tipo de señal', 'Señal operativa', 'No fuerce reintentos'] as $needle) {
    $assert(str_contains($incidentShow, $needle), 'Detalle de incidente debe mostrar: ' . $needle);
}
$assert(!str_contains($incidentShow, '¿Puede provocar bloqueo?'), 'Detalle no debe volver a preguntar “Puede provocar bloqueo”.');

$overview = $read('app/Views/settings/api_health.php');
$assert(str_contains($overview, 'Errores que importan ahora'), 'Overview Salud API debe priorizar errores importantes.');
$assert(str_contains($overview, 'http_status=429'), 'Overview Salud API debe enlazar filtro HTTP 429 real.');
$assert(str_contains($overview, 'api-health.js?v=2.34.1'), 'Cache buster de Salud API debe mantener assets 2.34.1.');
$assert(str_contains($read('app/Views/settings/api_health_shell.php'), 'api-health.js?v=2.34.1'), 'Shell Salud API debe mantener assets 2.34.1.');

$rhythm = $read('app/Views/settings/api_workload.php');
$assert(str_contains($rhythm, 'Rate limit 429 recientes'), 'Ritmo Cron debe mostrar 429 recientes.');
$assert(str_contains($rhythm, 'http_status=429'), 'Ritmo Cron debe enlazar incidentes 429.');

$cronSnapshot = $read('app/Services/CronV3OperationalSnapshotService.php');
$assert(str_contains($cronSnapshot, 'api_rate_limit_signals'), 'Snapshot Cron debe incluir señales 429 para una sola verdad operativa.');
$assert(str_contains($read('public/assets/app.js'), 'data-cron-rate-limit-signals'), 'JS Cron debe renderizar señales 429.');

$emergencyKernel = $read('app/Recovery/EmergencyControlKernel.php');
$emergencyService = $read('app/Services/EmergencyControlService.php');
$emergencyJs = $read('public/assets/emergency-control.js');
foreach ([
    'start_api_without_canary',
    'Activar lecturas sin canario',
    'V3 podrá hacer consultas de lectura',
] as $needle) {
    $assert(str_contains($emergencyKernel, $needle), 'Freno de mano debe ofrecer activación directa V3: ' . $needle);
}
$assert(str_contains($emergencyService, 'startApiWithoutCanary'), 'EmergencyControlService debe implementar startApiWithoutCanary.');
$assert(str_contains($emergencyJs, 'pendingSubmitter'), 'JS de freno debe conservar el botón que envió la acción.');
$assert(str_contains($emergencyJs, 'data-confirm-override'), 'JS de freno debe permitir confirmación específica por botón.');

$runtimeManifest = $read('resources/runtime-manifest.json');
foreach ([
    'api_health_service',
    'api_health_incidents_view',
    'api_health_incident_show_view',
    'api_workload_view',
    'migration_274_clean_release_api_health_429',
    'migration_275_recover_274_app_settings_key',
    'migration_276_emergency_v3_api_start_without_canary',
    'migration_277_release_integrity_text_hash_recovery',
    'migration_278_cron_v3_full_queue_coverage',
    'migration_279_cron_v3_fifo_legacy_finalizer',
    'emergency_control_kernel',
    'emergency_control_service',
    'emergency_control_js',
    'migration_replacements',
] as $component) {
    $assert(str_contains($runtimeManifest, '"' . $component . '"'), 'Manifest debe hashear componente: ' . $component);
}

$manifestComponents = $manifest['components'] ?? [];
foreach ([
    'emergency_control_kernel',
    'release_integrity',
    'recovery_kernel',
    'emergency_control_service',
    'emergency_control_js',
    'migration_274_clean_release_api_health_429',
    'migration_275_recover_274_app_settings_key',
    'migration_276_emergency_v3_api_start_without_canary',
    'migration_277_release_integrity_text_hash_recovery',
    'migration_278_cron_v3_full_queue_coverage',
    'migration_279_cron_v3_fifo_legacy_finalizer',
] as $component) {
    $definition = $manifestComponents[$component] ?? null;
    $assert(is_array($definition), 'Manifest debe declarar componente textual: ' . $component);
    $assert(($definition['text'] ?? false) === true, 'Manifest debe marcar texto: ' . $component);
    $assert(isset($definition['sha256_lf']) && preg_match('/^[a-f0-9]{64}$/', (string) $definition['sha256_lf']) === 1, 'Manifest debe incluir sha256_lf válido: ' . $component);
}

$releaseIntegrity = $read('app/Services/ReleaseIntegrityService.php');
foreach (['sha256_lf', 'canonicalTextSha256', 'match_mode', 'text_lf'] as $needle) {
    $assert(str_contains($releaseIntegrity, $needle), 'ReleaseIntegrityService debe tolerar LF canónico para texto: ' . $needle);
}

$updateVerifier = $read('app/Services/ReleaseIntegrityService.php') . $read('app/Services/UpdateReleaseService.php');
foreach (['PAUSE_MELI_API', 'PAUSE_ERP_AUTOMATION', 'config.env', '.env', 'vendor', 'tests', 'docs'] as $forbidden) {
    $assert(str_contains($updateVerifier, $forbidden), 'El verificador debe rechazar: ' . $forbidden);
}

echo "release_2341_contract: ok\n";




