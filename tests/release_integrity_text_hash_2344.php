<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\ReleaseIntegrityService;

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$tmp = sys_get_temp_dir() . '/erp_meli_release_integrity_2344_' . bin2hex(random_bytes(6));
$mkdir = static function (string $path): void {
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('No se pudo crear directorio temporal: ' . $path);
    }
};
$copy = static function (string $from, string $to) use ($mkdir): void {
    $mkdir(dirname($to));
    if (!copy($from, $to)) {
        throw new RuntimeException('No se pudo copiar archivo temporal: ' . $from);
    }
};
$rmTree = static function (string $path) use (&$rmTree): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        is_dir($child) ? $rmTree($child) : @unlink($child);
    }
    @rmdir($path);
};

try {
    $mkdir($tmp . '/resources');
    $copy($root . '/jobs/cron_probe.php', $tmp . '/jobs/cron_probe.php');
    $copy($root . '/jobs/process_sync_queue.php', $tmp . '/jobs/process_sync_queue.php');
    // The release repository intentionally carries only migrations 280-293.
    // This historical integrity test needs a named metadata fixture, not the
    // removed migration body.
    $mkdir($tmp . '/database/migrations');
    file_put_contents(
        $tmp . '/database/migrations/277_release_integrity_text_hash_recovery_2_34_4.sql',
        "-- historical metadata-only fixture; no business mutation\n"
    );
    file_put_contents($tmp . '/VERSION', "2.34.4\n");

    $kernelSource = (string) file_get_contents($root . '/app/Recovery/EmergencyControlKernel.php');
    $kernelLf = (string) preg_replace("/\r\n?|\n/", "\n", $kernelSource);
    $kernelCrlf = str_replace("\n", "\r\n", $kernelLf);
    $mkdir($tmp . '/app/Recovery');
    file_put_contents($tmp . '/app/Recovery/EmergencyControlKernel.php', $kernelLf);

    $manifest = [
        'version' => '2.34.4',
        'build_id' => 'erp-meli-2.34.4-release-integrity-text-lf-test',
        'generated_at' => gmdate('c'),
        'minimum_migration' => '277_release_integrity_text_hash_recovery_2_34_4.sql',
        'components' => [
            'cron_probe' => [
                'path' => 'jobs/cron_probe.php',
                'sha256' => hash_file('sha256', $tmp . '/jobs/cron_probe.php'),
            ],
            'process_sync_queue' => [
                'path' => 'jobs/process_sync_queue.php',
                'sha256' => hash_file('sha256', $tmp . '/jobs/process_sync_queue.php'),
            ],
            'emergency_control_kernel' => [
                'path' => 'app/Recovery/EmergencyControlKernel.php',
                'sha256' => hash('sha256', $kernelCrlf),
                'sha256_lf' => hash('sha256', $kernelLf),
                'text' => true,
            ],
        ],
    ];
    file_put_contents(
        $tmp . '/resources/runtime-manifest.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );

    $service = new ReleaseIntegrityService();
    $inspection = $service->inspectDirectory($tmp, false, false);
    $initialCodes = array_map(static fn (array $error): string => (string) ($error['code'] ?? ''), (array) ($inspection['errors'] ?? []));
    $assert(!in_array('component_mismatch', $initialCodes, true), 'LF canónico no debe producir component_mismatch.');
    $assert(
        in_array('runtime_publication_policy_invalid', $initialCodes, true),
        'El fixture histórico incompleto debe permanecer fail-closed por autoridad de publicación.'
    );
    $mode = $inspection['components']['emergency_control_kernel']['match_mode'] ?? '';
    $assert($mode === 'text_lf', 'Debe reportar match_mode=text_lf cuando el hash exacto difiere solo por finales de línea.');

    file_put_contents($tmp . '/app/Recovery/EmergencyControlKernel.php', $kernelLf . "\n// contenido real modificado\n");
    $tampered = $service->inspectDirectory($tmp, false, false);
    $assert($tampered['ok'] === false, 'Debe bloquear contenido real modificado.');
    $codes = array_map(static fn (array $error): string => (string) ($error['code'] ?? ''), (array) $tampered['errors']);
    $assert(in_array('component_mismatch', $codes, true), 'Debe bloquear con component_mismatch ante contenido real modificado.');
} finally {
    $rmTree($tmp);
}

echo "release_integrity_text_hash_2344: ok\n";
