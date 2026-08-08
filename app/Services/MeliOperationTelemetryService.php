<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class MeliOperationTelemetryService
{
    /** @var array<string,array<string,array<string,mixed>>> */
    private static array $summaryCache = [];
    /** @var array<string,array<string,array{p95_duration_ms:int,p95_decoded_bytes:int,samples:int}>> */
    private static array $percentileCache = [];

    /** @param array<string,mixed> $profile */
    public function record(
        ?int $accountId,
        string $requestId,
        array $profile,
        int $durationMs,
        int $wireBytes,
        int $decodedBytes,
        ?int $httpStatus,
        bool $reachedRemote,
        array $meta = []
    ): void {
        $responseItemCount = array_key_exists('response_item_count', $meta)
            ? max(0, (int) $meta['response_item_count'])
            : 0;
        $responseCountState = in_array(($meta['response_count_state'] ?? ''), ['complete', 'partial', 'unknown'], true)
            ? (string) $meta['response_count_state']
            : 'unknown';
        $responseResourceUnit = isset($meta['response_resource_unit'])
            ? mb_substr((string) $meta['response_resource_unit'], 0, 40)
            : null;
        if ($reachedRemote && $httpStatus !== null) {
            ApiExecutionMetadataContext::markRemoteResponseKnown(
                $responseItemCount
            );
        }
        try {
            $pdo = Database::connectionFresh();
            $operation = (string) ($profile['key'] ?? 'unknown_read');
            $pdo->prepare(
                'UPDATE api_request_logs
                 SET operation_key=?,load_class=?,wire_bytes=?,decoded_bytes=?,workload_units=?,
                     response_item_count=?,response_count_state=?,response_resource_unit=?,fanout_count=?
                 WHERE request_id=?'
            )->execute([
                $operation,
                (string) ($profile['load_class'] ?? 'heavy'),
                max(0, $wireBytes),
                max(0, $decodedBytes),
                max(0, (int) ($profile['workload_units'] ?? 0)),
                $responseItemCount,
                $responseCountState,
                $responseResourceUnit,
                max(0, (int) ($meta['fanout_count'] ?? 0)),
                $requestId,
            ]);
            $pdo->prepare(
                'INSERT INTO api_operation_metrics_hourly
                 (bucket_started_at,account_scope_key,meli_account_id,operation_key,load_class,sample_count,remote_count,
                   success_count,error_count,total_duration_ms,max_duration_ms,total_wire_bytes,
                   total_decoded_bytes,total_items,total_fanout,updated_at)
                 VALUES (DATE_FORMAT(UTC_TIMESTAMP(),"%Y-%m-%d %H:00:00"),?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                  sample_count=sample_count+1,remote_count=remote_count+VALUES(remote_count),
                  success_count=success_count+VALUES(success_count),error_count=error_count+VALUES(error_count),
                  total_duration_ms=total_duration_ms+VALUES(total_duration_ms),
                  max_duration_ms=GREATEST(max_duration_ms,VALUES(max_duration_ms)),
                  total_wire_bytes=total_wire_bytes+VALUES(total_wire_bytes),
                  total_decoded_bytes=total_decoded_bytes+VALUES(total_decoded_bytes),
                  total_items=total_items+VALUES(total_items),total_fanout=total_fanout+VALUES(total_fanout),
                  updated_at=UTC_TIMESTAMP()'
            )->execute([
                $accountId ?: 0,
                $accountId ?: null,
                $operation,
                (string) ($profile['load_class'] ?? 'heavy'),
                1,
                $reachedRemote ? 1 : 0,
                $reachedRemote && $httpStatus !== null && $httpStatus >= 200 && $httpStatus < 300 ? 1 : 0,
                $httpStatus === null || $httpStatus < 200 || $httpStatus >= 300 ? 1 : 0,
                max(0, $durationMs),
                max(0, $durationMs),
                max(0, $wireBytes),
                max(0, $decodedBytes),
                $responseItemCount,
                max(0, (int) ($meta['fanout_count'] ?? 0)),
            ]);
            if ((new SchemaInspectorService())->hasTable('api_operation_metric_samples')) {
                $pdo->prepare(
                    'INSERT INTO api_operation_metric_samples
                     (bucket_started_at,account_scope_key,meli_account_id,operation_key,load_class,duration_ms,
                      wire_bytes,decoded_bytes,response_item_count,fanout_count,http_status,reached_remote,successful)
                     VALUES (DATE_FORMAT(UTC_TIMESTAMP(),"%Y-%m-%d %H:00:00"),?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $accountId ?: 0,
                    $accountId ?: null,
                    $operation,
                    (string) ($profile['load_class'] ?? 'heavy'),
                    max(0, $durationMs),
                    max(0, $wireBytes),
                    max(0, $decodedBytes),
                    $responseItemCount,
                    max(0, (int) ($meta['fanout_count'] ?? 0)),
                    $httpStatus,
                    $reachedRemote ? 1 : 0,
                    $reachedRemote && $httpStatus !== null && $httpStatus >= 200 && $httpStatus < 300 ? 1 : 0,
                ]);
            }
            self::$summaryCache = [];
            self::$percentileCache = [];
        } catch (Throwable) {
            // La telemetría nunca puede alterar el resultado de una consulta.
        }
    }

    /** @return array<string,array<string,mixed>> */
    public function summaries(int $hours = 24, ?int $accountId = null): array
    {
        $hours = max(1, min(720, $hours));
        $cacheKey = $hours . ':' . ($accountId !== null ? max(0, $accountId) : '*');
        if (isset(self::$summaryCache[$cacheKey])) {
            return self::$summaryCache[$cacheKey];
        }
        $result = [];
        try {
            $whereAccount = $accountId !== null ? ' AND account_scope_key=?' : '';
            $stmt = Database::connectionFresh()->prepare(
                'SELECT operation_key,load_class,SUM(sample_count) samples,SUM(remote_count) remote_count,
                        SUM(success_count) success_count,SUM(error_count) error_count,
                        GREATEST(
                          SUM(error_count) - GREATEST(SUM(remote_count)-SUM(success_count),0),
                          0
                        ) local_failure_count,
                        ROUND(SUM(total_duration_ms)/GREATEST(SUM(sample_count),1)) avg_duration_ms,
                        MAX(max_duration_ms) max_duration_ms,
                        ROUND(SUM(total_wire_bytes)/GREATEST(SUM(remote_count),1)) avg_wire_bytes,
                        ROUND(SUM(total_decoded_bytes)/GREATEST(SUM(remote_count),1)) avg_decoded_bytes,
                        SUM(total_items) total_items,SUM(total_fanout) total_fanout
                 FROM api_operation_metrics_hourly
                 WHERE bucket_started_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $hours . ' HOUR)
                 ' . $whereAccount . '
                 GROUP BY operation_key,load_class ORDER BY samples DESC,operation_key'
            );
            $stmt->execute($accountId !== null ? [max(0, $accountId)] : []);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[(string) $row['operation_key']] = $row;
            }
        } catch (Throwable) {
        }
        return self::$summaryCache[$cacheKey] = $result;
    }

    /**
     * Percentiles observados sin conservar cuerpos de respuesta.
     *
     * @return array<string,array{p95_duration_ms:int,p95_decoded_bytes:int,samples:int}>
     */
    public function percentiles(int $hours = 168, ?int $accountId = null): array
    {
        $hours = max(1, min(720, $hours));
        $cacheKey = $hours . ':' . ($accountId !== null ? max(0, $accountId) : '*');
        if (isset(self::$percentileCache[$cacheKey])) {
            return self::$percentileCache[$cacheKey];
        }
        if (!(new SchemaInspectorService())->hasTable('api_operation_metric_samples')) {
            return [];
        }
        $whereAccount = $accountId !== null ? ' AND account_scope_key=?' : '';
        $stmt = Database::connectionFresh()->prepare(
            'SELECT operation_key,duration_ms,decoded_bytes
             FROM (
               SELECT id,operation_key,duration_ms,decoded_bytes,
                      ROW_NUMBER() OVER (PARTITION BY operation_key ORDER BY id DESC) sample_rank
               FROM api_operation_metric_samples
               WHERE created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $hours . ' HOUR)
                 AND reached_remote=1
                 ' . $whereAccount . '
             ) recent_samples
             WHERE sample_rank<=1000
             ORDER BY operation_key,id'
        );
        $stmt->execute($accountId !== null ? [max(0, $accountId)] : []);
        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groups[(string) $row['operation_key']][] = [
                'duration' => (int) $row['duration_ms'],
                'bytes' => (int) $row['decoded_bytes'],
            ];
        }
        $result = [];
        foreach ($groups as $operation => $rows) {
            $durations = array_column($rows, 'duration');
            $bytes = array_column($rows, 'bytes');
            sort($durations, SORT_NUMERIC);
            sort($bytes, SORT_NUMERIC);
            $index = max(0, (int) ceil(count($rows) * 0.95) - 1);
            $result[$operation] = [
                'p95_duration_ms' => (int) ($durations[$index] ?? 0),
                'p95_decoded_bytes' => (int) ($bytes[$index] ?? 0),
                'samples' => count($rows),
            ];
        }
        return self::$percentileCache[$cacheKey] = $result;
    }

    /** @return array<string,mixed> */
    public function observationStatus(): array
    {
        $settings = new AppSettingsService();
        $started = trim((string) $settings->get('api.workload.observation_started_at', ''));
        $required = max(1, $settings->int('api.workload.observation_hours', 24));
        $elapsed = $started !== '' && strtotime($started) !== false ? max(0, time() - (int) strtotime($started)) : 0;
        $profiles = (new MeliOperationProfileRegistry())->all();
        $metrics = $this->summaries(max(24, $required));
        $measured = 0;
        foreach ($profiles as $key => $profile) {
            if (!$profile['uses_api']) {
                continue;
            }
            if ((int) ($metrics[$key]['remote_count'] ?? 0) >= (int) $profile['sample_threshold']) {
                $measured++;
            }
        }
        $totalRemote = count(array_filter($profiles, static fn (array $profile): bool => !empty($profile['uses_api'])));
        return [
            'started_at' => $started ?: null,
            'required_hours' => $required,
            'elapsed_hours' => round($elapsed / 3600, 1),
            'remaining_hours' => round(max(0, ($required * 3600) - $elapsed) / 3600, 1),
            'time_complete' => $elapsed >= $required * 3600,
            'measured_profiles' => $measured,
            'total_profiles' => $totalRemote,
            'ready' => $elapsed >= $required * 3600 && $measured >= 1,
            'metrics' => $metrics,
        ];
    }

    /**
     * @param list<string> $operationKeys
     * @return array{ready:bool,verified:list<string>,pending:list<string>}
     */
    public function profileReadiness(array $operationKeys, ?int $accountId = null): array
    {
        $profiles = (new MeliOperationProfileRegistry())->all();
        $metrics = $this->summaries(168, $accountId);
        $verified = [];
        $pending = [];
        foreach (array_values(array_unique($operationKeys)) as $key) {
            $profile = $profiles[$key] ?? null;
            if (!is_array($profile) || empty($profile['uses_api'])) {
                continue;
            }
            $samples = (int) ($metrics[$key]['remote_count'] ?? 0);
            if ($samples >= (int) $profile['sample_threshold']) {
                $verified[] = $key;
            } else {
                $pending[] = $key;
            }
        }
        return ['ready' => $pending === [], 'verified' => $verified, 'pending' => $pending];
    }
}
