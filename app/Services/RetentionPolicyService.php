<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class RetentionPolicyService
{
    /** @return list<string> */
    public function datasets(): array
    {
        return [
            'notification_success',
            'notification_incidents',
            'api_request_logs',
            'cron_health_checks',
            'financial_job_items',
            'cron_run_steps',
            'process_metrics',
            'work_queue_items',
            'work_queue_runs',
            'api_budget_windows',
            'manual_probe_runs',
            'performance_metrics',
            'system_logs',
            'api_operation_samples',
            'webhook_events',
            'cron_backlog_snapshots',
            'cron_backlog_run_totals',
            'manual_campaign_events',
            'api_remote_permits',
            'operational_snapshots',
        ];
    }

    /**
     * Ejecuta exactamente una transición persistente de un conjunto.
     *
     * El coordinador web invoca este método una sola vez por petición. Un
     * archivo mensual, su rollup y cada eliminación son pasos separados.
     *
     * @return array{
     *   stage:string,dataset:string,month:?string,archives:int,
     *   rollups:int,deleted:int,complete:bool,message:string,
     *   archive_rows?:int
     * }
     */
    public function runDatasetStep(
        string $dataset,
        int $batchSize = 500,
        ?callable $leaseGuard = null
    ): array
    {
        if (!in_array($dataset, $this->datasets(), true)) {
            throw new RuntimeException('El conjunto de retención no está registrado.');
        }
        $batchSize = max(1, min(500, $batchSize));
        $this->assertLease($leaseGuard);
        $month = $this->oldestUnarchivedClosedMonth($dataset);
        if ($month !== null) {
            $this->assertLease($leaseGuard);
            $archive = (new ColdArchiveService())->createStep(
                $dataset,
                $month,
                $batchSize,
                $leaseGuard
            );
            return [
                'stage' => $archive['stage'],
                'dataset' => $dataset,
                'month' => $month,
                'archives' => !empty($archive['complete']) ? 1 : 0,
                'rollups' => 0,
                'deleted' => 0,
                'complete' => false,
                'message' => !empty($archive['complete'])
                    ? 'Se creó y verificó el archivo cifrado de ' . $month . '.'
                    : 'Se archivó un lote comprobable de ' . $archive['processed']
                        . ' filas de ' . $month . '.',
                'archive_rows' => $archive['processed'],
            ];
        }

        $rollupMonth = $this->oldestArchiveWithoutRollup($dataset);
        if ($rollupMonth !== null) {
            if (in_array($dataset, ['notification_success', 'notification_incidents'], true)) {
                $this->assertLease($leaseGuard);
                $result = $this->rollupNotificationDayStep(
                    $dataset,
                    $rollupMonth,
                    $leaseGuard
                );
                if (!empty($result['complete'])) {
                    $this->assertLease($leaseGuard);
                    Database::connection()->prepare(
                        'UPDATE system_cold_archives
                         SET rollup_verified_at=UTC_TIMESTAMP(3)
                         WHERE dataset_key=:dataset AND period_month=:month
                           AND status="ready" AND verified_at IS NOT NULL
                           AND rollup_cursor_date>=LAST_DAY(CONCAT(:month_end,"-01"))'
                    )->execute([
                        'dataset' => $dataset,
                        'month' => $rollupMonth,
                        'month_end' => $rollupMonth,
                    ]);
                }
                return [
                    'stage' => 'rollup',
                    'dataset' => $dataset,
                    'month' => $rollupMonth,
                    'archives' => 0,
                    'rollups' => $result['rollups'],
                    'deleted' => 0,
                    'complete' => false,
                    'message' => !empty($result['complete'])
                        ? 'Se completó el resumen verificable de ' . $rollupMonth . '.'
                        : 'Se resumió de forma verificable el día '
                            . (string) ($result['date'] ?? '') . '.',
                ];
            }
            $this->assertLease($leaseGuard);
            $rollups = $this->rollupMonth($dataset, $rollupMonth);
            $this->assertLease($leaseGuard);
            Database::connection()->prepare(
                'UPDATE system_cold_archives
                 SET rollup_verified_at=UTC_TIMESTAMP(3)
                 WHERE dataset_key=:dataset AND period_month=:month
                   AND status="ready" AND verified_at IS NOT NULL'
            )->execute(['dataset' => $dataset, 'month' => $rollupMonth]);
            return [
                'stage' => 'rollup',
                'dataset' => $dataset,
                'month' => $rollupMonth,
                'archives' => 0,
                'rollups' => $rollups,
                'deleted' => 0,
                'complete' => false,
                'message' => 'Se conservó el resumen verificable de ' . $rollupMonth . '.',
            ];
        }

        $this->assertLease($leaseGuard);
        $deleted = $this->deleteEligible($dataset, $batchSize, $leaseGuard);
        return [
            'stage' => $deleted > 0 ? 'delete' : 'complete',
            'dataset' => $dataset,
            'month' => null,
            'archives' => 0,
            'rollups' => 0,
            'deleted' => $deleted,
            'complete' => $deleted === 0,
            'message' => $deleted > 0
                ? "Se retiró un lote verificado de {$deleted} filas."
                : 'Este conjunto ya no tiene filas elegibles.',
        ];
    }

    /** @return array{rollups:int,deleted:int,archives:int,errors:int} */
    public function run(int $batchSize = 500): array
    {
        $batchSize = max(1, min(5000, $batchSize));
        $result = ['rollups' => 0, 'deleted' => 0, 'archives' => 0, 'errors' => 0];
        foreach ($this->datasets() as $dataset) {
            try {
                $month = $this->oldestUnarchivedClosedMonth($dataset);
                if ($month !== null) {
                    (new ColdArchiveService())->create($dataset, $month);
                    $result['archives']++;
                }
                $rollupMonth = $this->oldestArchiveWithoutRollup($dataset);
                if ($rollupMonth !== null) {
                    $result['rollups'] += $this->rollupMonth($dataset, $rollupMonth);
                    Database::connection()->prepare(
                        'UPDATE system_cold_archives
                         SET rollup_verified_at=UTC_TIMESTAMP(3)
                         WHERE dataset_key=:dataset AND period_month=:month
                           AND status="ready" AND verified_at IS NOT NULL'
                    )->execute(['dataset' => $dataset, 'month' => $rollupMonth]);
                }
                $result['deleted'] += $this->deleteEligible($dataset, $batchSize);
            } catch (Throwable) {
                $result['errors']++;
            }
        }
        try {
            $summary = $this->purgeSummaryStep($batchSize);
            $result['deleted'] += $summary['deleted'];
        } catch (Throwable) {
            $result['errors']++;
        }
        return $result;
    }

    public function oldestUnarchivedClosedMonth(string $dataset): ?string
    {
        if ($dataset === 'financial_job_items') {
            return $this->oldestImmutableFinancialMonth();
        }
        if (in_array($dataset, ['notification_success', 'notification_incidents'], true)) {
            return $this->oldestNotificationMonth($dataset);
        }
        [$table, $dateColumn] = $this->source($dataset);
        $cursor = null;
        $find = Database::connection()->prepare(
            'SELECT MIN(`' . $dateColumn . '`)
             FROM `' . $table . '`
             WHERE `' . $dateColumn . '`<UTC_DATE()-INTERVAL (DAY(UTC_DATE())-1) DAY'
            . ' AND (:cursor_is_null=1 OR `' . $dateColumn . '`>=:cursor_at)'
        );
        $archive = Database::connection()->prepare(
            'SELECT status FROM system_cold_archives
             WHERE dataset_key=:dataset AND period_month=:month LIMIT 1'
        );
        // El número de meses posibles está acotado; cada salto usa el índice
        // temporal y evita aplicar DATE_FORMAT a todas las filas crecientes.
        for ($attempt = 0; $attempt < 2400; $attempt++) {
            $find->execute([
                'cursor_is_null' => $cursor === null ? 1 : 0,
                'cursor_at' => $cursor ?? '1970-01-01 00:00:00',
            ]);
            $value = $find->fetchColumn();
            if (!is_string($value) || $value === '') {
                return null;
            }
            $month = substr($value, 0, 7);
            if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
                throw new RuntimeException('La fecha técnica no pudo clasificarse.');
            }
            $archive->execute(['dataset' => $dataset, 'month' => $month]);
            if ((string) ($archive->fetchColumn() ?: '') !== 'ready') {
                return $month;
            }
            $cursor = (new DateTimeImmutable($month . '-01 00:00:00'))
                ->modify('+1 month')
                ->format('Y-m-d H:i:s');
        }
        throw new RuntimeException('La exploración de meses técnicos excedió el límite seguro.');
    }

    private function oldestNotificationMonth(string $dataset): ?string
    {
        $statuses = $dataset === 'notification_success'
            ? '"processed","ignored","duplicate"'
            : '"failed","unknown_topic"';
        $retentionGuard = '';
        if ($dataset === 'notification_incidents') {
            $incidentDays = max(
                15,
                (new AppSettingsService())->int('retention.incident_days', 90)
            );
            $retentionGuard = '
               AND e.erp_received_at<DATE_SUB(
                   UTC_TIMESTAMP(),INTERVAL ' . $incidentDays . ' DAY
               )';
        }
        $stmt = Database::connection()->query(
            'SELECT DATE_FORMAT(e.erp_received_at,"%Y-%m") AS period_month
             FROM meli_notification_events e
             LEFT JOIN system_cold_archives a
               ON a.dataset_key=' . Database::connection()->quote($dataset) . '
              AND a.period_month=DATE_FORMAT(e.erp_received_at,"%Y-%m")
              AND a.status="ready"
             WHERE e.erp_received_at<UTC_DATE() - INTERVAL (DAY(UTC_DATE())-1) DAY
               AND e.status IN (' . $statuses . ')
               ' . $retentionGuard . '
               AND a.id IS NULL
             GROUP BY DATE_FORMAT(e.erp_received_at,"%Y-%m")
             ORDER BY period_month
             LIMIT 1'
        );
        $month = $stmt->fetchColumn();
        return is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? $month : null;
    }

    private function oldestImmutableFinancialMonth(): ?string
    {
        $incidentDays = max(
            15,
            (new AppSettingsService())->int('retention.incident_days', 90)
        );
        $stmt = Database::connection()->query(
            'SELECT DATE_FORMAT(i.created_at,"%Y-%m") AS period_month
             FROM order_financial_recalc_job_items i
             INNER JOIN order_financial_recalc_jobs j
               ON j.id=i.order_financial_recalc_job_id
             LEFT JOIN system_cold_archives a
               ON a.dataset_key="financial_job_items"
              AND a.period_month=DATE_FORMAT(i.created_at,"%Y-%m")
              AND a.status="ready"
             WHERE i.created_at<UTC_DATE() - INTERVAL (DAY(UTC_DATE())-1) DAY
               AND a.id IS NULL
             GROUP BY DATE_FORMAT(i.created_at,"%Y-%m")
             HAVING SUM(
                 i.status NOT IN ("complete","skipped")
                 OR j.status<>"complete"
                 OR j.completed_at IS NULL
                 OR j.completed_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $incidentDays . ' DAY)
             )=0
             ORDER BY period_month
             LIMIT 1'
        );
        $month = $stmt->fetchColumn();
        return is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? $month : null;
    }

    public function oldestArchiveWithoutRollup(string $dataset): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT period_month
             FROM system_cold_archives
             WHERE dataset_key=:dataset AND status="ready" AND verified_at IS NOT NULL
               AND rollup_verified_at IS NULL
             ORDER BY period_month
             LIMIT 1'
        );
        $stmt->execute(['dataset' => $dataset]);
        $month = $stmt->fetchColumn();
        return is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? $month : null;
    }

    public function rollupMonth(string $dataset, string $month): int
    {
        [$from, $to] = $this->period($month);
        return match ($dataset) {
            'api_request_logs' => $this->rollupApi($from, $to),
            'notification_success',
            'notification_incidents' => $this->rollupNotifications(
                $dataset,
                $month,
                $from,
                $to
            ),
            'cron_health_checks' => $this->rollupCron($from, $to),
            'cron_run_steps',
            'process_metrics',
            'work_queue_items',
            'work_queue_runs',
            'api_budget_windows',
            'manual_probe_runs',
            'performance_metrics',
            'system_logs',
            'api_operation_samples',
            'webhook_events',
            'cron_backlog_snapshots',
            'cron_backlog_run_totals',
            'manual_campaign_events' => $this->rollupTechnical($dataset, $from, $to),
            'api_remote_permits' => $this->rollupTechnical($dataset, $from, $to),
            'operational_snapshots' => $this->rollupTechnical($dataset, $from, $to),
            // El job padre conserva los totales y estados de la ejecución.
            // El archivo cifrado conserva el detalle exacto que se retirará.
            'financial_job_items' => 1,
            default => throw new RuntimeException('El conjunto de retención no está registrado.'),
        };
    }

    private function rollupApi(string $from, string $to): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO api_request_daily_rollups
             (rollup_date,meli_account_id,operation_key,outcome_class,http_status,reached_remote,
              requests,duration_total_ms,wire_bytes,decoded_bytes)
             SELECT DATE(created_at),COALESCE(meli_account_id,0),COALESCE(operation_key,""),
                    COALESCE(outcome_class,""),COALESCE(http_status,0),reached_remote,
                    COUNT(*),COALESCE(SUM(duration_ms),0),COALESCE(SUM(wire_bytes),0),
                    COALESCE(SUM(decoded_bytes),0)
             FROM api_request_logs
             WHERE created_at>=:from_at AND created_at<:to_at
             GROUP BY DATE(created_at),COALESCE(meli_account_id,0),COALESCE(operation_key,""),
                      COALESCE(outcome_class,""),COALESCE(http_status,0),reached_remote
             ON DUPLICATE KEY UPDATE
                requests=VALUES(requests),duration_total_ms=VALUES(duration_total_ms),
                wire_bytes=VALUES(wire_bytes),decoded_bytes=VALUES(decoded_bytes)'
        );
        $stmt->execute(['from_at' => $from, 'to_at' => $to]);
        return $stmt->rowCount();
    }

    private function rollupNotifications(
        string $dataset,
        string $month,
        string $from,
        string $to,
        bool $incremental = false
    ): int
    {
        $pdo = Database::connection();
        $statuses = $dataset === 'notification_success'
            ? '("processed","ignored","duplicate")'
            : '("failed","unknown_topic")';
        $stmt = $pdo->prepare(
            'INSERT INTO notification_event_daily_rollups
             (rollup_date,meli_account_id,canonical_topic,status,events,unique_resources)
             SELECT DATE(erp_received_at),COALESCE(meli_account_id,0),COALESCE(canonical_topic,""),
                    status,COUNT(*),
                    COUNT(DISTINCT COALESCE(NULLIF(remote_resource_id,""),NULLIF(resource,""),payload_hash))
             FROM meli_notification_events
             WHERE erp_received_at>=:from_at AND erp_received_at<:to_at
               AND status IN ' . $statuses . '
             GROUP BY DATE(erp_received_at),COALESCE(meli_account_id,0),
                      COALESCE(canonical_topic,""),status
             ON DUPLICATE KEY UPDATE events=VALUES(events),unique_resources=VALUES(unique_resources)'
        );
        $stmt->execute(['from_at' => $from, 'to_at' => $to]);
        $affected = $stmt->rowCount();
        $resourceMerge = $incremental
            ? 'last_terminal_status=IF(
                    VALUES(last_seen_at)>=last_seen_at,
                    VALUES(last_terminal_status),
                    last_terminal_status
                ),
                first_seen_at=LEAST(first_seen_at,VALUES(first_seen_at)),
                last_seen_at=GREATEST(last_seen_at,VALUES(last_seen_at)),
                occurrence_count=occurrence_count+VALUES(occurrence_count)'
            : 'first_seen_at=VALUES(first_seen_at),
                last_seen_at=VALUES(last_seen_at),
                occurrence_count=VALUES(occurrence_count),
                last_terminal_status=VALUES(last_terminal_status)';
        $resource = $pdo->prepare(
            'INSERT INTO notification_resource_monthly_rollups
             (rollup_month,meli_account_id,canonical_topic,remote_resource_id,first_seen_at,
              last_seen_at,occurrence_count,last_terminal_status)
             SELECT :month,COALESCE(meli_account_id,0),COALESCE(canonical_topic,""),
                    LEFT(COALESCE(NULLIF(remote_resource_id,""),NULLIF(resource,""),payload_hash),120),
                    MIN(erp_received_at),MAX(erp_received_at),COUNT(*),
                    SUBSTRING_INDEX(GROUP_CONCAT(status ORDER BY erp_received_at DESC,id DESC),",",1)
             FROM meli_notification_events
             WHERE erp_received_at>=:from_at AND erp_received_at<:to_at
               AND status IN ' . $statuses . '
             GROUP BY COALESCE(meli_account_id,0),COALESCE(canonical_topic,""),
                      LEFT(COALESCE(NULLIF(remote_resource_id,""),NULLIF(resource,""),payload_hash),120)
             ON DUPLICATE KEY UPDATE ' . $resourceMerge
        );
        $resource->execute(['month' => $month, 'from_at' => $from, 'to_at' => $to]);
        return $affected + $resource->rowCount();
    }

    /**
     * Resume exactamente un día y confirma el cursor en la misma transacción.
     *
     * De esta forma una recarga no duplica occurrence_count y una petición web
     * no intenta reagrupar un mes completo.
     *
     * @return array{date:?string,rollups:int,complete:bool}
     */
    private function rollupNotificationDayStep(
        string $dataset,
        string $month,
        ?callable $leaseGuard = null
    ): array
    {
        [$monthFrom, $monthTo] = $this->period($month);
        $this->assertLease($leaseGuard);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $archive = $pdo->prepare(
                'SELECT id,rollup_cursor_date
                 FROM system_cold_archives
                 WHERE dataset_key=:dataset AND period_month=:month
                   AND status="ready" AND verified_at IS NOT NULL
                 LIMIT 1 FOR UPDATE'
            );
            $archive->execute(['dataset' => $dataset, 'month' => $month]);
            $row = $archive->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new RuntimeException('El archivo verificado ya no está disponible.');
            }

            $cursor = trim((string) ($row['rollup_cursor_date'] ?? ''));
            $day = $cursor !== ''
                ? (new DateTimeImmutable($cursor . ' 00:00:00'))->modify('+1 day')
                : new DateTimeImmutable($monthFrom);
            $limit = new DateTimeImmutable($monthTo);
            if ($day >= $limit) {
                $this->assertLease($leaseGuard);
                $pdo->commit();
                return ['date' => null, 'rollups' => 0, 'complete' => true];
            }

            $from = $day->format('Y-m-d 00:00:00');
            $to = $day->modify('+1 day')->format('Y-m-d 00:00:00');
            $this->assertLease($leaseGuard);
            $rollups = $this->rollupNotifications($dataset, $month, $from, $to, true);
            $this->assertLease($leaseGuard);
            $pdo->prepare(
                'UPDATE system_cold_archives
                 SET rollup_cursor_date=:cursor
                 WHERE id=:id AND rollup_cursor_date<=>:previous_cursor'
            )->execute([
                'cursor' => $day->format('Y-m-d'),
                'id' => (int) $row['id'],
                'previous_cursor' => $cursor !== '' ? $cursor : null,
            ]);
            $pdo->commit();

            return [
                'date' => $day->format('Y-m-d'),
                'rollups' => $rollups,
                'complete' => $day->modify('+1 day') >= $limit,
            ];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function rollupCron(string $from, string $to): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO cron_daily_rollups
             (rollup_date,job_name,execution_source,result_state,runs,duration_total_ms,
              processed_total,errors_total,remote_runs)
             SELECT DATE(created_at),job_name,execution_source,
                    COALESCE(NULLIF(result_state,""),status),COUNT(*),
                    COALESCE(SUM(duration_ms),0),
                    COALESCE(SUM(completed_chunks+partial_chunks+orders_count),0),
                    COALESCE(SUM(error_chunks),0),
                    SUM(CASE WHEN payload_json LIKE \'%"remote":true%\' THEN 1 ELSE 0 END)
             FROM cron_health_checks
             WHERE created_at>=:from_at AND created_at<:to_at
             GROUP BY DATE(created_at),job_name,execution_source,
                      COALESCE(NULLIF(result_state,""),status)
             ON DUPLICATE KEY UPDATE runs=VALUES(runs),
                duration_total_ms=VALUES(duration_total_ms),
                processed_total=VALUES(processed_total),errors_total=VALUES(errors_total),
                remote_runs=VALUES(remote_runs)'
        );
        $stmt->execute(['from_at' => $from, 'to_at' => $to]);
        return $stmt->rowCount();
    }

    private function rollupTechnical(string $dataset, string $from, string $to): int
    {
        [$table, $dateColumn] = $this->source($dataset);
        [$dimension, $outcome, $errors, $remote, $duration, $bytes] = match ($dataset) {
            'cron_run_steps' => [
                'LEFT(COALESCE(step_name,""),120)',
                'LEFT(COALESCE(status,""),80)',
                'SUM(error_count)',
                '0',
                'SUM(duration_ms)',
                '0',
            ],
            'process_metrics' => [
                'LEFT(COALESCE(process_type,""),120)',
                'LEFT(COALESCE(status,""),80)',
                'SUM(error_count)',
                'SUM(api_request_count>0)',
                'SUM(duration_ms)',
                '0',
            ],
            'work_queue_items' => [
                'LEFT(COALESCE(queue_key,""),120)',
                'LEFT(COALESCE(result,""),80)',
                'SUM(result IN ("failed","partial","retried"))',
                'SUM(COALESCE(actual_api_calls,0)>0)',
                'SUM(COALESCE(duration_ms,0))',
                '0',
            ],
            'work_queue_runs' => [
                'LEFT(COALESCE(origin,""),120)',
                'LEFT(COALESCE(status,""),80)',
                'SUM(failed_count)',
                'SUM(api_calls_used>0)',
                'SUM(COALESCE(duration_ms,0))',
                '0',
            ],
            'api_budget_windows' => [
                'LEFT(COALESCE(scope,""),120)',
                'IF(error_400_count+error_401_count+error_403_count+'
                    . 'error_429_count+error_5xx_count>0,"with_error","healthy")',
                'SUM(error_400_count+error_401_count+error_403_count+'
                    . 'error_429_count+error_5xx_count)',
                'SUM(request_count>0)',
                '0',
                '0',
            ],
            'manual_probe_runs' => [
                'LEFT(COALESCE(sapi,""),120)',
                'LEFT(COALESCE(status,""),80)',
                'SUM(status IN ("failed","interrupted"))',
                '0',
                'SUM(observed_window_ms)',
                '0',
            ],
            'performance_metrics' => [
                'LEFT(COALESCE(metric_name,""),120)',
                'LEFT(COALESCE(source,""),80)',
                '0',
                '0',
                'SUM(CASE WHEN metric_name LIKE "%duration%"'
                    . ' OR metric_name LIKE "%latency%" THEN metric_value ELSE 0 END)',
                '0',
            ],
            'system_logs' => [
                'LEFT(COALESCE(level,""),120)',
                'LEFT(COALESCE(level,""),80)',
                'SUM(LOWER(level) IN ("warning","error","critical","alert","emergency"))',
                '0',
                '0',
                '0',
            ],
            'api_operation_samples' => [
                'LEFT(COALESCE(operation_key,""),120)',
                'IF(successful=1,"success","failure")',
                'SUM(successful=0)',
                'SUM(reached_remote=1)',
                'SUM(duration_ms)',
                'SUM(wire_bytes+decoded_bytes)',
            ],
            'webhook_events' => [
                'LEFT(COALESCE(topic,""),120)',
                'LEFT(COALESCE(status,""),80)',
                'SUM(status="error")',
                '0',
                '0',
                'SUM(OCTET_LENGTH(raw_json))',
            ],
            'cron_backlog_snapshots' => [
                'LEFT(COALESCE(queue_key,""),120)',
                'LEFT(CONCAT(COALESCE(measurement_state,"unavailable"),":",COALESCE(equation_state,"unavailable")),80)',
                'SUM(measurement_state<>"complete" OR equation_state="partial")',
                'SUM(COALESCE(http_dispatched,0)>0)',
                '0',
                '0',
            ],
            'cron_backlog_run_totals' => [
                '"all_queues"',
                'LEFT(COALESCE(measurement_state,"unavailable"),80)',
                'SUM(measurement_state<>"complete")',
                'SUM(COALESCE(http_dispatched,0)>0)',
                '0',
                '0',
            ],
            'manual_campaign_events' => [
                'LEFT(COALESCE(event_type,""),120)',
                'LEFT(COALESCE(severity,"info"),80)',
                'SUM(severity="error")',
                '0',
                '0',
                'SUM(OCTET_LENGTH(COALESCE(event_data_json,"")))',
            ],
            'api_remote_permits' => [
                'LEFT(COALESCE(endpoint_key,""),120)',
                'LEFT(COALESCE(status,""),80)',
                'SUM(COALESCE(http_status,0)=429 OR status="expired")',
                'SUM(dispatched_at IS NOT NULL)',
                'SUM(TIMESTAMPDIFF(MICROSECOND,created_at,COALESCE(completed_at,released_at,updated_at))/1000)',
                '0',
            ],
            'operational_snapshots' => [
                'LEFT(COALESCE(snapshot_kind,""),120)',
                'LEFT(COALESCE(protocol,"unavailable"),80)',
                'SUM(protocol IN ("partial","unavailable"))',
                '0',
                '0',
                'SUM(OCTET_LENGTH(COALESCE(payload_json,"")))',
            ],
            default => throw new RuntimeException('El resumen técnico no está registrado.'),
        };
        $stmt = Database::connection()->prepare(
            'INSERT INTO system_technical_daily_rollups
             (rollup_date,dataset_key,dimension_key,outcome_class,records,error_records,
              remote_records,duration_total_ms,bytes_total)
             SELECT DATE(`' . $dateColumn . '`),:dataset,' . $dimension . ',' . $outcome . ',
                    COUNT(*),' . $errors . ',' . $remote . ',' . $duration . ',' . $bytes . '
             FROM `' . $table . '`
             WHERE `' . $dateColumn . '`>=:from_at AND `' . $dateColumn . '`<:to_at
             GROUP BY DATE(`' . $dateColumn . '`),' . $dimension . ',' . $outcome . '
             ON DUPLICATE KEY UPDATE
                records=VALUES(records),error_records=VALUES(error_records),
                remote_records=VALUES(remote_records),
                duration_total_ms=VALUES(duration_total_ms),bytes_total=VALUES(bytes_total)'
        );
        $stmt->execute(['dataset' => $dataset, 'from_at' => $from, 'to_at' => $to]);
        return $stmt->rowCount();
    }

    public function deleteEligible(
        string $dataset,
        int $limit,
        ?callable $leaseGuard = null
    ): int
    {
        $this->assertLease($leaseGuard);
        $settings = new AppSettingsService();
        $successDays = max(1, $settings->int('retention.success_days', 15));
        $incidentDays = max($successDays, $settings->int('retention.incident_days', 90));
        $backlogDays = max(2, min(90, $settings->int('retention.cron_backlog_detail_days', 15)));
        $campaignEventDays = max(7, min(3650, $settings->int('manual_campaign.event_retention_days', 30)));
        [$table, $dateColumn] = $this->source($dataset);
        $classification = match ($dataset) {
            'notification_success' =>
                '(src.status IN ("processed","ignored","duplicate")'
                . ' AND src.`' . $dateColumn . '`<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $successDays . ' DAY))',
            'notification_incidents' =>
                '(src.status IN ("failed","unknown_topic")'
                . ' AND src.`' . $dateColumn . '`<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $incidentDays . ' DAY))',
            'api_request_logs' =>
                '(((COALESCE(src.http_status,0)<400 AND src.actionable=0 AND src.risk_signal=0'
                . ' AND src.was_blocked=0 AND COALESCE(src.outcome_class,"") NOT IN ("error","blocked"))'
                . ' AND src.`' . $dateColumn . '`<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $successDays . ' DAY))'
                . ' OR ((COALESCE(src.http_status,0)>=400 OR src.actionable=1 OR src.risk_signal=1'
                . ' OR src.was_blocked=1 OR COALESCE(src.outcome_class,"") IN ("error","blocked"))'
                . ' AND src.`' . $dateColumn . '`<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $incidentDays . ' DAY)))',
            'cron_health_checks' =>
                '((src.status="success"'
                . ' AND src.`' . $dateColumn . '`<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $successDays . ' DAY))'
                . ' OR (src.status="error"'
                . ' AND src.`' . $dateColumn . '`<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $incidentDays . ' DAY)))',
            'financial_job_items' =>
                '(src.status IN ("complete","skipped")'
                . ' AND COALESCE(src.processed_at,src.created_at)'
                . '<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $incidentDays . ' DAY)'
                . ' AND EXISTS ('
                . ' SELECT 1 FROM order_financial_recalc_jobs parent_job'
                . ' WHERE parent_job.id=src.order_financial_recalc_job_id'
                . ' AND parent_job.status="complete"'
                . ' AND parent_job.completed_at IS NOT NULL'
                . ' AND parent_job.completed_at'
                . '<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $incidentDays . ' DAY)'
                . '))',
            'cron_run_steps' =>
                '(src.status<>"running" AND src.finished_at IS NOT NULL AND ('
                . '(src.status IN ("action_required","failed","error")'
                . ' AND src.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $incidentDays . ' DAY))'
                . ' OR (src.status NOT IN ("action_required","failed","error")'
                . ' AND src.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $successDays . ' DAY))))',
            'process_metrics' =>
                '(src.status NOT IN ("running","paused") AND ('
                . '(src.status IN ("action_required","failed","error")'
                . ' AND src.measured_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $incidentDays . ' DAY))'
                . ' OR (src.status NOT IN ("action_required","failed","error")'
                . ' AND src.measured_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $successDays . ' DAY))))',
            'work_queue_items' =>
                '((src.result IN ("completed","skipped")'
                . ' AND src.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $successDays . ' DAY))'
                . ' OR (src.result IN ("partial","failed","retried")'
                . ' AND src.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $incidentDays . ' DAY)))',
            'work_queue_runs' =>
                '(((src.status IN ("completed","empty","skipped")'
                . ' AND src.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $successDays . ' DAY))'
                . ' OR (src.status IN ("partial","failed")'
                . ' AND src.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $incidentDays . ' DAY)))'
                . ' AND NOT EXISTS (SELECT 1 FROM system_work_queue_run_items child'
                . ' WHERE child.work_queue_run_id=src.id))',
            'api_budget_windows' =>
                '(DATE_ADD(src.window_started_at,INTERVAL src.window_seconds SECOND)<UTC_TIMESTAMP()'
                . ' AND (src.cooldown_until IS NULL OR src.cooldown_until<UTC_TIMESTAMP()) AND ('
                . '((src.error_400_count+src.error_401_count+src.error_403_count+'
                . 'src.error_429_count+src.error_5xx_count)=0'
                . ' AND src.window_started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $successDays . ' DAY))'
                . ' OR ((src.error_400_count+src.error_401_count+src.error_403_count+'
                . 'src.error_429_count+src.error_5xx_count)>0'
                . ' AND src.window_started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $incidentDays . ' DAY))))',
            'manual_probe_runs' =>
                '((src.status="passed" AND src.started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL '
                . $successDays . ' DAY)) OR (src.status IN ("failed","interrupted")'
                . ' AND src.started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $incidentDays . ' DAY)))',
            'performance_metrics' =>
                '(src.recorded_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $successDays . ' DAY))',
            'system_logs' =>
                '((LOWER(src.level) IN ("warning","error","critical","alert","emergency")'
                . ' AND src.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $incidentDays . ' DAY))'
                . ' OR (LOWER(src.level) NOT IN ("warning","error","critical","alert","emergency")'
                . ' AND src.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $successDays . ' DAY)))',
            'api_operation_samples' =>
                '((src.successful=1 AND COALESCE(src.http_status,0)<400'
                . ' AND src.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $successDays . ' DAY))'
                . ' OR ((src.successful=0 OR COALESCE(src.http_status,0)>=400)'
                . ' AND src.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $incidentDays . ' DAY)))',
            'webhook_events' =>
                '((src.status IN ("processed","ignored")'
                . ' AND src.received_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $successDays . ' DAY))'
                . ' OR (src.status="error"'
                . ' AND src.received_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $incidentDays . ' DAY)))',
            'cron_backlog_snapshots',
            'cron_backlog_run_totals' =>
                '(src.`' . $dateColumn . '`<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $backlogDays . ' DAY))',
            'manual_campaign_events' =>
                '(((src.severity IN ("neutral","info","success")'
                . ' AND src.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $campaignEventDays . ' DAY))'
                . ' OR (src.severity IN ("warning","error")'
                . ' AND src.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL ' . $incidentDays . ' DAY)))'
                . ' AND EXISTS (SELECT 1 FROM manual_campaigns campaign'
                . ' WHERE campaign.id=src.manual_campaign_id'
                . ' AND campaign.status IN ("completed","completed_with_issues","failed")))',
            'api_remote_permits' =>
                '(src.status IN ("completed","released","expired") AND ('
                . '((COALESCE(src.http_status,0) NOT IN (429) AND src.status<>"expired")'
                . ' AND src.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 DAY))'
                . ' OR ((COALESCE(src.http_status,0)=429 OR src.status="expired")'
                . ' AND src.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY))))',
            'operational_snapshots' =>
                '(src.measured_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 14 DAY))',
            default => '0=1',
        };
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $this->assertLease($leaseGuard);
            $select = $pdo->prepare(
            'SELECT src.*,m.archive_id AS _archive_id,
                    m.row_sha256 AS _archived_sha256
             FROM `' . $table . '` src
             INNER JOIN system_cold_archive_memberships m
               ON m.source_table=:source_table AND m.source_id=src.id
              AND m.source_deleted_at IS NULL AND m.stale_at IS NULL
             INNER JOIN system_cold_archives a
               ON a.id=m.archive_id AND a.dataset_key=:dataset
              AND a.status="ready" AND a.verified_at IS NOT NULL
              AND a.membership_verified_at IS NOT NULL
              AND a.rollup_verified_at IS NOT NULL
             WHERE ' . $classification . '
             ORDER BY src.id
             LIMIT ' . max(1, min(5000, $limit)) . '
             FOR UPDATE'
            );
            $select->execute(['source_table' => $table, 'dataset' => $dataset]);
            $rows = $select->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                $pdo->commit();
                return 0;
            }

            $ids = [];
            $staleIds = [];
            foreach ($rows as $row) {
                $sourceId = (int) ($row['id'] ?? 0);
                $archivedHash = (string) ($row['_archived_sha256'] ?? '');
                unset($row['_archive_id'], $row['_archived_sha256']);
                if (
                    $sourceId <= 0
                    || $archivedHash === ''
                    || !hash_equals($archivedHash, ColdArchiveService::rowHash($row))
                ) {
                    if ($sourceId > 0) {
                        $staleIds[] = $sourceId;
                    }
                    continue;
                }
                $ids[] = $sourceId;
            }
            $ids = array_values(array_unique($ids));
            $staleIds = array_values(array_unique($staleIds));

            $this->assertLease($leaseGuard);
            if ($staleIds !== []) {
                $pdo->exec(
                    'UPDATE system_cold_archive_memberships
                     SET stale_at=UTC_TIMESTAMP(3),
                         verification_error="source_changed_after_archive"
                     WHERE source_table=' . $pdo->quote($table) . '
                       AND source_deleted_at IS NULL
                       AND source_id IN (' . implode(',', $staleIds) . ')'
                );
            }
            if ($ids === []) {
                $this->assertLease($leaseGuard);
                $pdo->commit();
                return 0;
            }
            $this->assertLease($leaseGuard);
            $deleted = (int) $pdo->exec(
                'DELETE FROM `' . $table . '` WHERE id IN ('
                . implode(',', $ids) . ')'
            );
            if ($deleted !== count($ids)) {
                throw new RuntimeException(
                    'La fuente cambió durante la eliminación y el lote no fue aprobado.'
                );
            }
            $pdo->exec(
                'UPDATE system_cold_archive_memberships
                 SET source_deleted_at=UTC_TIMESTAMP(3),verification_error=NULL
                 WHERE source_table=' . $pdo->quote($table) . '
                   AND source_deleted_at IS NULL AND stale_at IS NULL
                   AND source_id IN (' . implode(',', $ids) . ')'
            );
            $this->assertLease($leaseGuard);
            $pdo->commit();
            return $deleted;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{deleted:int,complete:bool,message:string} */
    public function purgeSummaryStep(int $limit): array
    {
        $limit = max(1, min(5000, $limit));
        $days = max(30, (new AppSettingsService())->int('retention.summary_days', 365));
        foreach ([
            ['api_request_daily_rollups', 'rollup_date'],
            ['notification_event_daily_rollups', 'rollup_date'],
            ['notification_resource_monthly_rollups', 'last_seen_at'],
            ['cron_daily_rollups', 'rollup_date'],
            ['system_technical_daily_rollups', 'rollup_date'],
            ['api_operation_metrics_hourly', 'bucket_started_at'],
            ['system_query_performance_rollups', 'metric_date'],
            ['system_database_growth_snapshots', 'captured_at'],
        ] as [$table, $column]) {
            $affected = (int) Database::connection()->exec(
                'DELETE FROM `' . $table . '`
                 WHERE `' . $column . '`<DATE_SUB(UTC_DATE(),INTERVAL ' . $days . ' DAY)
                 LIMIT ' . $limit
            );
            if ($affected > 0) {
                return [
                    'deleted' => $affected,
                    'complete' => false,
                    'message' => 'Se retiró un lote de ' . $affected
                        . ' resúmenes vencidos de ' . $table . '.',
                ];
            }
        }
        return [
            'deleted' => 0,
            'complete' => true,
            'message' => 'No quedan resúmenes vencidos.',
        ];
    }

    /** @return array{0:string,1:string} */
    private function source(string $dataset): array
    {
        return match ($dataset) {
            'notification_events' => ['meli_notification_events', 'erp_received_at'],
            'notification_success',
            'notification_incidents' => ['meli_notification_events', 'erp_received_at'],
            'api_request_logs' => ['api_request_logs', 'created_at'],
            'cron_health_checks' => ['cron_health_checks', 'created_at'],
            'financial_job_items' => ['order_financial_recalc_job_items', 'created_at'],
            'cron_run_steps' => ['system_cron_run_steps', 'created_at'],
            'process_metrics' => ['system_process_metrics', 'measured_at'],
            'work_queue_items' => ['system_work_queue_run_items', 'created_at'],
            'work_queue_runs' => ['system_work_queue_runs', 'created_at'],
            'api_budget_windows' => ['api_budget_windows', 'window_started_at'],
            'manual_probe_runs' => ['manual_engine_probe_runs', 'started_at'],
            'performance_metrics' => ['system_performance_metrics', 'recorded_at'],
            'system_logs' => ['system_logs', 'created_at'],
            'api_operation_samples' => ['api_operation_metric_samples', 'created_at'],
            'webhook_events' => ['meli_webhook_events', 'received_at'],
            'cron_backlog_snapshots' => ['system_cron_backlog_snapshots', 'measured_at'],
            'cron_backlog_run_totals' => ['system_cron_backlog_run_totals', 'measured_at'],
            'manual_campaign_events' => ['manual_campaign_events', 'created_at'],
            'api_remote_permits' => ['api_remote_permits', 'created_at'],
            'operational_snapshots' => ['system_operational_snapshots', 'measured_at'],
            default => throw new RuntimeException('El conjunto de retención no está registrado.'),
        };
    }

    private function assertLease(?callable $leaseGuard): void
    {
        if ($leaseGuard !== null) {
            $leaseGuard();
        }
    }

    /** @return array{0:string,1:string} */
    private function period(string $month): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new RuntimeException('El mes no es válido.');
        }
        $from = new DateTimeImmutable($month . '-01 00:00:00');
        return [$from->format('Y-m-d H:i:s'), $from->modify('+1 month')->format('Y-m-d H:i:s')];
    }
}
