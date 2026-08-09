<?php

declare(strict_types=1);

/**
 * Cross-platform external-cutover state-machine rehearsal for 2.36.2.
 *
 * This test deliberately uses inert runtime probes: the production launcher
 * call graph is covered by the dedicated managed-entrypoint tests. Here the
 * authority under test is sequencing, atomic pointer publication, exact
 * quarantine/restore, technical-version rollback and mixed-runtime exclusion.
 * No network transport or updater code is reachable from this fixture.
 */

const CUTOVER_BASE_2362 = '75997f864b917c829d19f14e21cd7303d2685776';
const CUTOVER_SOURCE_VERSION_2362 = '2.35.1';
const CUTOVER_TARGET_VERSION_2362 = '2.36.2';

$repo = dirname(__DIR__);
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

$mkdir = static function (string $path): void {
    if (!is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) {
        throw new RuntimeException('fixture_directory_unavailable');
    }
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
        throw new RuntimeException('fixture_process_unavailable');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [
        'exit' => proc_close($process),
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
};

$atomicBytes = static function (string $path, string $bytes, bool $interrupt = false) use ($mkdir): ?string {
    $mkdir(dirname($path));
    $temporary = dirname($path) . DIRECTORY_SEPARATOR . '.' . basename($path)
        . '.tmp-' . bin2hex(random_bytes(5));
    $handle = fopen($temporary, 'xb');
    if (!is_resource($handle)) {
        throw new RuntimeException('atomic_temp_unavailable');
    }
    try {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($handle, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) {
                throw new RuntimeException('atomic_short_write');
            }
            $offset += $written;
        }
        if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
            throw new RuntimeException('atomic_file_sync_failed');
        }
    } finally {
        fclose($handle);
    }
    if ($interrupt) {
        return $temporary;
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('atomic_rename_failed');
    }
    return null;
};

/** @param array<string,mixed> $payload */
$atomicJson = static function (string $path, array $payload, bool $interrupt = false) use ($atomicBytes): ?string {
    return $atomicBytes(
        $path,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        $interrupt,
    );
};

/** @return array<string,array{type:string,size:int,sha256:string}> */
$snapshot = static function (array $roots): array {
    $result = [];
    foreach ($roots as $label => $root) {
        if (is_file($root)) {
            $result[(string) $label] = [
                'type' => 'file',
                'size' => (int) filesize($root),
                'sha256' => (string) hash_file('sha256', $root),
            ];
            continue;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        ) as $item) {
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
            $key = (string) $label . '/' . $relative;
            $result[$key] = $item->isFile()
                ? ['type' => 'file', 'size' => $item->getSize(), 'sha256' => (string) hash_file('sha256', $item->getPathname())]
                : ['type' => 'directory', 'size' => 0, 'sha256' => ''];
        }
    }
    ksort($result, SORT_STRING);
    return $result;
};

$fixture = sys_get_temp_dir() . '/erp-cutover-state-2362-' . bin2hex(random_bytes(6));
$webroot = $fixture . '/webroot';
$private = $fixture . '/operator-private';
$pointerPath = $webroot . '/shared/current-release.json';
$dbStatePath = $private . '/db-state.json';
$rollbackId = 'rollback-2.35.1-hf1.2';
$targetId = 'erp-meli-2.36.2-focal';
$routes = [
    'index.php' => 302,
    'login.php' => 200,
    'actualizar.php' => 302,
    'stop.php' => 302,
    'mantenimiento.php' => 302,
    'recuperar.php' => 302,
    'cron-status.php' => 403,
    'asset.php' => 200,
];
$mixedActiveRuntime = 0;
$maxDispatchDepth = 0;
$transitionLog = [];

try {
    foreach ([$webroot . '/shared/storage', $webroot . '/releases', $private] as $directory) {
        $mkdir($directory);
    }

    foreach ([$rollbackId => CUTOVER_SOURCE_VERSION_2362, $targetId => CUTOVER_TARGET_VERSION_2362] as $id => $version) {
        $release = $webroot . '/releases/' . $id;
        $mkdir($release);
        file_put_contents($release . '/VERSION', $version . "\n");
        file_put_contents(
            $release . '/probe.php',
            "<?php\n"
            . "declare(strict_types=1);\n"
            . '$statuses=' . var_export($routes, true) . ";\n"
            . '$route=(string)($argv[1] ?? \'index.php\');' . "\n"
            . '$depth=(int)($GLOBALS[\'ERP_REHEARSAL_DISPATCH_DEPTH\'] ?? 0);' . "\n"
            . 'echo json_encode([\'runtime\'=>' . var_export($version, true)
            . ',\'route\'=>$route,\'status\'=>$statuses[$route] ?? 404,\'target_reached\'=>true,\'dispatch_depth\'=>$depth]);' . "\n",
        );
    }

    $dispatcher = $webroot . '/stable-dispatch.php';
    file_put_contents(
        $dispatcher,
        "<?php\n"
        . "declare(strict_types=1);\n"
        . '$root=' . var_export($webroot, true) . ";\n"
        . '$pointer=json_decode((string)file_get_contents($root.\'/shared/current-release.json\'),true,16,JSON_THROW_ON_ERROR);' . "\n"
        . '$id=(string)($pointer[\'release_id\'] ?? \'\');$path=(string)($pointer[\'path\'] ?? \'\');' . "\n"
        . 'if($id===\'\'||$path!==\'releases/\'.$id||str_contains($path,\'..\')){throw new RuntimeException(\'unsafe_pointer\');}' . "\n"
        . '$release=realpath($root.\'/\'.$path);$releases=realpath($root.\'/releases\');' . "\n"
        . 'if($release===false||$releases===false||!str_starts_with(str_replace(\'\\\\\',\'/\',$release).\'/\',rtrim(str_replace(\'\\\\\',\'/\',$releases),\'/\').\'/\')){throw new RuntimeException(\'pointer_escape\');}' . "\n"
        . '$version=trim((string)file_get_contents($release.\'/VERSION\'));if($version!==(string)($pointer[\'version\'] ?? \'\')){throw new RuntimeException(\'pointer_version_mismatch\');}' . "\n"
        . '$GLOBALS[\'ERP_REHEARSAL_DISPATCH_DEPTH\']=(int)($GLOBALS[\'ERP_REHEARSAL_DISPATCH_DEPTH\'] ?? 0)+1;' . "\n"
        . 'if($GLOBALS[\'ERP_REHEARSAL_DISPATCH_DEPTH\']!==1){throw new RuntimeException(\'recursive_dispatch\');}' . "\n"
        . 'require $release.\'/probe.php\';' . "\n",
    );

    $oldPointer = [
        'release_id' => $rollbackId,
        'version' => CUTOVER_SOURCE_VERSION_2362,
        'path' => 'releases/' . $rollbackId,
        'previous_release_id' => null,
    ];
    $targetPointer = [
        'release_id' => $targetId,
        'version' => CUTOVER_TARGET_VERSION_2362,
        'path' => 'releases/' . $targetId,
        'previous_release_id' => $rollbackId,
        'previous_version' => CUTOVER_SOURCE_VERSION_2362,
    ];
    $atomicJson($pointerPath, $oldPointer);
    $atomicJson($dbStatePath, [
        'schema' => 293,
        'app_version' => CUTOVER_SOURCE_VERSION_2362,
        'applied_280_293' => 14,
        'migrations_to_apply' => 0,
    ]);

    $setTechnicalVersion = static function (string $expected, string $target) use (
        $dbStatePath,
        $atomicJson,
        &$transitionLog
    ): void {
        // Opening and closing the state on every critical operation models the
        // runbook rule: never trust an idle DB connection for mutation/rollback.
        $state = json_decode((string) file_get_contents($dbStatePath), true, 16, JSON_THROW_ON_ERROR);
        if (($state['schema'] ?? null) !== 293 || ($state['migrations_to_apply'] ?? null) !== 0
            || ($state['app_version'] ?? null) !== $expected
        ) {
            throw new RuntimeException('technical_version_precondition_failed');
        }
        $state['app_version'] = $target;
        $transitionLog[] = $expected . '->' . $target;
        $atomicJson($dbStatePath, $state);
    };

    $matrix = static function (string $expectedVersion) use (
        $run,
        $dispatcher,
        $routes,
        &$mixedActiveRuntime,
        &$maxDispatchDepth,
        $assert
    ): void {
        foreach ($routes as $route => $expectedStatus) {
            $result = $run([PHP_BINARY, $dispatcher, $route]);
            $decoded = json_decode(trim($result['stdout']), true);
            $assert($result['exit'] === 0 && is_array($decoded), 'web_probe_failed:' . $route);
            if (!is_array($decoded)) {
                continue;
            }
            $runtime = (string) ($decoded['runtime'] ?? '');
            $depth = (int) ($decoded['dispatch_depth'] ?? 0);
            $maxDispatchDepth = max($maxDispatchDepth, $depth);
            if (!in_array($runtime, [CUTOVER_SOURCE_VERSION_2362, CUTOVER_TARGET_VERSION_2362], true)) {
                ++$mixedActiveRuntime;
            }
            $assert($runtime === $expectedVersion, 'web_runtime_mismatch:' . $route);
            $assert(($decoded['target_reached'] ?? null) === true, 'web_target_not_reached:' . $route);
            $assert((int) ($decoded['status'] ?? 0) === $expectedStatus, 'web_status_mismatch:' . $route);
            $assert($depth === 1, 'dispatch_depth_not_one:' . $route);
        }
    };

    $protected = [
        'shared/config.env' => 'NON_SECRET_REHEARSAL_CONFIG',
        'shared/storage/runtime-state.json' => '{"fixture":"protected"}',
        'PAUSE_MELI_API' => '{"state":"stopped"}',
        'PAUSE_ERP_AUTOMATION' => '{"state":"stopped"}',
    ];
    foreach ($protected as $relative => $bytes) {
        $path = $webroot . '/' . $relative;
        $mkdir(dirname($path));
        file_put_contents($path, $bytes);
    }
    $protectedRoots = [
        'config' => $webroot . '/shared/config.env',
        'storage' => $webroot . '/shared/storage',
        'api_stop' => $webroot . '/PAUSE_MELI_API',
        'automation_stop' => $webroot . '/PAUSE_ERP_AUTOMATION',
    ];
    $protectedBefore = $snapshot($protectedRoots);

    $authority = json_decode(
        (string) file_get_contents($repo . '/resources/release/production-legacy-quarantine-2.36.1.json'),
        true,
        64,
        JSON_THROW_ON_ERROR,
    );
    $entries = (array) ($authority['entries'] ?? []);
    $assert(count($entries) === 6, 'quarantine_authority_not_six');
    $fixtureHashes = [];
    foreach ($entries as $index => $entry) {
        $relative = (string) ($entry['path'] ?? '');
        $bytes = "<?php\n// inert stale fixture " . ($index + 1) . "\n";
        $path = $webroot . '/' . $relative;
        $mkdir(dirname($path));
        file_put_contents($path, $bytes);
        $fixtureHashes[$relative] = hash('sha256', $bytes);
    }

    /** @var array<string,array{source:string,moved:string,copy:string,sha256:string}> $journal */
    $journal = [];
    $quarantine = static function () use (
        &$journal,
        $entries,
        $fixtureHashes,
        $webroot,
        $private,
        $mkdir
    ): void {
        foreach ($fixtureHashes as $relative => $sha256) {
            $source = $webroot . '/' . $relative;
            if (!is_file($source) || !hash_equals($sha256, (string) hash_file('sha256', $source))) {
                throw new RuntimeException('quarantine_preflight_hash_mismatch:' . $relative);
            }
        }
        foreach ($entries as $entry) {
            $relative = (string) $entry['path'];
            $sha256 = $fixtureHashes[$relative];
            $base = $private . '/quarantine/prod-2.36.2/' . (string) $entry['expected_sha256'] . '/' . $relative;
            $source = $webroot . '/' . $relative;
            $copy = $base . '.verified-copy';
            $moved = $base . '.quarantined';
            $mkdir(dirname($base));
            if (!copy($source, $copy) || !hash_equals($sha256, (string) hash_file('sha256', $copy))) {
                throw new RuntimeException('quarantine_copy_verify_failed:' . $relative);
            }
            if (!rename($source, $moved)) {
                throw new RuntimeException('quarantine_move_failed:' . $relative);
            }
            $journal[$relative] = compact('source', 'moved', 'copy', 'sha256');
        }
    };
    $restoreQuarantine = static function () use (&$journal, $mkdir): void {
        foreach (array_reverse($journal, true) as $record) {
            $mkdir(dirname($record['source']));
            if (!rename($record['moved'], $record['source'])
                || !hash_equals($record['sha256'], (string) hash_file('sha256', $record['source']))
            ) {
                throw new RuntimeException('quarantine_restore_failed');
            }
        }
        $journal = [];
    };

    // S0: exact production starting model. There is no schema work in 2.36.2.
    $initialState = json_decode((string) file_get_contents($dbStatePath), true, 16, JSON_THROW_ON_ERROR);
    $assert($initialState === [
        'schema' => 293,
        'app_version' => CUTOVER_SOURCE_VERSION_2362,
        'applied_280_293' => 14,
        'migrations_to_apply' => 0,
    ], 'production_start_model_drifted');
    $matrix(CUTOVER_SOURCE_VERSION_2362);

    // Interrupted pointer writes must leave the previously published runtime.
    $partial = $atomicJson($pointerPath, $targetPointer, true);
    $matrix(CUTOVER_SOURCE_VERSION_2362);
    $assert(is_string($partial) && is_file($partial), 'partial_pointer_fixture_missing');
    @unlink((string) $partial);

    // Successful technical transition, guard, pointer and exact quarantine.
    $setTechnicalVersion(CUTOVER_SOURCE_VERSION_2362, CUTOVER_TARGET_VERSION_2362);
    $atomicBytes($webroot . '/.htaccess', "# 503 CUTOVER GUARD\n");
    $atomicJson($pointerPath, $targetPointer);
    $matrix(CUTOVER_TARGET_VERSION_2362);
    $quarantine();
    $assert(count($journal) === 6, 'quarantine_did_not_move_six');
    foreach ($fixtureHashes as $relative => $_hash) {
        $assert(!file_exists($webroot . '/' . $relative), 'stale_still_reachable:' . $relative);
    }

    // Inject the production 2.36.1 failure boundary after pointer activation.
    // Rollback uses a newly opened state authority, restores all six exact
    // bytes, then exposes only the complete classic runtime.
    $atomicBytes($webroot . '/.htaccess', "# 503 ROLLBACK GUARD\n");
    $atomicJson($pointerPath, $oldPointer);
    $setTechnicalVersion(CUTOVER_TARGET_VERSION_2362, CUTOVER_SOURCE_VERSION_2362);
    $restoreQuarantine();
    $atomicBytes($webroot . '/.htaccess', "# NORMAL ROUTING\n");
    $matrix(CUTOVER_SOURCE_VERSION_2362);
    foreach ($fixtureHashes as $relative => $sha256) {
        $assert(
            is_file($webroot . '/' . $relative)
            && hash_equals($sha256, (string) hash_file('sha256', $webroot . '/' . $relative)),
            'rollback_stale_not_exact:' . $relative,
        );
    }

    // Final successful rehearsal remains physically stopped.
    $setTechnicalVersion(CUTOVER_SOURCE_VERSION_2362, CUTOVER_TARGET_VERSION_2362);
    $atomicBytes($webroot . '/.htaccess', "# 503 CUTOVER GUARD\n");
    $atomicJson($pointerPath, $targetPointer);
    $quarantine();
    $matrix(CUTOVER_TARGET_VERSION_2362);
    $atomicBytes($webroot . '/.htaccess', "# NORMAL ROUTING\n");
    $finalState = json_decode((string) file_get_contents($dbStatePath), true, 16, JSON_THROW_ON_ERROR);

    $assert(($finalState['schema'] ?? null) === 293, 'schema_changed');
    $assert(($finalState['migrations_to_apply'] ?? null) === 0, 'unexpected_migrations_applied');
    $assert(($finalState['app_version'] ?? null) === CUTOVER_TARGET_VERSION_2362, 'final_version_not_2362');
    $assert($transitionLog === ['2.35.1->2.36.2', '2.36.2->2.35.1', '2.35.1->2.36.2'],
        'technical_version_transition_sequence_drifted');
    $assert($protectedBefore === $snapshot($protectedRoots), 'protected_state_changed');
    $assert($mixedActiveRuntime === 0, 'mixed_active_runtime_observed');
    $assert($maxDispatchDepth === 1, 'dispatch_depth_exceeded_one');
    $assert(is_file($webroot . '/PAUSE_MELI_API') && is_file($webroot . '/PAUSE_ERP_AUTOMATION'),
        'physical_stop_marker_missing');

    $plan = (string) file_get_contents($repo . '/docs/release_2362_external_cutover.md');
    foreach ([
        'schema=293',
        'MIGRATIONS_TO_APPLY_EXPECTED=0',
        '2.35.1 → 2.36.2',
        'RAW_GIT_OR_PACKAGE_BYTES',
        'fresh verified connection',
        'MIXED_ACTIVE_RUNTIME=0',
        'PAUSE_MELI_API',
        'PAUSE_ERP_AUTOMATION',
        'No se invoca el updater',
    ] as $evidence) {
        $assert(str_contains($plan, $evidence), 'cutover_plan_evidence_missing:' . $evidence);
    }
} finally {
    $removeTree($fixture);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo 'PASS external_cutover_state_machine_2362 checks=' . $checks
    . ' migrations=0 rollback=PASS mixed=0 remote_http=0' . PHP_EOL;
