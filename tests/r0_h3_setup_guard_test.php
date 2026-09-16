<?php
declare(strict_types=1);

$setup = __DIR__ . DIRECTORY_SEPARATOR . 'r0_h3_setup.php';
$manifest = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'r0_h3_bad_manifest_' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($manifest, json_encode([
    'owner' => 'r0_h3_local_lab',
    'host' => '127.0.0.1',
    'port' => '33191',
    'database' => 'mysql',
    'run_id' => bin2hex(random_bytes(16)),
], JSON_THROW_ON_ERROR));

$cmd = '"' . PHP_BINARY . '" "' . $setup . '"';
$env = array_merge($_ENV, [
    'R0_H3_DB_NAME' => 'mysql',
    'R0_H3_DB_PORT' => '33191',
    'R0_H3_LAB_MANIFEST' => $manifest,
]);
$descriptors = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__), $env);
if (!is_resource($process)) {
    fwrite(STDERR, "setup_guard_proc_open_failed\n");
    exit(255);
}
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($process);
@unlink($manifest);

echo json_encode(['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
if ($code === 0 || !str_contains($stderr, 'r0_h3_lab_identity_invalid')) {
    fwrite(STDERR, json_encode(['assertion' => 'setup_guard_must_reject_system_database_before_ddl', 'code' => $code, 'stderr' => $stderr], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(255);
}

