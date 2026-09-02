<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;

final class RuntimeProcessInventoryService
{
    /** @return array<string,mixed> */
    public function inspect(): array
    {
        $root = AppPaths::releaseRoot();
        $jobs = [];
        foreach (glob($root . '/jobs/*.php') ?: [] as $file) {
            $source = (string) @file_get_contents($file);
            $name = basename($file);
            $status = 'review_required';
            if ($name === 'queue_v4_clean.php') {
                $status = 'active_launcher';
            } elseif (str_starts_with($name, '_')) {
                $status = 'internal_library';
            } elseif ($name === 'cron_probe.php') {
                $status = 'diagnostic_only';
            } elseif (str_starts_with($name, 'queue_core_')) {
                // Herramientas de diagnóstico o recuperación offline: nunca un Cron.
                $status = 'manual_offline_only';
            } elseif (in_array($name, [
                'process_database_maintenance.php',
                'reset_imported_meli_data.php',
            ], true)) {
                // Ejecución puntual solicitada por un administrador. No se registra
                // como lanzador periódico ni compite con Queue V4.
                $status = 'maintenance_cli';
            } elseif ($this->isSafeStub($source)) {
                $status = 'safe_stub';
            }
            $jobs[] = [
                'name' => $name,
                'status' => $status,
                'loads_bootstrap' => str_contains($source, "_bootstrap.php")
                    || str_contains($source, "bootstrap.php"),
                'remote_client_reference' => str_contains($source, 'MeliApiClient'),
            ];
        }

        $assets = [];
        foreach (glob($root . '/public/assets/*.js') ?: [] as $file) {
            $source = (string) @file_get_contents($file);
            preg_match_all('/setInterval\\s*\\(/', $source, $intervals);
            preg_match_all('/setTimeout\\s*\\(/', $source, $timeouts);
            preg_match_all('/fetch\\s*\\(/', $source, $fetches);
            $assets[] = [
                'name' => basename($file),
                'intervals' => count($intervals[0]),
                'timeouts' => count($timeouts[0]),
                'fetches' => count($fetches[0]),
                'legacy_assisted' => str_contains($source, 'assisted-step')
                    || str_contains($source, 'interactive/step'),
            ];
        }

        return [
            'captured_at' => gmdate(DATE_ATOM),
            'jobs' => $jobs,
            'browser_assets' => $assets,
            'active_launcher_count' => count(array_filter(
                $jobs,
                static fn (array $job): bool => $job['status'] === 'active_launcher'
            )),
            'review_required_count' => count(array_filter(
                $jobs,
                static fn (array $job): bool => $job['status'] === 'review_required'
            )),
        ];
    }

    private function isSafeStub(string $source): bool
    {
        return str_contains($source, 'ERP_JOB_RETIRED')
            || (
                !str_contains($source, "_bootstrap.php")
                && !str_contains($source, "bootstrap.php")
                && !str_contains($source, 'Database::')
            );
    }
}
