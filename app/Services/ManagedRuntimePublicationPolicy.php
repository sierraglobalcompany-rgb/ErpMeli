<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

final class ManagedRuntimePublicationPolicy
{
    /** @var array<string,list<array{path:string,mode:string,object:string,size:int,sha256:string}>> */
    private static array $packageEntryCache = [];
    /** @var array<string,string> */
    private static array $gitBlobCache = [];
    /** @var array<string,string> */
    private static array $resolvedRefCache = [];
    private const CLASSIFICATIONS = [
        'RUNTIME_REQUIRED',
        'RUNTIME_OPTIONAL_FAIL_CLOSED',
        'MIGRATION_DEPLOY_REQUIRED',
        'RELEASE_BUILD_INPUT',
        'TEST_ONLY',
        'DEVELOPMENT_TOOL',
        'DOCUMENTATION',
        'FRONTEND_STATIC',
        'OTHER_EXPLICIT',
    ];
    private const DEPENDENCY_REGISTRY = 'resources/release/managed-runtime-dependencies-2.39.5.json';
    /** @var list<string> */
    private const OPERATOR_RUNTIME_BIN = [
        'bin/create_admin.php',
        'bin/database_growth_audit.php',
        'bin/database_physical_recovery.php',
        'bin/db_explain_audit.php',
        'bin/meli_api_audit.php',
        'bin/migrate.php',
        'bin/query_performance_report.php',
        'bin/queue_core_dependency_check.php',
        'bin/runtime_process_audit.php',
    ];
    public const BASE_COMMIT = 'c40d073705833c94911c254ec633bf8eb231375e';
    public const INSTALLED_BASE_COMMIT = '1eef380afc6ceb42d8955b2def1a439eb4d579fe';
    public const VERSION = '2.39.5';
    public const BUILD_ID = 'erp-meli-2.39.5-h1-order-exact-context-rc1-20260816';
    public const BUILT_AT = '2026-08-16T17:00:00Z';
    public const MINIMUM_MIGRATION = '299_queue_v4_domain_exact_admission_2_39_3.sql';
    private const INVENTORY_MINIMUM_MIGRATION = '295_inventory_warehouse_v1_2_38_0.sql';
    private const QUEUE_V4_MINIMUM_MIGRATION = '294_queue_v4_clean_greenfield_2_37_0.sql';
    private const LEGACY_MINIMUM_MIGRATION = '293_queue_core_runtime_profile_defaults_b2_1.sql';
    /** @var array<string,array{build_id:string,minimum_migration:string,dependency_registry:string}> */
    private const INSTALLED_PROFILES = [
        '2.36.2' => [
            'build_id' => 'erp-meli-2.36.2-managed-entrypoint-bootstrap-rc1-20260809',
            'minimum_migration' => self::LEGACY_MINIMUM_MIGRATION,
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.36.2.json',
        ],
        '2.36.3' => [
            'build_id' => 'erp-meli-2.36.3-direct-updater-authority-hotfix-rc1-20260810',
            'minimum_migration' => self::LEGACY_MINIMUM_MIGRATION,
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.36.3.json',
        ],
        '2.36.4' => [
            'build_id' => 'erp-meli-2.36.4-safe-config-retirement-hotfix-rc1-20260811',
            'minimum_migration' => self::LEGACY_MINIMUM_MIGRATION,
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.36.4.json',
        ],
        '2.36.5' => [
            'build_id' => 'erp-meli-2.36.5-v3-retirement-admin-hotfix-rc1-20260811',
            'minimum_migration' => self::LEGACY_MINIMUM_MIGRATION,
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.36.5.json',
        ],
        '2.36.6' => [
            'build_id' => 'erp-meli-2.36.6-v4-readiness-bootstrap-hotfix-rc1-20260811',
            'minimum_migration' => self::LEGACY_MINIMUM_MIGRATION,
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.36.6.json',
        ],
        '2.36.7' => [
            'build_id' => 'erp-meli-2.36.7-feature-flag-order-hotfix-rc1-20260811',
            'minimum_migration' => self::LEGACY_MINIMUM_MIGRATION,
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.36.7.json',
        ],
        '2.36.8' => [
            'build_id' => 'erp-meli-2.36.8-v4-partial-arm-recovery-hotfix-rc1-20260811',
            'minimum_migration' => self::LEGACY_MINIMUM_MIGRATION,
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.36.8.json',
        ],
        '2.36.9' => [
            'build_id' => 'erp-meli-2.36.9-v4-password-recovery-hotfix-rc1-20260812',
            'minimum_migration' => self::LEGACY_MINIMUM_MIGRATION,
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.36.9.json',
        ],
        '2.36.10' => [
            'build_id' => 'erp-meli-2.36.10-v4-generation-authority-hotfix-rc1-20260812',
            'minimum_migration' => self::LEGACY_MINIMUM_MIGRATION,
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.36.10.json',
        ],
        '2.36.11' => [
            'build_id' => 'erp-meli-2.36.11-v4-readiness-classifier-hotfix-rc1-20260812',
            'minimum_migration' => self::LEGACY_MINIMUM_MIGRATION,
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.36.11.json',
        ],
        '2.36.12' => [
            'build_id' => 'erp-meli-2.36.12-v4-bootstrap-typeerror-hotfix-rc1-20260812',
            'minimum_migration' => self::LEGACY_MINIMUM_MIGRATION,
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.36.12.json',
        ],
        '2.36.13' => [
            'build_id' => 'erp-meli-2.36.13-v4-config-authority-postimage-hotfix-rc1-20260812',
            'minimum_migration' => self::LEGACY_MINIMUM_MIGRATION,
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.36.13.json',
        ],
        '2.36.14' => [
            'build_id' => 'erp-meli-2.36.14-v4-operational-readiness-no-preb2-backup-hotfix-rc1-20260812',
            'minimum_migration' => self::LEGACY_MINIMUM_MIGRATION,
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.36.14.json',
        ],
        '2.36.15' => [
            'build_id' => 'erp-meli-2.36.15-v4-canary-uncertain-get-recovery-hotfix-rc1-20260812',
            'minimum_migration' => self::LEGACY_MINIMUM_MIGRATION,
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.36.15.json',
        ],
        '2.37.0' => [
            'build_id' => 'erp-meli-2.37.0-queue-v4-clean-greenfield-rc1-20260812',
            'minimum_migration' => self::QUEUE_V4_MINIMUM_MIGRATION,
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.37.0.json',
        ],
        '2.37.1' => [
            'build_id' => 'erp-meli-2.37.1-queue-v4-clean-stabilized-rc1-20260812',
            'minimum_migration' => self::QUEUE_V4_MINIMUM_MIGRATION,
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.37.1.json',
        ],
        '2.37.2' => [
            'build_id' => 'erp-meli-2.37.2-queue-v4-snapshot-timeout-rc1-20260812',
            'minimum_migration' => self::QUEUE_V4_MINIMUM_MIGRATION,
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.37.2.json',
        ],
        '2.38.0' => [
            'build_id' => 'erp-meli-2.38.0-inventory-warehouse-v1-rc1-20260812',
            'minimum_migration' => self::INVENTORY_MINIMUM_MIGRATION,
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.38.0.json',
        ],
        '2.38.1' => [
            'build_id' => 'erp-meli-2.38.1-queue-v4-backlog-convergence-rc1-20260813',
            'minimum_migration' => self::INVENTORY_MINIMUM_MIGRATION,
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.38.1.json',
        ],
        '2.38.2' => [
            'build_id' => 'erp-meli-2.38.2-queue-v4-rate-limit-stability-rc1-20260813',
            'minimum_migration' => self::INVENTORY_MINIMUM_MIGRATION,
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.38.2.json',
        ],
        '2.38.3' => [
            'build_id' => 'erp-meli-2.38.3-queue-v4-automatic-oauth-control-plane-rc1-20260813',
            'minimum_migration' => '298_queue_v4_sales_repair_transport_authority_2_38_9.sql',
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.38.3.json',
        ],
        '2.38.4' => [
            'build_id' => 'erp-meli-2.38.4-queue-v4-oauth-real-path-containment-rc1-20260813',
            'minimum_migration' => '296_queue_v4_clean_oauth_control_plane_2_38_3.sql',
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.38.4.json',
        ],
        '2.38.5' => [
            'build_id' => 'erp-meli-2.38.5-queue-v4-transport-sales-api-health-rc1-20260813',
            'minimum_migration' => '298_queue_v4_sales_repair_transport_authority_2_38_9.sql',
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.38.5.json',
        ],
        '2.38.6' => [
            'build_id' => 'erp-meli-2.38.6-private-filesystem-authority-oauth-escrow-rc1-20260814',
            'minimum_migration' => '298_queue_v4_sales_repair_transport_authority_2_38_9.sql',
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.38.6.json',
        ],
        '2.38.8' => [
            'build_id' => 'erp-meli-2.38.8-simple-exact-sales-repair-hotfix-rc1-20260814',
            'minimum_migration' => '297_queue_v4_transport_sales_api_health_2_38_5.sql',
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.38.8.json',
        ],
        '2.38.9' => [
            'build_id' => 'erp-meli-2.38.9-sales-repair-transport-authority-hotfix-rc1-20260814',
            'minimum_migration' => '298_queue_v4_sales_repair_transport_authority_2_38_9.sql',
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.38.9.json',
        ],
        '2.39.0' => [
            'build_id' => 'erp-meli-2.39.0-legacy-cron-fail-closed-rc1-20260814',
            'minimum_migration' => '298_queue_v4_sales_repair_transport_authority_2_38_9.sql',
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.39.0.json',
        ],
        '2.39.1' => [
            'build_id' => 'erp-meli-2.39.1-legacy-reactivation-fail-closed-rc1-20260815',
            'minimum_migration' => '298_queue_v4_sales_repair_transport_authority_2_38_9.sql',
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.39.1.json',
        ],
        '2.39.2' => [
            'build_id' => 'erp-meli-2.39.2-b1-stop-orphan-admission-rc1-20260815',
            'minimum_migration' => '298_queue_v4_sales_repair_transport_authority_2_38_9.sql',
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.39.2.json',
        ],
        '2.39.3' => [
            'build_id' => 'erp-meli-2.39.3-domain-exact-finance-rc1-20260815',
            'minimum_migration' => '299_queue_v4_domain_exact_admission_2_39_3.sql',
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.39.3.json',
        ],
        '2.39.4' => [
            'build_id' => 'erp-meli-2.39.4-f2b-b429-rhythm-fail-closed-rc1-20260816',
            'minimum_migration' => '299_queue_v4_domain_exact_admission_2_39_3.sql',
            'dependency_registry' => 'resources/release/managed-runtime-dependencies-2.39.4.json',
        ],
        self::VERSION => [
            'build_id' => self::BUILD_ID,
            'minimum_migration' => self::MINIMUM_MIGRATION,
            'dependency_registry' => self::DEPENDENCY_REGISTRY,
        ],
    ];

    /** @param array<string,mixed> $manifest */
    public static function recognizesInstalledManifest(array $manifest): bool
    {
        return self::installedProfile($manifest) !== null;
    }

    /** @return list<string> */
    public static function manifestPaths(string $root, string $head = 'HEAD', string $base = self::BASE_COMMIT): array
    {
        $paths = [];
        foreach (self::packageEntries($root, $head) as $entry) {
            // The manifest is a Git-exact package member, but cannot hash itself.
            if ($entry['path'] !== 'resources/runtime-manifest.json') {
                $paths[] = $entry['path'];
            }
        }
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** @return array<string,array{path:string,sha256:string,sha256_lf:string,text:bool}> */
    public static function manifestComponents(string $root, string $head = 'HEAD', string $base = self::BASE_COMMIT): array
    {
        $components = [];
        foreach (self::manifestPaths($root, $head, $base) as $path) {
            $bytes = self::gitBlob($root, $head, $path);
            $key = self::componentKey($path);
            if (isset($components[$key])) {
                throw new RuntimeException('Duplicate manifest component key: ' . $key);
            }
            $components[$key] = [
                'path' => $path,
                'sha256' => hash('sha256', $bytes),
                'sha256_lf' => hash('sha256', str_replace(["\r\n", "\r"], "\n", $bytes)),
                'text' => true,
            ];
        }
        return $components;
    }

    /** @return array<string,mixed> */
    public static function buildManifest(string $root, string $head = 'HEAD', string $base = self::BASE_COMMIT): array
    {
        $headCommit = trim(self::git($root, ['rev-parse', $head]));
        $components = self::manifestComponents($root, $headCommit, $base);
        $paths = array_map(static fn (array $component): string => $component['path'], $components);
        $registryBytes = self::gitBlob($root, $headCommit, self::DEPENDENCY_REGISTRY);
        return [
            'product' => 'erp-meli',
            'version' => self::VERSION,
            'build_id' => self::BUILD_ID,
            'built_at' => self::BUILT_AT,
            'minimum_migration' => self::MINIMUM_MIGRATION,
            'publication_policy' => [
                'base_commit' => $base,
                'authority_model' => 'FULL_MANAGED_RUNTIME',
                'package_file_count' => count(self::packageEntries($root, $headCommit)),
                'component_count' => count($paths),
                'paths_sha256' => self::pathInventoryHash(array_values($paths)),
                'dependency_registry_sha256' => hash('sha256', $registryBytes),
                'raw_git_blobs' => true,
                'protected_external_state_excluded' => true,
                'build_only_excluded' => true,
            ],
            'components' => $components,
        ];
    }

    /** @param array<string,mixed> $manifest @return list<string> */
    public static function manifestIssues(
        string $root,
        array $manifest,
        string $head = 'HEAD',
        string $base = self::BASE_COMMIT
    ): array
    {
        $issues = [];
        $headCommit = trim(self::git($root, ['rev-parse', $head]));
        $expected = self::manifestPaths($root, $headCommit, $base);
        $registryBytes = self::gitBlob($root, $headCommit, self::DEPENDENCY_REGISTRY);
        $registry = self::parseDependencyRegistry($registryBytes);
        $registryHash = hash('sha256', $registryBytes);
        $components = $manifest['components'] ?? null;
        if (!is_array($components)) {
            return ['manifest_components_malformed'];
        }
        $actualPaths = [];
        $seenPaths = [];
        $seenCase = [];
        foreach ($components as $key => $definition) {
            if (!is_string($key) || !is_array($definition)) {
                $issues[] = 'manifest_component_malformed';
                continue;
            }
            $path = (string) ($definition['path'] ?? '');
            if (!self::safePath($path)) {
                $issues[] = 'manifest_path_unsafe:' . $key;
                continue;
            }
            if (isset($seenPaths[$path])) {
                $issues[] = 'manifest_path_duplicate:' . $path;
                continue;
            }
            $folded = strtolower($path);
            if (isset($seenCase[$folded]) && $seenCase[$folded] !== $path) {
                $issues[] = 'manifest_path_case_collision:' . $path;
            }
            $seenPaths[$path] = true;
            $seenCase[$folded] = $path;
            $actualPaths[] = $path;
            if ($key !== self::componentKey($path)) {
                $issues[] = 'manifest_component_key_mismatch:' . $path;
            }
            try {
                $bytes = self::gitBlob($root, $headCommit, $path);
            } catch (Throwable) {
                $issues[] = 'manifest_path_not_in_git:' . $path;
                continue;
            }
            $raw = strtolower((string) ($definition['sha256'] ?? ''));
            $lf = strtolower((string) ($definition['sha256_lf'] ?? ''));
            if (!hash_equals(hash('sha256', $bytes), $raw)) {
                $issues[] = 'manifest_raw_hash_mismatch:' . $path;
            }
            if (!hash_equals(hash('sha256', str_replace(["\r\n", "\r"], "\n", $bytes)), $lf)) {
                $issues[] = 'manifest_lf_hash_mismatch:' . $path;
            }
            if (($definition['text'] ?? null) !== true) {
                $issues[] = 'manifest_text_contract_mismatch:' . $path;
            }
        }
        sort($actualPaths, SORT_STRING);
        foreach (array_diff($expected, $actualPaths) as $path) {
            $issues[] = 'manifest_component_missing:' . $path;
        }
        foreach (array_diff($actualPaths, $expected) as $path) {
            $issues[] = 'manifest_component_orphan:' . $path;
        }
        if (!hash_equals($registry['runtime_manifest_paths_sha256'], self::pathInventoryHash($expected))) {
            $issues[] = 'runtime_registry_manifest_inventory_mismatch';
        }
        $policy = $manifest['publication_policy'] ?? null;
        if (!is_array($policy)
            || !hash_equals($base, (string) ($policy['base_commit'] ?? ''))
            || !hash_equals('FULL_MANAGED_RUNTIME', (string) ($policy['authority_model'] ?? ''))
            || (int) ($policy['package_file_count'] ?? -1) !== count(self::packageEntries($root, $headCommit))
            || (int) ($policy['component_count'] ?? -1) !== count($expected)
            || !hash_equals(self::pathInventoryHash($expected), (string) ($policy['paths_sha256'] ?? ''))
            || !hash_equals($registryHash, (string) ($policy['dependency_registry_sha256'] ?? ''))
            || ($policy['raw_git_blobs'] ?? null) !== true
            || ($policy['protected_external_state_excluded'] ?? null) !== true
            || ($policy['build_only_excluded'] ?? null) !== true
        ) {
            $issues[] = 'manifest_publication_policy_mismatch';
        }
        if (!hash_equals(self::VERSION, (string) ($manifest['version'] ?? ''))
            || !hash_equals(self::MINIMUM_MIGRATION, (string) ($manifest['minimum_migration'] ?? ''))
            || !hash_equals(self::BUILD_ID, (string) ($manifest['build_id'] ?? ''))
        ) {
            $issues[] = 'manifest_release_identity_mismatch';
        }
        return array_values(array_unique($issues));
    }

    /** @param array<string,mixed> $manifest @return list<string> */
    public static function installedManifestIssues(string $root, array $manifest): array
    {
        $profile = self::installedProfile($manifest);
        $dependencyRegistry = $profile['dependency_registry'] ?? self::DEPENDENCY_REGISTRY;
        $registryPath = rtrim($root, '/\\') . '/' . $dependencyRegistry;
        $registryBytes = is_file($registryPath) ? file_get_contents($registryPath) : false;
        if (!is_string($registryBytes)) {
            return ['runtime_dependency_registry_missing'];
        }
        try {
            $registry = self::parseDependencyRegistry($registryBytes);
        } catch (Throwable) {
            return ['runtime_dependency_registry_malformed'];
        }
        $components = $manifest['components'] ?? null;
        if (!is_array($components)) {
            return ['manifest_components_malformed'];
        }
        $paths = [];
        $case = [];
        $issues = [];
        foreach ($components as $definition) {
            if (!is_array($definition) || !self::safePath((string) ($definition['path'] ?? ''))) {
                $issues[] = 'manifest_component_malformed';
                continue;
            }
            $path = (string) $definition['path'];
            $folded = strtolower($path);
            if (isset($case[$folded])) {
                $issues[] = 'manifest_path_case_or_duplicate:' . $path;
                continue;
            }
            $case[$folded] = true;
            $paths[] = $path;
        }
        sort($paths, SORT_STRING);
        foreach ($registry['runtime_dependencies'] as $dependency) {
            if (($dependency['required_in_runtime_manifest'] ?? null) === true
                && !in_array((string) $dependency['path'], $paths, true)
            ) {
                $issues[] = 'manifest_required_dependency_missing:' . (string) $dependency['path'];
            }
        }
        if (!hash_equals($registry['runtime_manifest_paths_sha256'], self::pathInventoryHash($paths))) {
            $issues[] = 'manifest_installed_inventory_mismatch';
        }
        $policy = $manifest['publication_policy'] ?? null;
        if (!is_array($policy)
            || !hash_equals('FULL_MANAGED_RUNTIME', (string) ($policy['authority_model'] ?? ''))
            || (int) ($policy['package_file_count'] ?? -1) !== count($paths) + 1
            || (int) ($policy['component_count'] ?? -1) !== count($paths)
            || !hash_equals(self::pathInventoryHash($paths), (string) ($policy['paths_sha256'] ?? ''))
            || !hash_equals(hash('sha256', $registryBytes), (string) ($policy['dependency_registry_sha256'] ?? ''))
            || ($policy['raw_git_blobs'] ?? null) !== true
            || ($policy['protected_external_state_excluded'] ?? null) !== true
            || ($policy['build_only_excluded'] ?? null) !== true
        ) {
            $issues[] = 'manifest_publication_policy_mismatch';
        }
        if ($profile === null) {
            $issues[] = 'manifest_release_identity_mismatch';
        }
        return array_values(array_unique($issues));
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array{build_id:string,minimum_migration:string,dependency_registry:string}|null
     */
    private static function installedProfile(array $manifest): ?array
    {
        $version = trim((string) ($manifest['version'] ?? ''));
        $profile = self::INSTALLED_PROFILES[$version] ?? null;
        if (!is_array($profile)
            || !hash_equals($profile['build_id'], trim((string) ($manifest['build_id'] ?? '')))
            || !hash_equals($profile['minimum_migration'], trim((string) ($manifest['minimum_migration'] ?? '')))
        ) {
            return null;
        }

        return $profile;
    }

    /**
     * Validate an extracted package against the exact Git tree. The values are
     * raw bytes keyed by canonical POSIX archive path.
     *
     * @param array<string,mixed> $manifest
     * @param array<string,string> $files
     * @return list<string>
     */
    public static function packageIssues(
        string $root,
        array $manifest,
        array $files,
        string $head = 'HEAD',
        string $base = self::BASE_COMMIT
    ): array {
        $issues = self::manifestIssues($root, $manifest, $head, $base);
        $headCommit = trim(self::git($root, ['rev-parse', $head]));
        $expectedRows = self::packageEntries($root, $headCommit);
        $expected = [];
        foreach ($expectedRows as $row) {
            $expected[$row['path']] = $row;
        }

        $case = [];
        foreach ($files as $path => $bytes) {
            if (!self::safePath($path)) {
                $issues[] = 'package_path_unsafe';
                continue;
            }
            $folded = strtolower($path);
            if (isset($case[$folded]) && $case[$folded] !== $path) {
                $issues[] = 'package_path_case_collision:' . $path;
            }
            $case[$folded] = $path;
            if (!isset($expected[$path])) {
                $issues[] = 'package_unsafe_extra:' . $path;
                continue;
            }
            if (!hash_equals($expected[$path]['sha256'], hash('sha256', $bytes))) {
                $issues[] = 'package_git_blob_mismatch:' . $path;
            }
        }
        foreach (array_diff(array_keys($expected), array_keys($files)) as $path) {
            $issues[] = 'package_required_missing:' . $path;
        }

        $manifestBytes = $files['resources/runtime-manifest.json'] ?? null;
        if (!is_string($manifestBytes)
            || !hash_equals(hash('sha256', self::gitBlob($root, $headCommit, 'resources/runtime-manifest.json')), hash('sha256', $manifestBytes))
        ) {
            $issues[] = 'package_manifest_git_mismatch';
        }
        $topicsPath = 'resources/mercadolibre-api/generated/notification-topics.json';
        $topics = $files[$topicsPath] ?? null;
        if (!is_string($topics) || !MeliNotificationTopicRegistry::catalogBytesValid($topics)) {
            $issues[] = 'package_notification_topics_semantic_invalid';
        }

        return array_values(array_unique($issues));
    }

    /** @return list<array{path:string,mode:string,object:string,size:int,sha256:string}> */
    public static function packageEntries(string $root, string $head = 'HEAD'): array
    {
        $headCommit = trim(self::git($root, ['rev-parse', $head]));
        $cacheKey = str_replace('\\', '/', $root) . '|' . $headCommit;
        if (isset(self::$packageEntryCache[$cacheKey])) {
            return self::$packageEntryCache[$cacheKey];
        }
        $tree = self::gitTree($root, $headCommit);
        $paths = [];
        foreach ($tree as $path => $entry) {
            $classification = self::trackedPathClassification($path);
            if ($classification === 'UNCLASSIFIED') {
                throw new RuntimeException('Tracked path has no release classification: ' . $path);
            }
            if ($classification === 'PROTECTED_EXTERNAL_STATE') {
                throw new RuntimeException('Protected external state must not be Git-tracked: ' . $path);
            }
            if (in_array($classification, ['MANAGED_RUNTIME', 'MIGRATION'], true)) {
                $paths[] = $path;
            }
        }
        sort($paths, SORT_STRING);
        self::assertTreePaths($paths, $tree, 'package');
        $required = [
            'VERSION', 'asset.php', 'bootstrap.php', 'index.php', 'login.php', 'actualizar.php',
            'stop.php', 'mantenimiento.php', 'recuperar.php', 'cron-status.php', 'launcher/entrypoint.php',
            'public/index.php', 'bin/migrate.php', 'resources/runtime-manifest.json',
        ];
        foreach ($required as $path) {
            if (!in_array($path, $paths, true)) {
                throw new RuntimeException('Required package path is missing: ' . $path);
            }
        }
        $objects = [];
        foreach ($paths as $path) {
            $objects[] = $tree[$path]['object'];
        }
        $blobs = self::gitBlobsByObject($root, $objects);
        $entries = [];
        foreach ($paths as $path) {
            $object = $tree[$path]['object'];
            $bytes = $blobs[$object] ?? throw new RuntimeException('Git blob batch is incomplete: ' . $path);
            self::$gitBlobCache[str_replace('\\', '/', $root) . '|' . $headCommit . '|' . $path] = $bytes;
            $entries[] = [
                'path' => $path,
                'mode' => $tree[$path]['mode'],
                'object' => $object,
                'size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
            ];
        }
        self::$packageEntryCache[$cacheKey] = $entries;

        return $entries;
    }

    /** @param list<string> $objects @return array<string,string> */
    private static function gitBlobsByObject(string $root, array $objects): array
    {
        $objects = array_values(array_unique($objects));
        $input = tmpfile();
        if (!is_resource($input)) {
            throw new RuntimeException('Unable to create Git blob batch input.');
        }
        foreach ($objects as $object) {
            fwrite($input, $object . "\n");
        }
        rewind($input);
        $pipes = [];
        $process = proc_open(['git', '-C', $root, 'cat-file', '--batch'], [
            0 => $input, 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            fclose($input);
            throw new RuntimeException('Unable to start Git blob batch.');
        }
        $blobs = [];
        foreach ($objects as $expectedObject) {
            $header = fgets($pipes[1]);
            if (!is_string($header)
                || preg_match('/^([a-f0-9]{40}) blob ([0-9]+)\n$/', $header, $match) !== 1
                || !hash_equals($expectedObject, $match[1])
            ) {
                fclose($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                fclose($input);
                proc_close($process);
                throw new RuntimeException('Malformed Git blob batch: ' . trim((string) $stderr));
            }
            $remaining = (int) $match[2];
            $bytes = '';
            while ($remaining > 0) {
                $chunk = fread($pipes[1], $remaining);
                if (!is_string($chunk) || $chunk === '') {
                    throw new RuntimeException('Truncated Git blob batch.');
                }
                $bytes .= $chunk;
                $remaining -= strlen($chunk);
            }
            if (fread($pipes[1], 1) !== "\n") {
                throw new RuntimeException('Malformed Git blob batch delimiter.');
            }
            $blobs[$expectedObject] = $bytes;
        }
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        fclose($input);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Git blob batch failed: ' . trim((string) $stderr));
        }
        return $blobs;
    }

    public static function gitBlob(string $root, string $head, string $path): string
    {
        if (!self::safePath($path)) {
            throw new RuntimeException('Unsafe Git path.');
        }
        $commit = self::resolveCommit($root, $head);
        $cacheKey = str_replace('\\', '/', $root) . '|' . $commit . '|' . $path;
        if (!array_key_exists($cacheKey, self::$gitBlobCache)) {
            self::$gitBlobCache[$cacheKey] = self::git($root, ['show', $commit . ':' . $path]);
        }

        return self::$gitBlobCache[$cacheKey];
    }

    /** @return array<string,array{mode:string,type:string,object:string}> */
    public static function gitTree(string $root, string $head = 'HEAD'): array
    {
        $raw = self::git($root, ['ls-tree', '-r', '-z', '--full-tree', $head]);
        $tree = [];
        $case = [];
        foreach (explode("\0", $raw) as $record) {
            if ($record === '') {
                continue;
            }
            if (preg_match('/^([0-9]{6}) ([a-z]+) ([0-9a-f]+)\t(.+)$/s', $record, $match) !== 1) {
                throw new RuntimeException('Malformed Git tree record.');
            }
            $path = $match[4];
            if (!self::safePath($path)) {
                throw new RuntimeException('Unsafe Git tree path: ' . $path);
            }
            $folded = strtolower($path);
            if (isset($case[$folded]) && $case[$folded] !== $path) {
                throw new RuntimeException('Case-colliding Git paths: ' . $case[$folded] . ' / ' . $path);
            }
            $case[$folded] = $path;
            $tree[$path] = ['mode' => $match[1], 'type' => $match[2], 'object' => $match[3]];
        }
        return $tree;
    }

    /** @param list<string> $paths @param array<string,array{mode:string,type:string,object:string}> $tree */
    private static function assertTreePaths(array $paths, array $tree, string $purpose): void
    {
        $case = [];
        foreach ($paths as $path) {
            if (!isset($tree[$path])) {
                throw new RuntimeException(ucfirst($purpose) . ' path is absent from Git HEAD: ' . $path);
            }
            $entry = $tree[$path];
            if ($entry['type'] !== 'blob' || !in_array($entry['mode'], ['100644', '100755'], true)) {
                throw new RuntimeException(ucfirst($purpose) . ' path has unsafe Git type/mode: ' . $path);
            }
            $folded = strtolower($path);
            if (isset($case[$folded]) && $case[$folded] !== $path) {
                throw new RuntimeException(ucfirst($purpose) . ' paths collide by case.');
            }
            $case[$folded] = $path;
        }
    }

    /**
     * Classify every tracked path independently of release deltas. Unknown
     * paths fail publication instead of silently falling outside authority.
     */
    public static function trackedPathClassification(string $path): string
    {
        if (!self::safePath($path)) {
            return 'UNCLASSIFIED';
        }
        $segments = explode('/', $path);
        $lowerSegments = array_map('strtolower', $segments);
        $basename = strtolower((string) end($lowerSegments));
        if (in_array($basename, ['.env', 'config.env', 'pause_meli_api', 'pause_erp_automation'], true)
            || in_array(strtolower($segments[0]), [
                'storage', 'shared', 'uploads', 'backups', 'production-backups',
                'releases', 'oauth-recovery', 'emergency-control',
            ], true)
        ) {
            return 'PROTECTED_EXTERNAL_STATE';
        }

        if (count($segments) === 1) {
            if (in_array($path, [
                '.htaccess', 'VERSION', 'actualizar.php', 'asset.php', 'bootstrap.php',
                'composer.json', 'composer.lock', 'cron-status.php', 'index.php', 'login.php',
                'mantenimiento.php', 'recuperar.php', 'stop.php',
            ], true)) {
                return 'MANAGED_RUNTIME';
            }
            if (in_array($path, ['.gitattributes', '.gitignore', 'config.env.example', 'phpstan.neon'], true)) {
                return 'BUILD_ONLY';
            }
            return 'UNCLASSIFIED';
        }

        $top = strtolower($segments[0]);
        if ($top === 'tests') {
            return 'TEST_ONLY';
        }
        if ($top === 'bin' && in_array($path, self::OPERATOR_RUNTIME_BIN, true)) {
            return 'MANAGED_RUNTIME';
        }
        if (in_array($top, ['bin', '.github', 'tools'], true)) {
            return 'BUILD_ONLY';
        }
        if (in_array($top, ['docs', 'audits', 'graphify-out'], true)) {
            return 'NON_RUNTIME';
        }
        if ($top === 'database') {
            return preg_match('#^database/migrations/[A-Za-z0-9._-]+\.sql$#D', $path) === 1
                ? 'MIGRATION'
                : 'NON_RUNTIME';
        }
        if ($top === 'app' || $top === 'jobs' || $top === 'launcher' || $top === 'stop') {
            return str_ends_with(strtolower($path), '.php') ? 'MANAGED_RUNTIME' : 'UNCLASSIFIED';
        }
        if ($top === 'public') {
            return 'MANAGED_RUNTIME';
        }
        if ($top === 'resources') {
            if (str_starts_with(strtolower($path), 'resources/mercadolibre-api/source/')) {
                return 'NON_RUNTIME';
            }
            if (preg_match('#^resources/mercadolibre-api/generated/[A-Za-z0-9._-]+\.json$#D', $path) === 1
                || preg_match('#^resources/modules/[A-Za-z0-9._-]+/module\.json$#D', $path) === 1
                || preg_match('#^resources/release/[A-Za-z0-9._-]+\.json$#D', $path) === 1
                || in_array($path, ['resources/migration-replacements.json', 'resources/runtime-manifest.json'], true)
            ) {
                return 'MANAGED_RUNTIME';
            }
            return 'UNCLASSIFIED';
        }
        return 'UNCLASSIFIED';
    }

    private static function componentKey(string $path): string
    {
        return match ($path) {
            'jobs/cron_probe.php' => 'cron_probe',
            'jobs/process_sync_queue.php' => 'process_sync_queue',
            default => 'runtime_' . trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($path)), '_'),
        };
    }

    /** @return array{classification_rules:list<array<string,mixed>>,runtime_dependencies:list<array<string,mixed>>,runtime_manifest_paths_sha256:string} */
    private static function parseDependencyRegistry(string $bytes): array
    {
        $decoded = json_decode($bytes, true);
        if (!is_array($decoded) || ($decoded['schema_version'] ?? null) !== 1
            || !is_array($decoded['classification_rules'] ?? null)
            || !is_array($decoded['runtime_dependencies'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', (string) ($decoded['runtime_manifest_paths_sha256'] ?? '')) !== 1
        ) {
            throw new RuntimeException('Runtime dependency registry is malformed.');
        }
        $rules = array_values($decoded['classification_rules']);
        $dependencies = array_values($decoded['runtime_dependencies']);
        $dependencyIds = [];
        $dependencyPaths = [];
        foreach ($dependencies as $dependency) {
            if (!is_array($dependency)) {
                throw new RuntimeException('Runtime dependency entry is malformed.');
            }
            $id = (string) ($dependency['id'] ?? '');
            $path = (string) ($dependency['path'] ?? '');
            $classification = (string) ($dependency['classification'] ?? '');
            $consumers = $dependency['consumers'] ?? null;
            $provenance = $dependency['provenance'] ?? null;
            if ($id === '' || isset($dependencyIds[$id]) || !self::safePath($path)
                || isset($dependencyPaths[strtolower($path)])
                || !in_array($classification, self::CLASSIFICATIONS, true)
                || !is_bool($dependency['required_in_runtime_manifest'] ?? null)
                || !is_array($consumers) || $consumers === [] || !is_array($provenance) || $provenance === []
            ) {
                throw new RuntimeException('Runtime dependency contract is incomplete.');
            }
            foreach ($consumers as $consumer) {
                if (!is_array($consumer)
                    || !self::safePath((string) ($consumer['source_path'] ?? ''))
                    || trim((string) ($consumer['symbol'] ?? '')) === ''
                    || trim((string) ($consumer['path_literal'] ?? '')) === ''
                ) {
                    throw new RuntimeException('Runtime dependency consumer is malformed.');
                }
            }
            if (!hash_equals($classification, self::classifyPath($path, $rules))) {
                throw new RuntimeException('Runtime dependency classification is inconsistent.');
            }
            $dependencyIds[$id] = true;
            $dependencyPaths[strtolower($path)] = true;
        }
        return [
            'classification_rules' => $rules,
            'runtime_dependencies' => $dependencies,
            'runtime_manifest_paths_sha256' => (string) $decoded['runtime_manifest_paths_sha256'],
        ];
    }

    /** @param list<array<string,mixed>> $rules */
    private static function classifyPath(string $path, array $rules): string
    {
        $matches = [];
        foreach ($rules as $rule) {
            if (trim((string) ($rule['id'] ?? '')) === '') {
                throw new RuntimeException('Runtime classification rule is malformed.');
            }
            $classification = (string) ($rule['classification'] ?? '');
            $kind = (string) ($rule['kind'] ?? '');
            $matched = false;
            if (!in_array($classification, self::CLASSIFICATIONS, true)) {
                throw new RuntimeException('Unknown runtime classification.');
            }
            if ($kind === 'exact') {
                $values = $rule['values'] ?? null;
                if (!is_array($values) || $values === []) {
                    throw new RuntimeException('Exact runtime classification rule is malformed.');
                }
                $matched = in_array($path, array_map('strval', $values), true);
            } elseif ($kind === 'regex') {
                $pattern = (string) ($rule['value'] ?? '');
                if ($pattern === '' || @preg_match($pattern, '') === false) {
                    throw new RuntimeException('Regex runtime classification rule is malformed.');
                }
                $matched = preg_match($pattern, $path) === 1;
            } else {
                throw new RuntimeException('Unknown runtime classification rule kind.');
            }
            if ($matched) {
                $matches[] = $classification;
            }
        }
        if (count($matches) !== 1 || trim($matches[0]) === '') {
            throw new RuntimeException('Runtime path must match exactly one classification rule: ' . $path);
        }
        return $matches[0];
    }

    private static function safePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return false;
        }
        if (preg_match('/^[A-Za-z]:/', $path) === 1 || preg_match('#^[A-Za-z0-9._/-]+$#', $path) !== 1) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $paths */
    private static function pathInventoryHash(array $paths): string
    {
        sort($paths, SORT_STRING);
        return hash('sha256', implode("\n", $paths) . "\n");
    }

    private static function resolveCommit(string $root, string $head): string
    {
        if (preg_match('/^[a-f0-9]{40}$/', $head) === 1) {
            return $head;
        }
        $cacheKey = str_replace('\\', '/', $root) . '|' . $head;
        if (!isset(self::$resolvedRefCache[$cacheKey])) {
            self::$resolvedRefCache[$cacheKey] = trim(self::git($root, ['rev-parse', $head]));
        }

        return self::$resolvedRefCache[$cacheKey];
    }

    /** @param list<string> $arguments */
    private static function git(string $root, array $arguments): string
    {
        $command = array_merge(['git', '-C', $root], $arguments);
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start Git.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || !is_string($stdout)) {
            throw new RuntimeException('Git command failed: ' . trim((string) $stderr));
        }
        return $stdout;
    }
}
