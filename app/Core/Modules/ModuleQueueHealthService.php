<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Database;
use App\Services\InformationSchemaGateway;
use PDO;
use Throwable;

final class ModuleQueueHealthService
{
    /** @return array<string,mixed> */
    public function status(): array
    {
        $empty = [
            'available' => false,
            'active_jobs' => 0,
            'duplicate_groups' => 0,
            'expired_leases' => 0,
            'orphan_events' => 0,
            'lease_lost' => 0,
            'last_completed_at' => null,
            'recommendation' => 'Complete las migraciones del runtime modular.',
        ];

        try {
            $runtime = (new ModuleRuntimeReadinessService())->status();
            if (!$runtime['ready']) {
                return $empty + ['runtime' => $runtime];
            }
            $tables = (new InformationSchemaGateway(Database::connection()))->tablesExist([
                'system_module_jobs',
                'system_module_events',
            ]);
            if (
                !($tables['system_module_jobs'] ?? false)
                || !($tables['system_module_events'] ?? false)
            ) {
                return $empty;
            }

            $pdo = Database::connection();
            $active = (int) $pdo->query(
                "SELECT COUNT(*) FROM system_module_jobs WHERE status IN ('pending','running','retry','paused')"
            )->fetchColumn();
            $duplicates = (int) $pdo->query(
                "SELECT COUNT(*) FROM (
                    SELECT active_dedupe_key
                    FROM system_module_jobs
                    WHERE active_dedupe_key IS NOT NULL
                    GROUP BY active_dedupe_key
                    HAVING COUNT(*) > 1
                ) duplicate_groups"
            )->fetchColumn();
            $expired = (int) $pdo->query(
                "SELECT COUNT(*) FROM system_module_jobs
                 WHERE status='running' AND lock_expires_at IS NOT NULL AND lock_expires_at<UTC_TIMESTAMP()"
            )->fetchColumn();
            $orphans = (int) $pdo->query(
                "SELECT COUNT(*)
                 FROM system_module_events e
                 WHERE e.status='pending'
                   AND NOT EXISTS (
                       SELECT 1 FROM system_module_jobs j
                       WHERE j.module_id=e.module_id
                         AND j.status IN ('pending','running','retry','paused')
                         AND JSON_UNQUOTE(JSON_EXTRACT(j.payload_json,'$.source_event_id'))=CAST(e.source_event_id AS CHAR)
                   )"
            )->fetchColumn();
            $leaseLost = (int) $pdo->query(
                "SELECT COUNT(*) FROM system_module_jobs
                 WHERE stage='lease_lost' AND updated_at>=UTC_TIMESTAMP()-INTERVAL 24 HOUR"
            )->fetchColumn();
            $lastCompleted = $pdo->query(
                "SELECT MAX(finished_at) FROM system_module_jobs WHERE status='completed'"
            )->fetchColumn();

            $recommendation = 'La cola modular está íntegra.';
            if ($duplicates > 0 || $expired > 0) {
                $recommendation = 'La cola requiere revisión antes de ejecutar nuevos trabajos.';
            } elseif ($orphans > 0) {
                $recommendation = 'Hay eventos pendientes que pueden reconciliarse sin consultar Mercado Libre.';
            }

            return [
                'available' => true,
                'active_jobs' => $active,
                'duplicate_groups' => $duplicates,
                'expired_leases' => $expired,
                'orphan_events' => $orphans,
                'lease_lost' => $leaseLost,
                'last_completed_at' => is_string($lastCompleted) && $lastCompleted !== '' ? $lastCompleted : null,
                'recommendation' => $recommendation,
            ];
        } catch (Throwable) {
            return $empty + ['recommendation' => 'No fue posible comprobar la cola modular. Revise el diagnóstico técnico.'];
        }
    }
}
