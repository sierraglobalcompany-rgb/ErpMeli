<?php

declare(strict_types=1);

$repo = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/erp-scheduler-cutover-2361-' . bin2hex(random_bytes(6));
$failures = [];
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$assertions): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
};
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!file_exists($path)) {
        return;
    }
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
};
$mkdir = static function (string $path): void {
    if (!is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) {
        throw new RuntimeException('scheduler_fixture_directory_unavailable');
    }
};
$run = static function (array $command): array {
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('scheduler_fixture_process_unavailable');
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), trim($stdout), trim($stderr)];
};
$env = static function (string $name, string $value): void {
    putenv($name . '=' . $value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
};

try {
    foreach (['installation/shared', 'installation/releases/old/jobs', 'installation/releases/target/jobs'] as $directory) {
        $mkdir($temporary . '/' . $directory);
    }
    define('ERP_RELEASE_ROOT', $repo);
    define('ERP_INSTALLATION_ROOT', $temporary . '/installation');
    define('ERP_SHARED_ROOT', $temporary . '/installation/shared');
    require $repo . '/bootstrap.php';

    $installation = ERP_INSTALLATION_ROOT;
    file_put_contents($installation . '/PAUSE_MELI_API', '{"state":"stopped"}', LOCK_EX);
    file_put_contents($installation . '/PAUSE_ERP_AUTOMATION', '{"state":"stopped"}', LOCK_EX);
    $env('ML_WRITE_ENABLED', 'false');
    $env('CRON_V3_ENABLED', 'true');
    $env('CRON_V3_SHADOW_ENABLED', 'true');
    $env('CRON_V4_ENABLED', 'true');

    $safety = (new \App\Services\EmergencyControlService())->status();
    $assert(($safety['api'] ?? '') === 'stopped', 'API debe permanecer BLOCKED por el marcador estable.');
    $assert(($safety['automation'] ?? '') === 'stopped', 'Automation debe permanecer STOPPED por el marcador estable.');
    $assert(($safety['writes'] ?? '') === 'disabled', 'ML_WRITE_ENABLED=false debe permanecer efectivo.');

    \App\Services\ApiExecutionMetadataContext::resetRemoteDispatchCount();
    foreach (['local', 'remote'] as $lane) {
        ob_start();
        $exit = \App\Services\CronV3Cli::run($lane, ['cron_v3_' . $lane . '.php', '--runtime=5']);
        $payload = json_decode(trim((string) ob_get_clean()), true);
        $assert($exit === 0, 'Cron V3 detenido debe terminar de forma segura: ' . $lane);
        $assert(is_array($payload) && ($payload['status'] ?? '') === 'SKIPPED_AUTOMATION_STOPPED', 'Cron V3 no respetó Automation Stop: ' . $lane);
        $assert((int) ($payload['http_calls'] ?? -1) === 0, 'Cron V3 detenido no certificó HTTP=0: ' . $lane);
        $assert((int) ($payload['source_mutations'] ?? -1) === 0, 'Cron V3 detenido mutó una fuente: ' . $lane);
    }

    $v4Stopped = (new \App\QueueCore\CronV4Cli())->run(['cron_v4.php', '--runtime=5']);
    $assert(($v4Stopped['status'] ?? '') === 'SKIPPED_AUTOMATION_STOPPED', 'Cron V4 no respetó Automation Stop.');
    $assert((int) ($v4Stopped['side_effects'] ?? -1) === 0 && (int) ($v4Stopped['claimed'] ?? -1) === 0, 'Cron V4 detenido produjo efectos o claims.');
    $assert((int) ($v4Stopped['http'] ?? -1) === 0, 'Cron V4 detenido no certificó HTTP=0.');
    $assert(\App\Services\ApiExecutionMetadataContext::remoteDispatchCount() === 0, 'Un launcher detenido alcanzó transporte remoto.');

    @unlink($installation . '/PAUSE_ERP_AUTOMATION');
    $env('CRON_V3_ENABLED', 'false');
    $env('CRON_V3_SHADOW_ENABLED', 'false');
    ob_start();
    $v3DisabledExit = \App\Services\CronV3Cli::run('remote', ['cron_v3_remote.php', '--runtime=5']);
    $v3Disabled = json_decode(trim((string) ob_get_clean()), true);
    $assert($v3DisabledExit === 0 && ($v3Disabled['status'] ?? '') === 'disabled', 'Cron V3 debe estar disabled sin abrir MariaDB.');
    $assert((int) ($v3Disabled['http_calls'] ?? -1) === 0, 'Cron V3 disabled no certificó HTTP=0.');
    $env('CRON_V4_ENABLED', 'false');
    $v4Disabled = (new \App\QueueCore\CronV4Cli())->run(['cron_v4.php', '--runtime=5']);
    $assert(($v4Disabled['status'] ?? '') === 'DISABLED', 'Cron V4 debe estar disabled sin abrir MariaDB.');
    $assert((int) ($v4Disabled['http'] ?? -1) === 0 && (int) ($v4Disabled['claimed'] ?? -1) === 0, 'Cron V4 disabled no certificó HTTP=0/claims=0.');
    $assert(is_file($installation . '/PAUSE_MELI_API'), 'La prueba no puede retirar el bloqueo API.');

    $v3 = (string) file_get_contents($repo . '/app/Services/CronV3Cli.php');
    $v4 = (string) file_get_contents($repo . '/app/QueueCore/CronV4Cli.php');
    $v3Stop = strpos($v3, "(new EmergencyControlService())->automationStopped()");
    $v3Db = strpos($v3, "Database::useProfile('cli')");
    $v3Engine = strpos($v3, "acquireRuntime('v3', \$lane)");
    $v3Producer = strpos($v3, '$legacyImport =');
    $assert($v3Stop !== false && $v3Db !== false && $v3Stop < $v3Db, 'Automation Stop de V3 debe preceder MariaDB.');
    $assert($v3Engine !== false && $v3Producer !== false && $v3Engine < $v3Producer, 'El permit cercado V3 debe preceder productores/claims.');
    $v4Stop = strpos($v4, "(new EmergencyControlService())->automationStopped()");
    $v4Db = strpos($v4, "Database::useProfile('cli')");
    $v4Engine = strpos($v4, "acquireRuntime('v4', 'operational')");
    $v4Producer = strpos($v4, "scheduleDueAccounts(min(20, \$max))");
    $v4Runner = strpos($v4, "\$core['runner']->run(");
    $assert($v4Stop !== false && $v4Db !== false && $v4Stop < $v4Db, 'Automation Stop de V4 debe preceder MariaDB.');
    $assert($v4Engine !== false && $v4Producer !== false && $v4Runner !== false && $v4Engine < $v4Producer && $v4Engine < $v4Runner, 'El motor disabled debe bloquear V4 antes de productores, claims y transporte.');

    $launcherRoot = $temporary . '/installation';
    $mkdir($launcherRoot . '/launcher');
    copy($repo . '/launcher/cron.php', $launcherRoot . '/launcher/cron.php');
    foreach (['old' => 'OLD', 'target' => 'TARGET'] as $release => $label) {
        file_put_contents(
            $launcherRoot . '/releases/' . $release . '/jobs/safety_probe.php',
            "<?php echo '" . $label . "|' . ERP_RELEASE_ID . '|' . basename(ERP_RELEASE_ROOT);\n",
            LOCK_EX
        );
    }
    $publishPointer = static function (array $pointer) use ($launcherRoot): void {
        file_put_contents(
            $launcherRoot . '/shared/current-release.json',
            json_encode($pointer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    };
    foreach (['old' => 'OLD|old|old', 'target' => 'TARGET|target|target'] as $release => $expected) {
        $publishPointer(['release_id' => $release, 'path' => 'releases/' . $release]);
        [$exit, $stdout] = $run([PHP_BINARY, $launcherRoot . '/launcher/cron.php', 'safety_probe.php']);
        $assert($exit === 0 && $stdout === $expected, 'launcher/cron.php no resolvió exactamente el pointer ' . $release . '.');
    }
    file_put_contents($launcherRoot . '/shared/current-release.json', '{invalid', LOCK_EX);
    [$invalidExit, $invalidStdout, $invalidStderr] = $run([PHP_BINARY, $launcherRoot . '/launcher/cron.php', 'safety_probe.php']);
    $assert($invalidExit === 2 && $invalidStdout === '' && str_contains($invalidStderr, 'puntero'), 'Pointer inválido debe bloquear launcher cron.');
    $publishPointer(['release_id' => 'escape', 'path' => '../escape']);
    [$escapeExit, $escapeStdout] = $run([PHP_BINARY, $launcherRoot . '/launcher/cron.php', 'safety_probe.php']);
    $assert($escapeExit === 2 && $escapeStdout === '', 'Traversal de pointer debe bloquear launcher cron.');

    $plan = (string) file_get_contents($repo . '/docs/release_2361_external_cutover.md');
    foreach ([
        'HOSTINGER_CRON_V3_UNKNOWN',
        'bloqueo duro para activar V4',
        'queue_engine_control.active_engine=disabled',
        'Los receipts de ambos launchers deben declarar `http=0`',
    ] as $contract) {
        $assert(str_contains($plan, $contract), 'Falta contrato scheduler en el plan: ' . $contract);
    }
} finally {
    foreach (['CRON_V3_ENABLED', 'CRON_V3_SHADOW_ENABLED', 'CRON_V4_ENABLED', 'ML_WRITE_ENABLED'] as $name) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
    $removeTree($temporary);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'PASS scheduler_cutover_safety_2361 ' . $assertions . '/' . $assertions . PHP_EOL;
