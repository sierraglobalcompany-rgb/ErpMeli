<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use PDO;
use Throwable;

final class ManualProcessingEngineService
{
    private const COMPONENT = 'process_manual_campaign';

    /** @return array<string,mixed> */
    public function status(): array
    {
        $command = (new CronHealthService())->recommendedCampaignCommand();
        $base = [
            'state' => 'not_configured',
            'label' => 'Motor no configurado',
            'message' => 'Configure el motor manual en Hostinger y ejecute una primera prueba.',
            'ready' => false,
            'command' => $command,
            'interval_label' => 'Cada minuto',
            'worker_heartbeat_at' => null,
            'heartbeat_age_seconds' => null,
            'last_worker_result' => null,
            'last_worker_message' => null,
            'last_check_at' => null,
            'last_check_result' => null,
            'last_check_message' => null,
            'release_version' => null,
            'release_build_id' => null,
            'certification_status' => null,
            'certified' => false,
            'conservative_ready' => false,
            'engine_mode' => 'unavailable',
            'probe_success_streak' => 0,
            'minimum_migration' => null,
            'migration_applied' => null,
            'primary_action' => 'check',
        ];

        try {
            $integrity = (new ReleaseIntegrityService())->inspect(true);
            $minimumMigration = trim((string) ($integrity['minimum_migration'] ?? ''));
            $migrationApplied = $integrity['schema']['migration_applied'] ?? null;
            $component = $integrity['components'][self::COMPONENT] ?? null;
            $base['minimum_migration'] = $minimumMigration !== '' ? $minimumMigration : null;
            $base['migration_applied'] = $migrationApplied;

            if ($minimumMigration === '' || $migrationApplied !== true) {
                $databaseUnavailable = $migrationApplied === null;
                return array_merge($base, [
                    'state' => $databaseUnavailable ? 'installation_incomplete' : 'update_required',
                    'label' => $databaseUnavailable ? 'No se pudo comprobar la instalación' : 'Actualización pendiente',
                    'message' => $databaseUnavailable
                        ? 'No fue posible comprobar la base de datos. El motor no iniciará consultas.'
                        : 'Los archivos están instalados, pero falta actualizar la base de datos. El motor se detuvo sin consultar Mercado Libre.',
                    'primary_action' => $databaseUnavailable ? 'diagnostics' : 'update',
                ]);
            }
            if (
                !is_array($component)
                || empty($component['exists'])
                || empty($component['matches'])
            ) {
                return array_merge($base, [
                    'state' => 'installation_incomplete',
                    'label' => 'Instalación incompleta',
                    'message' => 'El archivo del motor no coincide con esta versión. No se iniciaron consultas.',
                    'primary_action' => 'diagnostics',
                ]);
            }

            $schema = new SchemaInspectorService();
            if (!$schema->hasTable('manual_processing_engine_health')) {
                return array_merge($base, [
                    'state' => 'migration_required',
                    'label' => 'Actualización pendiente',
                    'message' => 'Complete la actualización antes de configurar el motor de campañas.',
                    'primary_action' => 'update',
                ]);
            }

            $stmt = Database::connectionFresh()->prepare(
                'SELECT *,UNIX_TIMESTAMP(UTC_TIMESTAMP())-UNIX_TIMESTAMP(worker_heartbeat_at) heartbeat_age_seconds
                 FROM manual_processing_engine_health WHERE component=? LIMIT 1'
            );
            $stmt->execute([self::COMPONENT]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return array_merge($base, [
                    'state' => 'waiting_first_run',
                    'label' => 'Esperando primera señal',
                    'message' => 'La instalación está completa. Falta la primera ejecución del lanzador PHP.',
                    'primary_action' => 'check',
                ]);
            }

            $age = $row['heartbeat_age_seconds'] !== null
                ? max(0, (int) $row['heartbeat_age_seconds'])
                : null;
            $staleSeconds = max(
                60,
                min(900, (new AppSettingsService())->int('manual_processing.worker_stale_seconds', 180))
            );
            $result = (string) ($row['last_worker_result'] ?? '');
            $certified = (string) ($row['certification_status'] ?? '') === 'certified';
            $state = 'not_configured';
            $label = 'Esperando primera ejecución';
            $message = 'El archivo está instalado, pero todavía no hay una señal CLI del motor manual.';
            $ready = false;
            $primaryAction = 'check';

            if ($age !== null && $age <= $staleSeconds) {
                if ($result === 'error') {
                    $state = 'error';
                    $label = 'Motor con error';
                    $message = (string) ($row['last_worker_message'] ?: 'La última ejecución del motor terminó con error.');
                    $primaryAction = 'diagnostics';
                } else {
                    $state = $certified ? 'certified' : 'ready_conservative';
                    $label = $certified ? 'Motor certificado' : 'Listo en modo conservador';
                    $message = $certified
                        ? 'El lanzador PHP está activo y puede utilizar la ventana completa certificada.'
                        : 'El lanzador PHP está activo. Puede comenzar ahora con una ventana conservadora.';
                    $ready = true;
                    $primaryAction = 'campaign';
                }
            } elseif ($age !== null) {
                $state = 'stale';
                $label = 'Ejecución atrasada';
                $message = 'El motor dejó de enviar señales. Revise la tarea de Hostinger antes de comenzar.';
                $primaryAction = 'check';
            }

            return array_merge($base, $row, [
                'state' => $state,
                'label' => $label,
                'message' => $message,
                'ready' => $ready,
                'heartbeat_age_seconds' => $age,
                'certified' => $certified,
                'conservative_ready' => $ready && !$certified,
                'engine_mode' => $ready ? ($certified ? 'certified' : 'conservative') : 'unavailable',
                'primary_action' => $primaryAction,
            ]);
        } catch (Throwable) {
            return array_merge($base, [
                'state' => 'error',
                'label' => 'No se pudo comprobar',
                'message' => 'No fue posible comprobar el motor manual. Abra Diagnóstico antes de continuar.',
            ]);
        }
    }

    /** @return array<string,mixed> */
    public function check(): array
    {
        $checks = [
            'release_manifest' => false,
            'worker_file' => is_file(AppPaths::releaseRoot() . '/jobs/process_manual_campaign.php'),
            'probe_file' => is_file(AppPaths::releaseRoot() . '/jobs/manual_engine_probe.php'),
            'storage' => is_dir(AppPaths::storage()) && is_writable(AppPaths::storage()),
            'database' => false,
            'schema' => false,
            'migration' => false,
        ];

        try {
            $integrity = (new ReleaseIntegrityService())->inspect(true);
            $component = $integrity['components'][self::COMPONENT] ?? null;
            $checks['release_manifest'] = is_array($component)
                && !empty($component['exists'])
                && !empty($component['matches']);
            $checks['database'] = (int) Database::connectionFresh()->query('SELECT 1')->fetchColumn() === 1;
            $schema = new SchemaInspectorService();
            $checks['schema'] = $schema->hasTable('manual_processing_sessions')
                && $schema->hasTable('manual_processing_items')
                && $schema->hasTable('manual_processing_engine_health')
                && $schema->hasTable('manual_engine_probe_runs')
                && $schema->hasTable('manual_campaign_adapter_health');
            $checks['migration'] = ($integrity['schema']['migration_applied'] ?? null) === true;
        } catch (Throwable) {
            // El resultado humano de abajo conserva la prueba como fallida.
        }

        $localReady = !in_array(false, $checks, true);
        $status = $this->status();
        $result = $localReady ? 'ok' : 'error';
        $message = !$localReady
            ? 'La instalación local del motor necesita revisión.'
            : (!empty($status['ready'])
                ? 'Instalación correcta y señal CLI reciente.'
                : 'Instalación correcta. Falta ejecutar el worker desde Hostinger.');

        try {
            if ((new SchemaInspectorService())->hasTable('manual_processing_engine_health')) {
                $stmt = Database::connectionFresh()->prepare(
                    'INSERT INTO manual_processing_engine_health
                     (component,last_check_at,last_check_result,last_check_message)
                     VALUES (?,UTC_TIMESTAMP(),?,?)
                     ON DUPLICATE KEY UPDATE last_check_at=VALUES(last_check_at),
                       last_check_result=VALUES(last_check_result),last_check_message=VALUES(last_check_message)'
                );
                $stmt->execute([self::COMPONENT, $result, $message]);
            }
        } catch (Throwable) {
            // La comprobación no debe romper Configuración si el esquema está incompleto.
        }

        return [
            'ok' => $localReady && !empty($status['ready']),
            'installation_ready' => $localReady,
            'worker_ready' => !empty($status['ready']),
            'checks' => $checks,
            'message' => $message,
            'status' => $this->status(),
        ];
    }

    public function heartbeat(
        string $result,
        string $message,
        string $component = self::COMPONENT
    ): void
    {
        try {
            if (!(new SchemaInspectorService())->hasTable('manual_processing_engine_health')) {
                return;
            }
            $identity = (new ReleaseIntegrityService())->identity($component);
            $stmt = Database::connectionFresh()->prepare(
                'INSERT INTO manual_processing_engine_health
                 (component,worker_heartbeat_at,last_worker_result,last_worker_message,release_version,release_build_id)
                 VALUES (?,UTC_TIMESTAMP(),?,?,?,?)
                 ON DUPLICATE KEY UPDATE worker_heartbeat_at=VALUES(worker_heartbeat_at),
                   last_worker_result=VALUES(last_worker_result),last_worker_message=VALUES(last_worker_message),
                   release_version=VALUES(release_version),release_build_id=VALUES(release_build_id)'
            );
            $stmt->execute([
                mb_substr($component, 0, 80),
                mb_substr($result, 0, 40),
                mb_substr(Logger::redactString($message), 0, 500),
                mb_substr((string) $identity['version'], 0, 30),
                mb_substr((string) $identity['build_id'], 0, 100),
            ]);
        } catch (Throwable) {
            // El heartbeat de diagnóstico nunca reemplaza el resultado real del worker.
        }
    }

    public function recordProbeSuccess(int $runId, int $windowSeconds): void
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT probe_success_streak,certification_status FROM manual_processing_engine_health
                 WHERE component=? LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([self::COMPONENT]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $streak = min(3, max(0, (int) ($current['probe_success_streak'] ?? 0)) + 1);
            // Una prueba posterior nunca revoca una certificación aprobada.
            $certified = (string) ($current['certification_status'] ?? '') === 'certified'
                || ($streak >= 3 && $windowSeconds >= 55);
            $pdo->prepare(
                'INSERT INTO manual_processing_engine_health
                 (component,certification_status,certified_at,observed_interval_seconds,
                  stable_window_seconds,probe_success_streak,last_probe_run_id,last_check_at,last_check_result,last_check_message)
                 VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(),"ok",?)
                 ON DUPLICATE KEY UPDATE
                   certification_status=VALUES(certification_status),
                   certified_at=IF(VALUES(certification_status)="certified",UTC_TIMESTAMP(),certified_at),
                   observed_interval_seconds=VALUES(observed_interval_seconds),
                   stable_window_seconds=GREATEST(COALESCE(stable_window_seconds,0),VALUES(stable_window_seconds)),
                   probe_success_streak=VALUES(probe_success_streak),
                   last_probe_run_id=VALUES(last_probe_run_id),
                   last_check_at=VALUES(last_check_at),last_check_result=VALUES(last_check_result),
                   last_check_message=VALUES(last_check_message)'
            )->execute([
                self::COMPONENT,
                $certified ? 'certified' : 'testing',
                $certified ? gmdate('Y-m-d H:i:s') : null,
                60,
                $windowSeconds,
                $streak,
                $runId,
                $certified
                    ? 'Motor certificado. Puede atender campañas durante aproximadamente 54 segundos de cada minuto.'
                    : 'Prueba ' . $streak . ' de 3 completada. Continúe hasta certificar las ventanas de 10, 30 y 55 segundos.',
            ]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public function recordProbeFailure(int $runId, string $message): void
    {
        try {
            Database::connectionFresh()->prepare(
                'INSERT INTO manual_processing_engine_health
                 (component,certification_status,probe_success_streak,last_probe_run_id,last_check_at,last_check_result,last_check_message)
                 VALUES (?,"failed",0,?,UTC_TIMESTAMP(),"error",?)
                 ON DUPLICATE KEY UPDATE certification_status="failed",probe_success_streak=0,
                   last_probe_run_id=VALUES(last_probe_run_id),last_check_at=VALUES(last_check_at),
                   last_check_result=VALUES(last_check_result),last_check_message=VALUES(last_check_message)'
            )->execute([self::COMPONENT, $runId ?: null, mb_substr(Logger::redactString($message), 0, 500)]);
        } catch (Throwable) {
        }
    }
}
