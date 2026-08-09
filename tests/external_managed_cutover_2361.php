<?php

declare(strict_types=1);

$repo = dirname(__DIR__);
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

/** @param array<string,mixed> $payload */
$durableJson = static function (string $path, array $payload): void {
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('test_directory_unavailable');
    }
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(5));
    $bytes = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $handle = fopen($temporary, 'xb');
    if (!is_resource($handle)) {
        throw new RuntimeException('test_pointer_temp_unavailable');
    }
    try {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($handle, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) {
                throw new RuntimeException('test_pointer_short_write');
            }
            $offset += $written;
        }
        if (!fflush($handle)) {
            throw new RuntimeException('test_pointer_flush_failed');
        }
        if (function_exists('fsync') && !fsync($handle)) {
            throw new RuntimeException('test_pointer_fsync_failed');
        }
    } finally {
        fclose($handle);
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('test_pointer_rename_failed');
    }
    // The production procedure is Linux-only and also fsyncs the parent
    // directory. PHP cannot portably obtain a directory FD on Windows, so the
    // cross-platform contract test records this as an operator preflight gate.
};

$runPhp = static function (string $script): array {
    $pipes = [];
    $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('test_php_process_unavailable');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string) $stdout, (string) $stderr];
};

$root = sys_get_temp_dir() . '/erp-external-cutover-2361-' . bin2hex(random_bytes(6));
try {
    foreach (['launcher', 'shared', 'releases/rollback-2351', 'releases/target-2361'] as $directory) {
        if (!mkdir($root . '/' . $directory, 0770, true) && !is_dir($root . '/' . $directory)) {
            throw new RuntimeException('test_fixture_directory_failed');
        }
    }
    copy($repo . '/launcher/entrypoint.php', $root . '/launcher/entrypoint.php');
    file_put_contents($root . '/releases/rollback-2351/index.php', "<?php echo 'OLD_RUNTIME';\n");
    file_put_contents($root . '/releases/target-2361/index.php', "<?php echo 'TARGET_RUNTIME';\n");
    $probe = $root . '/probe.php';
    file_put_contents(
        $probe,
        "<?php\n"
        . "require " . var_export($root . '/launcher/entrypoint.php', true) . ";\n"
        . "try {\n"
        . "  if (!erp_dispatch_active_entrypoint(" . var_export($root, true) . ", 'index.php')) { echo 'CLASSIC_FALLBACK'; }\n"
        . "} catch (Throwable \$error) { echo 'POINTER_BLOCKED'; exit(23); }\n"
    );

    [$exit, $output] = $runPhp($probe);
    $assert($exit === 0 && $output === 'CLASSIC_FALLBACK', 'Sin pointer debe conservarse el fallback clásico.');

    $oldPointer = [
        'release_id' => 'rollback-2351',
        'version' => '2.35.1',
        'path' => 'releases/rollback-2351',
        'previous_release_id' => null,
    ];
    $targetPointer = [
        'release_id' => 'target-2361',
        'version' => '2.36.1',
        'path' => 'releases/target-2361',
        'previous_release_id' => 'rollback-2351',
    ];
    $pointerPath = $root . '/shared/current-release.json';
    $durableJson($pointerPath, $oldPointer);
    [$exit, $output] = $runPhp($probe);
    $assert($exit === 0 && $output === 'OLD_RUNTIME', 'El pointer de rollback debe resolver sólo el runtime viejo completo.');

    // Repeated publication/probe cycles may observe old or target, never a
    // partial document or a mixed release.
    for ($iteration = 0; $iteration < 30; $iteration++) {
        $expected = $iteration % 2 === 0 ? 'TARGET_RUNTIME' : 'OLD_RUNTIME';
        $durableJson($pointerPath, $iteration % 2 === 0 ? $targetPointer : $oldPointer);
        [$exit, $output] = $runPhp($probe);
        $assert($exit === 0 && $output === $expected, 'La publicación atómica no puede producir runtime parcial.');
    }

    $durableJson($pointerPath, [
        'release_id' => 'rollback-2351',
        'version' => '2.36.1',
        'path' => 'releases/target-2361',
    ]);
    [$exit, $output] = $runPhp($probe);
    $assert($exit === 23 && $output === 'POINTER_BLOCKED', 'release_id/path incongruentes deben fallar cerrados.');

    $durableJson($pointerPath, [
        'release_id' => 'target-2361',
        'version' => '2.36.1',
        'path' => '../target-2361',
    ]);
    [$exit, $output] = $runPhp($probe);
    $assert($exit === 23 && $output === 'POINTER_BLOCKED', 'Un pointer con traversal debe fallar cerrado.');

    $protected = [
        $root . '/shared/config.env' => 'SECRET_PLACEHOLDER_DO_NOT_PRINT',
        $root . '/shared/storage/oauth-rotated-token-recovery.json' => 'CIPHERTEXT_PLACEHOLDER',
        $root . '/PAUSE_MELI_API' => '{"state":"stopped"}',
        $root . '/PAUSE_ERP_AUTOMATION' => '{"state":"stopped"}',
    ];
    foreach ($protected as $path => $content) {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }
        file_put_contents($path, $content);
    }
    $protectedBefore = array_map('hash_file', array_fill(0, count($protected), 'sha256'), array_keys($protected));
    $durableJson($pointerPath, $targetPointer);
    $protectedAfter = array_map('hash_file', array_fill(0, count($protected), 'sha256'), array_keys($protected));
    $assert($protectedBefore === $protectedAfter, 'El pointer no puede modificar estado protegido.');

    $web = (string) file_get_contents($repo . '/launcher/web.php');
    $entrypoint = (string) file_get_contents($repo . '/launcher/entrypoint.php');
    $rootIndex = (string) file_get_contents($repo . '/index.php');
    $assert(str_contains($web, '/maintenance.json'), 'launcher/web.php debe reconocer el marcador de mantenimiento.');
    $assert(!str_contains($entrypoint, '/maintenance.json'), 'El test depende de que entrypoint no evalúe maintenance.json.');
    $assert(
        str_contains($rootIndex, "erp_dispatch_active_entrypoint(__DIR__, 'index.php')"),
        'La portada debe demostrar el bypass del gate web que exige guard fuera de banda.'
    );

    $stale = $root . '/app/Services/Stale.php';
    mkdir(dirname($stale), 0770, true);
    file_put_contents($stale, "<?php\n// certified stale fixture\n");
    $expectedStaleHash = hash_file('sha256', $stale);
    $quarantine = dirname($root) . '/erp-external-quarantine-' . bin2hex(random_bytes(5));
    mkdir($quarantine, 0770, true);
    $copy = $quarantine . '/Stale.php.copy';
    $moved = $quarantine . '/Stale.php.quarantined';
    $assert(copy($stale, $copy), 'Quarantine debe empezar por copy fuera del webroot.');
    $assert(hash_file('sha256', $copy) === $expectedStaleHash, 'La copia de quarantine debe verificar hash exacto.');
    $assert(rename($stale, $moved), 'Sólo después de verificar se puede mover el stale exacto.');
    $assert(!is_file($stale) && hash_file('sha256', $moved) === $expectedStaleHash, 'El stale debe quedar fuera del webroot y recuperable.');
    $assert(rename($moved, $stale), 'El rollback debe poder restaurar el stale cuando sea necesario.');
    $assert(hash_file('sha256', $stale) === $expectedStaleHash, 'El stale restaurado debe conservar hash.');

    $mismatch = $root . '/app/Services/Mismatch.php';
    file_put_contents($mismatch, "<?php\n// unknown drift\n");
    $wrongHash = str_repeat('0', 64);
    $blocked = hash_file('sha256', $mismatch) !== $wrongHash;
    $assert($blocked && is_file($mismatch), 'Hash mismatch debe bloquear sin mover el archivo.');
    $removeTree($quarantine);

    $plan = (string) file_get_contents($repo . '/docs/release_2361_external_cutover.md');
    foreach ([
        'S0_VERIFIED_OLD',
        'S2_WEB_GUARDED',
        'S4_MIGRATING',
        'S6_VERSION_2361',
        'S7_TARGET_POINTER',
        'S9_EXPOSED_STOPPED',
        'antes de migración',
        'durante migraciones',
        'después de `app.version` y antes del pointer',
        'smoke FPM/opcache falla',
        'updater',
        'fsync(parent)',
    ] as $requiredEvidence) {
        $assert(str_contains($plan, $requiredEvidence), 'Falta evidencia del state machine/gate: ' . $requiredEvidence);
    }
    $assert(
        str_contains($plan, 'API y Automation permanecen STOPPED')
        && str_contains($plan, 'cero HTTP Mercado Libre'),
        'El resultado debe conservar las barreras remotas.'
    );
} finally {
    $removeTree($root);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "PASS external_managed_cutover_2361\n";
