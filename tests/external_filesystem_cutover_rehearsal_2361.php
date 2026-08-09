<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/MeliNotificationTopicRegistry.php';
require dirname(__DIR__) . '/app/Services/RuntimePublicationPolicy.php';

use App\Services\RuntimePublicationPolicy;

$repo = dirname(__DIR__);
$oldCommit = 'f91cd534d271b964db1ad9e682260475eca96820';
$failures = [];
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    ++$checks;
    if (!$condition) {
        $failures[] = $message;
    }
};

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!file_exists($path) && !is_link($path)) {
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

/** @return array{exit:int,stdout:string,stderr:string} */
$run = static function (array $command, ?string $cwd = null): array {
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('rehearsal_process_unavailable');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
};

$mkdir = static function (string $path): void {
    if (!is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) {
        throw new RuntimeException('rehearsal_directory_unavailable');
    }
};

/**
 * The production contract additionally requires fsync(parent) on Linux. This
 * cross-platform rehearsal exercises full-write/flush/fsync(file)/rename and
 * treats certified parent-directory sync as an external preflight gate.
 */
$atomicBytes = static function (string $path, string $bytes, bool $crashBeforeRename = false) use ($mkdir): ?string {
    $directory = dirname($path);
    $mkdir($directory);
    $temporary = $directory . DIRECTORY_SEPARATOR . '.' . basename($path) . '.tmp-' . bin2hex(random_bytes(6));
    $handle = fopen($temporary, 'xb');
    if (!is_resource($handle)) {
        throw new RuntimeException('rehearsal_atomic_temp_unavailable');
    }
    try {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($handle, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) {
                throw new RuntimeException('rehearsal_atomic_short_write');
            }
            $offset += $written;
        }
        if (!fflush($handle)) {
            throw new RuntimeException('rehearsal_atomic_flush_failed');
        }
        if (function_exists('fsync') && !fsync($handle)) {
            throw new RuntimeException('rehearsal_atomic_file_fsync_failed');
        }
    } finally {
        fclose($handle);
    }
    if ($crashBeforeRename) {
        return $temporary;
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('rehearsal_atomic_rename_failed');
    }
    return null;
};

$atomicJson = static function (string $path, array $payload, bool $crashBeforeRename = false) use ($atomicBytes): ?string {
    return $atomicBytes(
        $path,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        $crashBeforeRename,
    );
};

/** @return array<string,array{type:string,size:int,sha256:string}> */
$snapshot = static function (array $roots): array {
    $result = [];
    foreach ($roots as $label => $path) {
        if (is_file($path)) {
            $result[(string) $label] = [
                'type' => 'file',
                'size' => (int) filesize($path),
                'sha256' => (string) hash_file('sha256', $path),
            ];
            continue;
        }
        if (!is_dir($path)) {
            $result[(string) $label] = ['type' => 'missing', 'size' => 0, 'sha256' => ''];
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($path) + 1));
            $key = (string) $label . '/' . $relative;
            $result[$key] = $item->isFile()
                ? ['type' => 'file', 'size' => $item->getSize(), 'sha256' => (string) hash_file('sha256', $item->getPathname())]
                : ['type' => 'directory', 'size' => 0, 'sha256' => ''];
        }
    }
    ksort($result, SORT_STRING);
    return $result;
};

$fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp-filesystem-cutover-2361-' . bin2hex(random_bytes(6));
$webroot = $fixtureRoot . '/production-like/webroot/erp-meli';
$operatorRoot = $fixtureRoot . '/operator-private';
$targetId = 'erp-meli-2.36.1-' . substr((string) trim($run(['git', '-C', $repo, 'rev-parse', 'HEAD'])['stdout']), 0, 12);
$rollbackId = 'rollback-2.35.1-focal';
$pointerPath = $webroot . '/shared/current-release.json';
$guardActive = true;
$mixedActiveRuntimeObserved = 0;
$observations = [];

try {
    $mkdir($webroot);
    $mkdir($operatorRoot . '/artifacts');

    // Materialize the stopped old runtime and rollback release from immutable
    // Git objects. No working-tree bytes enter either archive.
    $oldArchive = $operatorRoot . '/artifacts/rollback-2.35.1.zip';
    $oldArchiveResult = $run([
        'git', '-C', $repo, 'archive', '--format=zip', '--output=' . $oldArchive, $oldCommit,
    ]);
    $assert($oldArchiveResult['exit'] === 0 && is_file($oldArchive), 'Old Git-exact archive could not be created.');
    $oldZip = new ZipArchive();
    $assert($oldZip->open($oldArchive, ZipArchive::RDONLY) === true, 'Old Git-exact archive cannot be opened.');
    $assert($oldZip->extractTo($webroot), 'Old Git-exact runtime could not be materialized.');
    $mkdir($webroot . '/releases/' . $rollbackId);
    $assert($oldZip->extractTo($webroot . '/releases/' . $rollbackId), 'Rollback runtime could not be staged.');
    $oldZip->close();
    $assert(trim((string) file_get_contents($webroot . '/VERSION')) === '2.35.1', 'Classic runtime is not 2.35.1.');
    $assert(trim((string) file_get_contents($webroot . '/releases/' . $rollbackId . '/VERSION')) === '2.35.1', 'Rollback runtime is not 2.35.1.');

    // Build the target ZIP exclusively from committed Git blobs, verify every
    // entry against the policy authority, then stage it outside the webroot.
    $targetArchive = $operatorRoot . '/artifacts/ERP_MELI_2.36.1_GIT_EXACT.zip';
    $targetEntries = RuntimePublicationPolicy::packageEntries($repo, 'HEAD');
    $targetFiles = [];
    foreach ($targetEntries as $entry) {
        $targetFiles[$entry['path']] = RuntimePublicationPolicy::gitBlob($repo, 'HEAD', $entry['path']);
    }
    $buildZip = new ZipArchive();
    $assert($buildZip->open($targetArchive, ZipArchive::CREATE | ZipArchive::EXCL) === true,
        'Target Git-object archive could not be created.');
    foreach ($targetEntries as $entry) {
        $path = $entry['path'];
        $assert($buildZip->addFromString($path, $targetFiles[$path]), 'Target Git blob could not enter archive: ' . $path);
    }
    $assert($buildZip->close(), 'Target Git-object archive could not be finalized.');
    $assert(trim($targetFiles['VERSION'] ?? '') === '2.36.1', 'Target Git authority is not version 2.36.1.');
    $assert(is_file($targetArchive) && filesize($targetArchive) > 0, 'Target archive is empty.');

    $targetZip = new ZipArchive();
    $assert($targetZip->open($targetArchive, ZipArchive::RDONLY) === true, 'Target archive cannot be opened.');
    $seen = [];
    for ($index = 0; $index < $targetZip->numFiles; ++$index) {
        $stat = $targetZip->statIndex($index);
        $path = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
        $bytes = $targetZip->getFromIndex($index);
        $assert(isset($targetFiles[$path]), 'Target archive contains a non-authoritative path.');
        $assert(is_string($bytes) && isset($targetFiles[$path])
            && hash_equals(hash('sha256', $targetFiles[$path]), hash('sha256', $bytes)),
            'Target archive contains a Git blob mismatch.');
        $seen[$path] = true;
    }
    $assert(count($seen) === count($targetFiles), 'Target archive is missing managed runtime paths.');
    $targetStaging = $operatorRoot . '/staging/' . $targetId . '.staging';
    $mkdir($targetStaging);
    $assert($targetZip->extractTo($targetStaging), 'Target archive cannot be extracted to private staging.');
    $targetZip->close();
    foreach ($targetFiles as $path => $bytes) {
        $staged = $targetStaging . '/' . $path;
        $assert(is_file($staged) && hash_equals(hash('sha256', $bytes), (string) hash_file('sha256', $staged)),
            'Staged target is not Git-exact: ' . $path);
    }
    $assert(!is_dir($targetStaging . '/.git'), 'Git metadata entered target staging.');
    foreach (['config.env', '.env', 'storage', 'shared', 'uploads', 'backups', 'app/graphify-out'] as $excluded) {
        $assert(!file_exists($targetStaging . '/' . $excluded), 'Protected/nonruntime path entered target staging: ' . $excluded);
    }
    $targetFinal = $webroot . '/releases/' . $targetId;
    $assert(rename($targetStaging, $targetFinal), 'Verified target staging could not be published atomically.');

    // Model production-only nonruntime cache without importing it into target.
    $graphifyRoot = $webroot . '/app/graphify-out';
    $mkdir($graphifyRoot);
    for ($index = 1; $index <= 350; ++$index) {
        $name = sprintf('cache-%03d.json', $index);
        file_put_contents($graphifyRoot . '/' . $name, '{"fixture":"nonruntime","sequence":' . $index . '}');
    }
    $graphifyBefore = $snapshot(['graphify' => $graphifyRoot]);
    $assert(count($graphifyBefore) === 350, 'The production-like fixture does not contain 350 graphify cache files.');

    // Certify the six authoritative stale paths and instantiate safe fixture
    // bytes at those exact paths. Actual production hashes are never replaced
    // by the fixture hashes in the release authority.
    $authority = json_decode(
        (string) file_get_contents($repo . '/resources/release/production-legacy-quarantine-2.36.1.json'),
        true,
        64,
        JSON_THROW_ON_ERROR,
    );
    $expectedAuthority = [
        'app/Services/StorageMaintenanceService.php' => 'f01c0d298b8c69974965be3f1d197cc0f4ace2dd52fd8e05083611211683b817',
        'CronV3Cli.php' => '611d53a081477488fbdd9f1a2af3ac5164581a0348287bf889fc600377c4710c',
        'EmergencyControlKernel.php' => 'f9e69ae17b186e4354552521447a7df37766d31b5daea2bf1347f697af4c8eee',
        'EmergencyControlService.php' => '66a6828d20852176c6908f2e01b1415ffbcf4815006d5a8ab8e67b71933d7219',
        'ExecutionJournalService.php' => 'c38eaed9a0a352adba27b0f9f2f386239bca923cbd4d88e8c6f617f1ac29beb8',
        'MeliApiClient.php' => '2d7f7652b7b83c61da59281d8695db4e9b2db0ecbd121e3f7eb37909b5f5a67b',
    ];
    $entriesByPath = [];
    foreach ((array) ($authority['entries'] ?? []) as $entry) {
        $entriesByPath[(string) ($entry['path'] ?? '')] = $entry;
    }
    $assert(count($entriesByPath) === 6 && array_keys($entriesByPath) === array_keys($expectedAuthority),
        'Quarantine authority does not contain the exact six ordered paths.');
    foreach ($expectedAuthority as $path => $sha256) {
        $entry = $entriesByPath[$path] ?? [];
        $assert(($entry['expected_sha256'] ?? null) === $sha256, 'Authoritative stale SHA drifted: ' . $path);
        $assert(($entry['must_be_unreachable_after_cutover'] ?? null) === true, 'Stale path is not required unreachable: ' . $path);
        $assert(str_starts_with((string) ($entry['rollback_destination'] ?? ''), 'operator-private/'),
            'Stale rollback destination is not private: ' . $path);
    }

    $fixtureExpected = [];
    $fixtureBytes = [];
    foreach (array_keys($expectedAuthority) as $index => $path) {
        $bytes = "<?php\n// inert production-like stale fixture " . ($index + 1) . "\n";
        $fixtureBytes[$path] = $bytes;
        $fixtureExpected[$path] = hash('sha256', $bytes);
        $full = $webroot . '/' . $path;
        $mkdir(dirname($full));
        file_put_contents($full, $bytes);
    }

    // Protected state is represented by non-secret placeholders and compared
    // only through path/type/size/hash snapshots.
    $protectedFiles = [
        'shared/config.env' => 'REHEARSAL_CONFIG_PLACEHOLDER',
        'shared/storage/oauth-rotated-token-recovery.json' => 'REHEARSAL_CIPHERTEXT_ESCROW',
        'shared/storage/oauth-refresh-reservation.json' => 'REHEARSAL_RESERVATION',
        'shared/storage/logs/runtime.log' => 'REHEARSAL_LOG',
        'shared/storage/cache/runtime.cache' => 'REHEARSAL_CACHE',
        'uploads/user-content.bin' => 'REHEARSAL_UPLOAD',
        'backups/pre-cutover.marker' => 'REHEARSAL_BACKUP',
        'PAUSE_MELI_API' => '{"state":"blocked"}',
        'PAUSE_ERP_AUTOMATION' => '{"state":"stopped"}',
    ];
    foreach ($protectedFiles as $relative => $bytes) {
        $full = $webroot . '/' . $relative;
        $mkdir(dirname($full));
        file_put_contents($full, $bytes);
    }
    $protectedRoots = [
        'config' => $webroot . '/shared/config.env',
        'storage' => $webroot . '/shared/storage',
        'uploads' => $webroot . '/uploads',
        'backups' => $webroot . '/backups',
        'pause_api' => $webroot . '/PAUSE_MELI_API',
        'pause_automation' => $webroot . '/PAUSE_ERP_AUTOMATION',
    ];
    $protectedBefore = $snapshot($protectedRoots);

    $oldPointer = [
        'release_id' => $rollbackId,
        'version' => '2.35.1',
        'path' => 'releases/' . $rollbackId,
        'previous_release_id' => null,
        'previous_version' => null,
        'activated_at' => '2026-08-09T00:00:00Z',
    ];
    $targetPointer = [
        'release_id' => $targetId,
        'version' => '2.36.1',
        'path' => 'releases/' . $targetId,
        'previous_release_id' => $rollbackId,
        'previous_version' => '2.35.1',
        'activated_at' => '2026-08-09T00:01:00Z',
    ];
    $atomicJson($pointerPath, $oldPointer);

    $observe = static function () use (
        $pointerPath,
        $webroot,
        $targetId,
        $targetFiles,
        &$observations,
        &$guardActive,
        &$mixedActiveRuntimeObserved
    ): array {
        $pointer = json_decode((string) file_get_contents($pointerPath), true, 16, JSON_THROW_ON_ERROR);
        $relative = str_replace('\\', '/', (string) ($pointer['path'] ?? ''));
        $releaseId = (string) ($pointer['release_id'] ?? '');
        if ($relative === '' || $releaseId === '' || str_starts_with($relative, '/')
            || in_array('..', explode('/', $relative), true) || basename($relative) !== $releaseId
        ) {
            throw new RuntimeException('rehearsal_pointer_invalid');
        }
        $release = realpath($webroot . '/' . $relative);
        $releases = realpath($webroot . '/releases');
        if ($release === false || $releases === false
            || !str_starts_with(str_replace('\\', '/', $release) . '/', rtrim(str_replace('\\', '/', $releases), '/') . '/')
        ) {
            throw new RuntimeException('rehearsal_pointer_escape');
        }
        $version = trim((string) file_get_contents($release . '/VERSION'));
        if ($version !== (string) ($pointer['version'] ?? '')) {
            throw new RuntimeException('rehearsal_pointer_version_mismatch');
        }
        if ($releaseId === $targetId) {
            foreach ($targetFiles as $path => $bytes) {
                $file = $release . '/' . $path;
                if (!is_file($file) || !hash_equals(hash('sha256', $bytes), (string) hash_file('sha256', $file))) {
                    throw new RuntimeException('rehearsal_target_became_mixed');
                }
            }
        }
        $observations[] = $version;
        if (!$guardActive && !in_array($version, ['2.35.1', '2.36.1'], true)) {
            ++$mixedActiveRuntimeObserved;
        }
        return ['id' => $releaseId, 'version' => $version];
    };

    $quarantineRoot = $operatorRoot . '/quarantine/prod-2.36.1';
    /** @param array<string,array{source:string,moved:string,copy:string,sha256:string}> $journal */
    $restoreQuarantine = static function (array $journal) use ($mkdir): void {
        foreach (array_reverse($journal, true) as $record) {
            if (is_file($record['moved'])) {
                $mkdir(dirname($record['source']));
                if (is_file($record['source']) || !rename($record['moved'], $record['source'])) {
                    throw new RuntimeException('rehearsal_quarantine_restore_failed');
                }
            }
            if (!is_file($record['source'])
                || !hash_equals($record['sha256'], (string) hash_file('sha256', $record['source']))
            ) {
                throw new RuntimeException('rehearsal_quarantine_restore_hash_failed');
            }
        }
    };
    /** @param array<string,string> $expected @param array<string,array{source:string,moved:string,copy:string,sha256:string}> $journal */
    $quarantine = static function (array $expected, ?int $failAfterMoves, array &$journal) use (
        $webroot,
        $quarantineRoot,
        $expectedAuthority,
        $mkdir
    ): void {
        // Global preflight prevents a late hash mismatch from causing a partial move.
        foreach ($expected as $path => $sha256) {
            $source = $webroot . '/' . $path;
            if (!is_file($source) || !hash_equals($sha256, (string) hash_file('sha256', $source))) {
                throw new RuntimeException('rehearsal_stale_hash_mismatch:' . $path);
            }
        }
        foreach ($expected as $path => $sha256) {
            $base = $quarantineRoot . '/' . $expectedAuthority[$path] . '/' . $path;
            $copy = $base . '.verified-copy';
            $moved = $base . '.quarantined';
            $source = $webroot . '/' . $path;
            $mkdir(dirname($base));
            if (!copy($source, $copy) || !hash_equals($sha256, (string) hash_file('sha256', $copy))) {
                throw new RuntimeException('rehearsal_quarantine_copy_verify_failed:' . $path);
            }
            if (!rename($source, $moved)) {
                throw new RuntimeException('rehearsal_quarantine_move_failed:' . $path);
            }
            $journal[$path] = compact('source', 'moved', 'copy', 'sha256');
            if ($failAfterMoves !== null && count($journal) === $failAfterMoves) {
                throw new RuntimeException('rehearsal_injected_partial_quarantine_failure');
            }
        }
    };

    $normalHtaccess = (string) file_get_contents($webroot . '/.htaccess');
    $normalHtaccessHash = hash('sha256', $normalHtaccess);
    $atomicJson($webroot . '/shared/maintenance.json', [
        'enabled' => true,
        'reason' => 'external_cutover_rehearsal',
    ]);
    $guardBytes = "Options -Indexes\nErrorDocument 503 \"ERP maintenance\"\nRewriteEngine On\nRewriteRule ^ - [R=503,L]\n";
    $atomicBytes($webroot . '/.htaccess', $guardBytes);
    $assert(hash_file('sha256', $webroot . '/.htaccess') === hash('sha256', $guardBytes), 'Maintenance guard was not published atomically.');
    $assert($observe()['version'] === '2.35.1', 'Initial rollback pointer does not resolve old runtime.');

    // Combined failure matrix. DB fields are simulated technical state; Agent
    // E owns the real disposable-MariaDB execution and migration invariants.
    $db = ['schema' => 279, 'app.version' => '2.35.1'];
    $failureMatrix = [];
    $failureMatrix['before_migration'] = $observe()['version'] === '2.35.1' && $db['schema'] === 279;
    $db['schema'] = 286;
    $failureMatrix['mid_migrations'] = $guardActive && $observe()['version'] === '2.35.1';
    $db = ['schema' => 279, 'app.version' => '2.35.1']; // certified clone restore
    $db['schema'] = 293;
    $failureMatrix['after_migrations'] = $guardActive && $observe()['version'] === '2.35.1' && $db['app.version'] === '2.35.1';
    $failureMatrix['before_app_version'] = $db['schema'] === 293 && $observe()['version'] === '2.35.1';
    $db['app.version'] = '2.36.1';
    $failureMatrix['after_app_version_before_pointer'] = $guardActive && $observe()['version'] === '2.35.1';
    $db['app.version'] = '2.35.1';

    $orphanTemp = $atomicJson($pointerPath, $targetPointer, true);
    $failureMatrix['pointer_temp_crash'] = is_string($orphanTemp)
        && is_file($orphanTemp) && $observe()['version'] === '2.35.1';
    if (is_string($orphanTemp)) {
        @unlink($orphanTemp);
    }

    $db['app.version'] = '2.36.1';
    $atomicJson($pointerPath, $targetPointer);
    $failureMatrix['after_pointer'] = $guardActive && $observe()['version'] === '2.36.1';
    $atomicJson($pointerPath, $oldPointer);
    $db['app.version'] = '2.35.1';
    $failureMatrix['after_pointer_rollback'] = $observe()['version'] === '2.35.1';

    $db['app.version'] = '2.36.1';
    $atomicJson($pointerPath, $targetPointer);
    $failureMatrix['fpm_smoke_failure'] = $guardActive && $observe()['version'] === '2.36.1';
    $atomicJson($pointerPath, $oldPointer);
    $db['app.version'] = '2.35.1';
    $failureMatrix['fpm_smoke_rollback'] = $observe()['version'] === '2.35.1';

    // A mismatch blocks before any of the six paths moves.
    $mismatchPath = array_key_last($fixtureExpected);
    file_put_contents($webroot . '/' . $mismatchPath, $fixtureBytes[$mismatchPath] . "// drift\n");
    $mismatchJournal = [];
    try {
        $quarantine($fixtureExpected, null, $mismatchJournal);
        $failureMatrix['stale_hash_mismatch'] = false;
    } catch (RuntimeException $exception) {
        $failureMatrix['stale_hash_mismatch'] = str_starts_with($exception->getMessage(), 'rehearsal_stale_hash_mismatch')
            && $mismatchJournal === [];
    }
    file_put_contents($webroot . '/' . $mismatchPath, $fixtureBytes[$mismatchPath]);

    // Failure after three exact moves is recovered under guard, including the
    // pointer and technical version.
    $db['app.version'] = '2.36.1';
    $atomicJson($pointerPath, $targetPointer);
    $partialJournal = [];
    try {
        $quarantine($fixtureExpected, 3, $partialJournal);
        $failureMatrix['partial_quarantine'] = false;
    } catch (RuntimeException $exception) {
        $atomicJson($pointerPath, $oldPointer);
        $db['app.version'] = '2.35.1';
        $restoreQuarantine($partialJournal);
        $allRestored = true;
        foreach ($fixtureExpected as $path => $sha256) {
            $allRestored = $allRestored && is_file($webroot . '/' . $path)
                && hash_equals($sha256, (string) hash_file('sha256', $webroot . '/' . $path));
        }
        $failureMatrix['partial_quarantine'] = $exception->getMessage() === 'rehearsal_injected_partial_quarantine_failure'
            && count($partialJournal) === 3 && $allRestored && $observe()['version'] === '2.35.1';
    }

    // Exercise the complete external rollback after all six stale files have
    // already left the webroot and the target pointer is active under guard.
    $db = ['schema' => 293, 'app.version' => '2.36.1'];
    $atomicJson($pointerPath, $targetPointer);
    $rollbackJournal = [];
    $quarantine($fixtureExpected, null, $rollbackJournal);
    $atomicJson($pointerPath, $oldPointer);
    $db['app.version'] = '2.35.1';
    $restoreQuarantine($rollbackJournal);
    $fullRollbackRestored = count($rollbackJournal) === 6 && $observe()['version'] === '2.35.1';
    foreach ($fixtureExpected as $path => $sha256) {
        $fullRollbackRestored = $fullRollbackRestored
            && is_file($webroot . '/' . $path)
            && hash_equals($sha256, (string) hash_file('sha256', $webroot . '/' . $path));
    }
    $failureMatrix['full_quarantine_rollback'] = $fullRollbackRestored
        && $protectedBefore === $snapshot($protectedRoots)
        && $graphifyBefore === $snapshot(['graphify' => $graphifyRoot]);

    // Successful path: schema/version are coherent under guard, target pointer
    // is complete, all exact stale executables move outside webroot, then the
    // normal guard bytes are restored before exposure.
    $db = ['schema' => 293, 'app.version' => '2.36.1'];
    $atomicJson($pointerPath, $targetPointer);
    $assert($observe()['version'] === '2.36.1', 'Target pointer did not activate a complete target release.');
    $finalJournal = [];
    $quarantine($fixtureExpected, null, $finalJournal);
    $assert(count($finalJournal) === 6, 'Successful quarantine did not move six exact stale paths.');
    foreach ($finalJournal as $path => $record) {
        $assert(!file_exists($record['source']), 'Stale executable remains reachable in old webroot: ' . $path);
        $assert(is_file($record['moved']) && hash_equals($record['sha256'], (string) hash_file('sha256', $record['moved'])),
            'Quarantined stale file is not recoverable: ' . $path);
        $assert(!str_starts_with(str_replace('\\', '/', $record['moved']), str_replace('\\', '/', $webroot) . '/'),
            'Quarantine destination is inside webroot: ' . $path);
    }
    $assert($db === ['schema' => 293, 'app.version' => '2.36.1'], 'Final technical DB state is incoherent.');
    $assert($protectedBefore === $snapshot($protectedRoots), 'Protected external state changed during guarded cutover.');
    $assert($graphifyBefore === $snapshot(['graphify' => $graphifyRoot]), 'Nonruntime graphify cache changed during cutover.');

    $atomicBytes($webroot . '/.htaccess', $normalHtaccess);
    $assert(hash_equals($normalHtaccessHash, (string) hash_file('sha256', $webroot . '/.htaccess')),
        'Normal webroot guard was not restored byte-exact.');
    @unlink($webroot . '/shared/maintenance.json');
    $guardActive = false;
    $finalObservation = $observe();
    $assert($finalObservation === ['id' => $targetId, 'version' => '2.36.1'], 'Exposed runtime is not the complete target.');

    foreach ($failureMatrix as $scenario => $passed) {
        $assert($passed, 'Failure recovery scenario failed: ' . $scenario);
    }
    $assert(count($failureMatrix) === 13, 'Failure matrix is incomplete.');
    $assert($mixedActiveRuntimeObserved === 0, 'A mixed runtime was exposed at the active boundary.');
    $assert(array_diff($observations, ['2.35.1', '2.36.1']) === [], 'An observation saw a partial/unknown runtime.');
    $assert($protectedBefore === $snapshot($protectedRoots), 'Protected state changed after exposure.');
    $assert($graphifyBefore === $snapshot(['graphify' => $graphifyRoot]), 'Graphify cache changed after exposure.');
} catch (Throwable $exception) {
    $failures[] = 'Unhandled rehearsal error: ' . $exception->getMessage();
} finally {
    $removeTree($fixtureRoot);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, sprintf(
    "PASS external_filesystem_cutover_rehearsal_2361 checks=%d target_files=%d stale=6 graphify=350 protected=%d failures=13 rollback=1 mixed=0\n",
    $checks,
    count($targetFiles),
    count($protectedBefore),
));
