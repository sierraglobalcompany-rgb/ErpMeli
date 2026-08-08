<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$temporary = sys_get_temp_dir() . '/erp-managed-activation-' . bin2hex(random_bytes(6));
$mkdir = static function (string $directory): void {
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('No fue posible preparar la prueba temporal.');
    }
};
$remove = static function (string $path) use (&$remove): void {
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path)) {
        @unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $entry) {
        $remove($entry->getPathname());
    }
    @rmdir($path);
};
$run = static function (string $script): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    exec($command . ' 2>&1', $output, $exitCode);
    return ['exit' => $exitCode, 'output' => implode("\n", $output)];
};

try {
    foreach ([
        $temporary . '/launcher',
        $temporary . '/shared',
        $temporary . '/releases/release-a/public/assets',
        $temporary . '/releases/release-b/public/assets',
        $temporary . '/public/assets',
    ] as $directory) {
        $mkdir($directory);
    }
    copy($root . '/launcher/entrypoint.php', $temporary . '/launcher/entrypoint.php');
    copy($root . '/asset.php', $temporary . '/asset.php');
    foreach (['release-a' => 'A', 'release-b' => 'B'] as $release => $label) {
        file_put_contents(
            $temporary . '/releases/' . $release . '/login.php',
            "<?php echo 'RELEASE-{$label}';\n",
            LOCK_EX
        );
        file_put_contents(
            $temporary . '/releases/' . $release . '/public/assets/app.css',
            'asset-' . $label,
            LOCK_EX
        );
    }
    file_put_contents($temporary . '/public/assets/app.css', 'asset-classic', LOCK_EX);
    $pointer = static function (string $release) use ($temporary): void {
        file_put_contents(
            $temporary . '/shared/current-release.json',
            json_encode([
                'release_id' => $release,
                'path' => 'releases/' . $release,
            ], JSON_THROW_ON_ERROR),
            LOCK_EX
        );
    };
    $pointer('release-a');
    file_put_contents(
        $temporary . '/dispatch.php',
        "<?php require __DIR__ . '/launcher/entrypoint.php';"
        . "if (!erp_dispatch_active_entrypoint(__DIR__, 'login.php')) echo 'CLASSIC';\n",
        LOCK_EX
    );
    $result = $run($temporary . '/dispatch.php');
    $assert($result['exit'] === 0 && $result['output'] === 'RELEASE-A',
        'El entrypoint estable no siguió el puntero activo.');

    file_put_contents(
        $temporary . '/asset-request.php',
        "<?php \$_SERVER['REQUEST_METHOD']='GET'; \$_GET['path']='app.css';"
        . "require __DIR__ . '/asset.php';\n",
        LOCK_EX
    );
    $assetA = $run($temporary . '/asset-request.php');
    $assert($assetA['exit'] === 0 && $assetA['output'] === 'asset-A',
        'Los assets no siguieron el mismo puntero que el código.');

    $pointer('release-b');
    $assetB = $run($temporary . '/asset-request.php');
    $assert($assetB['exit'] === 0 && $assetB['output'] === 'asset-B',
        'El cambio atómico de puntero no cambió los assets de la release.');

    file_put_contents($temporary . '/shared/current-release.json', '{invalid', LOCK_EX);
    file_put_contents(
        $temporary . '/recovery.php',
        "<?php require __DIR__ . '/launcher/entrypoint.php';"
        . "echo erp_dispatch_active_entrypoint(__DIR__, 'login.php', true) ? 'ACTIVE' : 'CLASSIC';\n",
        LOCK_EX
    );
    $recovery = $run($temporary . '/recovery.php');
    $assert($recovery['exit'] === 0 && $recovery['output'] === 'CLASSIC',
        'El acceso de recuperación no conservó el fallback clásico.');

    @unlink($temporary . '/shared/current-release.json');
    $classicAsset = $run($temporary . '/asset-request.php');
    $assert($classicAsset['exit'] === 0 && $classicAsset['output'] === 'asset-classic',
        'Una instalación clásica no pudo servir sus assets.');

    $engine = (string) file_get_contents($root . '/app/Services/SecureUpdateEngineService.php');
    $releaseService = (string) file_get_contents($root . '/app/Services/UpdateReleaseService.php');
    $view = (string) file_get_contents($root . '/app/Views/settings/update.php');
    foreach (['index.php', 'login.php', 'actualizar.php', 'stop.php', 'mantenimiento.php', 'recuperar.php'] as $entrypoint) {
        $source = (string) file_get_contents($root . '/' . $entrypoint);
        $assert(
            str_contains($source, 'erp_dispatch_active_entrypoint'),
            'El entrypoint estable no despacha la release activa: ' . $entrypoint
        );
    }
    $rootHtaccess = (string) file_get_contents($root . '/.htaccess');
    $layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
    $assert(
        str_contains($rootHtaccess, 'asset.php?path=$1')
        && str_contains($layout, 'View::asset('),
        'El código y los assets no comparten el contrato atómico de release.'
    );
    $assert(
        !str_contains($releaseService, 'activateAssets(')
        && !str_contains($releaseService, "public/assets.active"),
        'La activación todavía mantiene un segundo cambio de assets no atómico.'
    );
    $assert(
        str_contains($engine, "\$mode !== 'atomic'")
        && !str_contains($view, 'value="classic"')
        && str_contains($releaseService, 'La sobrescritura clásica fue retirada'),
        'La actualización clásica todavía puede seleccionarse o ejecutarse.'
    );

    $forbidden = [];
    foreach (glob($root . '/releases/*', GLOB_ONLYDIR) ?: [] as $releaseDirectory) {
        foreach (['PAUSE_MELI_API', 'PAUSE_ERP_AUTOMATION'] as $marker) {
            if (is_file($releaseDirectory . '/' . $marker)) {
                $forbidden[] = basename($releaseDirectory) . '/' . $marker;
            }
        }
    }
    $assert($forbidden === [], 'Una release contiene marcadores físicos de parada.');
} finally {
    $remove($temporary);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "PASS managed_activation_2265\n";
