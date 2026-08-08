<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class CronV3OperationalReadService
{
    private const REQUIRED_TABLES = [
        'cron_v3_work',
        'cron_v3_attempts',
        'cron_v3_queue_ownership',
        'cron_v3_rate_buckets',
        'cron_v3_circuit_states',
        'cron_v3_snapshots',
    ];

    private const LANES = ['local', 'remote'];

    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    /** @return array<string,mixed> */
    public function overview(): array
    {
        try {
            $pdo = $this->pdo ?? Database::connectionFresh();
            $missingTables = $this->missingTables($pdo);
            if ($missingTables !== []) {
                return $this->unavailable('El esquema de Cron V3 todavía no está instalado por completo.');
            }

            $lanes = $this->emptyLanes();
            $this->readWork($pdo, $lanes);
            $this->readAttempts($pdo, $lanes);
            $this->readRemoteCooldowns($pdo, $lanes);
            $snapshots = $this->readRunSnapshots($pdo);
            $enabled = Env::bool('CRON_V3_ENABLED', false);

            foreach (self::LANES as $lane) {
                $lanes[$lane]['oldest_age_label'] = $this->ageLabel($lanes[$lane]['oldest_age_seconds']);
                $snapshot = $snapshots[$lane] ?? null;
                $lanes[$lane]['snapshot_state'] = $snapshot === null ? 'missing' : 'available';
                $lanes[$lane]['last_signal_at'] = $snapshot['observed_at'] ?? null;
                $lanes[$lane]['last_signal_age_seconds'] = $snapshot['age_seconds'] ?? null;
                $lanes[$lane]['last_signal_label'] = $snapshot === null
                    ? 'Sin señal de ejecución'
                    : $this->ageLabel((int) $snapshot['age_seconds']);
            }

            $totals = $this->totals($lanes);
            if (!$enabled) {
                return [
                    'ok' => true,
                    'available' => true,
                    'enabled' => false,
                    'state' => 'disabled',
                    'state_label' => 'Cron V3 deshabilitado',
                    'state_message' => 'El panel conserva lectura de la cola, pero los lanzadores V3 no están habilitados.',
                    'lanes' => $lanes,
                    'totals' => $totals,
                    'observed_at' => gmdate('Y-m-d H:i:s'),
                ];
            }

            if (!isset($snapshots['local'], $snapshots['remote'])) {
                return [
                    'ok' => false,
                    'available' => false,
                    'enabled' => true,
                    'state' => 'unavailable',
                    'state_label' => 'Cron V3 sin señal completa',
                    'state_message' => 'Falta el snapshot de uno o ambos lanzadores; no se puede declarar saludable.',
                    'lanes' => $lanes,
                    'totals' => $totals,
                    'observed_at' => gmdate('Y-m-d H:i:s'),
                ];
            }

            $maxSignalAge = max(
                (int) $snapshots['local']['age_seconds'],
                (int) $snapshots['remote']['age_seconds'],
            );
            if ($maxSignalAge > 180) {
                $state = 'stale';
                $label = 'Cron V3 con señal vencida';
                $message = 'Algún lanzador lleva más de tres minutos sin publicar snapshot.';
            } elseif ($totals['review'] > 0 || $totals['dead'] > 0 || $totals['expired_leases'] > 0 || $lanes['local']['http_last_hour'] > 0) {
                $state = 'attention';
                $label = 'Cron V3 requiere atención';
                $message = 'Hay revisión, trabajo muerto, leases vencidos o tráfico HTTP atribuido al carril local.';
            } else {
                $state = 'healthy';
                $label = 'Cron V3 operativo';
                $message = 'Ambos lanzadores tienen señal reciente y no se observan condiciones críticas.';
            }

            return [
                'ok' => true,
                'available' => true,
                'enabled' => true,
                'state' => $state,
                'state_label' => $label,
                'state_message' => $message,
                'lanes' => $lanes,
                'totals' => $totals,
                'observed_at' => gmdate('Y-m-d H:i:s'),
            ];
        } catch (Throwable) {
            return $this->unavailable('No se pudo leer Cron V3. No se modificó ninguna cola.');
        }
    }

    /** @return list<string> */
    private function missingTables(PDO $pdo): array
    {
        $missing = [];
        foreach (self::REQUIRED_TABLES as $table) {
            try {
                $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
            } catch (Throwable) {
                $missing[] = $table;
            }
        }

        return $missing;
    }

    /** @param array<string,array<string,mixed>> $lanes */
    private function readWork(PDO $pdo, array &$lanes): void
    {
        $rows = $pdo->query(
            "SELECT lane,status,COUNT(*) AS total,
                    SUM(CASE WHEN status IN ('deferred','waiting_rate','waiting_budget','waiting_api') AND available_at>UTC_TIMESTAMP(3) THEN 1 ELSE 0 END) AS cooling_down,
                    SUM(CASE WHEN status='leased' AND lease_until<UTC_TIMESTAMP(3) THEN 1 ELSE 0 END) AS expired_leases,
                    MIN(CASE WHEN status IN ('ready','leased','deferred','waiting_rate','waiting_budget','waiting_api') THEN created_at ELSE NULL END) AS oldest_active_at
             FROM cron_v3_work
             GROUP BY lane,status"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $lane = (string) ($row['lane'] ?? '');
            $status = (string) ($row['status'] ?? '');
            if (!isset($lanes[$lane]) || !array_key_exists($status, $lanes[$lane])) {
                continue;
            }
            $lanes[$lane][$status] = (int) ($row['total'] ?? 0);
            if (in_array($status, ['waiting_capability', 'waiting_identity', 'review'], true)) {
                $lanes[$lane]['parked'] += (int) ($row['total'] ?? 0);
            }
            $lanes[$lane]['cooldown'] += (int) ($row['cooling_down'] ?? 0);
            $lanes[$lane]['expired_leases'] += (int) ($row['expired_leases'] ?? 0);
            $age = $this->ageSeconds($row['oldest_active_at'] ?? null);
            if ($age !== null && ($lanes[$lane]['oldest_age_seconds'] === null || $age > $lanes[$lane]['oldest_age_seconds'])) {
                $lanes[$lane]['oldest_age_seconds'] = $age;
            }
        }
    }

    /** @param array<string,array<string,mixed>> $lanes */
    private function readAttempts(PDO $pdo, array &$lanes): void
    {
        $rows = $pdo->query(
            "SELECT lane,
                    SUM(CASE WHEN outcome='completed' AND finished_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 HOUR) THEN 1 ELSE 0 END) AS throughput,
                    SUM(CASE WHEN started_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 HOUR) THEN physical_http_calls ELSE 0 END) AS http_calls
             FROM cron_v3_attempts
             GROUP BY lane"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $lane = (string) ($row['lane'] ?? '');
            if (!isset($lanes[$lane])) {
                continue;
            }
            $lanes[$lane]['throughput_last_hour'] = (int) ($row['throughput'] ?? 0);
            $lanes[$lane]['http_last_hour'] = (int) ($row['http_calls'] ?? 0);
        }
    }

    /** @param array<string,array<string,mixed>> $lanes */
    private function readRemoteCooldowns(PDO $pdo, array &$lanes): void
    {
        $rate = (int) $pdo->query(
            'SELECT COUNT(*) FROM cron_v3_rate_buckets WHERE blocked_until>UTC_TIMESTAMP(3)'
        )->fetchColumn();
        $circuits = (int) $pdo->query(
            "SELECT COUNT(*) FROM cron_v3_circuit_states
             WHERE state IN ('open','half_open') AND (open_until IS NULL OR open_until>UTC_TIMESTAMP(3))"
        )->fetchColumn();
        $lanes['remote']['cooldown_scopes'] = $rate + $circuits;
        $lanes['remote']['cooldown'] += $rate + $circuits;
    }

    /** @return array<string,array{observed_at:string,age_seconds:int}> */
    private function readRunSnapshots(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT lane,observed_at FROM cron_v3_snapshots
             WHERE snapshot_key IN ('run:local','run:remote')"
        )->fetchAll(PDO::FETCH_ASSOC);
        $snapshots = [];
        foreach ($rows as $row) {
            $lane = (string) ($row['lane'] ?? '');
            $observedAt = (string) ($row['observed_at'] ?? '');
            $age = $this->ageSeconds($observedAt);
            if (!in_array($lane, self::LANES, true) || $observedAt === '' || $age === null) {
                continue;
            }
            $snapshots[$lane] = ['observed_at' => $observedAt, 'age_seconds' => $age];
        }

        return $snapshots;
    }

    /** @return array<string,array<string,mixed>> */
    private function emptyLanes(): array
    {
        $row = [
            'ready' => 0,
            'leased' => 0,
            'deferred' => 0,
            'waiting_capability' => 0,
            'waiting_identity' => 0,
            'waiting_rate' => 0,
            'waiting_budget' => 0,
            'waiting_api' => 0,
            'parked' => 0,
            'cooldown' => 0,
            'cooldown_scopes' => 0,
            'review' => 0,
            'dead' => 0,
            'expired_leases' => 0,
            'oldest_age_seconds' => null,
            'oldest_age_label' => 'Sin trabajo activo',
            'throughput_last_hour' => 0,
            'http_last_hour' => 0,
            'snapshot_state' => 'missing',
            'last_signal_at' => null,
            'last_signal_age_seconds' => null,
            'last_signal_label' => 'Sin señal de ejecución',
        ];

        return ['local' => $row, 'remote' => $row];
    }

    /** @param array<string,array<string,mixed>> $lanes @return array<string,int> */
    private function totals(array $lanes): array
    {
        $totals = [];
        foreach ([
            'ready', 'leased', 'deferred', 'waiting_capability', 'waiting_identity',
            'waiting_rate', 'waiting_budget', 'waiting_api', 'parked', 'cooldown',
            'review', 'dead', 'expired_leases', 'throughput_last_hour', 'http_last_hour',
        ] as $key) {
            $totals[$key] = (int) $lanes['local'][$key] + (int) $lanes['remote'][$key];
        }

        return $totals;
    }

    private function ageSeconds(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $then = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            return max(0, $now->getTimestamp() - $then->getTimestamp());
        } catch (Throwable) {
            return null;
        }
    }

    private function ageLabel(mixed $seconds): string
    {
        if (!is_int($seconds)) {
            return 'Sin trabajo activo';
        }
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' min';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . ' h';
        }

        return intdiv($seconds, 86400) . ' d';
    }

    /** @return array<string,mixed> */
    private function unavailable(string $message): array
    {
        return [
            'ok' => false,
            'available' => false,
            'enabled' => Env::bool('CRON_V3_ENABLED', false),
            'state' => 'unavailable',
            'state_label' => 'Cron V3 no disponible',
            'state_message' => $message,
            'lanes' => $this->emptyLanes(),
            'totals' => [
                'ready' => 0,
                'leased' => 0,
                'deferred' => 0,
                'waiting_capability' => 0,
                'waiting_identity' => 0,
                'waiting_rate' => 0,
                'waiting_budget' => 0,
                'waiting_api' => 0,
                'parked' => 0,
                'cooldown' => 0,
                'review' => 0,
                'dead' => 0,
                'expired_leases' => 0,
                'throughput_last_hour' => 0,
                'http_last_hour' => 0,
            ],
            'observed_at' => gmdate('Y-m-d H:i:s'),
        ];
    }
}
