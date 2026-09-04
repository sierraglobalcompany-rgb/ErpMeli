<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Contrato central de seguridad/uso por ruta. Durante la migración los
 * controladores conservan sus validaciones como segunda defensa.
 */
final class RouteMetadataRepository
{
    /** @return array<string,mixed> */
    public function for(string $method, string $path): array
    {
        $method = strtoupper($method);
        $public = $this->isPublic($method, $path);
        $domain = $this->domain($path);
        return [
            'domain' => $domain,
            'authentication' => $public ? 'public' : 'required',
            'role' => $this->role($method, $path),
            'permanent_admin' => str_starts_with($path, '/settings/update')
                || str_starts_with($path, '/settings/backups')
                || str_starts_with($path, '/settings/database-maintenance')
                || str_starts_with($path, '/settings/imported-data-reset')
                || str_starts_with($path, '/settings/diagnostics/migrations')
                || str_starts_with($path, '/notifications/technical')
                || str_starts_with($path, '/notifications/backfill')
                || str_starts_with($path, '/notifications/missed'),
            'csrf' => $method === 'POST' && !in_array($path, ['/login', '/performance/metrics'], true),
            'rate_limit' => $public && str_starts_with($path, '/catalogo/') ? 'public_catalog' : 'session',
            'response' => str_ends_with($path, '.json') ? 'json' : 'html',
        ];
    }

    private function isPublic(string $method, string $path): bool
    {
        return ($method === 'GET' && ($path === '/login' || $path === '/login.php' || $path === '/meli_callback.php'))
            || ($method === 'POST' && $path === '/login')
            || str_starts_with($path, '/catalogo/');
    }

    private function domain(string $path): string
    {
        return match (true) {
            $path === '/' => 'dashboard',
            str_starts_with($path, '/orders'), str_starts_with($path, '/sales'), str_starts_with($path, '/sync'),
            str_starts_with($path, '/sales-control') => 'sales',
            str_starts_with($path, '/shipments'), str_starts_with($path, '/packs') => 'logistics',
            str_starts_with($path, '/products'), str_starts_with($path, '/catalog') => 'products',
            str_starts_with($path, '/billing'), str_starts_with($path, '/financial') => 'finance',
            str_starts_with($path, '/notifications'), str_starts_with($path, '/webhooks') => 'operations',
            str_starts_with($path, '/settings') => 'administration',
            default => 'administration',
        };
    }

    private function role(string $method, string $path): string
    {
        if ($method === 'GET') {
            return 'authenticated';
        }
        if (str_contains($path, '/delete') || str_contains($path, '/cancel') || str_contains($path, '/apply')) {
            return 'admin';
        }
        return 'admin_or_operator';
    }
}
