<?php
declare(strict_types=1);

$db = (string) getenv('R0_H3_DB_NAME');
$port = (string) getenv('R0_H3_DB_PORT');
$manifestPath = (string) getenv('R0_H3_LAB_MANIFEST');
if ($db === '' || $port === '' || $manifestPath === '') {
    fwrite(STDERR, "r0_h3_lab_prepare_env_required\n");
    exit(90);
}
if (!preg_match('/^erp_meli_r0_h3_[a-z0-9_]{8,64}$/', $db) || $port !== '33191') {
    fwrite(STDERR, "r0_h3_lab_prepare_identity_invalid\n");
    exit(91);
}
$dir = dirname($manifestPath);
if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
    fwrite(STDERR, "r0_h3_lab_prepare_manifest_dir_failed\n");
    exit(92);
}
$runId = bin2hex(random_bytes(16));
$manifest = [
    'owner' => 'r0_h3_local_lab',
    'host' => '127.0.0.1',
    'port' => $port,
    'database' => $db,
    'run_id' => $runId,
    'created_by' => basename(__FILE__),
    'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
];
file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo json_encode(['manifest' => $manifestPath, 'database' => $db, 'run_id' => $runId], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

