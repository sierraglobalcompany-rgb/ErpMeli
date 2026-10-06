<?php
declare(strict_types=1);

// Local build tool only. Never include this file in the deployment payload.

function cap2AssertSafePackagePath(string $path): void
{
    if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')
        || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1
        || preg_match('#^[A-Za-z0-9._/-]+$#D', $path) !== 1
    ) {
        throw new RuntimeException('unsafe_package_path:' . $path);
    }
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new RuntimeException('unsafe_package_path:' . $path);
        }
    }
}

function cap2AssertRuntimePackagePath(string $path): void
{
    cap2AssertSafePackagePath($path);
    $segments = explode('/', strtolower($path));
    $basename = (string) end($segments);
    if (in_array($basename, ['.env', 'config.env', 'pause_meli_api', 'pause_erp_automation'], true)) {
        throw new RuntimeException('protected_runtime_basename:' . $path);
    }
    $rootFiles = [
        '.htaccess', 'VERSION', 'actualizar.php', 'asset.php', 'bootstrap.php',
        'composer.json', 'composer.lock', 'cron-status.php', 'index.php', 'login.php',
        'mantenimiento.php', 'recuperar.php', 'stop.php',
    ];
    $runtime = in_array($path, $rootFiles, true)
        || preg_match('#^(?:app|jobs|launcher|stop)/[A-Za-z0-9._/-]+\.php$#D', $path) === 1
        || preg_match('#^public/[A-Za-z0-9._/-]+$#D', $path) === 1
        || preg_match('#^resources/(?:runtime-manifest\.json|migration-replacements\.json|release/[A-Za-z0-9._-]+\.json|modules/[A-Za-z0-9._-]+/module\.json|mercadolibre-api/generated/[A-Za-z0-9._-]+\.json)$#D', $path) === 1
        || preg_match('#^database/migrations/[A-Za-z0-9._-]+\.sql$#D', $path) === 1
        || in_array($path, [
            'bin/create_admin.php', 'bin/database_growth_audit.php', 'bin/database_physical_recovery.php',
            'bin/db_explain_audit.php', 'bin/meli_api_audit.php', 'bin/migrate.php',
            'bin/query_performance_report.php', 'bin/queue_core_dependency_check.php',
            'bin/runtime_process_audit.php',
        ], true);
    if (!$runtime) {
        throw new RuntimeException('non_runtime_package_path:' . $path);
    }
}

/** @return array{exit:int,stdout:string,stderr:string} */
function cap2Git(string $root, array $arguments): array
{
    $process = proc_open(array_merge(['git', '-c', 'safe.directory=' . str_replace('\\', '/', $root), '-C', $root], $arguments), [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('git_start_failed');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0 || !is_string($stdout)) {
        throw new RuntimeException('git_failed:' . $exit . ':' . trim((string) $stderr));
    }
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => (string) $stderr];
}

function cap2GitBlob(string $root, string $commit, string $path): string
{
    cap2AssertSafePackagePath($path);
    return cap2Git($root, ['show', $commit . ':' . $path])['stdout'];
}

function cap2WriteFile(string $path, string $bytes): void
{
    $parent = dirname($path);
    if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
        throw new RuntimeException('mkdir_failed:' . $parent);
    }
    if (file_put_contents($path, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('write_failed:' . $path);
    }
}

function cap2Json(array $value): string
{
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
}

/** @return array<string,string> path => status */
function cap2GitDiffStatuses(string $root, string $base, string $head): array
{
    $raw = cap2Git($root, ['diff', '--name-status', '--no-renames', '-z', $base, $head])['stdout'];
    $parts = explode("\0", $raw);
    if ($parts !== [] && end($parts) === '') {
        array_pop($parts);
    }
    if (count($parts) % 2 !== 0) {
        throw new RuntimeException('malformed_git_diff');
    }
    $statuses = [];
    for ($i = 0; $i < count($parts); $i += 2) {
        $status = $parts[$i];
        $path = $parts[$i + 1];
        cap2AssertSafePackagePath($path);
        if (!in_array($status, ['A', 'M', 'D'], true)) {
            throw new RuntimeException('unsupported_git_status:' . $status . ':' . $path);
        }
        if (isset($statuses[$path])) {
            throw new RuntimeException('duplicate_git_path:' . $path);
        }
        $statuses[$path] = $status;
    }
    ksort($statuses, SORT_STRING);
    return $statuses;
}

/** @param array<string,string> $expected */
function cap2VerifyZip(string $path, array $expected): void
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('zip_reopen_failed:' . $path);
    }
    try {
        if ($zip->numFiles !== count($expected)) {
            throw new RuntimeException('zip_entry_count:' . $path);
        }
        $seen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name)) {
                throw new RuntimeException('zip_entry_name');
            }
            cap2AssertSafePackagePath($name);
            if (!isset($expected[$name]) || isset($seen[$name])) {
                throw new RuntimeException('zip_unexpected_entry:' . $name);
            }
            $bytes = $zip->getFromIndex($i);
            if (!is_string($bytes) || !hash_equals($expected[$name], hash('sha256', $bytes))) {
                throw new RuntimeException('zip_hash_mismatch:' . $name);
            }
            $seen[$name] = true;
        }
    } finally {
        $zip->close();
    }
}

/**
 * Builds an integrity-only, explicitly unapproved package from immutable Git blobs.
 *
 * @param list<string> $baseRuntimePaths
 * @param list<string> $headRuntimePaths
 * @return array{deploy_zip:string,rollback_zip:string,applied_tree:string,deploy_count:int,deploy_paths:list<string>,added_paths:list<string>,replaced_paths:list<string>,deploy_hashes:array<string,string>}
 */
function cap2BuildRawArtifact(
    string $root,
    string $base,
    string $head,
    string $out,
    array $baseRuntimePaths,
    array $headRuntimePaths,
    string $date,
    string $family = 'cap2'
): array {
    if (!in_array($family, ['cap2', 'calls'], true)) {
        throw new RuntimeException('invalid_package_family');
    }
    if (preg_match('/^[0-9]{8}$/D', $date) !== 1) {
        throw new RuntimeException('date_must_be_yyyymmdd');
    }
    if (is_dir($out) && (glob(rtrim($out, '/\\') . '/*') ?: []) !== []) {
        throw new RuntimeException('output_must_be_empty');
    }
    if (!is_dir($out) && !mkdir($out, 0777, true) && !is_dir($out)) {
        throw new RuntimeException('output_create_failed');
    }
    $base = trim(cap2Git($root, ['rev-parse', $base . '^{commit}'])['stdout']);
    $head = trim(cap2Git($root, ['rev-parse', $head . '^{commit}'])['stdout']);
    $tree = trim(cap2Git($root, ['rev-parse', $head . '^{tree}'])['stdout']);

    $baseRuntimePaths = array_values(array_unique(array_map('strval', $baseRuntimePaths)));
    $headRuntimePaths = array_values(array_unique(array_map('strval', $headRuntimePaths)));
    sort($baseRuntimePaths, SORT_STRING);
    sort($headRuntimePaths, SORT_STRING);
    foreach (array_merge($baseRuntimePaths, $headRuntimePaths) as $path) {
        cap2AssertRuntimePackagePath($path);
    }
    $baseSet = array_fill_keys($baseRuntimePaths, true);
    $headSet = array_fill_keys($headRuntimePaths, true);
    $statuses = cap2GitDiffStatuses($root, $base, $head);
    $deployPaths = [];
    $addedPaths = [];
    $replacedPaths = [];
    foreach ($statuses as $path => $status) {
        $runtime = isset($baseSet[$path]) || isset($headSet[$path]);
        if (!$runtime) {
            continue;
        }
        if ($status === 'D' || !isset($headSet[$path])) {
            throw new RuntimeException('runtime_delete_requires_manual_release_design:' . $path);
        }
        $deployPaths[] = $path;
        if ($status === 'A') {
            $addedPaths[] = $path;
        } else {
            $replacedPaths[] = $path;
        }
    }
    if ($deployPaths === []) {
        throw new RuntimeException('empty_runtime_delta');
    }

    $appliedTree = rtrim(str_replace('\\', '/', $out), '/') . '/applied-tree';
    foreach ($baseRuntimePaths as $path) {
        if (!isset($headSet[$path])) {
            throw new RuntimeException('runtime_delete_requires_manual_release_design:' . $path);
        }
        cap2WriteFile($appliedTree . '/' . $path, cap2GitBlob($root, $base, $path));
    }
    foreach ($deployPaths as $path) {
        cap2WriteFile($appliedTree . '/' . $path, cap2GitBlob($root, $head, $path));
    }

    $targetHashes = [];
    foreach ($headRuntimePaths as $path) {
        $expected = cap2GitBlob($root, $head, $path);
        $applied = @file_get_contents($appliedTree . '/' . $path);
        if (!is_string($applied) || !hash_equals(hash('sha256', $expected), hash('sha256', $applied))) {
            throw new RuntimeException('base_plus_patch_mismatch:' . $path);
        }
        $targetHashes[$path] = hash('sha256', $expected);
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appliedTree, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($appliedTree) + 1));
        if (!isset($headSet[$relative])) {
            throw new RuntimeException('applied_tree_extra:' . $relative);
        }
    }

    $deployHashes = [];
    $deployZip = rtrim(str_replace('\\', '/', $out), '/') . '/meli-' . $family . '-deploy-' . $date . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($deployZip, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException('deploy_zip_create_failed');
    }
    foreach ($deployPaths as $path) {
        $bytes = cap2GitBlob($root, $head, $path);
        if (!$zip->addFromString($path, $bytes)) {
            throw new RuntimeException('deploy_zip_add:' . $path);
        }
        $deployHashes[$path] = hash('sha256', $bytes);
    }
    if (!$zip->close()) {
        throw new RuntimeException('deploy_zip_close_failed');
    }
    cap2VerifyZip($deployZip, $deployHashes);

    $rollbackHashes = [];
    $baseFileHashes = [];
    $rollbackZip = rtrim(str_replace('\\', '/', $out), '/') . '/rollback.zip';
    $zip = new ZipArchive();
    if ($zip->open($rollbackZip, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException('rollback_zip_create_failed');
    }
    foreach ($replacedPaths as $path) {
        $bytes = cap2GitBlob($root, $base, $path);
        $zip->addFromString($path, $bytes);
        $baseFileHashes[$path] = hash('sha256', $bytes);
        $rollbackHashes[$path] = $baseFileHashes[$path];
    }
    $removeAdded = $addedPaths === [] ? "# No added runtime files.\n" : implode("\n", $addedPaths) . "\n";
    $zip->addFromString('REMOVE_ADDED_FILES.txt', $removeAdded);
    $rollbackHashes['REMOVE_ADDED_FILES.txt'] = hash('sha256', $removeAdded);
    $zip->close();
    cap2VerifyZip($rollbackZip, $rollbackHashes);

    cap2WriteFile($out . '/target.json', cap2Json([
        'base' => $base,
        'head' => $head,
        'tree' => $tree,
        'deploy_files' => $deployHashes,
        'target_runtime_files' => $targetHashes,
        'base_files' => $baseFileHashes,
        'new_files' => $addedPaths,
    ]));
    cap2WriteFile($out . '/SHA256SUMS.txt', implode("\n", array_map(
        static fn(string $path, string $hash): string => $hash . '  ' . $path,
        array_keys($deployHashes),
        array_values($deployHashes)
    )) . "\n");
    $control = implode("\n", [
        'PACKAGE_STATUS=PREPARATORY_NOT_FOR_UPLOAD',
        'DEPLOY_APPROVED=NO',
        'STRUCTURAL_INTEGRITY=PASS',
        'BASE=' . $base,
        'HEAD=' . $head,
        'TREE=' . $tree,
        'DEPLOY_FILES=' . count($deployPaths),
        'RAW_GIT_BLOBS=YES',
        'ZIP_REOPEN_HASHES=PASS',
        'BASE_PLUS_PATCH_FULL_TREE=PASS',
        'PRODUCTION_CHANGED=NO',
        'CRON_CHANGED=NO',
        'MIGRATIONS_RUN=0',
        '',
    ]);
    cap2WriteFile($out . '/CONTROL.txt', $control);

    return [
        'deploy_zip' => $deployZip,
        'rollback_zip' => $rollbackZip,
        'applied_tree' => $appliedTree,
        'deploy_count' => count($deployPaths),
        'deploy_paths' => $deployPaths,
        'added_paths' => $addedPaths,
        'replaced_paths' => $replacedPaths,
        'deploy_hashes' => $deployHashes,
    ];
}

/** Rehash existing entries and add explicitly named runtime blobs from one immutable commit. */
function cap2UpdaterAuthority(string $root, string $commit, array $additionalPaths = []): array
{
    if (preg_match('/^[0-9a-fA-F]{40}$/D', $commit) !== 1) {
        throw new RuntimeException('updater_authority_explicit_40_hex_commit_required');
    }
    if (trim(cap2Git($root, ['cat-file', '-t', $commit])['stdout']) !== 'commit') {
        throw new RuntimeException('updater_authority_commit_required');
    }
    $authorityPath = 'resources/release/updater-authority-2.41.0.json';
    // Decode objects as objects so metadata such as {} is not silently converted to [].
    $authority = json_decode(cap2GitBlob($root, $commit, $authorityPath), false, 64, JSON_THROW_ON_ERROR);
    if (!$authority instanceof stdClass || !isset($authority->new_runtime_dependencies)
        || !is_array($authority->new_runtime_dependencies) || !array_is_list($authority->new_runtime_dependencies)) {
        throw new RuntimeException('updater_authority_dependencies_invalid');
    }
    $seen = [];
    foreach ($authority->new_runtime_dependencies as $entry) {
        if (!$entry instanceof stdClass || !isset($entry->path, $entry->sha256)
            || !is_string($entry->path) || !is_string($entry->sha256)
            || preg_match('/^[0-9a-fA-F]{64}$/D', $entry->sha256) !== 1) {
            throw new RuntimeException('updater_authority_dependency_invalid');
        }
        cap2AssertRuntimePackagePath($entry->path);
        if (isset($seen[$entry->path]) || $entry->path === $authorityPath) {
            throw new RuntimeException('updater_authority_duplicate_or_self_dependency:' . $entry->path);
        }
        $seen[$entry->path] = true;
        if (trim(cap2Git($root, ['cat-file', '-t', $commit . ':' . $entry->path])['stdout']) !== 'blob') {
            throw new RuntimeException('updater_authority_dependency_blob_required:' . $entry->path);
        }
        $entry->sha256 = hash('sha256', cap2GitBlob($root, $commit, $entry->path));
    }
    foreach ($additionalPaths as $path) {
        if (!is_string($path) || trim($path) !== $path || $path === '') {
            throw new RuntimeException('updater_authority_additional_path_invalid');
        }
        cap2AssertRuntimePackagePath($path);
        if (isset($seen[$path]) || $path === $authorityPath) {
            throw new RuntimeException('updater_authority_duplicate_or_self_dependency:' . $path);
        }
        if (trim(cap2Git($root, ['cat-file', '-t', $commit . ':' . $path])['stdout']) !== 'blob') {
            throw new RuntimeException('updater_authority_dependency_blob_required:' . $path);
        }
        $seen[$path] = true;
        $entry = new stdClass();
        $entry->path = $path;
        $entry->sha256 = hash('sha256', cap2GitBlob($root, $commit, $path));
        $authority->new_runtime_dependencies[] = $entry;
    }
    usort(
        $authority->new_runtime_dependencies,
        static fn (stdClass $left, stdClass $right): int => strcmp((string) $left->path, (string) $right->path)
    );
    return get_object_vars($authority);
}

/** Seed new release metadata from the immediately previous authority; hashes are regenerated separately from Git blobs. */
function cap2SeedRelease2410(string $root): void
{
    $previousAuthority = json_decode(
        (string) file_get_contents($root . '/resources/release/updater-authority-2.40.1.json'),
        false,
        64,
        JSON_THROW_ON_ERROR
    );
    if (!$previousAuthority instanceof stdClass) {
        throw new RuntimeException('previous_updater_authority_invalid');
    }
    $previousAuthority->target_version = '2.41.0';
    $previousAuthority->contract = 'The 2.40.1/schema301 to 2.41.0/schema302 release adds the Financial V2 Billing capture authority: one unresolved automatic physical flight per remote order, a final Financial fence before transport, durable Billing results independent of later Financial leases, certainty-aware recovery, offline reconciliation of durable results, and preserved multi-order, recapture, and input_version behavior. Applies migration 302 exactly once. The update itself performs no recovery, OAuth, Billing, Cron, or Mercado Libre HTTP and does not mutate existing production data.';
    cap2WriteFile(
        $root . '/resources/release/updater-authority-2.41.0.json',
        cap2Json(get_object_vars($previousAuthority))
    );

    $previousRegistry = json_decode(
        (string) file_get_contents($root . '/resources/release/managed-runtime-dependencies-2.40.1.json'),
        true,
        64,
        JSON_THROW_ON_ERROR
    );
    if (!is_array($previousRegistry)) {
        throw new RuntimeException('previous_dependency_registry_invalid');
    }
    $previousRegistry['authority_id'] = 'financial-v2-release-2.41.0-runtime-dependencies';
    cap2WriteFile(
        $root . '/resources/release/managed-runtime-dependencies-2.41.0.json',
        cap2Json($previousRegistry)
    );
}

function cap2ArtifactMain(array $argv): int
{
    $root = dirname(__DIR__);
    $mode = $argv[1] ?? '';
    if ($mode === 'seed-release-2410') {
        cap2SeedRelease2410($root);
        echo "RELEASE_2_41_0_METADATA_SEEDED=YES\n";
        return 0;
    }
    if ($mode === 'updater-authority') {
        $authority = cap2UpdaterAuthority($root, $argv[2] ?? '', array_slice($argv, 3));
        // Validate/read every blob before any write: malformed input leaves the file unchanged.
        cap2WriteFile($root . '/resources/release/updater-authority-2.41.0.json', cap2Json($authority));
        echo 'UPDATER_AUTHORITY_DEPENDENCIES=' . count($authority['new_runtime_dependencies']) . "\n";
        return 0;
    }
    require_once __DIR__ . '/k1b_bootstrap.php';
    $ref = $argv[2] ?? 'HEAD';
    $registryPath = 'resources/release/managed-runtime-dependencies-2.41.0.json';
    if ($mode === 'registry') {
        $paths = App\Services\ManagedRuntimePublicationPolicy::manifestPaths($root, $ref);
        $registry = json_decode(App\Services\ManagedRuntimePublicationPolicy::gitBlob($root, $ref, $registryPath), true, 64, JSON_THROW_ON_ERROR);
        sort($paths, SORT_STRING);
        $registry['runtime_manifest_paths_sha256'] = hash('sha256', implode("\n", $paths) . "\n");
        cap2WriteFile($root . '/' . $registryPath, cap2Json($registry));
        echo 'REGISTRY_COMPONENTS=' . count($paths) . "\n";
        return 0;
    }
    if ($mode === 'manifest') {
        $manifest = App\Services\ManagedRuntimePublicationPolicy::buildManifest($root, $ref);
        cap2WriteFile($root . '/resources/runtime-manifest.json', cap2Json($manifest));
        echo 'MANIFEST_COMPONENTS=' . count($manifest['components']) . "\n";
        return 0;
    }
    if ($mode !== 'build') {
        throw new RuntimeException('usage: capacity_artifact.php build <ref> <absolute-output> [yyyymmdd] [family] [base-40-hex-commit] | updater-authority <40-hex-commit> [additional-runtime-path ...]');
    }
    $out = str_replace('\\', '/', $argv[3] ?? '');
    if (preg_match('#^[A-Za-z]:/#D', $out) !== 1) {
        throw new RuntimeException('absolute_output_required');
    }
    $date = $argv[4] ?? gmdate('Ymd');
    $base = $argv[6] ?? '191c5ee708d154471a442dfb6cca3324f8609b01';
    if (preg_match('/^[0-9a-fA-F]{40}$/D', $base) !== 1) {
        throw new RuntimeException('build_base_commit_must_be_40_hex');
    }
    $head = trim(cap2Git($root, ['rev-parse', $ref . '^{commit}'])['stdout']);
    $headEntries = App\Services\ManagedRuntimePublicationPolicy::packageEntries($root, $head);
    $baseEntries = App\Services\ManagedRuntimePublicationPolicy::packageEntries($root, $base);
    $manifest = json_decode(App\Services\ManagedRuntimePublicationPolicy::gitBlob($root, $head, 'resources/runtime-manifest.json'), true, 64, JSON_THROW_ON_ERROR);
    $issues = App\Services\ManagedRuntimePublicationPolicy::manifestIssues($root, $manifest, $head);
    if ($issues !== []) {
        throw new RuntimeException('manifest_issues:' . implode(',', $issues));
    }
    $result = cap2BuildRawArtifact($root, $base, $head, $out, array_column($baseEntries, 'path'), array_column($headEntries, 'path'), $date, $argv[5] ?? 'cap2');
    $integrity = (new App\Services\ReleaseIntegrityService())->inspectDirectory($result['applied_tree'], false, false);
    cap2WriteFile($out . '/integrity.json', cap2Json($integrity));
    if (empty($integrity['ok'])) {
        throw new RuntimeException('installed_integrity_failed');
    }
    echo file_get_contents($out . '/CONTROL.txt');
    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(cap2ArtifactMain($argv));
}
