<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "Falta ext-zip.\n");
    exit(2);
}
$path = $argv[1] ?? '';
if (!is_file($path)) {
    fwrite(STDERR, "Uso: php bin/inspect_update_package.php paquete.erpupd\n");
    exit(2);
}
$zip = new ZipArchive();
if ($zip->open($path) !== true) {
    fwrite(STDERR, "Paquete inválido.\n");
    exit(3);
}
$json = $zip->getFromName('update-manifest.json');
$zip->close();
if (!is_string($json)) {
    fwrite(STDERR, "No contiene update-manifest.json.\n");
    exit(3);
}
try {
    $manifest = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, "Manifiesto inválido: {$error->getMessage()}\n");
    exit(3);
}
unset($manifest['signature']);
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
echo 'Package SHA-256: ' . hash_file('sha256', $path) . "\n";
