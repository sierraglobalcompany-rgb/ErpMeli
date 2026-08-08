<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Router;

final class RouteCatalogService
{
    public function __construct(private readonly Router $router)
    {
    }

    /** @return array<string,list<array<string,mixed>>> */
    public function byDomain(): array
    {
        $domains = [];
        foreach ($this->router->definitions() as $route) {
            $domain = (string) ($route['metadata']['domain'] ?? 'other');
            $domains[$domain][] = $route;
        }
        ksort($domains);
        return $domains;
    }
}
