<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use RuntimeException;
use Throwable;

final class ReleaseIntegrityService
{
    private const MANIFEST = 'resources/runtime-manifest.json';

    /** @return array<string,mixed> */
    public function inspect(bool $checkDatabase = true): array
    {
        return $this->inspectDirectory(
            AppPaths::releaseRoot(),
            $checkDatabase,
            true
        );
    }

    /** @return array<string,mixed> */
    public function inspectDirectory(
        string $root,
        bool $checkDatabase = false,
        bool $checkNestedRelease = false
    ): array {
        $root = rtrim($root, '/\\');
        $manifestPath = $root . '/' . self::MANIFEST;
        $versionPath = $root . '/VERSION';
        $fileVersion = is_file($versionPath)
            ? trim((string) file_get_contents($versionPath))
            : 'desconocida';
        $errors = [];
        $moduleIssues = [];
        $components = [];
        $manifest = $this->readManifest($manifestPath);

        if ($manifest === null) {
            $errors[] = ['code' => 'missing_manifest', 'component' => self::MANIFEST];
        }

        $manifestVersion = trim((string) ($manifest['version'] ?? ''));
        $buildId = trim((string) ($manifest['build_id'] ?? ''));
        $requiredMigration = trim((string) ($manifest['minimum_migration'] ?? ''));
        if ($manifestVersion === '' || $manifestVersion !== $fileVersion) {
            $errors[] = [
                'code' => 'version_mismatch',
                'component' => 'VERSION',
                'expected' => $manifestVersion !== '' ? $manifestVersion : 'manifest_missing',
                'actual' => $fileVersion,
            ];
        }
        if ($buildId === '') {
            $errors[] = ['code' => 'missing_build_id', 'component' => self::MANIFEST];
        }
        $migrationPathIsSafe = $requiredMigration !== ''
            && basename($requiredMigration) === $requiredMigration
            && preg_match('/^[0-9]{3}_[a-zA-Z0-9_]+\.sql$/', $requiredMigration) === 1;
        if (!$migrationPathIsSafe || !is_file($root . '/database/migrations/' . $requiredMigration)) {
            $errors[] = [
                'code' => 'minimum_migration_file_missing',
                'component' => $requiredMigration !== '' ? $requiredMigration : 'manifest_missing',
            ];
        }

        foreach (($manifest['components'] ?? []) as $name => $definition) {
            if (!is_string($name) || !is_array($definition)) {
                continue;
            }
            $relative = str_replace('\\', '/', ltrim((string) ($definition['path'] ?? ''), '/\\'));
            $expectedHash = strtolower(trim((string) ($definition['sha256'] ?? '')));
            $expectedTextHash = strtolower(trim((string) ($definition['sha256_lf'] ?? '')));
            $textHashAllowed = (bool) ($definition['text'] ?? false);
            $safePath = preg_match('#^[a-zA-Z0-9_./-]+$#', $relative) === 1
                && !str_contains($relative, '..');
            $absolute = $safePath ? $root . '/' . $relative : '';
            $actualHash = $absolute !== '' && is_file($absolute) ? hash_file('sha256', $absolute) : false;
            $exactMatches = is_string($actualHash)
                && preg_match('/^[a-f0-9]{64}$/', $expectedHash) === 1
                && hash_equals($expectedHash, strtolower($actualHash));
            $actualTextHash = $textHashAllowed && $absolute !== '' && is_file($absolute)
                ? $this->canonicalTextSha256($absolute)
                : false;
            $textMatches = !$exactMatches
                && $textHashAllowed
                && is_string($actualTextHash)
                && preg_match('/^[a-f0-9]{64}$/', $expectedTextHash) === 1
                && hash_equals($expectedTextHash, strtolower($actualTextHash));
            $matches = $exactMatches || $textMatches;
            $components[$name] = [
                'path' => $relative,
                'exists' => $absolute !== '' && is_file($absolute),
                'matches' => $matches,
                'match_mode' => $exactMatches ? 'exact' : ($textMatches ? 'text_lf' : 'none'),
                'expected_short' => $expectedHash !== '' ? substr($expectedHash, 0, 12) : null,
                'actual_short' => is_string($actualHash) ? substr($actualHash, 0, 12) : null,
                'expected_text_short' => $expectedTextHash !== '' ? substr($expectedTextHash, 0, 12) : null,
                'actual_text_short' => is_string($actualTextHash) ? substr($actualTextHash, 0, 12) : null,
            ];
            if (!$matches) {
                $errors[] = [
                    'code' => 'component_mismatch',
                    'component' => $name,
                    'path' => $relative,
                ];
            }
        }

        if (is_array($manifest)) {
            foreach (RuntimePublicationPolicy::installedManifestIssues($root, $manifest) as $issue) {
                $errors[] = [
                    'code' => 'runtime_publication_policy_invalid',
                    'component' => $issue,
                ];
            }
        }

        foreach (['cron_probe', 'process_sync_queue'] as $requiredComponent) {
            if (!array_key_exists($requiredComponent, $components)) {
                $errors[] = ['code' => 'component_not_declared', 'component' => $requiredComponent];
            }
        }

        foreach (glob($root . '/resources/modules/*/module.json') ?: [] as $moduleManifestPath) {
            $moduleManifest = $this->readManifest($moduleManifestPath);
            $moduleId = (string) ($moduleManifest['id'] ?? basename(dirname($moduleManifestPath)));
            $providerPath = str_replace('\\', '/', ltrim((string) ($moduleManifest['provider_path'] ?? ''), '/\\'));
            if ($providerPath === '' || str_contains($providerPath, '..') || !is_file($root . '/' . $providerPath)) {
                $moduleIssues[] = [
                    'code' => 'module_provider_missing',
                    'component' => $moduleId,
                    'path' => $providerPath,
                ];
            }
        }

        $schema = [
            'checked' => $checkDatabase,
            'migration' => $requiredMigration,
            'migration_applied' => null,
            'version' => null,
            'cron_task_state' => null,
            'missing_columns' => [],
            'missing_notification_columns' => [],
            'missing_oauth_columns' => [],
        ];
        if ($checkDatabase) {
            try {
                $inspector = new SchemaInspectorService();
                $schema['cron_task_state'] = $inspector->hasTable('cron_task_state');
                $requiredColumns = [
                    'current_step',
                    'deadline_at',
                    'end_reason',
                    'lock_name',
                    'release_version',
                    'release_build_id',
                    'component_checksum',
                ];
                $schema['missing_columns'] = $inspector->missingColumns('cron_health_checks', $requiredColumns);
                $schema['missing_notification_columns'] = $inspector->missingColumns(
                    'meli_notification_work_items',
                    ['consecutive_failures', 'last_success_at', 'processing_event_id']
                );
                $schema['missing_oauth_columns'] = $inspector->missingColumns(
                    'meli_oauth_states',
                    ['processing_token_hash', 'processing_at', 'last_error_message']
                );
                $schema['migration_applied'] = $requiredMigration !== '' && $this->migrationApplied($requiredMigration);
                $schemaVersion = Database::connectionFresh()->query(
                    "SELECT setting_value FROM app_settings WHERE setting_key='app.version' LIMIT 1"
                )->fetchColumn();
                $schema['version'] = is_string($schemaVersion) ? trim($schemaVersion) : '';
                if (!$schema['cron_task_state']) {
                    $errors[] = ['code' => 'schema_missing_table', 'component' => 'cron_task_state'];
                }
                if ($schema['missing_columns'] !== []) {
                    $errors[] = [
                        'code' => 'schema_missing_columns',
                        'component' => 'cron_health_checks',
                        'columns' => $schema['missing_columns'],
                    ];
                }
                if ($schema['missing_notification_columns'] !== []) {
                    $errors[] = [
                        'code' => 'schema_missing_columns',
                        'component' => 'meli_notification_work_items',
                        'columns' => $schema['missing_notification_columns'],
                    ];
                }
                if ($schema['missing_oauth_columns'] !== []) {
                    $errors[] = [
                        'code' => 'schema_missing_columns',
                        'component' => 'meli_oauth_states',
                        'columns' => $schema['missing_oauth_columns'],
                    ];
                }
                if (!$schema['migration_applied']) {
                    $errors[] = [
                        'code' => 'migration_pending',
                        'component' => $requiredMigration !== '' ? $requiredMigration : 'unknown',
                    ];
                } elseif (
                    $manifestVersion === ''
                    || $schema['version'] === ''
                    || !hash_equals($manifestVersion, (string) $schema['version'])
                ) {
                    $errors[] = [
                        'code' => 'database_version_mismatch',
                        'component' => 'app.version',
                        'expected' => $manifestVersion !== '' ? $manifestVersion : 'manifest_missing',
                        'actual' => $schema['version'] !== '' ? $schema['version'] : 'sin registrar',
                    ];
                }
            } catch (Throwable) {
                $errors[] = ['code' => 'database_check_failed', 'component' => 'database'];
            }
        }

        $nested = $checkNestedRelease
            ? $this->nestedReleaseCandidates(AppPaths::installationRoot())
            : [];
        if ($nested !== []) {
            $errors[] = ['code' => 'nested_release_detected', 'component' => implode(', ', $nested)];
        }

        $state = $errors === []
            ? 'ok'
            : ($this->hasOnlySchemaErrors($errors) ? 'schema_pending' : 'mixed_release');

        return [
            'ok' => $errors === [],
            'state' => $state,
            'version' => $manifestVersion,
            'file_version' => $fileVersion,
            'build_id' => $buildId,
            'minimum_migration' => $requiredMigration,
            'manifest_path' => self::MANIFEST,
            'components' => $components,
            'module_issues' => $moduleIssues,
            'schema' => $schema,
            'nested_release_candidates' => $nested,
            'errors' => $errors,
            'checked_at' => gmdate('c'),
        ];
    }

    /** @return array{version:string,build_id:string,checksum:string} */
    public function identity(string $component): array
    {
        $inspection = $this->inspect(false);
        $details = $inspection['components'][$component] ?? [];
        return [
            'version' => (string) ($inspection['version'] ?: $inspection['file_version']),
            'build_id' => (string) $inspection['build_id'],
            'checksum' => (string) ($details['actual_short'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    public function assertReady(string $component): array
    {
        $inspection = $this->inspect(false);
        $blockingErrors = array_values(array_filter(
            (array) ($inspection['errors'] ?? []),
            static fn (array $error): bool => !in_array(
                (string) ($error['code'] ?? ''),
                ['migration_pending', 'database_version_mismatch'],
                true
            )
        ));
        if ($blockingErrors !== []) {
            $first = $blockingErrors[0];
            throw new RuntimeException(
                'Integridad de release no válida: '
                . (string) ($first['code'] ?? 'integrity_failed')
                . ' (' . (string) ($first['component'] ?? $component) . ').'
            );
        }
        if (!isset($inspection['components'][$component])) {
            throw new RuntimeException('El componente no está declarado en el manifiesto de runtime.');
        }
        (new ComponentSchemaContractService())->assertReady($component);
        return $inspection;
    }

    public function stampRun(int $healthId, string $component): void
    {
        if ($healthId <= 0) {
            return;
        }
        $identity = $this->identity($component);
        try {
            $stmt = Database::connectionFresh()->prepare(
                'UPDATE cron_health_checks
                 SET release_version=?,release_build_id=?,component_checksum=?
                 WHERE id=?'
            );
            $stmt->execute([
                mb_substr($identity['version'], 0, 30),
                mb_substr($identity['build_id'], 0, 80),
                mb_substr($identity['checksum'], 0, 64),
                $healthId,
            ]);
        } catch (Throwable) {
            // La observabilidad nunca puede reemplazar el resultado del proceso.
        }
    }

    /** @return array<string,mixed>|null */
    private function readManifest(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function migrationApplied(string $migration): bool
    {
        try {
            $stmt = Database::connectionFresh()->prepare(
                'SELECT COUNT(*) FROM schema_migrations WHERE version=?'
            );
            $stmt->execute([$migration]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function canonicalTextSha256(string $path): string|false
    {
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            return false;
        }
        if (str_contains($contents, "\0")) {
            return false;
        }
        $canonical = preg_replace("/\r\n?|\n/", "\n", $contents);
        return is_string($canonical) ? hash('sha256', $canonical) : false;
    }

    /** @return list<string> */
    private function nestedReleaseCandidates(string $root): array
    {
        $found = [];
        foreach (glob(rtrim($root, '/\\') . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $name = basename($directory);
            if (
                stripos($name, 'SUBIR') !== false
                && is_file($directory . '/bootstrap.php')
                && is_file($directory . '/VERSION')
            ) {
                $found[] = $name;
            }
        }
        sort($found);
        return array_slice($found, 0, 10);
    }

    /** @param list<array<string,mixed>> $errors */
    private function hasOnlySchemaErrors(array $errors): bool
    {
        foreach ($errors as $error) {
            if (!in_array((string) ($error['code'] ?? ''), [
                'schema_missing_table',
                'schema_missing_columns',
                'migration_pending',
                'database_version_mismatch',
            ], true)) {
                return false;
            }
        }
        return $errors !== [];
    }
}
