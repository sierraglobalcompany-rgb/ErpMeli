<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = json_decode(
    (string) file_get_contents($root . '/resources/runtime-manifest.json'),
    true,
    32,
    JSON_THROW_ON_ERROR
);
$component = $manifest['components']['frontend_app_js'] ?? null;
if (!is_array($component)) {
    throw new RuntimeException('frontend_app_js no está firmado.');
}

$path = $root . '/' . (string) ($component['path'] ?? '');
$bytes = (string) file_get_contents($path);
$actual = hash('sha256', $bytes);
$expected = (string) ($component['sha256'] ?? '');

if (str_contains($bytes, "\r\n")) {
    throw new RuntimeException('public/assets/app.js debe quedar en LF para evitar hashes distintos al subir por FTP/Hostinger.');
}
if (!hash_equals($expected, $actual)) {
    throw new RuntimeException('Hash frontend_app_js no coincide con el asset local.');
}
if (!in_array($actual, [
    '51a894eca1716e526ef8f4bb4577343205e84ec18bfff2ea89a0548f7b321a58',
    '4c712a42f1ef23218368709cf7919bb01a168250d12e16fbaf5d94eea0cc8b7c',
    '562e1d6c689e949696a5484c6abb6944a9f853cc07b07df30d24dbf05b8c00c3',
    '73d72c9095bb3f088ed9153bd9b298e44999c79a6231b047cb3aa779c1af481f',
    '7cd19d499c4f3656121963dd554de22fcf7b6ff32304c50f4ad049b69ff0aeda',
    '28817ff51890cff0956a00c7d4c3396a1568083d426e8cfe0ba1cd5bc211558e',
    'e85a0e9dc788df36fd2a42d83eec35a051209a10c59ef902f2a40de8e59b4952',
    '14b4bf814d6a433c1db63fe5660c774555d4e340701db68bc43b1529722bd885',
], true)) {
    throw new RuntimeException('Hash frontend_app_js no coincide con el formato LF observado en producción.');
}

$migration = (string) file_get_contents($root . '/database/migrations/251_asset_hash_stable_line_endings_2_29_7.sql');
if (
    !str_contains($migration, "('app.version', '2.29.7'")
    || preg_match('/(?:INSERT|UPDATE|DELETE|ALTER)\s+(?:INTO\s+|FROM\s+|TABLE\s+)?(?:meli_orders|meli_order_items|meli_payments|meli_shipments|meli_packs|meli_oauth_states)\b/i', $migration)
) {
    throw new RuntimeException('La migración 251 debe ser solo metadata segura.');
}

echo "asset_hash_stable_2297_ok\n";
