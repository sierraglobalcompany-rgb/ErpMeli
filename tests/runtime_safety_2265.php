<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

require $root . '/jobs/_cron_entry_state.php';
$first = cron_entry_early_lock();
$second = cron_entry_early_lock();
$assert(($first['status'] ?? '') === 'acquired', 'El primer lock temprano no fue adquirido.');
$assert(($second['status'] ?? '') === 'busy', 'El segundo lock temprano no fue clasificado como ocupado.');
if (is_resource($first['handle'] ?? null)) {
    flock($first['handle'], LOCK_UN);
    fclose($first['handle']);
}

$manifest = json_decode(
    (string) file_get_contents($root . '/resources/runtime-manifest.json'),
    true
);
foreach (['cron_probe', 'process_sync_queue'] as $component) {
    $definition = is_array($manifest) ? ($manifest['components'][$component] ?? null) : null;
    $path = is_array($definition) ? (string) ($definition['path'] ?? '') : '';
    $expected = is_array($definition) ? (string) ($definition['sha256'] ?? '') : '';
    $assert(
        $path !== '' && is_file($root . '/' . $path)
        && hash_equals($expected, (string) hash_file('sha256', $root . '/' . $path)),
        'El manifiesto no coincide con ' . $component . '.'
    );
}

if (!class_exists(ZipArchive::class)) {
    $failures[] = 'ZipArchive no está disponible para validar el paquete.';
} else {
    $temporary = sys_get_temp_dir() . '/erp-runtime-package-' . bin2hex(random_bytes(6));
    mkdir($temporary, 0700, true);
    $packagePath = $temporary . '/runtime.zip';
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($root . '/bin/build_managed_runtime_release.php')
        . ' --source=' . escapeshellarg($root)
        . ' --output=' . escapeshellarg($packagePath)
        . ' --ref=HEAD';
    exec($command . ' 2>&1', $output, $exitCode);
    $assert($exitCode === 0 && is_file($packagePath), 'El builder managed Git-exact no produjo una release válida.');

    if (is_file($packagePath)) {
        $zip = new ZipArchive();
        $assert($zip->open($packagePath) === true, 'El paquete generado no se puede abrir.');
        $paths = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $paths[] = str_replace('\\', '/', (string) $zip->getNameIndex($index));
        }
        $zip->close();
        foreach ($paths as $path) {
            $assert(
                preg_match(
                    '#(^|/)(?:PAUSE_MELI_API|PAUSE_ERP_AUTOMATION|config\.env|\.env|'
                    . 'graphify-out|tests|docs|audits|vendor|node_modules)(?:/|$)#i',
                    $path
                ) !== 1,
                'El paquete contiene contenido prohibido: ' . $path
            );
        }
        foreach (['VERSION', 'bootstrap.php', 'public/index.php', 'resources/runtime-manifest.json'] as $required) {
            $assert(in_array($required, $paths, true), 'Falta runtime obligatorio en paquete: ' . $required);
        }
    }
    @unlink($packagePath);
    @rmdir($temporary);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "PASS runtime_safety_2265\n";
