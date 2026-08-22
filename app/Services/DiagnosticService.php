<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\AppPaths;
use App\Core\Env;
use App\Core\RuntimeCompatibility;
use PDO;
use Throwable;

final class DiagnosticService
{
    public function summary(): array
    {
        $root = dirname(__DIR__, 2);
        return [
            'file_version' => AppVersionService::fileVersion(),
            'installed_version' => (new AppVersionService())->installedVersion(),
            'config_env' => is_file(AppPaths::configFile()) ? 'presente' : 'faltante',
            'app_key' => Env::get('APP_KEY', '') !== '' ? 'presente' : 'faltante',
            'install_lock' => is_file(AppPaths::storage('install.lock')) ? 'presente' : 'faltante',
            'ml_write_enabled' => Env::bool('ML_WRITE_ENABLED', false) ? 'true' : 'false',
            'php_runtime' => $this->runtime(),
            'storage' => $this->storageStatus($root),
            'migrations' => $this->migrations(),
            'migration_diagnostic' => $this->migrationDiagnostic(),
            'business_scope' => (new BusinessScopeAuditService())->inspect(),
            'modules' => (new ModuleHealthService())->expectedModules(),
            'incident_materializer' => $this->incidentMaterializer(),
            'recent_errors' => $this->recentErrors(),
        ];
    }

    public function migrations(): array
    {
        $path = dirname(__DIR__, 2) . '/database/migrations';
        $files = glob($path . '/*.sql') ?: [];
        sort($files);
        $applied = [];
        try {
            $applied = array_flip(Database::connection()->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {
            $applied = [];
        }
        return array_map(static fn(string $file): array => [
            'version' => basename($file),
            'applied' => isset($applied[basename($file)]),
        ], $files);
    }

    public function runtime(): array
    {
        $root = dirname(__DIR__, 2);
        $runtime = RuntimeCompatibility::snapshot();
        $composerConstraint = 'no detectado';
        $composerPath = $root . '/composer.json';
        if (is_file($composerPath)) {
            $composer = json_decode((string) file_get_contents($composerPath), true);
            if (is_array($composer) && isset($composer['require']['php'])) {
                $composerConstraint = (string) $composer['require']['php'];
            }
        }

        $runtime['composer_constraint'] = $composerConstraint;
        return $runtime;
    }

    private function storageStatus(string $root): array
    {
        $status = [];
        foreach (['storage', 'storage/logs', 'storage/cache', 'storage/raw', 'storage/exports'] as $dir) {
            $full = $dir === 'storage' ? AppPaths::storage() : AppPaths::storage(substr($dir, 8));
            $status[$dir] = is_dir($full) && is_writable($full) ? 'escribible' : (is_dir($full) ? 'sin permisos' : 'faltante');
        }
        return $status;
    }

    /** @return array{available:bool,current:bool,last_log_id:int,latest_log_id:int,lag:int} */
    private function incidentMaterializer(): array
    {
        try {
            $freshness = (new ApiIncidentReadModelService())->freshness();
            $last = (int) ($freshness['last_log_id'] ?? 0);
            $latest = (int) ($freshness['latest_log_id'] ?? 0);
            return [
                'available' => $last > 0 || $latest > 0,
                'current' => (bool) ($freshness['current'] ?? false),
                'last_log_id' => $last,
                'latest_log_id' => $latest,
                'lag' => max(0, $latest - $last),
            ];
        } catch (Throwable) {
            return ['available' => false, 'current' => false, 'last_log_id' => 0, 'latest_log_id' => 0, 'lag' => 0];
        }
    }

    private function recentErrors(): array
    {
        try {
            $rows = Database::connection()->query(
                "SELECT level,message,context_json,created_at
                 FROM system_logs WHERE level IN ('error','warning')
                 ORDER BY created_at DESC LIMIT 100"
            )->fetchAll(PDO::FETCH_ASSOC);
            $grouped = [];
            foreach ($rows as $row) {
                $context = json_decode((string) ($row['context_json'] ?? ''), true);
                $context = is_array($context) ? MigrationTraceService::sanitizeContext($context) : [];
                $technical = strtolower((string) ($context['error'] ?? ''));
                $schemaCompatibility = str_contains($technical, 'unknown column')
                    && (str_contains($technical, 'a.deleted_at')
                        || str_contains($technical, 'sync_sales_audit_jobs')
                        || str_contains($technical, 'j.locked_at'));
                $signatureSource = implode('|', [
                    (string) ($context['exception'] ?? ''),
                    (string) ($context['sqlstate'] ?? ''),
                    (string) ($context['driver_code'] ?? ''),
                    preg_replace('/err-\\d{8}-\\d{6}-[a-f0-9]+/i', 'ERR-*', $technical) ?? $technical,
                    $schemaCompatibility ? 'sales_control_schema_compatibility' : (string) ($row['message'] ?? ''),
                ]);
                $fingerprint = hash('sha256', $signatureSource);
                if (!isset($grouped[$fingerprint])) {
                    $grouped[$fingerprint] = [
                        'level' => (string) $row['level'],
                        'message' => $schemaCompatibility
                            ? 'Compatibilidad de esquema de Control de ventas'
                            : (string) $row['message'],
                        'context' => $schemaCompatibility
                            ? [
                                'classification' => 'schema_compatibility',
                                'recoverable' => true,
                                'action' => 'Instale la actualización pendiente y vuelva a abrir Control de ventas.',
                            ]
                            : $context,
                        'created_at' => (string) $row['created_at'],
                        'first_seen_at' => (string) $row['created_at'],
                        'last_seen_at' => (string) $row['created_at'],
                        'occurrences' => 1,
                    ];
                    continue;
                }
                $grouped[$fingerprint]['occurrences']++;
                $grouped[$fingerprint]['first_seen_at'] = (string) $row['created_at'];
            }
            return array_slice(array_values($grouped), 0, 8);
        } catch (Throwable) {
            return [];
        }
    }

    private function migrationDiagnostic(): array
    {
        try {
            return (new MigrationDiagnosticService())->summary();
        } catch (Throwable $e) {
            return [
                'error' => MigrationTraceService::safeMessage($e),
                'pending_count' => count(array_filter(
                    $this->migrations(),
                    static fn(array $row): bool => !($row['applied'] ?? false)
                )),
                'events' => [],
                'safe_to_retry' => false,
                'recommendation' => 'El diagnóstico de migraciones no pudo completarse. Revise el log sanitizado.',
            ];
        }
    }
}
