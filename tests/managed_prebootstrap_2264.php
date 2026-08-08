<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'erp-managed-prebootstrap-' . bin2hex(random_bytes(6));
$installation = $temporary . DIRECTORY_SEPARATOR . 'erp-meli';
$release = $installation . DIRECTORY_SEPARATOR . 'releases'
    . DIRECTORY_SEPARATOR . 'erp-meli-2.26.4';
$shared = $installation . DIRECTORY_SEPARATOR . 'shared';
$cache = $shared . DIRECTORY_SEPARATOR . 'storage'
    . DIRECTORY_SEPARATOR . 'cache';

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $removeTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
};

try {
    if (!mkdir($release . DIRECTORY_SEPARATOR . 'jobs', 0770, true) && !is_dir($release)) {
        throw new RuntimeException('No se pudo preparar la release temporal.');
    }
    if (!mkdir($cache, 0770, true) && !is_dir($cache)) {
        throw new RuntimeException('No se pudo preparar el storage compartido.');
    }
    file_put_contents(
        $shared . DIRECTORY_SEPARATOR . 'current-release.json',
        json_encode(['path' => 'releases/erp-meli-2.26.4'], JSON_THROW_ON_ERROR)
    );
    // Un paquete/release antiguo puede contener marcadores por error. Nunca
    // deben convertirse en autoridad de seguridad del runtime managed.
    file_put_contents($release . DIRECTORY_SEPARATOR . 'PAUSE_MELI_API', '{}');
    file_put_contents($release . DIRECTORY_SEPARATOR . 'PAUSE_ERP_AUTOMATION', '{}');
    file_put_contents(
        $cache . DIRECTORY_SEPARATOR . 'backup-maintenance-request.json',
        '{}'
    );

    define('ERP_INSTALLATION_ROOT', $installation);
    define('ERP_RELEASE_ROOT', $release);
    define('ERP_SHARED_ROOT', $shared);
    require $root . '/jobs/_prebootstrap_runtime_paths.php';
    require $root . '/jobs/_automation_emergency_stop.php';
    require $root . '/jobs/_meli_emergency_stop.php';

    $failures = [];
    if (erp_automation_emergency_stop_active()) {
        $failures[] = 'PAUSE_ERP_AUTOMATION dentro de la release no debe ser autoridad.';
    }
    if (erp_meli_emergency_stop_active()) {
        $failures[] = 'PAUSE_MELI_API dentro de la release no debe ser autoridad.';
    }
    file_put_contents($installation . DIRECTORY_SEPARATOR . 'PAUSE_MELI_API', '{}');
    file_put_contents($installation . DIRECTORY_SEPARATOR . 'PAUSE_ERP_AUTOMATION', '{}');
    if (!erp_automation_emergency_stop_active()) {
        $failures[] = 'No detectó PAUSE_ERP_AUTOMATION en la raíz estable.';
    }
    if (!erp_meli_emergency_stop_active()) {
        $failures[] = 'No detectó PAUSE_MELI_API en la raíz estable.';
    }
    if (!erp_prebootstrap_marker_exists('storage/cache/backup-maintenance-request.json')) {
        $failures[] = 'No detectó la copia pendiente en el storage compartido.';
    }
    $expected = str_replace('\\', '/', $cache)
        . '/backup-maintenance-request.json';
    $candidates = array_map(
        static fn (string $path): string => str_replace('\\', '/', $path),
        erp_prebootstrap_path_candidates(
            'storage/cache/backup-maintenance-request.json'
        )
    );
    if (!in_array($expected, $candidates, true)) {
        $failures[] = 'La ruta compartida no forma parte de los candidatos.';
    }
    if ($failures !== []) {
        fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
        exit(1);
    }
    echo "PASS managed_prebootstrap_2264\n";
} finally {
    $removeTree($temporary);
}
