<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Container;
use App\Core\Router;
use App\Services\Logger;
use Throwable;

final class ModuleKernel
{
    public function __construct(
        private readonly ?ModuleRegistry $registry = null,
        private readonly ?Container $container = null
    ) {
    }

    private function registry(): ModuleRegistry
    {
        return $this->registry ?? new ModuleRegistry();
    }

    public function registerRoutes(Router $router): void
    {
        foreach ($this->registry()->enabledProviders() as $provider) {
            try {
                if ($this->container !== null) {
                    $provider->register($this->container);
                }
                $provider->registerRoutes($router);
            } catch (Throwable $error) {
                $this->recordFailure($provider->id(), 'routes', $error);
            }
        }
    }

    /** @return list<array<string,mixed>> */
    public function navigation(string $role, bool $temporary): array
    {
        if ($temporary) {
            return [];
        }
        $items = [];
        foreach ($this->registry()->enabledProviders() as $provider) {
            try {
                foreach ($provider->navigation() as $item) {
                    if (in_array($role, $item['roles'], true)) {
                        $items[] = $item;
                    }
                }
            } catch (Throwable $error) {
                $this->recordFailure($provider->id(), 'navigation', $error);
            }
        }
        return $items;
    }

    /** @return array{css:list<string>,js:list<string>} */
    public function assets(): array
    {
        $assets = ['css' => [], 'js' => []];
        foreach ($this->registry()->enabledProviders() as $provider) {
            try {
                $moduleAssets = $provider->assets();
                foreach (['css', 'js'] as $type) {
                    foreach ($moduleAssets[$type] as $asset) {
                        if ($asset !== '') {
                            $assets[$type][] = $asset;
                        }
                    }
                }
            } catch (Throwable $error) {
                $this->recordFailure($provider->id(), 'assets', $error);
            }
        }
        $assets['css'] = array_values(array_unique($assets['css']));
        $assets['js'] = array_values(array_unique($assets['js']));
        return $assets;
    }

    /** @return array<string,mixed> */
    public function processDueJobs(int $limit = 2, ?float $deadline = null): array
    {
        $runtime = (new ModuleRuntimeReadinessService())->status();
        if (!$runtime['ready']) {
            return [
                'status' => 'migration_required',
                'processed' => 0,
                'errors' => 0,
                'lease_lost' => 0,
                'events_reconciled' => 0,
                'details' => [],
                'runtime' => $runtime,
            ];
        }
        return (new ModuleJobRunner($this->registry()))->processDue($limit, $deadline);
    }

    /** @return array<string,mixed> */
    public function processExactJob(int $jobId, ?float $deadline = null): array
    {
        $runtime = (new ModuleRuntimeReadinessService())->status();
        if (!$runtime['ready']) {
            return ['status' => 'migration_required', 'processed' => 0, 'errors' => 0];
        }
        return (new ModuleJobRunner($this->registry()))->processExact($jobId, $deadline);
    }

    private function recordFailure(string $moduleId, string $stage, Throwable $error): void
    {
        Logger::write('error', 'Módulo aislado no disponible.', [
            'module' => $moduleId,
            'stage' => $stage,
            'error_class' => $error::class,
        ]);
        try {
            $stmt = \App\Core\Database::connection()->prepare(
                "UPDATE system_modules
                 SET status='degraded',last_error_message=?,last_health_at=UTC_TIMESTAMP()
                 WHERE module_id=?"
            );
            $stmt->execute(['El módulo no pudo iniciar de forma segura.', $moduleId]);
        } catch (Throwable) {
        }
    }
}
