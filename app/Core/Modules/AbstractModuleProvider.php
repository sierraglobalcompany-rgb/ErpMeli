<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\AppPaths;
use App\Core\Container;

abstract class AbstractModuleProvider implements ModuleInterface
{
    final public function migrationPath(): string
    {
        return AppPaths::releaseRoot() . '/database/modules/' . $this->id();
    }

    public function coreRequirement(): string
    {
        return '>=2.12.0 <3.0.0';
    }

    public function featureFlag(): string
    {
        return 'module.' . str_replace('-', '_', $this->id()) . '.enabled';
    }

    public function dependencies(): array
    {
        return [];
    }

    public function register(Container $container): void
    {
    }

    public function assets(): array
    {
        return [
            'css' => ['assets/modules/modules.css'],
            'js' => ['assets/modules/modules.js'],
        ];
    }

    public function eventTopics(): array
    {
        return [];
    }

    public function jobTypes(): array
    {
        return [];
    }

    public function processJob(array $job): array
    {
        return ['processed' => 0, 'errors' => 0, 'status' => 'unsupported'];
    }

    public function healthChecks(): array
    {
        return [];
    }
}
