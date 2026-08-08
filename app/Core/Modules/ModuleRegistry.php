<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Database;
use App\Modules\MeliAds\ModuleProvider as MeliAdsProvider;
use App\Modules\MeliGrowth\ModuleProvider as MeliGrowthProvider;
use App\Modules\MeliInsights\ModuleProvider as MeliInsightsProvider;
use App\Modules\MeliLogistics\ModuleProvider as MeliLogisticsProvider;
use App\Modules\MeliPostSale\ModuleProvider as MeliPostSaleProvider;
use App\Services\AppSettingsService;
use PDO;
use Throwable;

final class ModuleRegistry
{
    /** @var list<class-string<ModuleInterface>> */
    private const PROVIDER_CLASSES = [
        MeliInsightsProvider::class,
        MeliGrowthProvider::class,
        MeliAdsProvider::class,
        MeliPostSaleProvider::class,
        MeliLogisticsProvider::class,
    ];
    /** @var array<class-string<ModuleInterface>,string> */
    private const PROVIDER_IDS = [
        MeliInsightsProvider::class => 'meli-insights',
        MeliGrowthProvider::class => 'meli-growth',
        MeliAdsProvider::class => 'meli-ads',
        MeliPostSaleProvider::class => 'meli-postsale',
        MeliLogisticsProvider::class => 'meli-logistics',
    ];

    /** @var array<string,ModuleInterface>|null */
    private ?array $providers = null;
    /** @var array<string,string> */
    private array $discoveryFailures = [];
    /** @var array<string,array<string,mixed>>|null */
    private ?array $states = null;

    /** @return array<string,ModuleInterface> */
    public function providers(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }
        $this->providers = [];
        foreach (self::PROVIDER_CLASSES as $providerClass) {
            try {
                if (!class_exists($providerClass)) {
                    $this->discoveryFailures[$providerClass] = 'provider_missing';
                    $this->markDegraded($providerClass, 'El proveedor del módulo no está instalado.');
                    continue;
                }
                $provider = new $providerClass();
                $this->providers[$provider->id()] = $provider;
            } catch (Throwable) {
                $this->discoveryFailures[$providerClass] = 'provider_boot_failed';
                $this->markDegraded($providerClass, 'El proveedor del módulo no pudo iniciar.');
            }
        }
        return $this->providers;
    }

    /** @return array<string,string> */
    public function discoveryFailures(): array
    {
        $this->providers();
        return $this->discoveryFailures;
    }

    public function provider(string $moduleId): ?ModuleInterface
    {
        return $this->providers()[$moduleId] ?? null;
    }

    /** @return array<string,array<string,mixed>> */
    public function states(bool $refresh = false): array
    {
        if (!$refresh && $this->states !== null) {
            return $this->states;
        }
        $states = [];
        try {
            $rows = Database::connection()->query('SELECT * FROM system_modules')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $states[(string) $row['module_id']] = $row;
            }
        } catch (Throwable) {
            // Antes de la migración 080 el núcleo debe continuar funcionando.
        }
        $this->states = $states;
        return $states;
    }

    public function isEnabled(string $moduleId): bool
    {
        if (!(new ModuleRuntimeReadinessService())->ready()) {
            return false;
        }
        $provider = $this->provider($moduleId);
        if ($provider === null) {
            return false;
        }
        $state = $this->states()[$moduleId] ?? null;
        if (!is_array($state) || (string) ($state['status'] ?? '') !== 'enabled') {
            return false;
        }
        // Un flag antiguo no debe iniciar rutas, jobs ni consumidores sobre un
        // esquema modular incompleto. Los eventos durables permanecen pendientes
        // hasta terminar las migraciones aisladas del módulo.
        if ((new ModuleMigrationRunner($this))->pending($moduleId) !== []) {
            return false;
        }
        try {
            return (new AppSettingsService())->bool($provider->featureFlag(), false);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string,ModuleInterface> */
    public function enabledProviders(): array
    {
        return array_filter(
            $this->providers(),
            fn (ModuleInterface $provider): bool => $this->isEnabled($provider->id())
        );
    }

    public function clear(): void
    {
        $this->states = null;
        ModuleRuntimeReadinessService::clear();
    }

    /** @param class-string<ModuleInterface> $providerClass */
    private function markDegraded(string $providerClass, string $message): void
    {
        $moduleId = self::PROVIDER_IDS[$providerClass] ?? null;
        if ($moduleId === null) {
            return;
        }
        try {
            $stmt = Database::connection()->prepare(
                "UPDATE system_modules SET status='degraded',last_health_at=UTC_TIMESTAMP(),last_error_message=? WHERE module_id=?"
            );
            $stmt->execute([$message, $moduleId]);
        } catch (Throwable) {
            // Antes de la migración modular el núcleo debe continuar funcionando.
        }
    }
}
