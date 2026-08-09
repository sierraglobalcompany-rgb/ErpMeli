<?php

declare(strict_types=1);

require __DIR__ . '/support/ExternalCutoverByteVerifier.php';

$repo = dirname(__DIR__);
$authorityPath = __DIR__ . '/fixtures/release/external-cutover-byte-authority-2362.json';
$authority = json_decode((string) file_get_contents($authorityPath), true, 64, JSON_THROW_ON_ERROR);
$base = (string) ($authority['base_commit'] ?? '');
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

/** @return array{exit:int,stdout:string,stderr:string} */
$run = static function (array $command): array {
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('process_unavailable');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
};

$options = getopt('', ['commit::', 'package::']);
$commit = is_string($options['commit'] ?? null) ? (string) $options['commit'] : trim((string) $run([
    'git', '-C', $repo, 'rev-parse', 'HEAD',
])['stdout']);
$providedPackage = is_string($options['package'] ?? null) ? (string) $options['package'] : null;
$temporaryPackage = null;

try {
    $updater = is_array($authority['updater_locked'] ?? null) ? $authority['updater_locked'] : [];
    $migrations = is_array($authority['migrations'] ?? null) ? $authority['migrations'] : [];
    $assert($base === '75997f864b917c829d19f14e21cd7303d2685776', 'base_authority_changed');
    $assert(($authority['hash_authority'] ?? '') === 'raw_git_blob_or_exact_release_package_bytes', 'hash_authority_invalid');
    $assert(count($updater) === 29, 'updater_inventory_not_29');
    $assert(count($migrations) === 14, 'migration_inventory_not_14');

    $package = $providedPackage;
    if ($package === null) {
        $temporaryPackage = tempnam(sys_get_temp_dir(), 'erp-2362-raw-');
        if (!is_string($temporaryPackage)) {
            throw new RuntimeException('temporary_package_unavailable');
        }
        @unlink($temporaryPackage);
        $temporaryPackage .= '.zip';
        $zip = new ZipArchive();
        if ($zip->open($temporaryPackage, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('raw_object_package_create_failed');
        }
        foreach (array_merge($updater, $migrations) as $entry) {
            $path = (string) $entry['path'];
            if (!$zip->addFromString($path, ExternalCutoverByteVerifier::gitBlobBytes($repo, $commit, $path))) {
                throw new RuntimeException('raw_object_package_member_failed:' . $path);
            }
        }
        $zip->close();
        $package = $temporaryPackage;
    }

    $verified = ExternalCutoverByteVerifier::verify($authority, $repo, $commit, $package);
    foreach ($verified['failures'] as $failure) {
        $failures[] = 'authority:' . $failure;
    }
    $assert($verified['git_verified'] === 43, 'raw_git_verified_not_43');
    if ($providedPackage === null) {
        $assert($verified['package_verified'] === 43, 'git_archive_verified_not_43');
    } else {
        $assert(
            $verified['package_verified'] + $verified['package_optional_absent'] === 43,
            'release_package_coverage_not_43',
        );
    }

    // Prove that a checkout transformed by core.autocrlf cannot become the
    // migration authority. Git object bytes and package bytes remain exact.
    $driftMigration = $migrations[0];
    $driftPath = (string) $driftMigration['path'];
    $raw = ExternalCutoverByteVerifier::gitBlobBytes($repo, $commit, $driftPath);
    $checkoutNormalized = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $raw));
    $assert($checkoutNormalized !== $raw, 'line_ending_fixture_not_changed');
    $assert(
        hash('sha256', $checkoutNormalized) === (string) $driftMigration['sha256'],
        'checkout_normalization_not_reproduced',
    );

    // Build an adversarial package from exact blobs, then replace one SQL
    // member with CRLF bytes. Exact-package verification must reject it.
    $corruptPackage = tempnam(sys_get_temp_dir(), 'erp-2362-corrupt-');
    if (!is_string($corruptPackage)) {
        throw new RuntimeException('corrupt_package_unavailable');
    }
    @unlink($corruptPackage);
    $corruptPackage .= '.zip';
    $zip = new ZipArchive();
    if ($zip->open($corruptPackage, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('corrupt_package_create_failed');
    }
    foreach (array_merge($updater, $migrations) as $entry) {
        $path = (string) $entry['path'];
        $bytes = $path === $driftPath
            ? $checkoutNormalized
            : ExternalCutoverByteVerifier::gitBlobBytes($repo, $commit, $path);
        if (!$zip->addFromString($path, $bytes)) {
            throw new RuntimeException('corrupt_package_member_failed:' . $path);
        }
    }
    $zip->close();
    $corrupt = ExternalCutoverByteVerifier::verify($authority, $repo, $commit, $corruptPackage);
    $assert(
        in_array('package_sha256_mismatch:' . $driftPath, $corrupt['failures'], true),
        'crlf_package_not_rejected',
    );
    @unlink($corruptPackage);
} finally {
    if (is_string($temporaryPackage)) {
        @unlink($temporaryPackage);
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL external_cutover_byte_authority_2362 ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo 'PASS external_cutover_byte_authority_2362'
    . ' updater_locked=29 migrations=14 authority=RAW_GIT_OR_PACKAGE_BYTES'
    . ' checkout_line_endings=IGNORED corrupt_package=REJECTED' . PHP_EOL;
