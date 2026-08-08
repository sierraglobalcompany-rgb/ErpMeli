<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class AutomationCapacityService
{
    /** @return array<string,mixed> */
    public function report(): array
    {
        $settings = new AppSettingsService();
        $durations = $this->automaticDurations();
        sort($durations, SORT_NUMERIC);
        $p50Index = max(0, (int) ceil(count($durations) * 0.50) - 1);
        $p95Index = max(0, (int) ceil(count($durations) * 0.95) - 1);
        $pacing = (new ApiPacingService())->explain();
        $queue = (new WorkQueueProjectionService())->summary();
        $demand = $this->queueDemand();
        $api = $this->apiActivity();
        $effectiveRpm = max(1, (int) ($pacing['account_rpm'] ?? $pacing['ceiling_rpm'] ?? 1));
        $pendingCalls = max(0, (int) ($demand['estimated_api_calls'] ?? 0));
        $configuredSeconds = max(10, min(60, $settings->int('cron.max_runtime_seconds', 40)));
        $latestRun = $this->latestAutomaticRun();
        $p95Ms = (int) ($durations[$p95Index] ?? 0);

        return [
            'runtime' => [
                'configured_seconds' => $configuredSeconds,
                'accept_work_seconds' => max(1, min(59, $settings->int('cron.accept_work_until_seconds', 30))),
                'samples' => count($durations),
                'average_ms' => $durations === [] ? 0 : (int) round(array_sum($durations) / count($durations)),
                'p50_ms' => (int) ($durations[$p50Index] ?? 0),
                'p95_ms' => $p95Ms,
                'utilization_percent' => min(100, (int) round($p95Ms / max(1, $configuredSeconds * 1000) * 100)),
                'last_end_reason' => (string) ($latestRun['end_reason'] ?? ''),
            ],
            'pacing' => $pacing,
            'api' => $api,
            'queue' => $queue + $demand,
            'estimated_drain_minutes' => $pendingCalls > 0 ? (int) ceil($pendingCalls / $effectiveRpm) : 0,
            'recommendation' => $this->recommendation($durations, $api, $queue, $pacing),
        ];
    }

    /** @return list<int> */
    private function automaticDurations(): array
    {
        try {
            if (!(new SchemaInspectorService())->hasTable('cron_health_checks')) {
                return [];
            }
            $stmt = Database::connectionFresh()->query(
                'SELECT duration_ms
                 FROM cron_health_checks
                 WHERE execution_source IN ("scheduled_cli","notification_cli")
                   AND status="success" AND duration_ms IS NOT NULL
                 ORDER BY id DESC LIMIT 50'
            );
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string,mixed> */
    private function latestAutomaticRun(): array
    {
        try {
            $stmt = Database::connectionFresh()->query(
                'SELECT end_reason,result_state,duration_ms,finished_at
                 FROM cron_health_checks
                 WHERE execution_source IN ("scheduled_cli","notification_cli")
                 ORDER BY id DESC LIMIT 1'
            );
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string,int> */
    private function apiActivity(): array
    {
        try {
            if (!(new SchemaInspectorService())->hasTable('api_operation_metrics_hourly')) {
                return ['calls_24h' => 0, 'calls_1h' => 0, 'errors_24h' => 0, 'rate_limits_1h' => 0];
            }
            $row = Database::connectionFresh()->query(
                'SELECT
                    COALESCE(SUM(CASE WHEN bucket_started_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)
                                      THEN remote_count ELSE 0 END),0) calls_24h,
                    COALESCE(SUM(CASE WHEN bucket_started_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)
                                      THEN remote_count ELSE 0 END),0) calls_1h,
                    COALESCE(SUM(CASE WHEN bucket_started_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)
                                      THEN error_count ELSE 0 END),0) errors_24h
                 FROM api_operation_metrics_hourly
                 WHERE bucket_started_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)'
            )->fetch(PDO::FETCH_ASSOC) ?: [];
            $rateLimits = 0;
            if ((new SchemaInspectorService())->hasTable('api_request_logs')) {
                $rateLimits = (int) Database::connectionFresh()->query(
                    'SELECT COUNT(*) FROM api_request_logs
                     WHERE http_status=429 AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)'
                )->fetchColumn();
            }
            return [
                'calls_24h' => (int) ($row['calls_24h'] ?? 0),
                'calls_1h' => (int) ($row['calls_1h'] ?? 0),
                'errors_24h' => (int) ($row['errors_24h'] ?? 0),
                'rate_limits_1h' => $rateLimits,
            ];
        } catch (Throwable) {
            return ['calls_24h' => 0, 'calls_1h' => 0, 'errors_24h' => 0, 'rate_limits_1h' => 0];
        }
    }

    /** @return array<string,int> */
    private function queueDemand(): array
    {
        try {
            if (!(new SchemaInspectorService())->hasTable('system_work_queue_projection')) {
                return ['estimated_api_calls' => 0, 'api_jobs' => 0];
            }
            $row = Database::connectionFresh()->query(
                'SELECT
                    COALESCE(SUM(CASE WHEN is_api_task=1 THEN GREATEST(estimated_api_calls,1) ELSE 0 END),0)
                        estimated_api_calls,
                    COALESCE(SUM(is_api_task=1),0) api_jobs
                 FROM system_work_queue_projection
                 WHERE display_status IN ("pending","retry","scheduled","waiting_budget","running")'
            )->fetch(PDO::FETCH_ASSOC) ?: [];
            return [
                'estimated_api_calls' => (int) ($row['estimated_api_calls'] ?? 0),
                'api_jobs' => (int) ($row['api_jobs'] ?? 0),
            ];
        } catch (Throwable) {
            return ['estimated_api_calls' => 0, 'api_jobs' => 0];
        }
    }

    /** @param list<int> $durations @param array<string,int> $api @param array<string,mixed> $queue @param array<string,mixed> $pacing */
    private function recommendation(array $durations, array $api, array $queue, array $pacing): array
    {
        if ((int) ($api['rate_limits_1h'] ?? 0) > 0) {
            return ['tone' => 'danger', 'title' => 'Reducir ritmo', 'message' => 'Se detectaron respuestas 429 durante la última hora. Automatización ya redujo el ritmo efectivo.'];
        }
        if ($durations === []) {
            return ['tone' => 'warning', 'title' => 'Certificar CRON automático', 'message' => 'No hay muestras automáticas suficientes. Mantenga el techo conservador hasta completar dos ejecuciones CLI.'];
        }
        if ((int) ($queue['errors'] ?? 0) > 0) {
            return ['tone' => 'warning', 'title' => 'Corregir errores antes de acelerar', 'message' => 'La cola contiene errores activos; aumentar consultas no resolvería fallos locales o de permisos.'];
        }
        return [
            'tone' => 'success',
            'title' => 'Ritmo protegido',
            'message' => 'El techo es ' . (int) ($pacing['ceiling_rpm'] ?? 0) . ' consultas/minuto y el ERP aplica un ritmo menor cuando la evidencia lo exige.',
        ];
    }
}
