<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\CronV3DoctorService;

final class CronV3UnavailablePdo2291 extends PDO
{
    public function __construct()
    {
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        throw new RuntimeException('database unavailable in doctor test');
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        throw new RuntimeException('database unavailable in doctor test');
    }
}

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$root = dirname(__DIR__);
$doctorSource = (string) file_get_contents($root . '/app/Services/CronV3DoctorService.php');
$cliSource = (string) file_get_contents($root . '/app/Services/CronV3Cli.php');

$check(str_contains($doctorSource, "'read_only' => true"), 'El doctor debe declararse read-only.');
$check(str_contains($doctorSource, "'activation_authority' => 'env_resolver'"),
    'Falta activation_authority=env_resolver explicito.');
$check(str_contains($doctorSource, "'divergence' => ['present' => false, 'items' => []]"),
    'Falta la salida estructurada de divergencia ENV/DB.');
$check(str_contains($doctorSource, "'CRON_V3_ENABLED' => 'cron_v3.enabled'")
    && str_contains($doctorSource, "'CRON_V3_SHADOW_ENABLED' => 'cron_v3.shadow_enabled'"),
    'El doctor debe comparar flags de activación ENV y app_settings.');
$check(str_contains($doctorSource, "'scope_level', 'application_id', 'endpoint_key', 'operation_key'"),
    'El doctor debe diagnosticar las dimensiones de rate authority instaladas por 244.');
$check(str_contains($doctorSource, "array_diff(\$sourceNames, \$appliedNames)")
    && str_contains($doctorSource, "'missing_database_versions'"),
    'El doctor debe exigir todas las migraciones de un prefijo, incluido el puente 241.');
$check(str_contains($doctorSource, "'10.6.0'") && str_contains($doctorSource, "'8.0.4'"),
    'El doctor debe bloquear versiones sin JSON_TABLE: MariaDB 10.6 o MySQL 8.0.4 como minimo.');
$check(str_contains($doctorSource, "'meli_application_id_unavailable'")
    && str_contains($doctorSource, "\$issues[] = 'rate_bucket_scope_dimensions_unavailable'"),
    'El doctor remoto debe bloquear application_id o dimensiones rate ausentes.');
$check(!str_contains($doctorSource, 'MeliApiClient') && !str_contains($doctorSource, 'CurlMeliHttpTransport'),
    'El doctor no debe construir transporte Mercado Libre.');
$check(!preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|CREATE|DROP)\b\s/i', $doctorSource),
    'CronV3DoctorService contiene una sentencia SQL de mutación.');
$check(str_contains($cliSource, "in_array('--doctor', \$argv, true)")
    && str_contains($cliSource, "in_array('--json', \$argv, true)"),
    'CronV3Cli debe soportar --doctor --json.');
$check(str_contains($cliSource, "return !empty(\$doctor['ok']) ? 0 : 2;"),
    'El doctor bloqueado debe devolver un exit code no cero.');
$check(strpos($cliSource, "in_array('--doctor', \$argv, true)") < strpos($cliSource, "job_try_lock('cron_v3_' . \$lane)"),
    'El doctor debe ejecutarse antes de locks o trabajo.');

foreach (['local', 'remote'] as $lane) {
    $launcher = (string) file_get_contents($root . '/jobs/cron_v3_' . $lane . '.php');
    $check(str_contains($launcher, "CronV3Cli::run('" . $lane . "'") && str_contains($launcher, "\$_SERVER['argv']"),
        'El launcher ' . $lane . ' no delega argv completo a CronV3Cli.');
}

$snapshot = (new CronV3DoctorService(new CronV3UnavailablePdo2291()))->snapshot('remote');
$check(($snapshot['read_only'] ?? false) === true, 'La salida runtime debe confirmar read_only.');
$check(($snapshot['http_calls'] ?? -1) === 0, 'El doctor debe reportar cero HTTP.');
$check(($snapshot['activation_authority'] ?? '') === 'env_resolver', 'La autoridad runtime no usa App\\Core\\Env.');
$check(($snapshot['requested_lane'] ?? '') === 'remote', 'El doctor perdió el carril solicitado.');
$check(($snapshot['state'] ?? '') === 'blocked', 'Un esquema no verificable debe bloquear la certificación.');
$check(in_array('schema_migrations_unavailable', (array) ($snapshot['issues'] ?? []), true),
    'El doctor debe diagnosticar schema_migrations no disponible.');
$check(isset($snapshot['components']['handlers']['local'], $snapshot['components']['handlers']['remote']),
    'El doctor debe inventariar handlers locales y remotos sin ejecutar trabajo.');
$check(isset($snapshot['snapshots']['local'], $snapshot['snapshots']['remote']),
    'El doctor debe conservar ambos carriles de snapshots.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "PASS cron_v3_doctor_2291\n";
