<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

final class CronV3RuntimeStatusService
{
    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $pdo = $this->connection();
        $v2 = $this->v2Signal($pdo);
        $v3 = (new CronV3OperationalReadService($pdo))->overview();
        $cutover = (new CronV3OperationalCutoverService($pdo))->snapshot();
        $mode = (new CronV3OperationalModeService($pdo))->snapshot();
        $blocking = [];
        if (Env::bool('ML_WRITE_ENABLED', false)) {
            $blocking[] = 'ML_WRITE_ENABLED está activo; V3 operativo no puede correr.';
        }
        if (!Env::bool('CRON_V3_ENABLED', false)) {
            $blocking[] = 'CRON_V3_ENABLED está apagado.';
        }
        if (Env::bool('CRON_V3_SHADOW_ENABLED', false)) {
            $blocking[] = 'CRON_V3_SHADOW_ENABLED sigue activo; quite Shadow para operación real.';
        }

        $localFresh = $this->laneFresh($v3, 'local');
        $remoteFresh = $this->laneFresh($v3, 'remote');
        $v2Fresh = (int) ($v2['age_seconds'] ?? 999999) <= 180;
        $state = 'operational';
        $label = 'V3 operativo';
        if (!$mode['operational_mode']) {
            $state = 'not_operational';
            $label = 'V3 operativo no activado';
        } elseif ($blocking !== []) {
            $state = 'blocked';
            $label = 'V3 bloqueado';
        } elseif (!$localFresh || !$remoteFresh) {
            $state = $v2Fresh ? 'hostinger_still_v2' : 'v3_no_signal';
            $label = $v2Fresh ? 'Hostinger todavía llama V2' : 'Hostinger no está llamando V3';
        } elseif (($cutover['state'] ?? '') !== 'operational_active') {
            $state = 'cutover_incomplete';
            $label = 'Corte V3 incompleto';
        }

        return [
            'ok' => $blocking === [] && $state === 'operational',
            'read_only' => true,
            'state' => $state,
            'state_label' => $label,
            'blocking' => array_merge($blocking, array_values((array) ($cutover['blocking'] ?? []))),
            'mode' => $mode,
            'v2' => $v2,
            'v3' => $v3,
            'cutover' => $cutover,
            'commands' => $this->commands(),
            'observed_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string,mixed> */
    private function v2Signal(PDO $pdo): array
    {
        try {
            $row = $pdo->query(
                "SELECT observed_at,payload_json,generation
                 FROM cron_v3_snapshots
                 WHERE snapshot_key='v2-process-sync-signal'
                 LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $row = $pdo->query(
                    "SELECT COALESCE(finished_at,started_at,created_at) AS observed_at,
                            COALESCE(payload_json,'{}') AS payload_json,
                            id AS generation
                     FROM cron_health_checks
                     WHERE job_name='process_sync_queue'
                     ORDER BY id DESC LIMIT 1"
                )->fetch(PDO::FETCH_ASSOC);
            }
            if (!is_array($row)) {
                return ['available' => false, 'fresh' => false, 'label' => 'Sin señal V2'];
            }
            $age = $this->ageSeconds($row['observed_at'] ?? null);
            return [
                'available' => true,
                'fresh' => $age !== null && $age <= 180,
                'observed_at' => $row['observed_at'] ?? null,
                'age_seconds' => $age,
                'label' => $age === null ? 'Sin señal V2' : $this->ageLabel($age),
                'generation' => (int) ($row['generation'] ?? 0),
            ];
        } catch (Throwable) {
            return ['available' => false, 'fresh' => false, 'label' => 'No se pudo comprobar V2'];
        }
    }

    /** @param array<string,mixed> $v3 */
    private function laneFresh(array $v3, string $lane): bool
    {
        $age = $v3['lanes'][$lane]['last_signal_age_seconds'] ?? null;
        return is_int($age) && $age <= 180;
    }

    /** @return array<string,string> */
    private function commands(): array
    {
        return [];
    }

    private function ageSeconds(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $timestamp = strtotime($value . ' UTC');
        return $timestamp === false ? null : max(0, time() - $timestamp);
    }

    private function ageLabel(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' min';
        }
        return intdiv($seconds, 3600) . ' h';
    }

    private function connection(): PDO
    {
        return $this->pdo ?? Database::connectionFresh();
    }
}
