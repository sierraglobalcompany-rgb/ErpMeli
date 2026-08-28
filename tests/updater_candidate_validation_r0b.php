<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/MeliNotificationTopicRegistry.php';
require dirname(__DIR__) . '/app/Services/RuntimePublicationPolicy.php';
require dirname(__DIR__) . '/app/Services/ManagedRuntimePublicationPolicy.php';
require dirname(__DIR__) . '/app/Services/ReleaseIntegrityService.php';

use App\Services\ManagedRuntimePublicationPolicy;
use App\Services\ReleaseIntegrityService;

$root = dirname(__DIR__);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$has = static fn (array $issues, string $needle): bool => in_array($needle, $issues, true)
    || (bool) array_filter($issues, static fn (string $issue): bool => str_starts_with($issue, $needle));

$manifestPath = $root . '/resources/runtime-manifest.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($manifest)) {
    throw new RuntimeException('runtime manifest malformed');
}
$updateManifest = [
    'manifest_version' => 1,
    'product_id' => 'erp-meli',
    'release_id' => 'erp-meli-' . (string) $manifest['version'] . '-r0b-test',
    'version' => (string) $manifest['version'],
    'channel' => 'manual',
    'source_trust' => 'local_admin',
    'files' => [['path' => 'VERSION', 'sha256' => hash_file('sha256', $root . '/VERSION')]],
];

$assert(
    ManagedRuntimePublicationPolicy::candidateManifestIssues($root, $manifest, $updateManifest) === [],
    'valid candidate rejected'
);
$installed = (new ReleaseIntegrityService())->inspectDirectory(
    $root,
    false,
    false,
    ReleaseIntegrityService::INSPECTION_CONTEXT_INSTALLED
);
$assert((bool) $installed['ok'], 'installed strict validation failed for current runtime');

$candidate = static function (callable $mutate) use ($manifest, $updateManifest): array {
    $runtime = $manifest;
    $update = $updateManifest;
    $mutate($runtime, $update);
    return [$runtime, $update];
};

[$runtime, $update] = $candidate(static function (array &$runtime): void {
    $runtime['version'] = '2.40.99';
});
$assert($has(ManagedRuntimePublicationPolicy::candidateManifestIssues($root, $runtime, $update), 'candidate_update_version_mismatch'), 'candidate runtime/update version mismatch passed');

[$runtime, $update] = $candidate(static function (array &$runtime, array &$update): void {
    $update['product_id'] = 'other-product';
});
$assert($has(ManagedRuntimePublicationPolicy::candidateManifestIssues($root, $runtime, $update), 'candidate_product_mismatch'), 'wrong product passed');

$firstKey = array_key_first($manifest['components']);
[$runtime, $update] = $candidate(static function (array &$runtime) use ($firstKey): void {
    $runtime['components'][$firstKey]['sha256'] = str_repeat('0', 64);
});
$assert($has(ManagedRuntimePublicationPolicy::candidateManifestIssues($root, $runtime, $update), 'candidate_component_hash_mismatch:'), 'modified component byte passed');

[$runtime, $update] = $candidate(static function (array &$runtime) use ($firstKey): void {
    $runtime['components']['r0b_duplicate'] = $runtime['components'][$firstKey];
});
$assert($has(ManagedRuntimePublicationPolicy::candidateManifestIssues($root, $runtime, $update), 'candidate_component_path_duplicate:'), 'duplicate component path passed');

[$runtime, $update] = $candidate(static function (array &$runtime, array &$update): void {
    $runtime['version'] = '9.9.9';
    $runtime['build_id'] = 'erp-meli-9.9.9-r0b-test';
    $update['version'] = '9.9.9';
});
$assert($has(ManagedRuntimePublicationPolicy::candidateManifestIssues($root, $runtime, $update), 'candidate_dependency_registry_missing'), 'missing dependency registry passed');

[$runtime, $update] = $candidate(static function (array &$runtime): void {
    $runtime['publication_policy']['dependency_registry_sha256'] = str_repeat('1', 64);
});
$assert($has(ManagedRuntimePublicationPolicy::candidateManifestIssues($root, $runtime, $update), 'candidate_dependency_registry_sha256_mismatch'), 'dependency registry sha mismatch passed');

[$runtime, $update] = $candidate(static function (array &$runtime): void {
    $runtime['publication_policy']['paths_sha256'] = str_repeat('2', 64);
});
$assert($has(ManagedRuntimePublicationPolicy::candidateManifestIssues($root, $runtime, $update), 'candidate_paths_sha256_mismatch'), 'paths inventory mismatch passed');

[$runtime, $update] = $candidate(static function (array &$runtime): void {
    unset($runtime['components']['runtime_VERSION']);
});
$assert($has(ManagedRuntimePublicationPolicy::candidateManifestIssues($root, $runtime, $update), 'candidate_required_dependency_missing:VERSION'), 'required dependency missing passed');

[$runtime, $update] = $candidate(static function (array &$runtime): void {
    $runtime['publication_policy']['base_commit'] = str_repeat('a', 40);
});
$assert($has(ManagedRuntimePublicationPolicy::candidateManifestIssues($root, $runtime, $update), 'candidate_coverage_base_commit_mismatch'), 'coverage base mismatch passed');

[$runtime, $update] = $candidate(static function (array &$runtime): void {
    $runtime['publication_policy']['component_count'] = -1;
});
$assert($has(ManagedRuntimePublicationPolicy::candidateManifestIssues($root, $runtime, $update), 'candidate_component_count_mismatch'), 'component count mismatch passed');

[$runtime, $update] = $candidate(static function (array &$runtime): void {
    $runtime['publication_policy']['package_file_count'] = -1;
});
$assert($has(ManagedRuntimePublicationPolicy::candidateManifestIssues($root, $runtime, $update), 'candidate_package_file_count_mismatch'), 'package file count mismatch passed');

[$runtime, $update] = $candidate(static function (array &$runtime): void {
    $runtime['publication_policy']['authority_model'] = 'LEGACY';
});
$assert($has(ManagedRuntimePublicationPolicy::candidateManifestIssues($root, $runtime, $update), 'candidate_authority_model_mismatch'), 'authority model mismatch passed');

[$runtime, $update] = $candidate(static function (array &$runtime): void {
    $runtime['minimum_migration'] = '../escape.sql';
});
$assert($has(ManagedRuntimePublicationPolicy::candidateManifestIssues($root, $runtime, $update), 'candidate_minimum_migration_invalid'), 'invalid minimum migration passed');

[$runtime, $update] = $candidate(static function (array &$runtime, array &$update): void {
    $runtime['version'] = 'bad-version';
    $update['version'] = 'bad-version';
});
$assert($has(ManagedRuntimePublicationPolicy::candidateManifestIssues($root, $runtime, $update), 'candidate_version_invalid'), 'malformed future version passed');

[$runtime, $update] = $candidate(static function (array &$runtime): void {
    $runtime['build_id'] = 'not-this-system';
});
$assert($has(ManagedRuntimePublicationPolicy::candidateManifestIssues($root, $runtime, $update), 'candidate_build_id_mismatch'), 'build id mismatch passed');

$fakeInstalled = $manifest;
$fakeInstalled['build_id'] = 'erp-meli-2.40.2-corrupt-installed-identity';
$assert(
    ManagedRuntimePublicationPolicy::installedManifestIssues($root, $fakeInstalled) !== [],
    'fake unknown/corrupt installed release passed'
);

fwrite(STDOUT, 'R0B updater candidate validation: ' . $checks . " checks passed\n");
