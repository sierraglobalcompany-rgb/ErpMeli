<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Database;
use App\Core\AppPaths;
use App\Services\AppVersionService;
use App\Services\AppSettingsService;
use PDO;
use Throwable;

final class ModuleHealthService
{
    public function __construct(private readonly ?ModuleRegistry $registry = null)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(bool $includeLiveChecks = false): array
    {
        $registry = $this->registry ?? new ModuleRegistry();
        $runtime = (new ModuleRuntimeReadinessService())->status();
        $states = $registry->states();
        $runner = new ModuleMigrationRunner($registry);
        $providers = $registry->providers();
        $pendingByModule = $runner->pendingAll(array_keys($providers));
        $rows = [];
        $settingsService = new AppSettingsService();
        $settingDefaults = ['module.rollout.allowed_modules' => 'meli-insights'];
        foreach ($providers as $provider) {
            $settingDefaults[$provider->featureFlag()] = '0';
        }
        $settings = $settingsService->getMany($settingDefaults);
        $allowed = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($settings['module.rollout.allowed_modules'] ?? 'meli-insights'))
        )));
        foreach ($providers as $id => $provider) {
            if (!is_file(AppPaths::releaseRoot() . '/resources/modules/' . $id . '/module.json')) {
                continue;
            }
            $state = $states[$id] ?? [];
            $pending = $pendingByModule[$id] ?? [];
            $checks = [];
            if ($includeLiveChecks) {
                try {
                    $checks = $provider->healthChecks();
                } catch (Throwable) {
                    $checks[] = ['key' => 'provider', 'label' => 'Inicialización', 'ok' => false, 'message' => 'No fue posible ejecutar el diagnóstico.'];
                }
            }
            $compatible = version_compare(AppVersionService::fileVersion(), '2.12.0', '>=')
                && version_compare(AppVersionService::fileVersion(), '3.0.0', '<');
            $status = !$runtime['ready']
                ? 'migration_required'
                : ($pending !== [] ? 'migration_required' : ($state['status'] ?? 'discovered'));
            $rows[] = [
                'id' => $id,
                'label' => $provider->label(),
                'version' => $provider->version(),
                'status' => $status,
                'enabled' => !empty($runtime['ready'])
                    && $pending === []
                    && (string) ($state['status'] ?? '') === 'enabled'
                    && filter_var((string) ($settings[$provider->featureFlag()] ?? '0'), FILTER_VALIDATE_BOOL),
                'runtime_ready' => $runtime['ready'],
                'runtime_reason' => $runtime['reason'],
                'pending_migrations' => count($pending),
                'dependencies' => $provider->dependencies(),
                'compatible' => $compatible,
                'last_health_at' => $state['last_health_at'] ?? null,
                'last_error_message' => $state['last_error_message'] ?? null,
                'checks' => $checks,
                'rollout_allowed' => in_array($id, $allowed, true),
            ];
        }
        return $rows;
    }

    public function record(string $moduleId, string $status, array $details = []): void
    {
        try {
            $stmt = Database::connection()->prepare(
                'INSERT INTO system_module_health (module_id,status,details_json,checked_at)
                 VALUES (?,?,?,UTC_TIMESTAMP())'
            );
            $stmt->execute([$moduleId, $status, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            Database::connection()->prepare(
                'UPDATE system_modules SET last_health_at=UTC_TIMESTAMP(),last_error_message=? WHERE module_id=?'
            )->execute([$status === 'ok' ? null : 'El diagnóstico del módulo requiere atención.', $moduleId]);
        } catch (Throwable) {
        }
    }
}
