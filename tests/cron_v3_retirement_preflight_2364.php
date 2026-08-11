<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\CronV3SetupAssistantService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$keys = [
    'CRON_V3_ENABLED',
    'CRON_V3_SHADOW_ENABLED',
    'CRON_V4_ENABLED',
    'CRON_V3_RATE_LIMIT',
    'CRON_V3_API_TIMEOUT',
    'CRON_V3_API_CONNECT_TIMEOUT',
    'ML_WRITE_ENABLED',
];
foreach ($keys as $key) {
    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);
}

$fixture = tempnam(sys_get_temp_dir(), 'cron-v3-retirement-');
if ($fixture === false) {
    throw new RuntimeException('retirement_fixture_unavailable');
}

try {
    file_put_contents($fixture, implode(PHP_EOL, [
        'APP_KEY=do-not-expose-this-value',
        'CRON_V3_ENABLED=false',
        'CRON_V3_SHADOW_ENABLED=false',
        'CRON_V4_ENABLED=false',
        'ML_WRITE_ENABLED=false',
        'CRON_V3_RATE_LIMIT=10',
        'CRON_V3_API_TIMEOUT=8',
        'CRON_V3_API_CONNECT_TIMEOUT=3',
        '',
    ]));

    $service = new CronV3SetupAssistantService(null, $fixture, dirname(__DIR__));
    $snapshot = $service->snapshot();
    $preflight = $snapshot['retirement_preflight'] ?? [];
    $required = [
        'CRON_V3_ENABLED' => false,
        'CRON_V3_SHADOW_ENABLED' => false,
        'CRON_V4_ENABLED' => false,
        'ML_WRITE_ENABLED' => false,
    ];
    $assert(($preflight['ok'] ?? false) === true, 'El preflight seguro nominal no aprobó.');
    $assert(($preflight['effective_flags'] ?? null) === $required, 'Los cuatro flags efectivos no coinciden.');
    $assert(($preflight['required_state'] ?? null) === $required, 'La autoridad requerida no coincide.');
    $assert(($preflight['process_override_conflicts'] ?? null) === [], 'Aparecieron conflictos inexistentes.');
    $assert(!str_contains(json_encode($snapshot, JSON_THROW_ON_ERROR), 'do-not-expose-this-value'), 'El snapshot expuso APP_KEY.');

    putenv('CRON_V4_ENABLED=true');
    $blocked = $service->snapshot()['retirement_preflight'] ?? [];
    $assert(($blocked['ok'] ?? true) === false, 'CRON_V4 process=true no bloqueó.');
    $assert(($blocked['effective_flags']['CRON_V4_ENABLED'] ?? false) === true, 'No se observó V4 efectivo.');
    $assert(($blocked['process_override_conflicts'] ?? []) === ['CRON_V4_ENABLED'], 'Conflicto V4 incorrecto.');
    putenv('CRON_V4_ENABLED');

    putenv('ML_WRITE_ENABLED=invalid-value');
    $invalid = $service->snapshot()['retirement_preflight'] ?? [];
    $assert(($invalid['ok'] ?? true) === false, 'ML_WRITE inválido no bloqueó.');
    $assert(array_key_exists('ML_WRITE_ENABLED', $invalid['effective_flags'] ?? []), 'Falta ML_WRITE efectivo.');
    $assert($invalid['effective_flags']['ML_WRITE_ENABLED'] === null, 'ML_WRITE inválido no quedó indeterminado.');
    $assert(($invalid['process_override_conflicts'] ?? []) === ['ML_WRITE_ENABLED'], 'Conflicto ML_WRITE incorrecto.');
    putenv('ML_WRITE_ENABLED');

    file_put_contents($fixture, "CRON_V3_ENABLED=true\nCRON_V3_SHADOW_ENABLED=false\nCRON_V4_ENABLED=false\nCRON_V3_RATE_LIMIT=10\nCRON_V3_API_TIMEOUT=8\nCRON_V3_API_CONNECT_TIMEOUT=3\n");
    $unsafeFile = $service->snapshot();
    $assert(($unsafeFile['safe_config_applied'] ?? true) === false, 'CRON_V3_ENABLED=true fue aceptado como safe config.');
    $assert(($unsafeFile['retirement_preflight']['ok'] ?? true) === false, 'El preflight aceptó V3 activo.');

    file_put_contents($fixture, "CRON_V3_ENABLED=false\nCRON_V3_SHADOW_ENABLED=false\nCRON_V3_RATE_LIMIT=10\nCRON_V3_API_TIMEOUT=8\nCRON_V3_API_CONNECT_TIMEOUT=3\n");
    $missingV4 = $service->snapshot();
    $assert(($missingV4['safe_config_applied'] ?? true) === false, 'La ausencia física de CRON_V4 fue aceptada como preparación completa.');
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL cron_v3_retirement_preflight_2364 ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    foreach ($keys as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
    @unlink($fixture);
}

echo "PASS cron_v3_retirement_preflight_2364\n";
