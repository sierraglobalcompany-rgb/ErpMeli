<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\CronV3SetupAssistantService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$fixture = tempnam(sys_get_temp_dir(), 'cron-v3-setup-');
if ($fixture === false) {
    throw new RuntimeException('No se pudo preparar config fixture.');
}

foreach (['CRON_V3_ENABLED', 'CRON_V3_SHADOW_ENABLED', 'CRON_V4_ENABLED', 'CRON_V3_RATE_LIMIT', 'CRON_V3_API_TIMEOUT', 'CRON_V3_API_CONNECT_TIMEOUT', 'ML_WRITE_ENABLED'] as $key) {
    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);
}

try {
    file_put_contents($fixture, implode(PHP_EOL, [
        'APP_KEY=secret-test-value',
        'DB_PASSWORD=super-secret-password',
        'MELI_CLIENT_SECRET=secret-oauth',
        'CRON_V3_ENABLED=true',
        '',
    ]));

    $service = new CronV3SetupAssistantService(null, $fixture, dirname(__DIR__));
    $result = $service->prepareSafeConfig(9);
    $body = (string) file_get_contents($fixture);

    $assert(($result['ok'] ?? false) === true, 'prepareSafeConfig no aprobó.');
    $assert(str_contains($body, 'APP_KEY=secret-test-value'), 'APP_KEY fue alterado.');
    $assert(str_contains($body, 'DB_PASSWORD=super-secret-password'), 'DB_PASSWORD fue alterado.');
    $assert(str_contains($body, 'MELI_CLIENT_SECRET=secret-oauth'), 'MELI_CLIENT_SECRET fue alterado.');
    $assert(str_contains($body, 'CRON_V3_ENABLED=false'), 'CRON_V3_ENABLED debe quedar apagado.');
    $assert(str_contains($body, 'CRON_V3_SHADOW_ENABLED=false'), 'Shadow debe iniciar apagado.');
    $assert(str_contains($body, 'CRON_V4_ENABLED=false'), 'Cron V4 debe permanecer apagado durante el retiro V3.');
    $assert(str_contains($body, 'CRON_V3_RATE_LIMIT=10'), 'Rate seguro faltante.');
    $assert(str_contains($body, 'CRON_V3_API_TIMEOUT=8'), 'Timeout API seguro faltante.');
    $assert(str_contains($body, 'CRON_V3_API_CONNECT_TIMEOUT=3'), 'Connect timeout seguro faltante.');

    putenv('CRON_V3_ENABLED=true');
    try {
        (new CronV3SetupAssistantService(null, $fixture, dirname(__DIR__)))->prepareSafeConfig(9);
        throw new RuntimeException('El override de proceso no fue bloqueado.');
    } catch (RuntimeException $expected) {
        $assert(str_contains($expected->getMessage(), 'cron_v3_process_env_override'), 'Bloqueo inesperado para override.');
    } finally {
        putenv('CRON_V3_ENABLED');
    }

    putenv('CRON_V4_ENABLED=true');
    try {
        (new CronV3SetupAssistantService(null, $fixture, dirname(__DIR__)))->prepareSafeConfig(9);
        throw new RuntimeException('El override V4 de proceso no fue bloqueado.');
    } catch (RuntimeException $expected) {
        $assert($expected->getMessage() === 'cron_v3_process_env_override:CRON_V4_ENABLED', 'Bloqueo inesperado para override V4.');
    } finally {
        putenv('CRON_V4_ENABLED');
    }

    putenv('ML_WRITE_ENABLED=true');
    try {
        (new CronV3SetupAssistantService(null, $fixture, dirname(__DIR__)))->prepareSafeConfig(9);
        throw new RuntimeException('ML_WRITE_ENABLED=true no bloqueó la preparación.');
    } catch (RuntimeException $expected) {
        $assert($expected->getMessage() === 'cron_v3_ml_write_enabled', 'Bloqueo inesperado para ML_WRITE_ENABLED.');
    } finally {
        putenv('ML_WRITE_ENABLED');
    }

    putenv('ML_WRITE_ENABLED=valor-invalido');
    try {
        (new CronV3SetupAssistantService(null, $fixture, dirname(__DIR__)))->prepareSafeConfig(9);
        throw new RuntimeException('ML_WRITE_ENABLED inválido no bloqueó la preparación.');
    } catch (RuntimeException $expected) {
        $assert($expected->getMessage() === 'cron_v3_ml_write_enabled', 'Bloqueo inesperado para ML_WRITE_ENABLED inválido.');
    } finally {
        putenv('ML_WRITE_ENABLED');
    }

    $source = (string) file_get_contents(dirname(__DIR__) . '/jobs/cron_v3_setup_check.php');
    $assert(!str_contains($source, 'MeliApiClient'), 'setup_check no debe construir cliente Mercado Libre.');
    $assert(!preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\b/i', $source), 'setup_check no debe tener SQL de mutación.');

    $routes = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
    $assert(str_contains($routes, "/settings/cron/v3-setup.json"), 'Falta GET setup.');
    $assert(str_contains($routes, "/settings/cron/v3-setup/prepare-safe-config"), 'Falta POST prepare.');
    $assert(str_contains($routes, "/settings/cron/v3-setup/enable-shadow"), 'Falta POST shadow.');
} finally {
    @unlink($fixture);
}

echo "PASS cron_v3_activation_assistant_2293\n";
