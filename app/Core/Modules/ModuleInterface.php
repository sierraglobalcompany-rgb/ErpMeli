<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Container;
use App\Core\Router;

interface ModuleInterface
{
    public function id(): string;

    public function label(): string;

    public function version(): string;

    public function coreRequirement(): string;

    public function featureFlag(): string;

    /** @return list<string> */
    public function dependencies(): array;

    public function migrationPath(): string;

    public function register(Container $container): void;

    public function registerRoutes(Router $router): void;

    /** @return list<array{section:string,section_label:string,section_icon:string,href:string,icon:string,label:string,matches:list<string>,roles:list<string>}> */
    public function navigation(): array;

    /** @return array{css:list<string>,js:list<string>} */
    public function assets(): array;

    /** @return list<string> */
    public function eventTopics(): array;

    /** @return list<string> */
    public function jobTypes(): array;

    /** @param array<string,mixed> $job @return array<string,mixed> */
    public function processJob(array $job): array;

    /** @return list<array{key:string,label:string,ok:bool,message:string}> */
    public function healthChecks(): array;
}
