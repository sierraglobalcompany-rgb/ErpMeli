<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Release-authoring policy for the exceptional 2.36.1 managed-runtime cutover.
 *
 * This service is deliberately independent from the in-application updater. It
 * classifies release inputs and proves that executable entrypoints and their
 * literal view/static dependencies are inside the managed release boundary.
 */
final class ManagedRuntimeAuthorityService
{
    public const MANAGED_RUNTIME = 'MANAGED_RUNTIME';
    public const MIGRATIONS = 'MIGRATIONS';
    public const BUILD_ONLY = 'BUILD_ONLY';
    public const TEST_ONLY = 'TEST_ONLY';
    public const PROTECTED_EXTERNAL_STATE = 'PROTECTED_EXTERNAL_STATE';
    public const NON_RUNTIME = 'NON_RUNTIME';
    public const UNCLASSIFIED = 'UNCLASSIFIED';

    /** @var list<string> */
    private const REQUIRED_ROOT_ENTRYPOINTS = [
        'actualizar.php',
        'asset.php',
        'bootstrap.php',
        'cron-status.php',
        'index.php',
        'login.php',
        'mantenimiento.php',
        'recuperar.php',
        'stop.php',
    ];

    /** @var list<string> */
    private const REQUIRED_RELEASE_FILES = [
        '.htaccess',
        'VERSION',
        'composer.json',
        'composer.lock',
    ];

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

    /** @var list<string> */
    private const BUILD_ONLY_BIN = [
        'bin/build_runtime_manifest_b21.php',
        'bin/build_update_package.php',
        'bin/dev_router_2290.php',
        'bin/inspect_update_package.php',
        'bin/lint.php',
        'bin/local_read_benchmark.php',
        'bin/php_compat.php',
    ];

    /**
     * @return self::MANAGED_RUNTIME|self::MIGRATIONS|self::BUILD_ONLY|self::TEST_ONLY|self::PROTECTED_EXTERNAL_STATE|self::NON_RUNTIME|self::UNCLASSIFIED
     */
    public static function classifyPath(string $path): string
    {
        $path = self::canonicalPath($path);

        if (self::isProtectedExternalState($path)) {
            return self::PROTECTED_EXTERNAL_STATE;
        }
        if (in_array($path, self::REQUIRED_ROOT_ENTRYPOINTS, true)
            || in_array($path, self::REQUIRED_RELEASE_FILES, true)
            || in_array($path, self::OPERATOR_RUNTIME_BIN, true)
        ) {
            return self::MANAGED_RUNTIME;
        }
        if (preg_match('#^(?:app|jobs|launcher|public|stop)/#D', $path) === 1) {
            if (preg_match('#^app/graphify-out/#D', $path) === 1) {
                return self::NON_RUNTIME;
            }
            return self::MANAGED_RUNTIME;
        }
        if (preg_match('#^database/migrations/[0-9]{3}_[A-Za-z0-9_]+\.sql$#D', $path) === 1) {
            return self::MIGRATIONS;
        }
        if (preg_match('#^resources/mercadolibre-api/source/#D', $path) === 1) {
            return self::BUILD_ONLY;
        }
        if (preg_match('#^resources/(?:mercadolibre-api/generated/|modules/|release/)#D', $path) === 1
            || in_array($path, ['resources/migration-replacements.json', 'resources/runtime-manifest.json'], true)
        ) {
            return self::MANAGED_RUNTIME;
        }
        if (preg_match('#^tests/#D', $path) === 1) {
            return self::TEST_ONLY;
        }
        if (preg_match('#^(?:docs/|\.github/)#D', $path) === 1
            || in_array($path, ['.gitattributes', '.gitignore', 'phpstan.neon'], true)
        ) {
            return self::NON_RUNTIME;
        }
        if ($path === 'config.env.example') {
            return self::BUILD_ONLY;
        }
        if (preg_match('#^bin/.*\.ps1$#Di', $path) === 1 || in_array($path, self::BUILD_ONLY_BIN, true)) {
            return self::BUILD_ONLY;
        }

        return self::UNCLASSIFIED;
    }

    /** @param list<string> $paths @return list<string> */
    public static function releasePaths(array $paths): array
    {
        $release = [];
        foreach ($paths as $path) {
            $classification = self::classifyPath($path);
            if ($classification === self::MANAGED_RUNTIME || $classification === self::MIGRATIONS) {
                $release[] = self::canonicalPath($path);
            }
        }
        $release = array_values(array_unique($release));
        sort($release, SORT_STRING);
        return $release;
    }

    /**
     * Inspect a complete tracked-file map. Values must be the exact file bytes.
     *
     * @param array<string,string> $files
     * @return list<string>
     */
    public static function coverageIssues(array $files): array
    {
        $canonical = [];
        $case = [];
        $issues = [];
        foreach ($files as $path => $bytes) {
            try {
                $path = self::canonicalPath($path);
            } catch (InvalidArgumentException) {
                $issues[] = 'unsafe_path';
                continue;
            }
            $folded = strtolower($path);
            if (isset($case[$folded]) && $case[$folded] !== $path) {
                $issues[] = 'case_collision:' . $path;
                continue;
            }
            if (self::classifyPath($path) === self::UNCLASSIFIED) {
                $issues[] = 'unclassified_path:' . $path;
            }
            $case[$folded] = $path;
            $canonical[$path] = $bytes;
        }

        foreach (array_merge(self::REQUIRED_ROOT_ENTRYPOINTS, self::REQUIRED_RELEASE_FILES) as $path) {
            if (!isset($canonical[$path])) {
                $issues[] = 'required_release_path_missing:' . $path;
            } elseif (self::classifyPath($path) !== self::MANAGED_RUNTIME) {
                $issues[] = 'required_release_path_unmanaged:' . $path;
            }
        }

        foreach ($canonical as $path => $bytes) {
            if (self::mustBeManagedExecutable($path)
                && self::classifyPath($path) !== self::MANAGED_RUNTIME
            ) {
                $issues[] = 'executable_path_unmanaged:' . $path;
            }
            if (self::classifyPath($path) !== self::MANAGED_RUNTIME || !str_ends_with($path, '.php')) {
                continue;
            }
            foreach (self::literalViews($bytes) as $view) {
                $viewPath = 'app/Views/' . $view . '.php';
                if (!isset($canonical[$viewPath])) {
                    $issues[] = 'routed_view_missing:' . $path . '->' . $viewPath;
                } elseif (self::classifyPath($viewPath) !== self::MANAGED_RUNTIME) {
                    $issues[] = 'routed_view_unmanaged:' . $viewPath;
                }
            }
            foreach (self::literalAssets($bytes) as $asset) {
                $assetPath = 'public/assets/' . $asset;
                if (!isset($canonical[$assetPath])) {
                    $issues[] = 'static_asset_missing:' . $path . '->' . $assetPath;
                } elseif (self::classifyPath($assetPath) !== self::MANAGED_RUNTIME) {
                    $issues[] = 'static_asset_unmanaged:' . $assetPath;
                }
            }
            foreach (self::literalRouteClassPaths($bytes) as $controllerPath) {
                if (!isset($canonical[$controllerPath])) {
                    $issues[] = 'route_handler_missing:' . $path . '->' . $controllerPath;
                } elseif (self::classifyPath($controllerPath) !== self::MANAGED_RUNTIME) {
                    $issues[] = 'route_handler_unmanaged:' . $controllerPath;
                }
            }
        }

        foreach ($canonical as $path => $_bytes) {
            if ((preg_match('#^app/(?:Views|Modules/.+/Views)/.+\.php$#D', $path) === 1
                    || preg_match('#^public/assets/.+$#D', $path) === 1)
                && self::classifyPath($path) !== self::MANAGED_RUNTIME
            ) {
                $issues[] = 'dynamic_runtime_dependency_unmanaged:' . $path;
            }
        }

        return array_values(array_unique($issues));
    }

    /** @return list<string> */
    public static function requiredRootEntrypoints(): array
    {
        return self::REQUIRED_ROOT_ENTRYPOINTS;
    }

    private static function mustBeManagedExecutable(string $path): bool
    {
        if (!str_ends_with(strtolower($path), '.php')) {
            return false;
        }
        if (!str_contains($path, '/')) {
            return true;
        }
        return preg_match('#^(?:app|jobs|launcher|public|stop)/#D', $path) === 1
            || in_array($path, self::OPERATOR_RUNTIME_BIN, true);
    }

    private static function isProtectedExternalState(string $path): bool
    {
        if (in_array($path, [
            '.env', 'config.env', 'PAUSE_MELI_API', 'PAUSE_ERP_AUTOMATION',
            'EMERGENCY_CANARY', 'EMERGENCY_OAUTH_REFRESH',
        ], true)) {
            return true;
        }
        return preg_match('#^(?:storage|shared|uploads|backups|releases)/#D', $path) === 1
            || preg_match('#(?:^|/)(?:oauth-rotated-token-recovery|oauth-refresh-reservation)\.json$#D', $path) === 1;
    }

    /** @return list<string> */
    private static function literalViews(string $bytes): array
    {
        preg_match_all('/\bView::render\(\s*([\'\"])([A-Za-z0-9_\/-]+)\1/', $bytes, $matches);
        $views = array_values(array_unique(array_map('strval', $matches[2])));
        sort($views, SORT_STRING);
        return $views;
    }

    /** @return list<string> */
    private static function literalAssets(string $bytes): array
    {
        preg_match_all('/\bView::asset\(\s*[^,]+,\s*([\'\"])([A-Za-z0-9_.\/-]+)\1/', $bytes, $matches);
        $assets = [];
        foreach ($matches[2] as $asset) {
            $asset = ltrim((string) $asset, '/');
            if (!str_contains($asset, '..')) {
                $assets[] = preg_replace('#^assets/#', '', $asset) ?? $asset;
            }
        }
        $assets = array_values(array_unique($assets));
        sort($assets, SORT_STRING);
        return $assets;
    }

    /** @return list<string> */
    private static function literalRouteClassPaths(string $bytes): array
    {
        preg_match_all(
            '/\$router->(?:get|post|put|patch|delete)\([^;]*?,\s*\[\s*([A-Za-z_][A-Za-z0-9_]*)::class/s',
            $bytes,
            $routeMatches,
        );
        if ($routeMatches[1] === []) {
            return [];
        }
        preg_match('/\bnamespace\s+(App(?:\x5c[A-Za-z0-9_]+)*)\s*;/', $bytes, $namespaceMatch);
        $namespace = (string) ($namespaceMatch[1] ?? '');
        preg_match_all(
            '/\buse\s+(App\x5c[A-Za-z0-9_\x5c]+)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/',
            $bytes,
            $useMatches,
            PREG_SET_ORDER,
        );
        $imports = [];
        foreach ($useMatches as $match) {
            $class = (string) $match[1];
            $parts = explode('\\', $class);
            $imports[(string) ($match[2] ?? end($parts))] = $class;
        }
        $paths = [];
        foreach ($routeMatches[1] as $routeClass) {
            $routeClass = ltrim((string) $routeClass, '\\');
            if (str_starts_with($routeClass, 'App\\')) {
                $class = $routeClass;
            } elseif (isset($imports[$routeClass])) {
                $class = $imports[$routeClass];
            } elseif ($namespace !== '') {
                $class = $namespace . '\\' . $routeClass;
            } else {
                continue;
            }
            if (str_starts_with($class, 'App\\')) {
                $paths[] = 'app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            }
        }
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        return $paths;
    }

    private static function canonicalPath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:/', $path) === 1
            || preg_match('#^[A-Za-z0-9._/-]+$#D', $path) !== 1
        ) {
            throw new InvalidArgumentException('Runtime authority path is unsafe.');
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Runtime authority path is unsafe.');
            }
        }
        return $path;
    }
}
