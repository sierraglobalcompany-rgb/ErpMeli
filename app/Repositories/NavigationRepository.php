<?php

declare(strict_types=1);

namespace App\Repositories;

final class NavigationRepository
{
    public function sections(string $role, bool $temporary): array
    {
        $sections = [
            [
                'key' => 'sales',
                'label' => 'Ventas',
                'icon' => 'cart',
                'items' => [
                    $this->item('/sales', 'cart', 'Ventas Mercado Libre', ['/sales', '/orders']),
                    $this->item('/sales-control', 'chart', 'Control de ventas'),
                    $this->item('/sync', 'refresh', 'Sincronizaciones'),
                    $this->item('/shipments', 'link', 'Logística', ['/shipments', '/packs']),
                    $this->item('/questions', 'bell', 'Atención al cliente', ['/questions', '/claims']),
                ],
            ],
            [
                'key' => 'products',
                'label' => 'Productos',
                'icon' => 'building',
                'items' => [
                    $this->item('/products/meli', 'cart', 'Productos Mercado Libre'),
                    $this->item('/products/internal', 'building', 'Bodega', ['/products/internal', '/products/imports']),
                    $this->item('/products/links', 'link', 'Vinculación', ['/products/links', '/products/unlinked']),
                    $this->item('/catalogs', 'file', 'Catálogos'),
                ],
            ],
            [
                'key' => 'finance',
                'label' => 'Finanzas',
                'icon' => 'money',
                'items' => [
                    $this->item('/billing', 'chart', 'Facturación', ['/billing', '/billing/date', '/reports', '/reports/date']),
                    $this->item('/reports/profitability', 'money', 'Reportes', ['/reports/profitability', '/exports']),
                    $this->item('/financial-recalc', 'refresh', 'Conciliación'),
                ],
            ],
            [
                'key' => 'operation',
                'label' => 'Operación',
                'icon' => 'bell',
                'items' => [
                    $this->item('/notifications', 'bell', 'Notificaciones'),
                    $this->item('/alerts', 'bell', 'Alertas'),
                    $this->item('/webhooks', 'webhook', 'Integraciones', ['/webhooks', '/logs']),
                ],
            ],
        ];

        if ($role === 'admin' && !$temporary) {
            $sections[] = [
                'key' => 'administration',
                'label' => 'Administración',
                'icon' => 'users',
                'items' => [
                    $this->item('/settings/manual-processing', 'refresh', 'Procesar ahora'),
                    $this->item('/companies', 'building', 'Organización', ['/companies', '/accounts']),
                    $this->item('/users', 'users', 'Usuarios y accesos'),
                    $this->item('/settings', 'file', 'Configuración'),
                ],
            ];
        }

        return $sections;
    }

    public function tabs(string $routePath): array
    {
        $groups = $this->tabGroups();
        foreach ($groups as $group) {
            foreach ($group as $tab) {
                if ($this->matches($routePath, $tab['matches'])) {
                    return $group;
                }
            }
        }
        if (str_starts_with($routePath, '/settings/')) {
            return $groups[array_key_last($groups)] ?? [];
        }
        return [];
    }

    public function isActive(string $routePath, array $matches): bool
    {
        return $this->matches($routePath, $matches);
    }

    private function tabGroups(): array
    {
        return [
            [
                ['href' => '/sales', 'label' => 'Ventas', 'matches' => ['=/sales', '/sales/show', '/sales/orders']],
                ['href' => '/sales/integrity', 'label' => 'Integridad', 'matches' => ['/sales/integrity']],
            ],
            [
                ['href' => '/sales-control', 'label' => 'Resumen anual', 'matches' => ['=/sales-control', '/sales-control/month']],
                ['href' => '/sales-control/issues', 'label' => 'Por revisar', 'matches' => ['/sales-control/issues']],
                ['href' => '/sales-control/fiscal', 'label' => 'Preparación fiscal', 'matches' => ['/sales-control/fiscal']],
                ['href' => '/sales-control/closes', 'label' => 'Cierres', 'matches' => ['/sales-control/closes']],
            ],
            [
                ['href' => '/sync', 'label' => 'Monitor', 'matches' => ['=/sync', '/sync/account']],
                ['href' => '/sync/schedule', 'label' => 'Agenda', 'matches' => ['/sync/schedule']],
                ['href' => '/sales-control', 'label' => 'Control de ventas', 'matches' => ['/sales-control']],
                ['href' => '/sync/recurring', 'label' => 'Programación automática', 'matches' => ['/sync/recurring']],
                ['href' => '/financial-recalc', 'label' => 'Cola financiera', 'matches' => ['/financial-recalc']],
            ],
            [
                ['href' => '/shipments', 'label' => 'Envíos', 'matches' => ['/shipments']],
                ['href' => '/packs', 'label' => 'Packs', 'matches' => ['/packs']],
            ],
            [
                ['href' => '/questions', 'label' => 'Preguntas', 'matches' => ['/questions']],
                ['href' => '/claims', 'label' => 'Reclamos', 'matches' => ['/claims']],
            ],
            [
                ['href' => '/billing', 'label' => 'Mensual', 'matches' => ['/billing']],
                ['href' => '/billing/date', 'label' => 'Por fechas', 'matches' => ['/billing/date']],
            ],
            [
                ['href' => '/catalogs', 'label' => 'Catálogos', 'matches' => ['=/catalogs', '/catalogs/create']],
                ['href' => '/catalogs/private', 'label' => 'Vista privada', 'matches' => ['/catalogs/private']],
                ['href' => '/catalogs/categories', 'label' => 'Categorías', 'matches' => ['/catalogs/categories']],
                ['href' => '/catalogs/settings', 'label' => 'Configuración', 'matches' => ['/catalogs/settings']],
            ],
            [
                ['href' => '/notifications', 'label' => 'Necesitan atención', 'matches' => ['=/notifications']],
                ['href' => '/notifications/activity', 'label' => 'Actividad reciente', 'matches' => ['/notifications/activity']],
                ['href' => '/notifications/health', 'label' => 'Salud y recuperación', 'matches' => ['/notifications/health']],
                ['href' => '/notifications/automation', 'label' => 'Automatización', 'matches' => ['/notifications/automation']],
            ],
            [
                ['href' => '/companies', 'label' => 'Empresas', 'matches' => ['/companies']],
                ['href' => '/accounts', 'label' => 'Cuentas Mercado Libre', 'matches' => ['/accounts']],
            ],
            [
                ['href' => '/notifications/technical/events', 'label' => 'Eventos técnicos', 'matches' => ['/notifications/technical/events', '/webhooks']],
                ['href' => '/logs', 'label' => 'Logs', 'matches' => ['/logs']],
                ['href' => '/settings/api-docs', 'label' => 'Documentación API', 'matches' => ['/settings/api-docs']],
            ],
            [
                ['href' => '/settings', 'label' => 'Centro', 'matches' => ['=/settings']],
                ['href' => '/settings/api-health', 'label' => 'Salud API', 'matches' => ['/settings/api-health']],
                ['href' => '/settings/manual-processing', 'label' => 'Procesar ahora', 'matches' => ['/settings/manual-processing']],
                ['href' => '/settings/cron', 'label' => 'Cron', 'matches' => ['/settings/cron']],
                ['href' => '/settings/diagnostics', 'label' => 'Diagnóstico', 'matches' => ['/settings/diagnostics']],
                ['href' => '/settings/update', 'label' => 'Actualizaciones', 'matches' => ['/settings/update']],
                ['href' => '/settings/backups', 'label' => 'Copias', 'matches' => ['/settings/backups']],
                ['href' => '/settings/database-maintenance', 'label' => 'Saneamiento', 'matches' => ['/settings/database-maintenance']],
                ['href' => '/settings/modules', 'label' => 'Módulos', 'matches' => ['/settings/modules']],
            ],
        ];
    }

    private function item(string $href, string $icon, string $label, ?array $matches = null): array
    {
        return [
            'href' => $href,
            'icon' => $icon,
            'label' => $label,
            'matches' => $matches ?? [$href],
        ];
    }

    private function matches(string $routePath, array $matches): bool
    {
        foreach ($matches as $match) {
            if (str_starts_with($match, '=')) {
                if ($routePath === substr($match, 1)) {
                    return true;
                }
                continue;
            }
            if ($match === '/') {
                if ($routePath === '/') {
                    return true;
                }
                continue;
            }
            if ($routePath === $match || str_starts_with($routePath, $match . '/')) {
                return true;
            }
        }
        return false;
    }
}
