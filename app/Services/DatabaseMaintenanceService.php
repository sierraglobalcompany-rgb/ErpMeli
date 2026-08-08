<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class DatabaseMaintenanceService
{
    private const CLI_LEASE_SECONDS = 120;
    private const BACKUP_MAX_AGE_SECONDS = 86400;
    private const PROTECTION_ERP_BACKUP = 'erp_backup';
    private const PROTECTION_EXTERNAL = 'external_backup';
    private const PROTECTION_WAIVED = 'waived';

    /** @var list<string> */
    private const DATASETS = [
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
    ];

    /** @var list<string> */
    private const PAYLOAD_TABLES = [
        'meli_orders',
        'meli_shipments',
        'meli_payments',
        'meli_packs',
        'meli_order_items',
    ];

    /** @var list<string> */
    private const PROTECTED_TABLES = [
        'companies',
        'meli_accounts',
        'meli_items',
        'meli_orders',
        'meli_order_items',
        'meli_packs',
        'meli_pack_orders',
        'meli_payments',
        'meli_shipments',
        'product_meli_links',
        'internal_products',
        'catalogs',
        'catalog_items',
        'manual_campaigns',
        'manual_campaign_items',
        'sync_sales_audit_runs',
        'sync_sales_audit_run_orders',
        'sync_sales_audit_evidence_events',
        'sales_control_captures',
        'sales_control_closes',
        'sales_control_reopenings',
        'sales_control_fiscal_snapshots',
        'monthly_reports',
        'monthly_report_orders',
        'monthly_report_items',
        'date_report_runs',
        'date_report_orders',
        'date_report_items',
    ];

    /** @return array<string,mixed> */
    public function overview(?int $sessionId = null, ?int $userId = null): array
    {
        $session = null;
        if ($sessionId !== null && $sessionId > 0 && $userId !== null) {
            $session = $this->session($sessionId, $userId);
        } elseif ($userId !== null) {
            $stmt = Database::connection()->prepare(
                'SELECT id FROM database_maintenance_sessions
                 WHERE requested_by=:user_id
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute(['user_id' => $userId]);
            $latest = (int) ($stmt->fetchColumn() ?: 0);
            $session = $latest > 0 ? $this->session($latest, $userId) : null;
        }
        $storedAnalysis = is_array($session['plan']['analysis'] ?? null)
            ? $session['plan']['analysis']
            : null;
        $analysis = $storedAnalysis ?? $this->analysis(false);
        if ($session !== null) {
            // La copia forma parte del plan congelado. Nunca sustituirla por la
            // copia global más reciente: esa sustitución permitiría sanear con
            // evidencia distinta de la que se analizó.
            $boundBackup = $this->verifiedBackup(
                max(0, (int) ($session['backup_id'] ?? 0))
            );
            $analysis['verified_backup'] = $boundBackup === null
                ? null
                : $this->backupDescriptor($boundBackup);
        }
        if (
            $session !== null
            && (string) $session['status'] === 'analyzed'
        ) {
            $protection = $this->protectionDescriptor($session, $analysis);
            $analysis['protection'] = $protection;
            $session['protection'] = $protection;
            $session['next_action'] = $protection['ready']
                ? 'Revisar el plan e iniciar un lote canario.'
                : 'Elegir cómo proteger esta sesión antes de eliminar ruido técnico.';
        } elseif ($session !== null) {
            $protection = $this->protectionDescriptor($session, $analysis);
            $analysis['protection'] = $protection;
            $session['protection'] = $protection;
        }
        return [
            'analysis' => $analysis,
            'session' => $session,
            'recovery_plan' => $session !== null && (string) $session['status'] === 'completed'
                ? (new PhysicalTableRecoveryService())->plan()
                : [],
            'physical_recovery' => is_array($session['plan']['physical_recovery'] ?? null)
                ? $session['plan']['physical_recovery']
                : null,
            'recent_steps' => $session === null
                ? []
                : $this->recentSteps((int) $session['id']),
            'safety' => (new SystemSafetyStatusService())->status(),
        ];
    }

    /** @return array<string,mixed> */
    public function analysis(bool $deep = true): array
    {
        $pdo = Database::connection();
        $schema = (new InformationSchemaGateway($pdo))->database();
        $stmt = $pdo->prepare(
            'SELECT TABLE_NAME,ENGINE,TABLE_ROWS,DATA_LENGTH,INDEX_LENGTH,DATA_FREE
             FROM information_schema.TABLES
             WHERE BINARY TABLE_SCHEMA=BINARY :schema
               AND TABLE_TYPE="BASE TABLE"
             ORDER BY DATA_LENGTH+INDEX_LENGTH DESC'
        );
        $stmt->execute(['schema' => $schema]);
        $tables = [];
        $totals = [
            'data_bytes' => 0,
            'index_bytes' => 0,
            'free_bytes' => 0,
            'estimated_rows' => 0,
        ];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $metric = [
                'table' => (string) $row['TABLE_NAME'],
                'engine' => (string) ($row['ENGINE'] ?? ''),
                'estimated_rows' => max(0, (int) $row['TABLE_ROWS']),
                'data_bytes' => max(0, (int) $row['DATA_LENGTH']),
                'index_bytes' => max(0, (int) $row['INDEX_LENGTH']),
                'free_bytes' => max(0, (int) $row['DATA_FREE']),
            ];
            $tables[] = $metric;
            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) $metric[$key];
            }
        }

        $eligible = $deep ? [
            'notification_success' => $this->scalar(
                'SELECT COUNT(*) FROM meli_notification_events
                 WHERE status IN ("processed","ignored","duplicate")
                   AND erp_received_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 DAY)
                   AND erp_received_at<
                       UTC_DATE()-INTERVAL (DAY(UTC_DATE())-1) DAY'
            ),
            'notification_incidents' => $this->scalar(
                'SELECT COUNT(*) FROM meli_notification_events
                 WHERE status IN ("failed","unknown_topic")
                   AND erp_received_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)'
            ),
            'api_request_logs' => $this->scalar(
                'SELECT COUNT(*) FROM api_request_logs
                 WHERE (
                    (COALESCE(http_status,0)<400 AND actionable=0 AND risk_signal=0
                     AND was_blocked=0
                     AND COALESCE(outcome_class,"") NOT IN ("error","blocked")
                     AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 DAY))
                    OR
                    ((COALESCE(http_status,0)>=400 OR actionable=1 OR risk_signal=1
                      OR was_blocked=1
                      OR COALESCE(outcome_class,"") IN ("error","blocked"))
                     AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY))
                 )
                   AND created_at<
                       UTC_DATE()-INTERVAL (DAY(UTC_DATE())-1) DAY'
            ),
            'cron_health_checks' => $this->scalar(
                'SELECT COUNT(*) FROM cron_health_checks
                 WHERE (status="success" AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 DAY))
                    OR (status="error" AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY))'
            ),
            'financial_job_items' => $this->scalar(
                'SELECT COUNT(*)
                 FROM order_financial_recalc_job_items i
                 INNER JOIN order_financial_recalc_jobs j
                   ON j.id=i.order_financial_recalc_job_id
                 WHERE i.status IN ("complete","skipped")
                   AND COALESCE(i.processed_at,i.created_at)
                       <DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)
                   AND j.status="complete"
                   AND j.completed_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)'
            ),
            'cron_run_steps' => $this->scalar(
                'SELECT COUNT(*) FROM system_cron_run_steps
                 WHERE status<>"running" AND finished_at IS NOT NULL
                   AND ((status IN ("action_required","failed","error")
                         AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY))
                     OR (status NOT IN ("action_required","failed","error")
                         AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 DAY)))'
            ),
            'process_metrics' => $this->scalar(
                'SELECT COUNT(*) FROM system_process_metrics
                 WHERE status NOT IN ("running","paused")
                   AND ((status IN ("action_required","failed","error")
                         AND measured_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY))
                     OR (status NOT IN ("action_required","failed","error")
                         AND measured_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 DAY)))'
            ),
            'work_queue_items' => $this->scalar(
                'SELECT COUNT(*) FROM system_work_queue_run_items
                 WHERE (result IN ("completed","skipped")
                        AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 DAY))
                    OR (result IN ("partial","failed","retried")
                        AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY))'
            ),
            'work_queue_runs' => $this->scalar(
                'SELECT COUNT(*) FROM system_work_queue_runs r
                 WHERE (((status IN ("completed","empty","skipped")
                          AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 DAY))
                      OR (status IN ("partial","failed")
                          AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)))
                   AND NOT EXISTS (
                       SELECT 1 FROM system_work_queue_run_items i
                       WHERE i.work_queue_run_id=r.id
                   ))'
            ),
            'api_budget_windows' => $this->scalar(
                'SELECT COUNT(*) FROM api_budget_windows
                 WHERE DATE_ADD(window_started_at,INTERVAL window_seconds SECOND)<UTC_TIMESTAMP()
                   AND (cooldown_until IS NULL OR cooldown_until<UTC_TIMESTAMP())
                   AND (((error_400_count+error_401_count+error_403_count+
                          error_429_count+error_5xx_count)=0
                         AND window_started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 DAY))
                     OR ((error_400_count+error_401_count+error_403_count+
                          error_429_count+error_5xx_count)>0
                         AND window_started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)))'
            ),
            'manual_probe_runs' => $this->scalar(
                'SELECT COUNT(*) FROM manual_engine_probe_runs
                 WHERE (status="passed"
                        AND started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 DAY))
                    OR (status IN ("failed","interrupted")
                        AND started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY))'
            ),
            'performance_metrics' => $this->scalar(
                'SELECT COUNT(*) FROM system_performance_metrics
                 WHERE recorded_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 DAY)'
            ),
            'system_logs' => $this->scalar(
                'SELECT COUNT(*) FROM system_logs
                 WHERE (LOWER(level) IN ("warning","error","critical","alert","emergency")
                        AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY))
                    OR (LOWER(level) NOT IN ("warning","error","critical","alert","emergency")
                        AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 DAY))'
            ),
            'api_operation_samples' => $this->scalar(
                'SELECT COUNT(*) FROM api_operation_metric_samples
                 WHERE (successful=1 AND COALESCE(http_status,0)<400
                        AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 DAY))
                    OR ((successful=0 OR COALESCE(http_status,0)>=400)
                        AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY))'
            ),
            'webhook_events' => $this->scalar(
                'SELECT COUNT(*) FROM meli_webhook_events
                 WHERE (status IN ("processed","ignored")
                        AND received_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 DAY))
                    OR (status="error"
                        AND received_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY))'
            ),
        ] : array_fill_keys(self::DATASETS, null);
        $openPeriodDeferred = $deep
            ? $this->scalar(
                'SELECT
                    (SELECT COUNT(*)
                     FROM meli_notification_events
                     WHERE status IN ("processed","ignored","duplicate")
                       AND erp_received_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 DAY)
                       AND erp_received_at>=
                           UTC_DATE()-INTERVAL (DAY(UTC_DATE())-1) DAY)
                    +
                    (SELECT COUNT(*)
                     FROM api_request_logs
                     WHERE COALESCE(http_status,0)<400
                       AND actionable=0
                       AND risk_signal=0
                       AND was_blocked=0
                       AND COALESCE(outcome_class,"") NOT IN ("error","blocked")
                       AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 DAY)
                       AND created_at>=
                           UTC_DATE()-INTERVAL (DAY(UTC_DATE())-1) DAY)'
            )
            : null;
        $localRepairs = $deep
            ? $this->scalar(
                'SELECT
                    (SELECT COUNT(*)
                     FROM meli_notification_events
                     WHERE (canonical_topic="" OR canonical_topic IS NULL)
                       AND status IN ("queued","unknown_topic","waiting_retry"))
                    +
                    (SELECT COUNT(*)
                     FROM meli_notification_events
                     WHERE disposition IN (
                        "unknown_topic_legacy_local",
                        "recognized_ignored",
                        "satisfied_local",
                        "recognized_local_missing_no_replay"
                     )
                       AND error_message IS NOT NULL)'
            )
            : null;
        $payloads = $deep ? $this->payloadCandidates() : ['rows' => null, 'bytes' => null];
        $archiveRows = $pdo->query(
            'SELECT
                SUM(status="ready") AS ready_archives,
                COALESCE(SUM(CASE WHEN status="ready" THEN row_count ELSE 0 END),0) AS archived_rows,
                COALESCE(SUM(CASE WHEN status="ready" THEN size_bytes ELSE 0 END),0) AS archive_bytes
             FROM system_cold_archives'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $backup = $this->latestVerifiedBackup();
        $active = $pdo->query(
            'SELECT COUNT(*) FROM database_maintenance_sessions
             WHERE status IN ("running","pausing","finishing")'
        )->fetchColumn();
        $disk = @disk_free_space(AppPaths::privateRoot());
        $eligibleTotal = $deep ? array_sum(array_map('intval', $eligible)) : null;
        $actionableTotal = $deep
            ? (int) $eligibleTotal
                + (int) ($payloads['rows'] ?? 0)
                + (int) $localRepairs
            : null;
        return [
            'analysis_complete' => $deep,
            'generated_at' => gmdate(DATE_ATOM),
            'totals' => $totals,
            'largest_tables' => array_slice($tables, 0, 12),
            'eligible' => $eligible,
            'eligible_total' => $eligibleTotal,
            'open_period_deferred' => $openPeriodDeferred,
            'local_repairs' => $localRepairs,
            'actionable_total' => $actionableTotal,
            'payloads' => $payloads,
            'ready_archives' => (int) ($archiveRows['ready_archives'] ?? 0),
            'archived_rows' => (int) ($archiveRows['archived_rows'] ?? 0),
            'archive_bytes' => (int) ($archiveRows['archive_bytes'] ?? 0),
            'verified_backup' => null,
            'latest_verified_backup' => $backup,
            'active_sessions' => (int) $active,
            'disk_free_bytes' => is_float($disk) ? (int) $disk : null,
            'estimated_logical_release_bytes' => $deep
                ? max(
                    0,
                    (int) ($payloads['bytes'] ?? 0)
                        + (int) round((int) $eligibleTotal * $this->averageEligibleBytes($tables))
                )
                : null,
            'protected_tables' => self::PROTECTED_TABLES,
        ];
    }

    /** @return array<string,mixed> */
    public function analyze(int $userId): array
    {
        $analysis = $this->analysis();
        /*
         * El análisis nunca adopta la última copia global. La protección debe
         * nacer después y quedar vinculada por contexto a esta sesión exacta.
         */
        $binding = null;
        $analysis['verified_backup'] = null;
        $integrity = $this->integritySnapshot();
        $plan = [
            'datasets' => self::DATASETS,
            'payload_migration' => true,
            'orphan_cleanup' => true,
            'expired_cold_archive_cleanup' => true,
            'physical_recovery_separate' => true,
            'analysis' => $analysis,
            'backup_binding' => $binding,
            'protection' => [
                'mode' => '',
                'status' => 'required',
                'ready' => false,
                'label' => 'Protección pendiente',
                'message' => 'Elija copia local, respaldo externo o continuar sin respaldo antes de iniciar.',
            ],
        ];
        $stmt = Database::connection()->prepare(
            'INSERT INTO database_maintenance_sessions
             (public_id,requested_by,backup_id,status,phase,plan_json,counters_json,
              integrity_before_json,integrity_sha256,safe_message)
             VALUES (:public_id,:user_id,:backup_id,"analyzed","analysis",:plan,:counters,
                     :integrity,:integrity_hash,:message)'
        );
        $stmt->execute([
            'public_id' => $this->uuid(),
            'user_id' => $userId,
            'backup_id' => null,
            'plan' => $this->json($plan),
            'counters' => $this->json($this->emptyCounters()),
            'integrity' => $this->json($integrity),
            'integrity_hash' => $this->hash($integrity),
            'message' => (int) ($analysis['actionable_total'] ?? 0) > 0
                ? 'El análisis terminó. Revise la copia verificada antes de comenzar.'
                : 'El análisis terminó sin limpieza pendiente. Puede verificar nuevamente la integridad.',
        ]);
        return $this->session((int) Database::connection()->lastInsertId(), $userId);
    }

    /**
     * Fija una copia recién verificada a la misma sesión que la solicitó.
     * No cambia la huella del análisis y no sustituye copias de otras sesiones.
     */
    public function bindVerifiedBackup(int $sessionId, int $backupId, int $userId): void
    {
        $backup = $this->verifiedBackup($backupId, true);
        if ($backup === null || !$this->backupIsFresh($backup)) {
            throw new RuntimeException('La copia todavía no está lista para el saneamiento.');
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'SELECT id,requested_by,backup_id,status,plan_json,created_at,
                        integrity_before_json
                   FROM database_maintenance_sessions
                  WHERE id=:id AND requested_by=:user_id
                  LIMIT 1 FOR UPDATE'
            );
            $statement->execute(['id' => $sessionId, 'user_id' => $userId]);
            $session = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($session) || (string) $session['status'] !== 'analyzed') {
                throw new RuntimeException('La sesión de saneamiento ya no acepta una copia nueva.');
            }
            $currentBackup = max(0, (int) ($session['backup_id'] ?? 0));
            if ($currentBackup > 0 && $currentBackup !== $backupId) {
                throw new RuntimeException('La sesión ya está vinculada a otra copia.');
            }
            $verifiedAt = strtotime((string) ($backup['verified_at'] ?? '') . ' UTC');
            $analyzedAt = strtotime((string) ($session['created_at'] ?? '') . ' UTC');
            if (
                !is_int($verifiedAt)
                || !is_int($analyzedAt)
                || $verifiedAt < $analyzedAt
            ) {
                throw new RuntimeException('La copia debe ser posterior al análisis.');
            }
            if (
                (int) ($backup['requested_by'] ?? 0) !== $userId
                || (string) ($backup['context_type'] ?? '') !== 'database_sanitation'
                || (int) ($backup['context_id'] ?? 0) !== $sessionId
            ) {
                throw new RuntimeException(
                    'La copia no fue creada para esta sesión exacta de saneamiento.'
                );
            }
            $before = json_decode(
                (string) ($session['integrity_before_json'] ?? ''),
                true
            );
            $before = is_array($before) ? $before : [];
            if (!$this->integrityMatches($before, $this->integritySnapshot())) {
                throw new RuntimeException(
                    'La información comercial cambió después del análisis. '
                    . 'Ejecute nuevamente “Analizar sin borrar”.'
                );
            }
            $plan = json_decode((string) ($session['plan_json'] ?? '{}'), true);
            $plan = is_array($plan) ? $plan : [];
            $binding = $this->backupDescriptor($backup);
            $plan['backup_binding'] = $binding;
            $plan['protection'] = $this->protectionPlan(
                self::PROTECTION_ERP_BACKUP,
                'Copia ERP verificada #' . $backupId,
                'La sesión usará la copia cifrada verificada creada para este análisis.'
            );
            $plan['analysis'] = is_array($plan['analysis'] ?? null)
                ? $plan['analysis']
                : [];
            $plan['analysis']['verified_backup'] = $binding;
            $columns = (new SchemaInspectorService())->columns('database_maintenance_sessions');
            $set = 'backup_id=:backup_id,plan_json=:plan,safe_message=:message';
            $parameters = [
                'backup_id' => $backupId,
                'plan' => $this->json($plan),
                'message' => 'La copia vinculada está lista. Revise el plan antes de comenzar.',
                'id' => $sessionId,
                'user_id' => $userId,
            ];
            if (isset($columns['protection_mode'])) {
                $set .= ',protection_mode=:protection_mode,
                         protection_status="ready",
                         protection_confirmed_by=:confirmed_by,
                         protection_confirmed_at=UTC_TIMESTAMP(3),
                         protection_note=:protection_note';
                $parameters += [
                    'protection_mode' => self::PROTECTION_ERP_BACKUP,
                    'confirmed_by' => $userId,
                    'protection_note' => 'Copia ERP verificada #' . $backupId,
                ];
            }
            $pdo->prepare(
                'UPDATE database_maintenance_sessions
                    SET ' . $set . '
                  WHERE id=:id AND requested_by=:user_id AND status="analyzed"'
            )->execute($parameters);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function setProtection(
        int $sessionId,
        int $userId,
        string $mode,
        ?int $backupId = null
    ): array {
        $mode = trim($mode);
        if ($mode === self::PROTECTION_ERP_BACKUP) {
            $this->bindVerifiedBackup($sessionId, max(0, (int) $backupId), $userId);
            return $this->session($sessionId, $userId);
        }
        if (!in_array($mode, [self::PROTECTION_EXTERNAL, self::PROTECTION_WAIVED], true)) {
            throw new RuntimeException('La decisión de protección no es válida.');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $session = $this->lockedSession($sessionId, $userId);
            if ((string) ($session['status'] ?? '') !== 'analyzed') {
                throw new RuntimeException('La sesión ya no acepta cambios de protección.');
            }
            $this->assertSafety($sessionId);
            $before = json_decode((string) ($session['integrity_before_json'] ?? ''), true);
            $before = is_array($before) ? $before : [];
            if (!$this->integrityMatches($before, $this->integritySnapshot())) {
                throw new RuntimeException(
                    'La información comercial cambió desde el análisis. '
                    . 'Ejecute nuevamente “Analizar sin borrar”.'
                );
            }

            $plan = json_decode((string) ($session['plan_json'] ?? '{}'), true);
            $plan = is_array($plan) ? $plan : [];
            $label = $mode === self::PROTECTION_EXTERNAL
                ? 'Respaldo externo confirmado'
                : 'Sin respaldo interno';
            $message = $mode === self::PROTECTION_EXTERNAL
                ? 'El administrador confirmó que ya conserva una copia externa antes de sanear.'
                : 'El administrador aceptó ejecutar únicamente saneamiento lógico de ruido técnico sin respaldo interno.';
            $plan['backup_binding'] = null;
            $plan['protection'] = $this->protectionPlan($mode, $label, $message);
            $plan['analysis'] = is_array($plan['analysis'] ?? null) ? $plan['analysis'] : [];
            $plan['analysis']['verified_backup'] = null;

            $columns = (new SchemaInspectorService())->columns('database_maintenance_sessions');
            $set = 'backup_id=NULL,plan_json=:plan,safe_message=:message';
            $parameters = [
                'plan' => $this->json($plan),
                'message' => $label . '. Revise el plan e inicie primero un lote canario.',
                'id' => $sessionId,
                'user_id' => $userId,
            ];
            if (isset($columns['protection_mode'])) {
                $set .= ',protection_mode=:protection_mode,
                         protection_status="ready",
                         protection_confirmed_by=:confirmed_by,
                         protection_confirmed_at=UTC_TIMESTAMP(3),
                         protection_note=:protection_note,
                         canary_status="pending",
                         canary_checked_at=NULL';
                $parameters += [
                    'protection_mode' => $mode,
                    'confirmed_by' => $userId,
                    'protection_note' => $message,
                ];
            }
            $pdo->prepare(
                'UPDATE database_maintenance_sessions
                    SET ' . $set . '
                  WHERE id=:id AND requested_by=:user_id AND status="analyzed"'
            )->execute($parameters);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        return $this->session($sessionId, $userId);
    }

    /** @return array<string,mixed> */
    public function start(
        int $sessionId,
        int $userId,
        string $controlToken,
        ?int $requestedBackupId = null
    ): array
    {
        $controlToken = $this->validControlToken($controlToken);
        $exclusive = new MaintenanceExecutionLock();
        $exclusive->acquire();
        $pdo = Database::connection();
        $freeze = new DatabaseMutationFreezeService();
        $freezeActivated = false;
        $pdo->beginTransaction();
        try {
            $session = $this->lockedSession($sessionId, $userId);
            if (!in_array((string) $session['status'], ['analyzed', 'paused'], true)) {
                throw new RuntimeException('Esta sesión ya no puede comenzar.');
            }
            $this->assertSafety($sessionId);
            $plan = json_decode((string) ($session['plan_json'] ?? '{}'), true);
            $plan = is_array($plan) ? $plan : [];
            $binding = is_array($plan['backup_binding'] ?? null)
                ? $plan['backup_binding']
                : [];
            $protection = $this->protectionDescriptor($session, $plan);
            $boundBackupId = max(0, (int) ($session['backup_id'] ?? 0));
            $backupId = $requestedBackupId !== null
                ? max(0, $requestedBackupId)
                : $boundBackupId;
            if (!$protection['ready']) {
                throw new RuntimeException(
                    'Elija cómo proteger esta sesión antes de iniciar el saneamiento.'
                );
            }
            $backup = null;
            if ($protection['mode'] === self::PROTECTION_ERP_BACKUP) {
                if (
                    $backupId < 1
                    || $backupId !== $boundBackupId
                    || (int) ($binding['id'] ?? 0) !== $boundBackupId
                ) {
                    throw new RuntimeException(
                        'La copia vinculada cambió. Elija nuevamente la protección de esta sesión.'
                    );
                }
                $backup = $this->verifiedBackup($backupId, true);
                if ($backup === null) {
                    throw new RuntimeException(
                        'Cree y compruebe una copia cifrada antes de iniciar el saneamiento.'
                    );
                }
                $verifiedAt = strtotime((string) ($backup['verified_at'] ?? '') . ' UTC');
                $analyzedAt = strtotime((string) ($session['created_at'] ?? '') . ' UTC');
                if (
                    !is_int($verifiedAt)
                    || !is_int($analyzedAt)
                    || $verifiedAt < $analyzedAt
                    || (int) ($backup['requested_by'] ?? 0) !== $userId
                    || (string) ($backup['context_type'] ?? '') !== 'database_sanitation'
                    || (int) ($backup['context_id'] ?? 0) !== $sessionId
                ) {
                    throw new RuntimeException(
                        'La copia vinculada no pertenece a este análisis exacto.'
                    );
                }
                foreach (['checksum_sha256', 'manifest_sha256'] as $fingerprint) {
                    $expected = strtolower((string) ($binding[$fingerprint] ?? ''));
                    $current = strtolower((string) ($backup[$fingerprint] ?? ''));
                    if (
                        preg_match('/^[a-f0-9]{64}$/', $expected) !== 1
                        || !hash_equals($expected, $current)
                    ) {
                        throw new RuntimeException(
                            'La copia vinculada cambió después del análisis. '
                            . 'No se inició ninguna eliminación.'
                        );
                    }
                }
            }
            $before = json_decode(
                (string) ($session['integrity_before_json'] ?? ''),
                true
            );
            $before = is_array($before) ? $before : [];
            if (!$this->integrityMatches($before, $this->integritySnapshot())) {
                throw new RuntimeException(
                    'La información comercial cambió desde el análisis. '
                    . 'No se inició ninguna eliminación.'
                );
            }
            $resumePhase = (string) $session['phase'] === 'analysis'
                ? 'legacy_notifications'
                : (string) $session['phase'];
            $resumePosition = max(0, (int) $session['dataset_position']);
            if ($resumePhase === 'payloads' && $resumePosition >= count(self::PAYLOAD_TABLES)) {
                // Compatibilidad con sesiones iniciadas antes del cursor por tabla.
                $resumePosition = 0;
            }
            $resumeDataset = match ($resumePhase) {
                'retention' => self::DATASETS[$resumePosition] ?? self::DATASETS[0],
                'payloads' => self::PAYLOAD_TABLES[$resumePosition] ?? self::PAYLOAD_TABLES[0],
                default => null,
            };
            $freeze->activate(
                'database_sanitation',
                'session:' . $sessionId,
                ['session_id' => $sessionId, 'user_id' => $userId]
            );
            $freezeActivated = true;
            $stmt = $pdo->prepare(
                'UPDATE database_maintenance_sessions
                 SET status="running",phase=:phase,
                     dataset_key=:dataset,dataset_position=:position,
                     backup_id=:backup_id,
                     control_token_hash=:token_hash,
                     control_expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 45 SECOND),
                     started_at=COALESCE(started_at,UTC_TIMESTAMP(3)),
                     paused_at=NULL,safe_message=:message
                 WHERE id=:id AND requested_by=:user_id'
            );
            $stmt->execute([
                'phase' => $resumePhase,
                'dataset' => $resumeDataset,
                'position' => $resumePosition,
                'backup_id' => $backup === null ? null : (int) $backup['id'],
                'token_hash' => hash('sha256', $controlToken),
                'message' => 'Saneamiento autorizado con '
                    . mb_strtolower((string) $protection['label'])
                    . '. Esta pestaña ejecutará micro-lotes locales y guardará un checkpoint.',
                'id' => $sessionId,
                'user_id' => $userId,
            ]);
            $pdo->commit();
            return $this->session($sessionId, $userId);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($freezeActivated) {
                try {
                    $freeze->release('database_sanitation', 'session:' . $sessionId);
                } catch (Throwable) {
                    // El marcador queda deliberadamente activo si no puede
                    // retirarse con certeza; un administrador deberá revisarlo.
                }
            }
            throw $error;
        } finally {
            $exclusive->release();
        }
    }

    /**
     * Ejecuta un solo micro-paso desde PHP CLI. La web nunca llama este método.
     *
     * @return array<string,mixed>|null
     */
    public function runCliStep(int $sessionId): ?array
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('El saneamiento solo puede modificar datos desde PHP CLI.');
        }
        $exclusive = new MaintenanceExecutionLock();
        $exclusive->acquire();
        $owner = bin2hex(random_bytes(32));
        $token = 'cli-' . bin2hex(random_bytes(24));
        $generation = 0;
        try {
            $physicalClaim = $this->claimPhysicalRecovery($sessionId, $owner);
            if ($physicalClaim !== null) {
                $generation = (int) $physicalClaim['generation'];
                try {
                    $result = $this->performClaimedPhysicalRecovery(
                        $sessionId,
                        (int) $physicalClaim['requested_by'],
                        (string) $physicalClaim['table'],
                        $owner,
                        $generation
                    );
                    return $result;
                } catch (Throwable $error) {
                    $this->failClaimedPhysicalRecovery(
                        $sessionId,
                        (int) $physicalClaim['requested_by'],
                        $owner,
                        $generation,
                        $error
                    );
                    throw $error;
                }
            }
            $claim = $this->claimCliStep($sessionId, $owner, $token);
            if ($claim === null) {
                return null;
            }
            $generation = (int) $claim['generation'];
            return $this->step(
                $sessionId,
                (int) $claim['requested_by'],
                $token,
                'cli-' . bin2hex(random_bytes(32)),
                $owner,
                $generation
            );
        } finally {
            if ($generation > 0) {
                $this->releaseCliLease($sessionId, $owner, $generation);
            }
            $exclusive->release();
        }
    }

    /**
     * Ejecuta un único micro-lote local desde una pestaña administrativa.
     * No inicia trabajo de fondo: al cerrar el navegador no se ejecutan más pasos.
     *
     * @return array<string,mixed>|null
     */
    public function runInteractiveStep(int $sessionId, int $userId, string $controlToken): ?array
    {
        $exclusive = new MaintenanceExecutionLock();
        $exclusive->acquire();
        $owner = bin2hex(random_bytes(32));
        $generation = 0;
        try {
            $claim = $this->claimCliStep($sessionId, $owner, $controlToken);
            if ($claim === null) {
                return null;
            }
            $generation = (int) $claim['generation'];
            if ((int) $claim['requested_by'] !== $userId) {
                throw new RuntimeException('La sesión de saneamiento no pertenece al administrador actual.');
            }
            return $this->step(
                $sessionId,
                $userId,
                $controlToken,
                'web-' . bin2hex(random_bytes(32)),
                $owner,
                $generation
            );
        } finally {
            if ($generation > 0) {
                $this->releaseCliLease($sessionId, $owner, $generation);
            }
            $exclusive->release();
        }
    }

    /**
     * Encola una sola reconstrucción exacta. La petición web únicamente deja
     * el encargo de recuperación física de forma separada al saneamiento lógico.
     *
     * @return array<string,mixed>
     */
    public function requestPhysicalRecovery(
        int $sessionId,
        int $userId,
        string $table,
        bool $confirmed
    ): array {
        if (!$confirmed) {
            throw new RuntimeException('Confirme la contraseña administrativa antes de preparar esta tabla.');
        }
        $table = trim($table);
        $planRows = (new PhysicalTableRecoveryService())->plan();
        $eligible = false;
        foreach ($planRows as $row) {
            if ((string) ($row['table'] ?? '') === $table && !empty($row['eligible'])) {
                $eligible = true;
                break;
            }
        }
        if (!$eligible) {
            throw new RuntimeException('La tabla ya no requiere recuperación física.');
        }

        $exclusive = new MaintenanceExecutionLock();
        $exclusive->acquire();
        $freeze = new DatabaseMutationFreezeService();
        $freezeActivated = false;
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $session = $this->lockedSession($sessionId, $userId);
            if ((string) ($session['status'] ?? '') !== 'completed') {
                throw new RuntimeException(
                    'La recuperación física solo se prepara después del saneamiento verificado.'
                );
            }
            $plan = json_decode((string) ($session['plan_json'] ?? '{}'), true);
            $plan = is_array($plan) ? $plan : [];
            $protection = $this->protectionDescriptor($session, $plan);
            if ($protection['mode'] === self::PROTECTION_WAIVED) {
                throw new RuntimeException(
                    'La recuperación física requiere copia ERP verificada o respaldo externo confirmado.'
                );
            }
            if (
                $protection['mode'] === self::PROTECTION_ERP_BACKUP
                && $this->verifiedBackup((int) ($session['backup_id'] ?? 0), true) === null
            ) {
                throw new RuntimeException(
                    'La copia exacta de esta sesión ya no está disponible o no supera su verificación.'
                );
            }
            $this->assertSafety($sessionId);
            $current = is_array($plan['physical_recovery'] ?? null)
                ? $plan['physical_recovery']
                : [];
            if (in_array((string) ($current['status'] ?? ''), ['queued', 'running'], true)) {
                if ((string) ($current['table'] ?? '') === $table) {
                    $pdo->commit();
                    return $this->session($sessionId, $userId);
                }
                throw new RuntimeException(
                    'Ya existe otra tabla preparada para recuperación física. Espere su resultado.'
                );
            }
            $freeze->activate(
                'database_sanitation',
                'session:' . $sessionId,
                ['session_id' => $sessionId, 'user_id' => $userId]
            );
            $freezeActivated = true;
            $plan['physical_recovery'] = [
                'request_id' => bin2hex(random_bytes(16)),
                'table' => $table,
                'status' => 'queued',
                'requested_at' => gmdate(DATE_ATOM),
                'started_at' => null,
                'completed_at' => null,
                'generation' => null,
                'safe_message' => 'Tabla preparada para recuperación física controlada.',
            ];
            $stmt = $pdo->prepare(
                'UPDATE database_maintenance_sessions
                 SET plan_json=:plan,safe_message=:message
                 WHERE id=:id AND requested_by=:user_id AND status="completed"'
            );
            $stmt->execute([
                'plan' => $this->json($plan),
                'message' => 'Recuperación física preparada para ' . $table . '.',
                'id' => $sessionId,
                'user_id' => $userId,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('La sesión cambió antes de preparar la tabla.');
            }
            $pdo->commit();
            return $this->session($sessionId, $userId);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($freezeActivated) {
                try {
                    $freeze->release('database_sanitation', 'session:' . $sessionId);
                } catch (Throwable) {
                    // Conservar la protección es más seguro que retirarla sin certeza.
                }
            }
            throw $error;
        } finally {
            $exclusive->release();
        }
    }

    /** @return array<string,mixed> */
    public function runCliRebuild(int $sessionId, string $table): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('La reconstrucción física solo puede ejecutarse desde PHP CLI.');
        }
        $stmt = Database::connection()->prepare(
            'SELECT requested_by FROM database_maintenance_sessions WHERE id=:id LIMIT 1'
        );
        $stmt->execute(['id' => $sessionId]);
        $userId = (int) ($stmt->fetchColumn() ?: 0);
        if ($userId < 1) {
            throw new RuntimeException('No se encontró la sesión de saneamiento.');
        }
        return $this->rebuild($sessionId, $userId, $table, true);
    }

    /**
     * @return array{requested_by:int,table:string,generation:int}|null
     */
    private function claimPhysicalRecovery(int $sessionId, string $owner): ?array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM database_maintenance_sessions
                 WHERE id=:id LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['id' => $sessionId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($session)) {
                throw new RuntimeException('No se encontró la sesión de saneamiento.');
            }
            if (!in_array((string) ($session['status'] ?? ''), ['completed', 'finished'], true)) {
                $pdo->commit();
                return null;
            }
            $plan = json_decode((string) ($session['plan_json'] ?? '{}'), true);
            $plan = is_array($plan) ? $plan : [];
            $request = is_array($plan['physical_recovery'] ?? null)
                ? $plan['physical_recovery']
                : [];
            $requestStatus = (string) ($request['status'] ?? '');
            if ($requestStatus === 'running') {
                $leaseExpired = empty($session['lease_expires_at'])
                    || strtotime((string) $session['lease_expires_at'] . ' UTC') < time();
                if ($leaseExpired) {
                    $request['status'] = 'needs_review';
                    $request['safe_message'] = 'La reconstrucción fue interrumpida. '
                        . 'No se repetirá hasta comprobar el estado de MariaDB.';
                    $request['reviewed_at'] = gmdate(DATE_ATOM);
                    $plan['physical_recovery'] = $request;
                    $pdo->prepare(
                        'UPDATE database_maintenance_sessions
                         SET plan_json=:plan,lease_owner=NULL,lease_expires_at=NULL,
                             lease_heartbeat_at=NULL,safe_message=:message
                         WHERE id=:id'
                    )->execute([
                        'plan' => $this->json($plan),
                        'message' => (string) $request['safe_message'],
                        'id' => $sessionId,
                    ]);
                }
                $pdo->commit();
                return null;
            }
            if ($requestStatus !== 'queued') {
                $pdo->commit();
                return null;
            }
            $table = trim((string) ($request['table'] ?? ''));
            if ($table === '') {
                throw new RuntimeException('La solicitud física no identifica una tabla.');
            }
            $generation = max(
                (int) ($session['generation'] ?? 0),
                (int) ($session['lease_generation'] ?? 0)
            ) + 1;
            $request['status'] = 'running';
            $request['started_at'] = gmdate(DATE_ATOM);
            $request['generation'] = $generation;
            $request['lease_owner'] = $owner;
            $request['safe_message'] = 'MariaDB está reconstruyendo una tabla.';
            $plan['physical_recovery'] = $request;
            $claim = $pdo->prepare(
                'UPDATE database_maintenance_sessions
                 SET plan_json=:plan,generation=:generation,
                     lease_generation=:lease_generation,lease_owner=:lease_owner,
                     lease_heartbeat_at=UTC_TIMESTAMP(3),
                     lease_expires_at=DATE_ADD(
                         UTC_TIMESTAMP(3),INTERVAL ' . self::CLI_LEASE_SECONDS . ' SECOND
                     ),safe_message=:message
                 WHERE id=:id AND status IN ("completed","finished")'
            );
            $claim->execute([
                'plan' => $this->json($plan),
                'generation' => $generation,
                'lease_generation' => $generation,
                'lease_owner' => $owner,
                'message' => 'Recuperando espacio físico de ' . $table . '.',
                'id' => $sessionId,
            ]);
            if ($claim->rowCount() !== 1) {
                $pdo->rollBack();
                return null;
            }
            $pdo->commit();
            return [
                'requested_by' => (int) ($session['requested_by'] ?? 0),
                'table' => $table,
                'generation' => $generation,
            ];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    private function performClaimedPhysicalRecovery(
        int $sessionId,
        int $userId,
        string $table,
        string $owner,
        int $generation
    ): array {
        $this->assertSafety($sessionId);
        (new DatabaseMutationFreezeService())->heartbeat(
            'database_sanitation',
            'session:' . $sessionId
        );
        $session = $this->session($sessionId, $userId);
        if ($this->verifiedBackup((int) ($session['backup_id'] ?? 0), true) === null) {
            throw new RuntimeException('La copia exacta dejó de superar la verificación.');
        }
        $started = microtime(true);
        $physical = (new PhysicalTableRecoveryService())->rebuild($table, true);
        $duration = max(0, (int) round((microtime(true) - $started) * 1000));
        $updated = $this->completeClaimedPhysicalRecovery(
            $sessionId,
            $userId,
            $owner,
            $generation,
            $physical,
            $duration
        );
        (new DatabaseMutationFreezeService())->release(
            'database_sanitation',
            'session:' . $sessionId
        );
        return [
            'ok' => true,
            'action' => [
                'kind' => 'physical_recovery',
                'table' => $table,
                'message' => (string) (
                    $updated['plan']['physical_recovery']['safe_message']
                    ?? 'La recuperación física terminó.'
                ),
            ],
            'duration_ms' => $duration,
            'server_time' => gmdate(DATE_ATOM),
            'status' => $updated,
        ];
    }

    /**
     * @param array<string,mixed> $physical
     * @return array<string,mixed>
     */
    private function completeClaimedPhysicalRecovery(
        int $sessionId,
        int $userId,
        string $owner,
        int $generation,
        array $physical,
        int $durationMs
    ): array {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $session = $this->lockedSession($sessionId, $userId);
            if (
                (int) ($session['lease_generation'] ?? 0) !== $generation
                || !hash_equals((string) ($session['lease_owner'] ?? ''), $owner)
            ) {
                throw new RuntimeException(
                    'El resultado físico llegó después de perder su autorización.'
                );
            }
            $plan = json_decode((string) ($session['plan_json'] ?? '{}'), true);
            $plan = is_array($plan) ? $plan : [];
            $request = is_array($plan['physical_recovery'] ?? null)
                ? $plan['physical_recovery']
                : [];
            if (
                (string) ($request['status'] ?? '') !== 'running'
                || (int) ($request['generation'] ?? 0) !== $generation
                || !hash_equals((string) ($request['lease_owner'] ?? ''), $owner)
            ) {
                throw new RuntimeException('La solicitud física ya no pertenece a este proceso.');
            }
            $resultStatus = (string) ($physical['status'] ?? 'completed');
            $request['status'] = $resultStatus === 'not_required' ? 'not_required' : 'completed';
            $request['completed_at'] = gmdate(DATE_ATOM);
            $request['duration_ms'] = $durationMs;
            $request['safe_message'] = $resultStatus === 'not_required'
                ? 'La tabla ya no requería reconstrucción.'
                : 'La tabla fue reconstruida y comprobada.';
            unset($request['lease_owner']);
            $plan['physical_recovery'] = $request;
            $update = $pdo->prepare(
                'UPDATE database_maintenance_sessions
                 SET plan_json=:plan,lease_owner=NULL,lease_expires_at=NULL,
                     lease_heartbeat_at=NULL,safe_message=:message
                 WHERE id=:id AND requested_by=:user_id
                   AND lease_generation=:generation AND lease_owner=:lease_owner'
            );
            $update->execute([
                'plan' => $this->json($plan),
                'message' => (string) $request['safe_message'],
                'id' => $sessionId,
                'user_id' => $userId,
                'generation' => $generation,
                'lease_owner' => $owner,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('El resultado físico no pudo aprobarse.');
            }
            $pdo->commit();
            return $this->session($sessionId, $userId);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function failClaimedPhysicalRecovery(
        int $sessionId,
        int $userId,
        string $owner,
        int $generation,
        Throwable $error
    ): void {
        $pdo = null;
        try {
            $pdo = Database::connection();
            $pdo->beginTransaction();
            $session = $this->lockedSession($sessionId, $userId);
            if (
                (int) ($session['lease_generation'] ?? 0) !== $generation
                || !hash_equals((string) ($session['lease_owner'] ?? ''), $owner)
            ) {
                $pdo->rollBack();
                return;
            }
            $plan = json_decode((string) ($session['plan_json'] ?? '{}'), true);
            $plan = is_array($plan) ? $plan : [];
            $request = is_array($plan['physical_recovery'] ?? null)
                ? $plan['physical_recovery']
                : [];
            if (
                (string) ($request['status'] ?? '') !== 'running'
                || (int) ($request['generation'] ?? 0) !== $generation
                || !hash_equals((string) ($request['lease_owner'] ?? ''), $owner)
            ) {
                $pdo->rollBack();
                return;
            }
            $message = $this->safeFailure($error);
            $request['status'] = 'failed';
            $request['completed_at'] = gmdate(DATE_ATOM);
            $request['safe_message'] = $message;
            unset($request['lease_owner']);
            $plan['physical_recovery'] = $request;
            $pdo->prepare(
                'UPDATE database_maintenance_sessions
                 SET plan_json=:plan,lease_owner=NULL,lease_expires_at=NULL,
                     lease_heartbeat_at=NULL,safe_message=:message
                 WHERE id=:id AND requested_by=:user_id
                   AND lease_generation=:generation AND lease_owner=:lease_owner'
            )->execute([
                'plan' => $this->json($plan),
                'message' => $message,
                'id' => $sessionId,
                'user_id' => $userId,
                'generation' => $generation,
                'lease_owner' => $owner,
            ]);
            $pdo->commit();
            try {
                (new DatabaseMutationFreezeService())->release(
                    'database_sanitation',
                    'session:' . $sessionId
                );
            } catch (Throwable) {
                // El freno permanece si no es posible confirmar su propietario.
            }
        } catch (Throwable) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    /**
     * Reclama un único lote. Un lease vencido con un paso sin aprobar no se
     * repite: queda pausado para revisión desde el último checkpoint aprobado.
     *
     * @return array{requested_by:int,generation:int}|null
     */
    private function claimCliStep(int $sessionId, string $owner, string $token): ?array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT *
                 FROM database_maintenance_sessions
                 WHERE id=:id LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['id' => $sessionId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($session)) {
                throw new RuntimeException('No se encontró la sesión de saneamiento.');
            }
            $userId = (int) ($session['requested_by'] ?? 0);
            if (in_array((string) ($session['status'] ?? ''), ['pausing', 'finishing'], true)) {
                $pdo->commit();
                $this->settleControlRequest($sessionId, $userId);
                return null;
            }
            if ((string) ($session['status'] ?? '') !== 'running') {
                $pdo->commit();
                return null;
            }

            $running = $pdo->prepare(
                'SELECT id,generation,lease_owner
                 FROM database_maintenance_steps
                 WHERE maintenance_session_id=:session_id AND status="running"
                 ORDER BY id LIMIT 1 FOR UPDATE'
            );
            $running->execute(['session_id' => $sessionId]);
            $unfinished = $running->fetch(PDO::FETCH_ASSOC);
            if (is_array($unfinished)) {
                $leaseExpired = empty($session['lease_expires_at'])
                    || strtotime((string) $session['lease_expires_at'] . ' UTC') < time();
                if (!$leaseExpired) {
                    $pdo->commit();
                    return null;
                }
                $newGeneration = max(
                    (int) ($session['generation'] ?? 0),
                    (int) ($session['lease_generation'] ?? 0)
                ) + 1;
                $pdo->prepare(
                    'UPDATE database_maintenance_steps
                     SET status="failed",completed_at=UTC_TIMESTAMP(3),
                         safe_message=:message
                     WHERE id=:id AND status="running"'
                )->execute([
                    'message' => 'El proceso terminó sin aprobar el lote; no se repetirá automáticamente.',
                    'id' => (int) $unfinished['id'],
                ]);
                $pdo->prepare(
                    'UPDATE database_maintenance_sessions
                     SET status="paused",generation=:generation,
                         lease_generation=:lease_generation,lease_owner=NULL,
                         lease_expires_at=NULL,lease_heartbeat_at=NULL,
                         control_expires_at=NULL,paused_at=UTC_TIMESTAMP(3),
                         safe_message=:message
                     WHERE id=:id'
                )->execute([
                    'generation' => $newGeneration,
                    'lease_generation' => $newGeneration,
                    'message' => 'Un lote quedó sin confirmación. Se conservó el último checkpoint '
                        . 'aprobado y la sesión requiere revisión.',
                    'id' => $sessionId,
                ]);
                $pdo->commit();
                return null;
            }

            $leaseLive = !empty($session['lease_owner'])
                && !empty($session['lease_expires_at'])
                && strtotime((string) $session['lease_expires_at'] . ' UTC') >= time();
            if ($leaseLive) {
                $pdo->commit();
                return null;
            }
            $generation = max(
                (int) ($session['generation'] ?? 0),
                (int) ($session['lease_generation'] ?? 0)
            ) + 1;
            $claim = $pdo->prepare(
                'UPDATE database_maintenance_sessions
                 SET generation=:generation,lease_generation=:lease_generation,
                     lease_owner=:lease_owner,lease_heartbeat_at=UTC_TIMESTAMP(3),
                     lease_expires_at=DATE_ADD(
                         UTC_TIMESTAMP(3),INTERVAL ' . self::CLI_LEASE_SECONDS . ' SECOND
                     ),
                     control_token_hash=:token_hash,
                     control_expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 45 SECOND)
                 WHERE id=:id AND status="running"'
            );
            $claim->execute([
                'generation' => $generation,
                'lease_generation' => $generation,
                'lease_owner' => $owner,
                'token_hash' => hash('sha256', $token),
                'id' => $sessionId,
            ]);
            if ($claim->rowCount() !== 1) {
                $pdo->rollBack();
                return null;
            }
            $pdo->commit();
            return ['requested_by' => $userId, 'generation' => $generation];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function releaseCliLease(int $sessionId, string $owner, int $generation): void
    {
        try {
            Database::connection()->prepare(
                'UPDATE database_maintenance_sessions
                 SET lease_owner=NULL,lease_expires_at=NULL,lease_heartbeat_at=NULL
                 WHERE id=:id AND lease_owner=:lease_owner
                   AND lease_generation=:lease_generation'
            )->execute([
                'id' => $sessionId,
                'lease_owner' => $owner,
                'lease_generation' => $generation,
            ]);
        } catch (Throwable) {
            // El lease expira. Nunca se modifica una generación posterior.
        }
    }

    /** @return array<string,mixed> */
    public function step(
        int $sessionId,
        int $userId,
        string $controlToken,
        string $idempotencyKey,
        string $leaseOwner = '',
        int $leaseGeneration = 0
    ): array {
        $controlToken = $this->validControlToken($controlToken);
        if (
            preg_match('/^[a-f0-9]{64}$/', $leaseOwner) !== 1
            || $leaseGeneration < 1
        ) {
            throw new RuntimeException('El lote no tiene un lease local válido.');
        }
        $idempotencyKey = trim($idempotencyKey);
        if (preg_match('/^[A-Za-z0-9._:-]{12,160}$/', $idempotencyKey) !== 1) {
            throw new RuntimeException('El identificador del paso no es válido.');
        }
        $keyHash = hash('sha256', $idempotencyKey);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $session = $this->lockedSession($sessionId, $userId);
            $existing = $pdo->prepare(
                'SELECT status,result_json FROM database_maintenance_steps
                 WHERE maintenance_session_id=:session_id
                   AND idempotency_key=:idempotency_key
                 LIMIT 1'
            );
            $existing->execute([
                'session_id' => $sessionId,
                'idempotency_key' => $keyHash,
            ]);
            $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
            $stored = is_array($existingRow)
                ? (string) ($existingRow['result_json'] ?? '')
                : '';
            if (
                is_array($existingRow)
                && (string) $existingRow['status'] === 'completed'
                && $stored !== ''
            ) {
                $pdo->commit();
                $result = json_decode($stored, true);
                if (!is_array($result)) {
                    return $this->status($sessionId, $userId);
                }
                $result['status'] = $this->session($sessionId, $userId);
                return $result;
            }
            if ((string) $session['status'] !== 'running') {
                throw new RuntimeException('La sesión está pausada o ya terminó.');
            }
            $leaseExpired = empty($session['lease_expires_at'])
                || strtotime((string) $session['lease_expires_at'] . ' UTC') < time();
            if (
                $leaseExpired
                || !hash_equals((string) ($session['lease_owner'] ?? ''), $leaseOwner)
                || (int) ($session['lease_generation'] ?? 0) !== $leaseGeneration
                || (int) ($session['generation'] ?? 0) !== $leaseGeneration
            ) {
                throw new RuntimeException('El lote perdió su lease local antes de comenzar.');
            }
            $this->assertSafety($sessionId);
            (new DatabaseMutationFreezeService())->heartbeat(
                'database_sanitation',
                'session:' . $sessionId
            );
            $plan = json_decode((string) ($session['plan_json'] ?? '{}'), true);
            $plan = is_array($plan) ? $plan : [];
            $protection = $this->protectionDescriptor($session, $plan);
            if (!$protection['ready']) {
                throw new RuntimeException('La protección de esta sesión dejó de estar vigente.');
            }
            if (
                $protection['mode'] === self::PROTECTION_ERP_BACKUP
                && $this->verifiedBackup((int) ($session['backup_id'] ?? 0)) === null
            ) {
                throw new RuntimeException('La copia verificada dejó de estar disponible.');
            }
            $knownToken = (string) ($session['control_token_hash'] ?? '');
            $tokenHash = hash('sha256', $controlToken);
            $controlExpired = empty($session['control_expires_at'])
                || strtotime((string) $session['control_expires_at'] . ' UTC') < time();
            if ($knownToken !== '' && !$controlExpired && !hash_equals($knownToken, $tokenHash)) {
                throw new RuntimeException('Esta sesión está siendo controlada en otra pestaña.');
            }
            $running = $pdo->prepare(
                'SELECT id,started_at
                 FROM database_maintenance_steps
                 WHERE maintenance_session_id=:session_id AND status="running"
                 ORDER BY id LIMIT 1 FOR UPDATE'
            );
            $running->execute(['session_id' => $sessionId]);
            $interrupted = $running->fetch(PDO::FETCH_ASSOC);
            if (is_array($interrupted)) {
                if (!$controlExpired) {
                    throw new RuntimeException('Ya existe un micro-paso en curso.');
                }
                $pdo->prepare(
                    'UPDATE database_maintenance_steps
                     SET status="failed",completed_at=UTC_TIMESTAMP(3),
                         safe_message=:message,
                         result_json=:result
                     WHERE id=:id AND maintenance_session_id=:session_id AND status="running"'
                )->execute([
                    'message' => 'El micro-lote anterior quedó sin confirmación y fue cerrado para continuar.',
                    'result' => json_encode([
                        'interrupted' => true,
                        'approved' => false,
                    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'id' => (int) $interrupted['id'],
                    'session_id' => $sessionId,
                ]);
            }
            if (is_array($existingRow)) {
                throw new RuntimeException(
                    'El micro-paso anterior ya fue cerrado. Recargue el estado antes de continuar.'
                );
            }
            $sequence = $this->nextSequence($sessionId);
            $stmt = $pdo->prepare(
                'INSERT INTO database_maintenance_steps
                 (maintenance_session_id,sequence_no,idempotency_key,generation,
                  lease_owner,phase,dataset_key,status)
                 VALUES (:session_id,:sequence_no,:idempotency_key,:generation,
                         :lease_owner,:phase,:dataset,"running")'
            );
            $stmt->execute([
                'session_id' => $sessionId,
                'sequence_no' => $sequence,
                'idempotency_key' => $keyHash,
                'generation' => $leaseGeneration,
                'lease_owner' => $leaseOwner,
                'phase' => (string) $session['phase'],
                'dataset' => $session['dataset_key'],
            ]);
            $stepId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'UPDATE database_maintenance_sessions
                 SET control_token_hash=:token_hash,
                     control_expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 45 SECOND),
                     lease_heartbeat_at=UTC_TIMESTAMP(3),
                     lease_expires_at=DATE_ADD(
                         UTC_TIMESTAMP(3),INTERVAL ' . self::CLI_LEASE_SECONDS . ' SECOND
                     ),
                     last_step_at=UTC_TIMESTAMP(3)
                 WHERE id=:id AND lease_owner=:lease_owner
                   AND lease_generation=:lease_generation'
            )->execute([
                'token_hash' => $tokenHash,
                'id' => $sessionId,
                'lease_owner' => $leaseOwner,
                'lease_generation' => $leaseGeneration,
            ]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        $started = microtime(true);
        try {
            $action = $this->performOneAction($session);
            $duration = (int) round((microtime(true) - $started) * 1000);
            return $this->approveStep(
                $sessionId,
                $userId,
                $stepId,
                $leaseGeneration,
                $leaseOwner,
                $action,
                $duration
            );
        } catch (Throwable $error) {
            $duration = (int) round((microtime(true) - $started) * 1000);
            $this->failStep(
                $sessionId,
                $userId,
                $stepId,
                $leaseGeneration,
                $leaseOwner,
                hash('sha256', $controlToken),
                $error,
                $duration
            );
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function pause(int $sessionId, int $userId): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $session = $this->lockedSession($sessionId, $userId);
            $plan = json_decode((string) ($session['plan_json'] ?? '{}'), true);
            $plan = is_array($plan) ? $plan : [];
            $plan['control_request'] = 'pause';
            $pdo->prepare(
            'UPDATE database_maintenance_sessions
             SET status="pausing",plan_json=:plan,
                 safe_message=:message
             WHERE id=:id AND requested_by=:user_id AND status="running"'
            )->execute([
                'plan' => $this->json($plan),
                'message' => 'Pausa solicitada. Se cerrará primero el lote que ya esté en curso.',
                'id' => $sessionId,
                'user_id' => $userId,
            ]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        $this->settleControlRequest($sessionId, $userId);
        return $this->session($sessionId, $userId);
    }

    /** @return array<string,mixed> */
    public function finish(int $sessionId, int $userId): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $session = $this->lockedSession($sessionId, $userId);
            if (in_array((string) $session['status'], ['completed', 'finished'], true)) {
                $pdo->commit();
                return $this->session($sessionId, $userId);
            }
            if (!in_array((string) $session['status'], ['running', 'paused', 'analyzed'], true)) {
                throw new RuntimeException('Esta sesión no puede finalizar en su estado actual.');
            }
            $plan = json_decode((string) ($session['plan_json'] ?? '{}'), true);
            $plan = is_array($plan) ? $plan : [];
            $plan['control_request'] = 'finish';
            $plan['finish_after_verify'] = true;
            $finishingStatus = $this->maintenanceStatusSupports('finishing')
                ? 'finishing'
                : 'pausing';
            $pdo->prepare(
                'UPDATE database_maintenance_sessions
                 SET status=:finishing_status,plan_json=:plan,safe_message=:message
                 WHERE id=:id AND requested_by=:user_id'
            )->execute([
                'finishing_status' => $finishingStatus,
                'plan' => $this->json($plan),
                'message' => 'Finalización solicitada. Se verificará la integridad antes de liberar la base.',
                'id' => $sessionId,
                'user_id' => $userId,
            ]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        $this->settleControlRequest($sessionId, $userId);
        return $this->session($sessionId, $userId);
    }

    /**
     * Aplica una solicitud de control solo cuando no existe un micro-lote
     * ejecutándose. Finalizar nunca omite la fase de verificación ni libera el
     * freeze desde la petición web.
     */
    private function settleControlRequest(int $sessionId, int $userId): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $session = $this->lockedSession($sessionId, $userId);
            if (!in_array((string) $session['status'], ['pausing', 'finishing'], true)) {
                $pdo->commit();
                return;
            }
            $running = $pdo->prepare(
                'SELECT COUNT(*) FROM database_maintenance_steps
                 WHERE maintenance_session_id=:session_id AND status="running"'
            );
            $running->execute(['session_id' => $sessionId]);
            if ((int) $running->fetchColumn() > 0) {
                $pdo->commit();
                return;
            }
            $plan = json_decode((string) ($session['plan_json'] ?? '{}'), true);
            $plan = is_array($plan) ? $plan : [];
            $request = (string) ($plan['control_request'] ?? 'pause');
            unset($plan['control_request']);
            if ($request === 'finish') {
                $plan['finish_after_verify'] = true;
                $plan['verification_after'] = [];
                $plan['verification_position'] = 0;
                $pdo->prepare(
                    'UPDATE database_maintenance_sessions
                     SET status="running",phase="verify",dataset_key=NULL,
                         dataset_position=0,plan_json=:plan,
                         control_expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 45 SECOND),
                         safe_message=:message
                     WHERE id=:id AND requested_by=:user_id
                       AND status IN ("pausing","finishing")'
                )->execute([
                    'plan' => $this->json($plan),
                    'message' => 'Verificando integridad antes de finalizar.',
                    'id' => $sessionId,
                    'user_id' => $userId,
                ]);
            } else {
                $pdo->prepare(
                    'UPDATE database_maintenance_sessions
                     SET status="paused",paused_at=UTC_TIMESTAMP(3),
                         plan_json=:plan,control_expires_at=NULL,
                         safe_message=:message
                     WHERE id=:id AND requested_by=:user_id
                       AND status IN ("pausing","finishing")'
                )->execute([
                    'plan' => $this->json($plan),
                    'message' => 'Saneamiento pausado después del último lote aprobado.',
                    'id' => $sessionId,
                    'user_id' => $userId,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function status(int $sessionId, int $userId): array
    {
        $session = $this->session($sessionId, $userId);
        $physical = is_array($session['plan']['physical_recovery'] ?? null)
            ? $session['plan']['physical_recovery']
            : null;
        return [
            'ok' => true,
            'server_time' => gmdate(DATE_ATOM),
            'session' => $session,
            'recent_steps' => $this->recentSteps($sessionId),
            'recovery_plan' => (string) $session['status'] === 'completed'
                ? (new PhysicalTableRecoveryService())->plan()
                : [],
            'physical_recovery' => $physical,
        ];
    }

    /** @return array<string,mixed> */
    public function rebuild(
        int $sessionId,
        int $userId,
        string $table,
        bool $confirmed
    ): array {
        $session = $this->session($sessionId, $userId);
        if (!in_array((string) $session['status'], ['completed', 'finished'], true)) {
            throw new RuntimeException(
                'Termine y verifique primero el saneamiento lógico.'
            );
        }
        if ($this->verifiedBackup((int) ($session['backup_id'] ?? 0), true) === null) {
            throw new RuntimeException(
                'La copia verificada ya no está disponible. Cree otra antes de reconstruir.'
            );
        }
        $exclusive = new MaintenanceExecutionLock();
        $exclusive->acquire();
        try {
            $this->assertSafety($sessionId);
            return (new PhysicalTableRecoveryService())->rebuild($table, $confirmed);
        } finally {
            $exclusive->release();
        }
    }

    /** @return array<string,mixed> */
    public function session(int $sessionId, int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM database_maintenance_sessions
             WHERE id=:id AND requested_by=:user_id LIMIT 1'
        );
        $stmt->execute(['id' => $sessionId, 'user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \App\Core\HttpException(404, 'No se encontró la sesión de saneamiento.');
        }
        foreach ([
            'plan_json' => 'plan',
            'counters_json' => 'counters',
            'integrity_before_json' => 'integrity_before',
            'integrity_after_json' => 'integrity_after',
        ] as $source => $target) {
            $decoded = json_decode((string) ($row[$source] ?? ''), true);
            $row[$target] = is_array($decoded) ? $decoded : [];
            unset($row[$source]);
        }
        foreach ([
            'last_step_at',
            'started_at',
            'paused_at',
            'completed_at',
            'created_at',
            'updated_at',
        ] as $dateField) {
            if (!empty($row[$dateField])) {
                $row[$dateField] = DateTimePresenter::formatQueue($row[$dateField]);
            }
        }
        $row['protection'] = $this->protectionDescriptor($row, $row['plan']);
        $row['next_action'] = $this->nextAction($row);
        $row['progress_percent'] = $this->progressPercent($row);
        return $row;
    }

    /** @param array<string,mixed> $session @return array<string,mixed> */
    private function performOneAction(array $session): array
    {
        $phase = (string) $session['phase'];
        $position = max(0, (int) $session['dataset_position']);
        $batch = max(
            1,
            min(500, (new AppSettingsService())->int('database_maintenance.batch_size', 500))
        );
        if ($phase === 'retention') {
            $dataset = self::DATASETS[$position] ?? null;
            if ($dataset === null) {
                return [
                    'kind' => 'phase',
                    'next_phase' => 'payloads',
                    'message' => 'Archivo y retención listos.',
                ];
            }
            $result = (new RetentionPolicyService())->runDatasetStep($dataset, $batch);
            return [
                'kind' => 'retention',
                'dataset' => $dataset,
                'dataset_complete' => (bool) $result['complete'],
                'rows_reviewed' => max(
                    $result['deleted'],
                    (int) ($result['archive_rows'] ?? 0)
                ),
                'rows_archived' => (int) ($result['archive_rows'] ?? 0),
                'rows_summarized' => $result['rollups'],
                'rows_deleted' => $result['deleted'],
                'archives' => $result['archives'],
                'message' => (string) $result['message'],
            ];
        }
        if ($phase === 'legacy_notifications') {
            $result = (new NotificationLegacyNormalizationService())->normalizeBatch(min(500, $batch));
            $updated = (int) ($result['updated'] ?? 0);
            return [
                'kind' => 'legacy_notifications',
                'phase_complete' => (bool) ($result['complete'] ?? false),
                'rows_reviewed' => (int) ($result['reviewed'] ?? 0),
                'rows_summarized' => $updated,
                'errors' => (int) ($result['errors'] ?? 0),
                'message' => $updated > 0
                    ? 'Se normalizó un lote legacy local sin replay automático.'
                    : 'No quedan notificaciones legacy pendientes de normalizar.',
            ];
        }
        if ($phase === 'legacy_messages') {
            $result = (new NotificationLegacyNormalizationService())->compactMessageBatch(
                min(500, $batch)
            );
            if ($result['errors'] > 0) {
                throw new RuntimeException(
                    'No fue posible compactar el texto legacy. '
                    . 'La sesión se pausó sin omitir filas.'
                );
            }
            $updated = $result['updated'];
            return [
                'kind' => 'legacy_messages',
                'phase_complete' => $result['complete'],
                'rows_reviewed' => $result['reviewed'],
                'rows_summarized' => $updated,
                'errors' => 0,
                'message' => $updated > 0
                    ? 'Se retiró texto técnico legacy redundante; la clasificación permanece.'
                    : 'No queda texto técnico legacy redundante.',
            ];
        }
        if ($phase === 'payloads') {
            $table = self::PAYLOAD_TABLES[$position] ?? null;
            if ($table === null) {
                return [
                    'kind' => 'phase',
                    'next_phase' => 'orphans',
                    'message' => 'Los payloads elegibles quedaron externalizados.',
                ];
            }
            $plan = json_decode((string) ($session['plan_json'] ?? ''), true);
            $plan = is_array($plan) ? $plan : [];
            $cursors = is_array($plan['payload_cursors'] ?? null)
                ? $plan['payload_cursors']
                : [];
            $cursor = max(0, (int) ($cursors[$table] ?? 0));
            $result = (new RemotePayloadMigrationService())->migrateBatch(
                min(500, $batch),
                $table,
                $cursor
            );
            if ((int) $result['errors'] > 0) {
                throw new RuntimeException(
                    'No fue posible externalizar uno o más payloads. '
                    . 'El saneamiento se pausó sin omitir esas filas.'
                );
            }
            return [
                'kind' => 'payloads',
                'table' => $table,
                'table_complete' => (bool) $result['table_complete'],
                'cursor' => (int) $result['last_id'],
                'rows_reviewed' => (int) $result['reviewed'],
                'payloads_externalized' => (int) $result['processed'],
                'bytes_released' => (int) $result['bytes_released'],
                'errors' => (int) $result['errors'],
                'message' => (int) $result['processed'] > 0
                    ? 'Se externalizó un lote de ' . $table . '.'
                    : 'No quedan payloads elegibles en ' . $table . '.',
            ];
        }
        if ($phase === 'orphans') {
            $payloadStore = new FileRemotePayloadStore();
            $references = $payloadStore->purgeDanglingReferences(min(100, $batch));
            if ((int) $references['deleted'] > 0) {
                return [
                    'kind' => 'orphans',
                    'phase_complete' => false,
                    'rows_deleted' => (int) $references['deleted'],
                    'errors' => 0,
                    'message' => 'Se retiró un lote de referencias a payloads cuyos recursos ya no existen.',
                ];
            }
            $result = $payloadStore->purgeOrphans(min(100, $batch));
            if ((int) $result['errors'] > 0) {
                throw new RuntimeException(
                    'No fue posible comprobar uno o más archivos privados. '
                    . 'La sesión se pausó antes de aprobar esta fase.'
                );
            }
            return [
                'kind' => 'orphans',
                'phase_complete' => (int) $result['deleted'] === 0,
                'rows_deleted' => (int) $result['deleted'],
                'errors' => (int) $result['errors'],
                'message' => (int) $result['deleted'] > 0
                    ? 'Se retiró un lote de archivos privados huérfanos.'
                    : 'No quedan archivos privados huérfanos.',
            ];
        }
        if ($phase === 'cold_archives') {
            $result = (new ColdArchiveService())->purgeExpired(min(10, $batch));
            if ($result['errors'] > 0) {
                throw new RuntimeException(
                    'No fue posible retirar uno o más archivos fríos vencidos. '
                    . 'La sesión se pausó para conservarlos.'
                );
            }
            return [
                'kind' => 'cold_archives',
                'phase_complete' => $result['deleted'] === 0,
                'errors' => $result['errors'],
                'message' => $result['deleted'] > 0
                    ? 'Se retiró un lote de archivos fríos cuya retención ya venció.'
                    : 'No quedan archivos fríos vencidos.',
            ];
        }
        if ($phase === 'summaries') {
            $result = (new RetentionPolicyService())->purgeSummaryStep($batch);
            return [
                'kind' => 'summaries',
                'phase_complete' => (bool) $result['complete'],
                'rows_deleted' => (int) $result['deleted'],
                'message' => (string) $result['message'],
            ];
        }
        if ($phase === 'verify') {
            $before = json_decode((string) ($session['integrity_before_json'] ?? ''), true);
            $before = is_array($before) ? $before : [];
            if ($before === []) {
                throw new RuntimeException('La sesión no conserva una huella inicial verificable.');
            }
            $plan = json_decode((string) ($session['plan_json'] ?? ''), true);
            $plan = is_array($plan) ? $plan : [];
            $verified = is_array($plan['verification_after'] ?? null)
                ? $plan['verification_after']
                : [];
            $keys = array_keys($before);
            $verificationPosition = max(0, (int) ($plan['verification_position'] ?? 0));
            $key = $keys[$verificationPosition] ?? null;
            if (!is_string($key) || $key === '') {
                throw new RuntimeException('La verificación incremental perdió su posición.');
            }
            $current = $this->integrityComponent($key);
            $expected = is_array($before[$key] ?? null) ? $before[$key] : [];
            if (!$this->integrityComponentMatches($expected, $current)) {
                throw new RuntimeException(
                    'La verificación detectó un cambio en información comercial protegida.'
                );
            }
            $verified[$key] = $current;
            $nextPosition = $verificationPosition + 1;
            $complete = $nextPosition >= count($keys);
            if ($complete && !$this->integrityMatches($before, $verified)) {
                throw new RuntimeException(
                    'La verificación detectó un cambio en información comercial protegida.'
                );
            }
            return [
                'kind' => $complete ? 'verify' : 'verify_component',
                'verification_key' => $key,
                'next_verification_key' => $keys[$nextPosition] ?? null,
                'verification_position' => $nextPosition,
                'verification_after' => $verified,
                'phase_complete' => $complete,
                'integrity_after' => $complete ? $verified : null,
                'message' => $complete
                    ? 'La información comercial protegida conserva su huella.'
                    : 'Se comprobó un conjunto de información protegida.',
            ];
        }
        throw new RuntimeException('La fase de saneamiento no está registrada.');
    }

    /**
     * @param array<string,mixed> $action
     * @return array<string,mixed>
     */
    private function approveStep(
        int $sessionId,
        int $userId,
        int $stepId,
        int $generation,
        string $leaseOwner,
        array $action,
        int $durationMs
    ): array {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $session = $this->lockedSession($sessionId, $userId);
            if ((int) $session['generation'] !== $generation) {
                throw new RuntimeException('El lote perdió su autorización antes de guardar.');
            }
            if (
                (int) ($session['lease_generation'] ?? 0) !== $generation
                || !hash_equals((string) ($session['lease_owner'] ?? ''), $leaseOwner)
            ) {
                throw new RuntimeException('El lote perdió su propietario antes de guardar.');
            }
            $counters = json_decode((string) $session['counters_json'], true);
            $counters = is_array($counters) ? $counters : $this->emptyCounters();
            $plan = json_decode((string) $session['plan_json'], true);
            $plan = is_array($plan) ? $plan : [];
            $mapping = [
                'rows_reviewed' => 'reviewed',
                'rows_archived' => 'archived',
                'rows_summarized' => 'summarized',
                'rows_deleted' => 'deleted',
                'payloads_externalized' => 'payloads',
                'bytes_released' => 'bytes_released',
                'errors' => 'errors',
                'archives' => 'archives',
            ];
            foreach ($mapping as $source => $target) {
                $counters[$target] = (int) ($counters[$target] ?? 0)
                    + max(0, (int) ($action[$source] ?? 0));
            }
            $phase = (string) $session['phase'];
            $position = (int) $session['dataset_position'];
            $dataset = $session['dataset_key'];
            $requestedState = (string) $session['status'];
            $status = 'running';
            $completedAt = null;
            $integrityAfter = null;
            if (($action['kind'] ?? '') === 'retention' && !empty($action['dataset_complete'])) {
                $position++;
                $dataset = self::DATASETS[$position] ?? null;
                if ($dataset === null) {
                    $phase = 'payloads';
                    $position = 0;
                    $dataset = self::PAYLOAD_TABLES[0];
                }
            } elseif (($action['kind'] ?? '') === 'phase') {
                $phase = (string) ($action['next_phase'] ?? 'payloads');
                $position = 0;
                $dataset = $phase === 'payloads' ? self::PAYLOAD_TABLES[0] : null;
            } elseif (
                ($action['kind'] ?? '') === 'legacy_notifications'
                && !empty($action['phase_complete'])
            ) {
                $phase = 'legacy_messages';
                $position = 0;
                $dataset = null;
            } elseif (
                ($action['kind'] ?? '') === 'legacy_messages'
                && !empty($action['phase_complete'])
            ) {
                $phase = 'retention';
                $position = 0;
                $dataset = self::DATASETS[0];
            } elseif (($action['kind'] ?? '') === 'payloads') {
                $table = (string) ($action['table'] ?? '');
                if (in_array($table, self::PAYLOAD_TABLES, true)) {
                    $plan['payload_cursors'] = is_array($plan['payload_cursors'] ?? null)
                        ? $plan['payload_cursors']
                        : [];
                    $plan['payload_cursors'][$table] = max(
                        0,
                        (int) ($action['cursor'] ?? 0)
                    );
                }
                if (!empty($action['table_complete'])) {
                    $position++;
                    $dataset = self::PAYLOAD_TABLES[$position] ?? null;
                    if ($dataset === null) {
                        $phase = 'orphans';
                        $position = 0;
                    }
                } else {
                    $dataset = $table !== '' ? $table : $dataset;
                }
            } elseif (($action['kind'] ?? '') === 'orphans' && !empty($action['phase_complete'])) {
                $phase = 'cold_archives';
            } elseif (
                ($action['kind'] ?? '') === 'cold_archives'
                && !empty($action['phase_complete'])
            ) {
                $phase = 'summaries';
            } elseif (
                ($action['kind'] ?? '') === 'summaries'
                && !empty($action['phase_complete'])
            ) {
                $phase = 'verify';
                $plan['verification_position'] = 0;
                $plan['verification_after'] = [];
            } elseif (($action['kind'] ?? '') === 'verify_component') {
                $plan['verification_position'] = max(
                    0,
                    (int) ($action['verification_position'] ?? 0)
                );
                $plan['verification_after'] = is_array($action['verification_after'] ?? null)
                    ? $action['verification_after']
                    : [];
                $nextVerificationKey = $action['next_verification_key'] ?? null;
                $dataset = is_string($nextVerificationKey) && $nextVerificationKey !== ''
                    ? $nextVerificationKey
                    : null;
            } elseif (
                ($action['kind'] ?? '') === 'verify'
            ) {
                $counters = $this->reconcilePayloadCounters($counters, $plan);
                $phase = 'completed';
                $status = !empty($plan['finish_after_verify']) ? 'finished' : 'completed';
                $completedAt = gmdate('Y-m-d H:i:s.v');
                $integrityAfter = $this->json($action['integrity_after'] ?? []);
            }
            if (in_array($requestedState, ['pausing', 'finishing'], true)) {
                $controlRequest = (string) ($plan['control_request'] ?? 'pause');
                unset($plan['control_request']);
                if ($controlRequest === 'finish') {
                    $plan['finish_after_verify'] = true;
                    if (($action['kind'] ?? '') !== 'verify') {
                        $phase = 'verify';
                        $position = 0;
                        $dataset = null;
                        $plan['verification_position'] = 0;
                        $plan['verification_after'] = [];
                        $status = 'running';
                    }
                } else {
                    $status = 'paused';
                }
            }
            $result = [
                'ok' => true,
                'action' => $action,
                'duration_ms' => $durationMs,
                'server_time' => gmdate(DATE_ATOM),
            ];
            $safeMessage = match ($status) {
                'paused' => 'Pausa aplicada después del último lote aprobado. '
                    . (string) ($action['message'] ?? ''),
                'finished' => 'La sesión terminó después de conservar el lote que ya estaba en curso.',
                default => (string) ($action['message'] ?? 'Paso aprobado.'),
            };
            $step = $pdo->prepare(
                'UPDATE database_maintenance_steps
                 SET status="completed",rows_reviewed=:reviewed,rows_archived=:archived,
                     rows_summarized=:summarized,rows_deleted=:deleted,
                     payloads_externalized=:payloads,bytes_released=:bytes_released,
                     duration_ms=:duration,safe_message=:message,result_json=:result,
                     completed_at=UTC_TIMESTAMP(3)
                 WHERE id=:id AND maintenance_session_id=:session_id
                   AND generation=:generation AND lease_owner=:lease_owner
                   AND status="running"'
            );
            $step->execute([
                'reviewed' => max(0, (int) ($action['rows_reviewed'] ?? 0)),
                'archived' => max(0, (int) ($action['rows_archived'] ?? 0)),
                'summarized' => max(0, (int) ($action['rows_summarized'] ?? 0)),
                'deleted' => max(0, (int) ($action['rows_deleted'] ?? 0)),
                'payloads' => max(0, (int) ($action['payloads_externalized'] ?? 0)),
                'bytes_released' => max(0, (int) ($action['bytes_released'] ?? 0)),
                'duration' => max(0, $durationMs),
                'message' => mb_substr($safeMessage, 0, 500),
                'result' => $this->json($result),
                'id' => $stepId,
                'session_id' => $sessionId,
                'generation' => $generation,
                'lease_owner' => $leaseOwner,
            ]);
            if ($step->rowCount() !== 1) {
                throw new RuntimeException('El paso ya no conserva su lease.');
            }
            $completedBefore = $pdo->prepare(
                'SELECT COUNT(*) FROM database_maintenance_steps
                  WHERE maintenance_session_id=:session_id
                    AND id<>:step_id AND status="completed"'
            );
            $completedBefore->execute(['session_id' => $sessionId, 'step_id' => $stepId]);
            $firstApprovedStep = (int) $completedBefore->fetchColumn() === 0;
            $columns = (new SchemaInspectorService())->columns('database_maintenance_sessions');
            $setCanary = isset($columns['canary_status']) && $firstApprovedStep
                ? ',canary_status="passed",canary_checked_at=UTC_TIMESTAMP(3)'
                : '';
            $sessionUpdate = $pdo->prepare(
                'UPDATE database_maintenance_sessions
                 SET status=:status,phase=:phase,dataset_key=:dataset,
                     dataset_position=:position,counters_json=:counters,plan_json=:plan,
                     integrity_after_json=COALESCE(:integrity_after,integrity_after_json),
                     control_expires_at=CASE WHEN :terminal_control=1 THEN NULL
                         ELSE DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 45 SECOND) END,
                     safe_message=:message,
                     completed_at=CASE WHEN :terminal_completed=1 THEN UTC_TIMESTAMP(3)
                         ELSE completed_at END' . $setCanary . '
                  WHERE id=:id AND requested_by=:user_id AND generation=:generation
                    AND lease_generation=:lease_generation AND lease_owner=:lease_owner'
            );
            $sessionUpdate->execute([
                'status' => $status,
                'phase' => $phase,
                'dataset' => $dataset,
                'position' => $position,
                'counters' => $this->json($counters),
                'plan' => $this->json($plan),
                'integrity_after' => $integrityAfter,
                'terminal_control' => in_array(
                    $status,
                    ['paused', 'completed', 'finished'],
                    true
                ) ? 1 : 0,
                'terminal_completed' => in_array(
                    $status,
                    ['completed', 'finished'],
                    true
                ) ? 1 : 0,
                'message' => mb_substr($safeMessage, 0, 500),
                'id' => $sessionId,
                'user_id' => $userId,
                'generation' => $generation,
                'lease_generation' => $generation,
                'lease_owner' => $leaseOwner,
            ]);
            if ($sessionUpdate->rowCount() !== 1) {
                throw new RuntimeException('El lote perdió su lease antes de aprobar el checkpoint.');
            }
            $pdo->commit();
            if (in_array($status, ['completed', 'finished'], true)) {
                (new PhysicalTableRecoveryService())->invalidateCache();
                $this->compactTerminalSteps($sessionId);
                (new DatabaseMutationFreezeService())->release(
                    'database_sanitation',
                    'session:' . $sessionId
                );
            }
            $result['status'] = $this->session($sessionId, $userId);
            return $result;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function failStep(
        int $sessionId,
        int $userId,
        int $stepId,
        int $generation,
        string $leaseOwner,
        string $controlOwnerHash,
        Throwable $error,
        int $durationMs
    ): void {
        $pdo = null;
        try {
            $pdo = Database::connection();
            $pdo->beginTransaction();
            $message = $this->safeFailure($error);
            $step = $pdo->prepare(
                'UPDATE database_maintenance_steps
                 SET status="failed",duration_ms=:duration,safe_message=:message,
                     completed_at=UTC_TIMESTAMP(3)
                 WHERE id=:id AND maintenance_session_id=:session_id
                   AND generation=:generation AND lease_owner=:lease_owner
                   AND status="running"'
            );
            $step->execute([
                'duration' => max(0, $durationMs),
                'message' => $message,
                'id' => $stepId,
                'session_id' => $sessionId,
                'generation' => $generation,
                'lease_owner' => $leaseOwner,
            ]);
            if ($step->rowCount() !== 1) {
                $pdo->rollBack();
                return;
            }
            $session = $pdo->prepare(
                'UPDATE database_maintenance_sessions
                 SET status="paused",paused_at=UTC_TIMESTAMP(3),
                     control_expires_at=NULL,safe_message=:message
                 WHERE id=:id AND requested_by=:user_id
                   AND generation=:generation AND control_token_hash=:owner_hash
                   AND lease_generation=:lease_generation AND lease_owner=:lease_owner
                   AND status IN ("running","pausing","finishing")'
            );
            $session->execute([
                'message' => $message,
                'id' => $sessionId,
                'user_id' => $userId,
                'generation' => $generation,
                'lease_generation' => $generation,
                'lease_owner' => $leaseOwner,
                'owner_hash' => $controlOwnerHash,
            ]);
            if ($session->rowCount() !== 1) {
                $pdo->rollBack();
                return;
            }
            $pdo->commit();
        } catch (Throwable) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    /** @return array<string,mixed> */
    private function lockedSession(int $sessionId, int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM database_maintenance_sessions
             WHERE id=:id AND requested_by=:user_id
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['id' => $sessionId, 'user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \App\Core\HttpException(404, 'No se encontró la sesión de saneamiento.');
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function recentSteps(int $sessionId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT sequence_no,phase,dataset_key,status,rows_reviewed,rows_archived,
                    rows_summarized,rows_deleted,payloads_externalized,bytes_released,
                    duration_ms,safe_message,started_at,completed_at
             FROM database_maintenance_steps
             WHERE maintenance_session_id=:session_id
             ORDER BY sequence_no DESC LIMIT 10'
        );
        $stmt->execute(['session_id' => $sessionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            foreach (['started_at', 'completed_at'] as $dateField) {
                if (!empty($row[$dateField])) {
                    $row[$dateField] = DateTimePresenter::formatQueue($row[$dateField]);
                }
            }
        }
        unset($row);
        return $rows;
    }

    private function nextSequence(int $sessionId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(MAX(sequence_no),0)+1
             FROM database_maintenance_steps
             WHERE maintenance_session_id=:session_id'
        );
        $stmt->execute(['session_id' => $sessionId]);
        return max(1, (int) $stmt->fetchColumn());
    }

    /** @return array<string,mixed> */
    private function protectionPlan(string $mode, string $label, string $message): array
    {
        return [
            'mode' => $mode,
            'status' => 'ready',
            'ready' => true,
            'label' => $label,
            'message' => $message,
            'confirmed_at' => gmdate(DATE_ATOM),
        ];
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $planOrAnalysis
     * @return array{mode:string,status:string,ready:bool,label:string,message:string}
     */
    private function protectionDescriptor(array $session, array $planOrAnalysis = []): array
    {
        $planProtection = [];
        if (is_array($session['plan']['protection'] ?? null)) {
            $planProtection = $session['plan']['protection'];
        } elseif (is_array($planOrAnalysis['protection'] ?? null)) {
            $planProtection = $planOrAnalysis['protection'];
        }

        $mode = trim((string) ($planProtection['mode'] ?? ''));
        $status = trim((string) ($planProtection['status'] ?? ''));
        if ($status === '' && !empty($planProtection['ready'])) {
            $status = 'ready';
        }
        if ($status === '') {
            $status = 'required';
        }

        $databaseMode = trim((string) ($session['protection_mode'] ?? ''));
        if ($databaseMode !== '') {
            $mode = $databaseMode;
            $databaseStatus = trim((string) ($session['protection_status'] ?? ''));
            if ($databaseStatus !== '') {
                $status = $databaseStatus;
            }
        }
        $ready = in_array($mode, [
            self::PROTECTION_ERP_BACKUP,
            self::PROTECTION_EXTERNAL,
            self::PROTECTION_WAIVED,
        ], true) && in_array($status, ['ready', 'canary_ready', 'canary_passed'], true);

        if ($mode === self::PROTECTION_ERP_BACKUP) {
            return [
                'mode' => $mode,
                'status' => $status,
                'ready' => $ready,
                'label' => 'Copia ERP verificada',
                'message' => 'Se usará una copia cifrada y verificada vinculada a esta sesión.',
            ];
        }
        if ($mode === self::PROTECTION_EXTERNAL) {
            return [
                'mode' => $mode,
                'status' => $status,
                'ready' => $ready,
                'label' => 'Respaldo externo confirmado',
                'message' => 'El administrador confirmó que conserva una copia externa antes de sanear.',
            ];
        }
        if ($mode === self::PROTECTION_WAIVED) {
            return [
                'mode' => $mode,
                'status' => $status,
                'ready' => $ready,
                'label' => 'Sin respaldo interno',
                'message' => 'Solo se permitirá saneamiento lógico de ruido técnico; la recuperación física queda bloqueada.',
            ];
        }
        return [
            'mode' => '',
            'status' => 'required',
            'ready' => false,
            'label' => 'Protección pendiente',
            'message' => 'Elija copia local, respaldo externo o continuar sin respaldo antes de iniciar.',
        ];
    }

    /** @return array<string,mixed>|null */
    private function latestVerifiedBackup(): ?array
    {
        $stmt = Database::connection()->query(
            'SELECT id,public_id,status,erp_version,size_bytes,table_count,row_count,
                    storage_name,verified_at,completed_at,checksum_sha256,manifest_sha256
             FROM system_backup_archives
             WHERE status="ready" AND verified_at IS NOT NULL AND deleted_at IS NULL
             ORDER BY verified_at DESC,id DESC LIMIT 1'
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $path = AppPaths::backups() . '/' . basename((string) $row['storage_name']);
        $row['file_available'] = is_file($path) && (int) @filesize($path) > 128;
        $row['fresh'] = $this->backupIsFresh($row);
        return $row;
    }

    /** @return array<string,mixed>|null */
    private function verifiedBackup(int $id, bool $cryptographic = false): ?array
    {
        if ($id < 1) {
            return null;
        }
        $stmt = Database::connection()->prepare(
            'SELECT * FROM system_backup_archives
             WHERE id=:id AND status="ready" AND verified_at IS NOT NULL
               AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $path = AppPaths::backups() . '/' . basename((string) $row['storage_name']);
        if (!is_file($path) || (int) @filesize($path) <= 128) {
            return null;
        }
        $expectedSize = max(0, (int) ($row['size_bytes'] ?? 0));
        if ($expectedSize > 0 && (int) @filesize($path) !== $expectedSize) {
            return null;
        }
        if ($cryptographic) {
            try {
                $expectedChecksum = strtolower((string) ($row['checksum_sha256'] ?? ''));
                if (
                    preg_match('/^[a-f0-9]{64}$/', $expectedChecksum) !== 1
                    || !hash_equals($expectedChecksum, (string) hash_file('sha256', $path))
                ) {
                    return null;
                }
                $verified = (new BackupArchiveService())->verify($path);
                $manifest = strtolower((string) ($row['manifest_sha256'] ?? ''));
                if (
                    preg_match('/^[a-f0-9]{64}$/', $manifest) !== 1
                    || !hash_equals($manifest, strtolower((string) $verified['manifest_checksum']))
                ) {
                    return null;
                }
            } catch (Throwable) {
                return null;
            }
        }
        return $row;
    }

    /** @param array<string,mixed> $backup @return array<string,mixed> */
    private function backupDescriptor(array $backup): array
    {
        $verifiedAt = (string) ($backup['verified_at'] ?? '');
        return [
            'id' => max(0, (int) ($backup['id'] ?? 0)),
            'public_id' => (string) ($backup['public_id'] ?? ''),
            'status' => (string) ($backup['status'] ?? ''),
            'erp_version' => (string) ($backup['erp_version'] ?? ''),
            'size_bytes' => max(0, (int) ($backup['size_bytes'] ?? 0)),
            'table_count' => max(0, (int) ($backup['table_count'] ?? 0)),
            'row_count' => max(0, (int) ($backup['row_count'] ?? 0)),
            'verified_at' => $verifiedAt,
            'completed_at' => $backup['completed_at'] ?? null,
            'checksum_sha256' => strtolower((string) ($backup['checksum_sha256'] ?? '')),
            'manifest_sha256' => strtolower((string) ($backup['manifest_sha256'] ?? '')),
            'file_available' => true,
            'fresh' => $this->backupIsFresh($backup),
        ];
    }

    /** @param array<string,mixed> $backup */
    private function backupIsFresh(array $backup): bool
    {
        $verifiedAt = trim((string) ($backup['verified_at'] ?? ''));
        $timestamp = $verifiedAt !== '' ? strtotime($verifiedAt . ' UTC') : false;
        return is_int($timestamp)
            && $timestamp <= time() + 300
            && $timestamp >= time() - self::BACKUP_MAX_AGE_SECONDS;
    }

    private function assertSafety(int $sessionId): void
    {
        if (!(new MeliEmergencyStopService())->active()) {
            throw new RuntimeException('Detenga Mercado Libre antes de sanear la base.');
        }
        if (!(new EmergencyControlService())->automationStopped()) {
            throw new RuntimeException('Detenga la automatización antes de sanear la base.');
        }
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM database_maintenance_sessions
             WHERE id<>:session_id AND status IN ("running","pausing","finishing")
               AND control_expires_at>=UTC_TIMESTAMP(3)'
        );
        $stmt->execute(['session_id' => $sessionId]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('Ya existe otra sesión de saneamiento activa.');
        }
    }

    /** @return array<string,mixed> */
    private function payloadCandidates(): array
    {
        $rows = 0;
        $bytes = 0;
        foreach ([
            ['meli_orders', 'synced_at'],
            ['meli_shipments', 'synced_at'],
            ['meli_payments', 'synced_at'],
            ['meli_packs', 'synced_at'],
            ['meli_order_items', 'created_at'],
        ] as [$table, $date]) {
            $result = Database::connection()->query(
                'SELECT COUNT(*) AS rows_count,
                        COALESCE(SUM(OCTET_LENGTH(raw_json)),0) AS bytes_count
                 FROM `' . $table . '`
                 WHERE raw_json IS NOT NULL AND raw_json<>""
                   AND `' . $date . '`<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 DAY)'
            )->fetch(PDO::FETCH_ASSOC) ?: [];
            $rows += (int) ($result['rows_count'] ?? 0);
            $bytes += (int) ($result['bytes_count'] ?? 0);
        }
        return ['rows' => $rows, 'bytes' => $bytes];
    }

    /**
     * Una interrupción puede ocurrir después de aprobar archivos y limpiar el
     * raw_json, pero antes de confirmar el contador del paso. Como el marcador
     * de mantenimiento impide escritores concurrentes, el delta entre el plan
     * inicial y los candidatos restantes es la autoridad al cerrar la sesión.
     *
     * @param array<string,mixed> $counters
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function reconcilePayloadCounters(array $counters, array $plan): array
    {
        $initial = is_array($plan['analysis']['payloads'] ?? null)
            ? $plan['analysis']['payloads']
            : [];
        if (!is_int($initial['rows'] ?? null) || !is_int($initial['bytes'] ?? null)) {
            return $counters;
        }
        $remaining = $this->payloadCandidates();
        $actualRows = max(0, (int) $initial['rows'] - (int) $remaining['rows']);
        $actualBytes = max(0, (int) $initial['bytes'] - (int) $remaining['bytes']);
        $reportedRows = max(0, (int) ($counters['payloads'] ?? 0));
        if ($actualRows > $reportedRows) {
            $counters['reviewed'] = max(0, (int) ($counters['reviewed'] ?? 0))
                + ($actualRows - $reportedRows);
        }
        $counters['payloads'] = max($reportedRows, $actualRows);
        $counters['bytes_released'] = max(
            max(0, (int) ($counters['bytes_released'] ?? 0)),
            $actualBytes
        );
        return $counters;
    }

    /** @return array<string,mixed> */
    private function integritySnapshot(): array
    {
        $gateway = new InformationSchemaGateway();
        $exists = $gateway->tablesExist(self::PROTECTED_TABLES);
        $snapshot = [];
        foreach (self::PROTECTED_TABLES as $table) {
            if (!($exists[$table] ?? false)) {
                continue;
            }
            $snapshot[$table] = $this->integrityTableComponent($table, $gateway);
        }
        $snapshot['financial_totals'] = $this->financialIntegrityComponent();
        if (($exists['meli_orders'] ?? false) && ($exists['meli_order_items'] ?? false)) {
            $snapshot['acceptance_sale_2000014234269247'] =
                $this->acceptanceSaleIntegrityComponent();
        }
        $snapshot['snapshot_contract'] = [
            'version' => 3,
            'excludes' => ['raw_json', 'raw_path', 'updated_at'],
            'digest' => 'sha256_stream_v1',
        ];
        ksort($snapshot);
        return $snapshot;
    }

    /** @return array<string,mixed> */
    private function integrityComponent(string $key): array
    {
        if (in_array($key, self::PROTECTED_TABLES, true)) {
            $gateway = new InformationSchemaGateway();
            $exists = $gateway->tablesExist([$key]);
            if (!($exists[$key] ?? false)) {
                throw new RuntimeException('Una tabla protegida ya no está disponible.');
            }
            return $this->integrityTableComponent($key, $gateway);
        }
        return match ($key) {
            'financial_totals' => $this->financialIntegrityComponent(),
            'acceptance_sale_2000014234269247' => $this->acceptanceSaleIntegrityComponent(),
            'snapshot_contract' => [
                'version' => 3,
                'excludes' => ['raw_json', 'raw_path', 'updated_at'],
                'digest' => 'sha256_stream_v1',
            ],
            default => throw new RuntimeException('La huella protegida contiene un conjunto desconocido.'),
        };
    }

    /** @return array<string,mixed> */
    private function integrityTableComponent(
        string $table,
        InformationSchemaGateway $gateway
    ): array {
        $columns = $gateway->columns($table);
        $id = array_key_exists('id', $columns) ? 'id' : array_key_first($columns);
        if (!is_string($id) || preg_match('/^[A-Za-z0-9_]+$/', $id) !== 1) {
            throw new RuntimeException('Una tabla protegida no tiene identidad verificable.');
        }
        $signatureColumns = array_values(array_filter(
            array_keys($columns),
            static fn (string $column): bool => !in_array(
                $column,
                ['raw_json', 'raw_path', 'updated_at'],
                true
            ) && preg_match('/^[A-Za-z0-9_]+$/', $column) === 1
        ));
        $signature = $signatureColumns === []
            ? '0'
            : 'COALESCE(SUM(CRC32(CONCAT_WS(CHAR(31),'
                . implode(',', array_map(
                    static fn (string $column): string =>
                        'COALESCE(CAST(`' . $column . '` AS CHAR),"<NULL>")',
                    $signatureColumns
                ))
                . '))),0)';
        $row = Database::connection()->query(
            'SELECT COUNT(*) AS rows_count,
                    COALESCE(SUM(CRC32(CAST(`' . $id . '` AS CHAR))),0) AS id_crc,
                    COALESCE(MAX(CAST(`' . $id . '` AS CHAR)),"") AS max_id,
                    ' . $signature . ' AS business_crc
             FROM `' . $table . '`'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $hash = hash_init('sha256');
        $select = implode(',', array_map(
            static fn (string $column): string => '`' . $column . '`',
            $signatureColumns
        ));
        $cursor = Database::connection()->query(
            'SELECT ' . ($select !== '' ? $select : '`' . $id . '`')
            . ' FROM `' . $table . '` ORDER BY `' . $id . '`'
        );
        while ($businessRow = $cursor->fetch(PDO::FETCH_ASSOC)) {
            foreach ($businessRow as $column => $value) {
                $bytes = $value === null ? '<NULL>' : (string) $value;
                hash_update(
                    $hash,
                    strlen((string) $column) . ':' . $column
                    . '=' . strlen($bytes) . ':' . $bytes . "\n"
                );
            }
            hash_update($hash, "--row--\n");
        }
        return [
            'rows' => (int) ($row['rows_count'] ?? 0),
            'id_crc' => (string) ($row['id_crc'] ?? '0'),
            'max_id' => (string) ($row['max_id'] ?? ''),
            'business_crc' => (string) ($row['business_crc'] ?? '0'),
            'business_sha256' => hash_final($hash),
        ];
    }

    /** @return array<string,string> */
    private function financialIntegrityComponent(): array
    {
        return [
            'order_total' => $this->decimalScalar(
                'SELECT COALESCE(SUM(total_amount),0) FROM meli_orders'
            ),
            'order_paid' => $this->decimalScalar(
                'SELECT COALESCE(SUM(paid_amount),0) FROM meli_orders'
            ),
            'item_gross' => $this->decimalScalar(
                'SELECT COALESCE(SUM(unit_price*quantity),0) FROM meli_order_items'
            ),
            'item_sale_fee' => $this->decimalScalar(
                'SELECT COALESCE(SUM(sale_fee),0) FROM meli_order_items'
            ),
            'payment_transaction' => $this->decimalScalar(
                'SELECT COALESCE(SUM(transaction_amount),0) FROM meli_payments'
            ),
            'payment_total_paid' => $this->decimalScalar(
                'SELECT COALESCE(SUM(total_paid_amount),0) FROM meli_payments'
            ),
            'shipment_seller_cost' => $this->decimalScalar(
                'SELECT COALESCE(SUM(seller_cost),0) FROM meli_shipments'
            ),
        ];
    }

    /** @return array{orders:int,products:int,units:int} */
    private function acceptanceSaleIntegrityComponent(): array
    {
        $sale = Database::connection()->query(
            'SELECT
                COUNT(DISTINCT o.id) AS orders_count,
                COUNT(i.id) AS products_count,
                COALESCE(SUM(i.quantity),0) AS units_count
             FROM meli_orders o
             LEFT JOIN meli_order_items i
               ON i.meli_order_id=o.id AND i.meli_account_id=o.meli_account_id
             WHERE o.external_pack_id="2000014234269247"'
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'orders' => (int) ($sale['orders_count'] ?? 0),
            'products' => (int) ($sale['products_count'] ?? 0),
            'units' => (int) ($sale['units_count'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $current */
    private function integrityComponentMatches(array $expected, array $current): bool
    {
        if ($expected === []) {
            return false;
        }
        foreach ($expected as $field => $value) {
            if (!array_key_exists($field, $current)) {
                return false;
            }
            if (is_array($value) || is_array($current[$field])) {
                if ($value !== $current[$field]) {
                    return false;
                }
                continue;
            }
            if ((string) $current[$field] !== (string) $value) {
                return false;
            }
        }
        return true;
    }

    /**
     * Las sesiones iniciadas antes de incorporar la huella comercial v2
     * contienen solamente conteos, IDs y el caso de aceptación. Pueden
     * terminar después de una actualización si todos esos campos siguen
     * coincidiendo. Las sesiones nuevas usan comparación estricta v2.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private function integrityMatches(array $before, array $after): bool
    {
        $contract = $before['snapshot_contract']['version'] ?? null;
        if ((int) $contract >= 3) {
            return hash_equals($this->hash($before), $this->hash($after));
        }

        if ($before === []) {
            return false;
        }
        foreach ($before as $key => $expected) {
            if (!array_key_exists($key, $after) || !is_array($expected) || !is_array($after[$key])) {
                return false;
            }
            if (!$this->integrityComponentMatches($expected, $after[$key])) {
                return false;
            }
        }
        return true;
    }

    /** @param list<array<string,mixed>> $tables */
    private function averageEligibleBytes(array $tables): float
    {
        $wanted = array_flip([
            'meli_notification_events',
            'api_request_logs',
            'cron_health_checks',
            'order_financial_recalc_job_items',
            'system_cron_run_steps',
            'system_process_metrics',
            'system_work_queue_run_items',
            'system_work_queue_runs',
            'api_budget_windows',
            'manual_engine_probe_runs',
            'system_performance_metrics',
            'system_logs',
            'api_operation_metric_samples',
            'meli_webhook_events',
            'system_cron_backlog_snapshots',
            'system_cron_backlog_run_totals',
            'manual_campaign_events',
        ]);
        $bytes = 0;
        $rows = 0;
        foreach ($tables as $table) {
            if (!isset($wanted[$table['table']])) {
                continue;
            }
            $bytes += (int) $table['data_bytes'];
            $rows += max(1, (int) $table['estimated_rows']);
        }
        return $rows > 0 ? $bytes / $rows : 0.0;
    }

    /** @return array<string,int> */
    private function emptyCounters(): array
    {
        return [
            'reviewed' => 0,
            'archived' => 0,
            'summarized' => 0,
            'deleted' => 0,
            'payloads' => 0,
            'bytes_released' => 0,
            'archives' => 0,
            'errors' => 0,
        ];
    }

    /** @param array<string,mixed> $session */
    private function nextAction(array $session): string
    {
        $physical = is_array($session['plan']['physical_recovery'] ?? null)
            ? $session['plan']['physical_recovery']
            : [];
        if ((string) ($session['status'] ?? '') === 'completed' && $physical !== []) {
            return match ((string) ($physical['status'] ?? '')) {
                'queued' => 'Esperar la ejecución controlada de recuperación física.',
                'running' => 'Esperar a que MariaDB termine la tabla actual.',
                'needs_review' => 'Comprobar el estado de MariaDB antes de repetir.',
                'failed' => 'Revisar la causa antes de preparar otra tabla.',
                'completed', 'not_required' => 'Revisar otra tabla o cerrar el mantenimiento.',
                default => 'Revisar la recuperación física.',
            };
        }
        return match ((string) $session['status']) {
            'analyzed' => !empty($session['protection']['ready'])
                ? 'Iniciar el lote canario de saneamiento.'
                : 'Elegir cómo proteger esta sesión antes de iniciar.',
            'paused' => 'Continuar desde el último lote aprobado.',
            'completed' => 'Revisar la recuperación física, tabla por tabla.',
            'finished' => 'Crear un análisis nuevo cuando lo necesite.',
            'failed' => 'Revisar el último error antes de continuar.',
            default => match ((string) $session['phase']) {
                'retention' => 'Archivar, resumir o retirar el siguiente lote elegible.',
                'legacy_notifications' => 'Normalizar el siguiente lote legacy sin replay automático.',
                'legacy_messages' => 'Retirar texto legacy repetido conservando su clasificación.',
                'payloads' => 'Externalizar el siguiente lote de payloads completos.',
                'orphans' => 'Comprobar archivos privados huérfanos.',
                'cold_archives' => 'Retirar el siguiente archivo frío cuya retención venció.',
                'summaries' => 'Retirar el siguiente lote de resúmenes cuya retención venció.',
                'verify' => 'Comparar la huella comercial antes y después.',
                default => 'Preparar el siguiente micro-paso.',
            },
        };
    }

    /** @param array<string,mixed> $session */
    private function progressPercent(array $session): float
    {
        if (in_array((string) $session['status'], ['completed', 'finished'], true)) {
            return 100.0;
        }
        $phase = (string) $session['phase'];
        if ($phase === 'retention') {
            return min(
                70.0,
                10.0 + ((int) $session['dataset_position'] / count(self::DATASETS)) * 60.0
            );
        }
        return match ($phase) {
            'legacy_notifications' => 4.0,
            'legacy_messages' => 8.0,
            'payloads' => 75.0,
            'orphans' => 88.0,
            'cold_archives' => 92.0,
            'summaries' => 94.0,
            'verify' => 96.0,
            default => 0.0,
        };
    }

    private function compactTerminalSteps(int $sessionId): void
    {
        $keepTail = max(
            10,
            min(
                500,
                (new AppSettingsService())->int(
                    'database_maintenance.step_compaction_keep_tail',
                    50
                )
            )
        );
        try {
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $result = (new MaintenanceStepCompactionService())->compact(
                    $sessionId,
                    $keepTail,
                    5000
                );
                if ((int) ($result['deleted'] ?? 0) < 5000) {
                    break;
                }
            }
        } catch (Throwable) {
            try {
                Database::connection()->prepare(
                    'UPDATE database_maintenance_sessions
                     SET safe_message=:message
                     WHERE id=:id AND status IN ("completed","finished","failed")'
                )->execute([
                    'message' => 'El saneamiento terminó y la integridad fue aprobada. '
                        . 'La compactación de su bitácora técnica queda pendiente.',
                    'id' => $sessionId,
                ]);
            } catch (Throwable) {
                // El saneamiento ya quedó aprobado. Un fallo de telemetría no
                // puede revertir ni ocultar ese resultado terminal.
            }
        }
    }

    private function scalar(string $sql): int
    {
        return max(0, (int) Database::connection()->query($sql)->fetchColumn());
    }

    private function maintenanceStatusSupports(string $status): bool
    {
        try {
            $stmt = Database::connection()->prepare(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
                   AND BINARY TABLE_NAME=BINARY 'database_maintenance_sessions'
                   AND BINARY COLUMN_NAME=BINARY 'status'
                 LIMIT 1"
            );
            $stmt->execute();
            return str_contains(strtolower((string) $stmt->fetchColumn()), "'" . strtolower($status) . "'");
        } catch (Throwable) {
            return false;
        }
    }

    private function decimalScalar(string $sql): string
    {
        $value = Database::connection()->query($sql)->fetchColumn();
        return number_format((float) $value, 2, '.', '');
    }

    private function validControlToken(string $token): string
    {
        $token = trim($token);
        if (preg_match('/^[A-Za-z0-9._:-]{24,160}$/', $token) !== 1) {
            throw new RuntimeException('La identidad de la pestaña no es válida.');
        }
        return $token;
    }

    /** @param array<string,mixed> $value */
    private function hash(array $value): string
    {
        ksort($value);
        return hash('sha256', $this->json($value));
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function safeFailure(Throwable $error): string
    {
        $message = $error->getMessage();
        if (preg_match('/SQLSTATE|PDOException|unknown column|\/home\/|[A-Z]:\\\\/i', $message)) {
            return 'El micro-paso no pudo completarse. Revise el diagnóstico local.';
        }
        return mb_substr($message !== '' ? $message : 'El micro-paso no pudo completarse.', 0, 500);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
