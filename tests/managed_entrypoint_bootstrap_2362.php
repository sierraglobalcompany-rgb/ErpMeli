<?php

declare(strict_types=1);

$repo = dirname(__DIR__);
$base = '75997f864b917c829d19f14e21cd7303d2685776';
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
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
$write = static function (string $path, string $contents): void {
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('fixture_directory_failed');
    }
    if (file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
        throw new RuntimeException('fixture_write_failed');
    }
};
$run = static function (string $script): array {
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=1', '-d', 'log_errors=0', $script],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('php_process_failed');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
};
$gitShow = static function (string $spec) use ($repo): string {
    $pipes = [];
    $process = proc_open(
        ['git', '-C', $repo, 'show', $spec],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('git_show_failed');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('git_show_failed:' . trim((string) $stderr));
    }
    return (string) $stdout;
};

$entrypoints = [
    'index.php' => false,
    'login.php' => true,
    'actualizar.php' => true,
    'stop.php' => true,
    'mantenimiento.php' => true,
    'recuperar.php' => true,
    'cron-status.php' => true,
];
$temporary = sys_get_temp_dir() . '/erp-managed-entrypoint-2362-' . bin2hex(random_bytes(6));

try {
    $stableRoot = $temporary . '/installation';
    $targetRoot = $stableRoot . '/releases/target-2362';
    foreach ([$stableRoot . '/launcher', $stableRoot . '/shared', $targetRoot . '/launcher'] as $directory) {
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('fixture_directory_failed');
        }
    }

    $classicLauncher = $gitShow($base . ':launcher/entrypoint.php');
    $targetLauncher = (string) file_get_contents($repo . '/launcher/entrypoint.php');
    $write($stableRoot . '/launcher/entrypoint.php', $classicLauncher);
    $write($targetRoot . '/launcher/entrypoint.php', $targetLauncher);
    $write(
        $stableRoot . '/shared/current-release.json',
        json_encode([
            'release_id' => 'target-2362',
            'version' => '2.36.2',
            'path' => 'releases/target-2362',
        ], JSON_THROW_ON_ERROR)
    );

    foreach ($entrypoints as $entrypoint => $allowRecovery) {
        $call = 'erp_dispatch_active_entrypoint(__DIR__, ' . var_export($entrypoint, true)
            . ($allowRecovery ? ', true' : '') . ')';
        $write(
            $stableRoot . '/' . $entrypoint,
            "<?php\nrequire_once __DIR__ . '/launcher/entrypoint.php';\n"
            . "if ({$call}) { return; }\n"
            . "echo 'STABLE:" . $entrypoint . "';\n"
        );
        $write(
            $targetRoot . '/' . $entrypoint,
            "<?php\nrequire_once __DIR__ . '/launcher/entrypoint.php';\n"
            . "if ({$call}) { echo 'RECURSIVE'; return; }\n"
            . "echo 'TARGET:" . $entrypoint . ":DEPTH=1';\n"
        );
    }

    // Exact regression: two physical copies of the BASE launcher redeclare the dispatcher.
    $write($targetRoot . '/launcher/entrypoint.php', $classicLauncher);
    $fatal = $run($stableRoot . '/index.php');
    $assert(
        $fatal['exit'] !== 0
        && str_contains($fatal['stdout'] . $fatal['stderr'], 'Cannot redeclare')
        && str_contains($fatal['stdout'] . $fatal['stderr'], 'erp_dispatch_active_entrypoint'),
        'El fixture exacto de 2.36.1 no reprodujo la redeclaración fatal: exit=' . $fatal['exit']
            . ' stdout=' . trim($fatal['stdout']) . ' stderr=' . trim($fatal['stderr'])
    );

    // Hotfix: the stable HF1.2 dispatcher remains authoritative and the target launcher is a checked no-op.
    $write($targetRoot . '/launcher/entrypoint.php', $targetLauncher);
    foreach (array_keys($entrypoints) as $entrypoint) {
        $result = $run($stableRoot . '/' . $entrypoint);
        $assert($result['exit'] === 0, 'Fatal en managed entrypoint: ' . $entrypoint);
        $assert(
            $result['stdout'] === 'TARGET:' . $entrypoint . ':DEPTH=1',
            'Runtime incorrecto o redispatch en: ' . $entrypoint . ' output=' . $result['stdout']
        );
        $assert(!str_contains($result['stderr'], 'Cannot redeclare'), 'Redeclaración residual en: ' . $entrypoint);
    }

    // The target launcher is also safe as a classic/direct root with no local pointer.
    foreach (array_keys($entrypoints) as $entrypoint) {
        $result = $run($targetRoot . '/' . $entrypoint);
        $assert(
            $result['exit'] === 0 && $result['stdout'] === 'TARGET:' . $entrypoint . ':DEPTH=1',
            'El modo clásico/directo falló en: ' . $entrypoint
        );
    }

    // An arbitrary dispatcher, a partial authority, and an inconsistent physical copy all fail closed.
    $write(
        $temporary . '/collision.php',
        "<?php\nfunction erp_dispatch_active_entrypoint(): bool { return false; }\n"
        . "try { require " . var_export($targetRoot . '/launcher/entrypoint.php', true) . "; } "
        . "catch (RuntimeException) { echo 'COLLISION_BLOCKED'; }\n"
    );
    $collision = $run($temporary . '/collision.php');
    $assert($collision['exit'] === 0 && $collision['stdout'] === 'COLLISION_BLOCKED', 'Colisión arbitraria aceptada.');

    $write(
        $temporary . '/partial.php',
        "<?php\ndefine('ERP_RELEASE_BOOTSTRAPPED', true);\n"
        . "try { require " . var_export($targetRoot . '/launcher/entrypoint.php', true) . "; } "
        . "catch (RuntimeException) { echo 'PARTIAL_BLOCKED'; }\n"
    );
    $partial = $run($temporary . '/partial.php');
    $assert($partial['exit'] === 0 && $partial['stdout'] === 'PARTIAL_BLOCKED', 'Autoridad parcial aceptada.');

    $write(
        $temporary . '/duplicate.php',
        "<?php\nrequire " . var_export($targetRoot . '/launcher/entrypoint.php', true) . ";\n"
        . "try { require " . var_export($targetRoot . '/launcher/entrypoint.php', true) . "; } "
        . "catch (RuntimeException) { echo 'DUPLICATE_BLOCKED'; }\n"
    );
    $duplicate = $run($temporary . '/duplicate.php');
    $assert($duplicate['exit'] === 0 && $duplicate['stdout'] === 'DUPLICATE_BLOCKED', 'Include duplicado sin autoridad aceptado.');

    // Pointer corruption remains strict for index and recovery-safe for emergency/admin entrypoints.
    $pointerPath = $stableRoot . '/shared/current-release.json';
    $pointerCases = [
        'malformed' => '{"release_id":',
        'unsafe_relative' => '{"release_id":"target-2362","path":"../target-2362"}',
        'outside_releases' => '{"release_id":"outside","path":"outside"}',
        'wrong_release_id' => '{"release_id":"wrong","path":"releases/target-2362"}',
        'missing_entrypoint' => '{"release_id":"missing","path":"releases/missing"}',
    ];
    mkdir($stableRoot . '/outside', 0700, true);
    foreach ($pointerCases as $case => $payload) {
        $write($pointerPath, $payload);
        $strict = $run($stableRoot . '/index.php');
        $recovery = $run($stableRoot . '/login.php');
        $assert($strict['exit'] !== 0, 'Pointer corrupto no bloqueó index: ' . $case);
        $assert(
            $recovery['exit'] === 0 && $recovery['stdout'] === 'STABLE:login.php',
            'Pointer corrupto rompió recuperación clásica: ' . $case
        );
    }
    @unlink($pointerPath);
    $missingStrict = $run($stableRoot . '/index.php');
    $missingRecovery = $run($stableRoot . '/login.php');
    $assert($missingStrict['exit'] === 0 && $missingStrict['stdout'] === 'STABLE:index.php', 'Pointer ausente rompió classic.');
    $assert($missingRecovery['exit'] === 0 && $missingRecovery['stdout'] === 'STABLE:login.php', 'Pointer ausente rompió recovery.');

    // The real seven entrypoints keep one dispatcher call and adjacent paths do not load this launcher recursively.
    foreach (array_keys($entrypoints) as $entrypoint) {
        $source = (string) file_get_contents($repo . '/' . $entrypoint);
        $assert(substr_count($source, 'erp_dispatch_active_entrypoint(') === 1, 'Dispatcher count inválido: ' . $entrypoint);
        $assert(str_contains($source, "require_once __DIR__ . '/launcher/entrypoint.php'"), 'Launcher ausente: ' . $entrypoint);
    }
    foreach (['asset.php', 'bootstrap.php', '.htaccess', 'public/index.php'] as $adjacent) {
        $source = (string) file_get_contents($repo . '/' . $adjacent);
        $assert(!str_contains($source, "require_once __DIR__ . '/launcher/entrypoint.php'"), 'Recursión adyacente: ' . $adjacent);
    }
} finally {
    $removeTree($temporary);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, array_map(static fn (string $failure): string => 'FAIL: ' . $failure, $failures)) . PHP_EOL);
    exit(1);
}

echo "PASS managed_entrypoint_bootstrap_2362 ENTRYPOINTS=7 MAX_DISPATCH_DEPTH=1 FATAL_REPRODUCED=1\n";
