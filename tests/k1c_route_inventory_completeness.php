<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';

$inventory = file_get_contents(__DIR__ . '/../docs/architecture/K1C_AUTOMATION_UI_ROUTE_INVENTORY.csv');
$routes = file_get_contents(__DIR__ . '/../public/index.php');
k1b_assert(is_string($inventory) && is_string($routes), 'Route inventory and route file must be readable.');

$required = [
    '/settings',
    '/settings/cron',
    '/settings/cron/queue-v4.json',
    '/settings/cron/api-risks.json',
    '/settings/cron/next',
    '/settings/cron/queue',
    '/settings/cron/history',
    '/settings/cron/diagnostics',
    '/settings/cron/attention',
    '/settings/api-workload',
    '/settings/manual-processing',
    '/settings/manual-processing/preview',
    '/settings/manual-processing/start',
    '/settings/api-health',
    '/settings/api-health/incidents',
    '/settings/api-health/technical',
    '/notifications',
    '/notifications/automation',
    '/notifications/technical/events',
];

foreach ($required as $route) {
    k1b_assert(str_contains($inventory, $route), "Route missing from K1C inventory: {$route}");
    k1b_assert(str_contains($routes, "'{$route}'") || str_contains($routes, "\"{$route}\""), "Route missing from router: {$route}");
}

echo "STATUS=PASS K1C_ROUTE_INVENTORY_COMPLETENESS\n";
