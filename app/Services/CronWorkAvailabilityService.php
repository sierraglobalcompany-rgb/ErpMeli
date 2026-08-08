<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * Sondea colas sin reclamar trabajos. Así una tarea vacía no consume uno de
 * los pocos turnos disponibles en cada ejecución corta del cron.
 */
final class CronWorkAvailabilityService
{
    /** @var array<string,array<string,mixed>> */
    private array $cache = [];

    /**
     * Reutiliza sondeos dentro de una misma invocación CLI. Un refresh invalida
     * exclusivamente las colas indicadas, para medir el efecto real del lote sin
     * repetir todos los COUNT del orquestador.
     *
     * @param list<string> $keys
     * @return array<string,array<string,mixed>>
     */
    public function snapshotCached(array $keys, bool $refresh = false): array
    {
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys))));
        if ($refresh) {
            foreach ($keys as $key) {
                unset($this->cache[$key]);
            }
        }
        $missing = array_values(array_filter($keys, fn (string $key): bool => !isset($this->cache[$key])));
        if ($missing !== []) {
            foreach ($this->snapshot($missing) as $key => $measurement) {
                $this->cache[$key] = $measurement;
            }
        }
        return array_intersect_key($this->cache, array_fill_keys($keys, true));
    }

    /**
     * @return array<string,array{known:bool,measurement_state:string,work_count:int,eligible_count:int,total_pending:int,waiting_schedule:int,running_count:int,attention_count:int,oldest_due_at:?string,measured_at:string}>
     */
    public function snapshot(?array $onlyKeys = null): array
    {
        // Las sondas son diferidas: cuando el orquestador habilita solo un
        // subconjunto (por ejemplo mantenimiento local con API detenida), no
        // debe escanear todas las demás colas antes de descartarlas.
        $probes = [
            'operational_maintenance' => fn (): array => $this->operationalMaintenance(),
            'notification_spool' => fn (): array => $this->spool(),
            'notification_backfill' => fn (): array => $this->query('meli_notification_backfill_runs',
                'SELECT COUNT(*),MIN(COALESCE(next_run_at,created_at)) FROM meli_notification_backfill_runs
                 WHERE status IN ("analyzing","running") AND (next_run_at IS NULL OR next_run_at<=UTC_TIMESTAMP())
                   AND (lock_expires_at IS NULL OR lock_expires_at<UTC_TIMESTAMP())',
                'SELECT COUNT(*) FROM meli_notification_backfill_runs WHERE status IN ("analyzing","running")'),
            'notification_fallback' => fn (): array => $this->query('meli_notification_work_items',
                'SELECT COUNT(*),MIN(COALESCE(next_run_at,first_received_at)) FROM meli_notification_work_items
                 WHERE status IN ("pending","retry") AND next_run_at<=UTC_TIMESTAMP()
                   AND (lock_expires_at IS NULL OR lock_expires_at<UTC_TIMESTAMP())',
                'SELECT COUNT(*) FROM meli_notification_work_items WHERE status IN ("pending","retry")'),
            'recurring_sync' => fn (): array => $this->recurringSync(),
            'orders_sync' => fn (): array => $this->query('sync_batch_chunks',
                'SELECT COUNT(*),MIN(COALESCE(next_run_at,created_at)) FROM sync_batch_chunks
                 WHERE sync_type="orders" AND status IN ("queued","partial")
                   AND (next_run_at IS NULL OR next_run_at<=UTC_TIMESTAMP())',
                'SELECT COUNT(*) FROM sync_batch_chunks WHERE sync_type="orders" AND status IN ("queued","partial")'),
            'order_enrichment' => fn (): array => $this->query('order_resource_enrichment_jobs',
                'SELECT COUNT(*),MIN(next_run_at) FROM order_resource_enrichment_jobs
                 WHERE status IN ("pending","retry") AND next_run_at<=UTC_TIMESTAMP()
                   AND (locked_at IS NULL OR locked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))',
                'SELECT COUNT(*) FROM order_resource_enrichment_jobs WHERE status IN ("pending","retry")'),
            'sale_pack_reconciliation' => fn (): array => $this->salePackReconciliation(),
            'sale_financial_reconciliation' => fn (): array => $this->query('sale_financial_reconciliation_jobs',
                'SELECT COUNT(*),MIN(next_run_at) FROM sale_financial_reconciliation_jobs
                 WHERE status IN ("pending","retry","awaiting_remote") AND next_run_at<=UTC_TIMESTAMP()
                   AND (lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP())',
                'SELECT COUNT(*) FROM sale_financial_reconciliation_jobs WHERE status IN ("pending","retry","awaiting_remote")'),
            'order_date_repair' => fn (): array => $this->query('order_datetime_repair_jobs',
                'SELECT COUNT(*),MIN(created_at) FROM order_datetime_repair_jobs WHERE status IN ("pending","running")',
                'SELECT COUNT(*) FROM order_datetime_repair_jobs WHERE status IN ("pending","running")'),
            'manual_campaign' => fn (): array => $this->manualCampaign(),
            'questions' => fn (): array => $this->questions(),
            'financial_recalc' => fn (): array => $this->query('order_financial_recalc_jobs',
                'SELECT COUNT(*),MIN(created_at) FROM order_financial_recalc_jobs WHERE status="pending"',
                'SELECT COUNT(*) FROM order_financial_recalc_jobs WHERE status IN ("pending","running")'),
            'sales_repair' => fn (): array => $this->query('sync_sales_repair_jobs',
                'SELECT COUNT(*),MIN(created_at) FROM sync_sales_repair_jobs WHERE status IN ("pending","running")',
                'SELECT COUNT(*) FROM sync_sales_repair_jobs WHERE status IN ("pending","running")'),
            'sales_audit' => fn (): array => $this->query('sync_sales_audit_jobs',
                'SELECT COUNT(*),MIN(COALESCE(next_run_at,created_at)) FROM sync_sales_audit_jobs
                 WHERE status IN ("pending","waiting_budget")
                   AND next_run_at<=UTC_TIMESTAMP()
                   AND (lock_expires_at IS NULL OR lock_expires_at<UTC_TIMESTAMP())',
                'SELECT COUNT(*) FROM sync_sales_audit_jobs WHERE status IN ("pending","waiting_budget")'),
            'sales_fiscal' => fn (): array => $this->query('sales_control_fiscal_jobs',
                'SELECT COUNT(*),MIN(COALESCE(next_run_at,created_at)) FROM sales_control_fiscal_jobs
                 WHERE status IN ("pending","retry","waiting_budget")
                   AND (next_run_at IS NULL OR next_run_at<=UTC_TIMESTAMP())
                   AND (lock_expires_at IS NULL OR lock_expires_at<UTC_TIMESTAMP())',
                'SELECT COUNT(*) FROM sales_control_fiscal_jobs WHERE status IN ("pending","retry","waiting_budget")'),
            'catalog_descriptions' => fn (): array => $this->query('catalog_description_jobs',
                'SELECT COUNT(*),MIN(COALESCE(next_run_at,created_at)) FROM catalog_description_jobs
                 WHERE status IN ("queued","waiting","running")
                   AND (next_run_at IS NULL OR next_run_at<=UTC_TIMESTAMP())
                   AND (lock_expires_at IS NULL OR lock_expires_at<UTC_TIMESTAMP())',
                'SELECT COUNT(*) FROM catalog_description_jobs WHERE status IN ("queued","waiting","running")'),
            'items_sync' => fn (): array => $this->query('meli_item_sync_jobs',
                'SELECT COUNT(*),MIN(next_run_at) FROM meli_item_sync_jobs
                 WHERE phase IN ("discovering","details","partial") AND next_run_at<=UTC_TIMESTAMP()
                   AND (locked_at IS NULL OR locked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))',
                'SELECT COUNT(*) FROM meli_item_sync_jobs WHERE phase IN ("discovering","details","partial")'),
            'module_jobs' => fn (): array => $this->query('system_module_jobs',
                'SELECT COUNT(*),MIN(next_run_at) FROM system_module_jobs
                 WHERE status IN ("pending","retry") AND next_run_at<=UTC_TIMESTAMP()
                   AND (lock_expires_at IS NULL OR lock_expires_at<UTC_TIMESTAMP())',
                'SELECT COUNT(*) FROM system_module_jobs WHERE status IN ("pending","retry")'),
        ];
        $selected = $onlyKeys === null
            ? $probes
            : array_intersect_key($probes, array_fill_keys(array_values(array_filter(
                array_map('strval', $onlyKeys),
                static fn (string $key): bool => $key !== ''
            )), true));
        $snapshot = [];
        foreach ($selected as $key => $probe) {
            $snapshot[$key] = $probe();
            $snapshot[$key] = $this->normalize($snapshot[$key]);
        }
        return $snapshot;
    }

    /** @return array<string,mixed> */
    private function query(string $table, string $sql, ?string $totalSql = null): array
    {
        try {
            if (!(new SchemaInspectorService())->hasTable($table)) {
                return $this->unknown();
            }
            $row = Database::connectionFresh()->query($sql)->fetch(PDO::FETCH_NUM);
            $eligible = max(0, (int) ($row[0] ?? 0));
            $total = $totalSql !== null
                ? max(0, (int) Database::connectionFresh()->query($totalSql)->fetchColumn())
                : $eligible;
            return $this->known(
                $eligible,
                $total,
                !empty($row[1]) ? (string) $row[1] : null,
                $totalSql === null ? 'partial' : 'complete'
            );
        } catch (Throwable $error) {
            Logger::write('warning', 'No fue posible sondear una cola del cron.', [
                'module' => 'cron',
                'table' => $table,
                'error_class' => $error::class,
            ]);
            return $this->unknown();
        }
    }

    /** @return array<string,mixed> */
    private function spool(): array
    {
        try {
            $count = (new WebhookSpoolService())->pendingCount();
            return $this->known($count, $count, null);
        } catch (Throwable) {
            return $this->unknown();
        }
    }

    /** @return array<string,mixed> */
    private function operationalMaintenance(): array
    {
        // La periodicidad vive en cron_task_state.next_run_at. Esta sonda solo
        // anuncia una unidad local acotada y no consulta tablas crecientes ni
        // information_schema. Cada servicio valida su propio contrato al correr.
        return $this->known(1, 1, null);
    }

    /** @return array<string,mixed> */
    private function salePackReconciliation(): array
    {
        try {
            $schema = new SchemaInspectorService();
            if (!$schema->hasTable('sale_pack_reconciliation_jobs')
                || !$schema->hasTable('sale_pack_rebuild_runs')) {
                return $this->unknown();
            }
            $row = Database::connectionFresh()->query(
                'SELECT SUM(work_count),MIN(oldest_due_at)
                 FROM (
                    SELECT COUNT(*) work_count,MIN(next_run_at) oldest_due_at
                    FROM sale_pack_reconciliation_jobs
                    WHERE status IN ("pending","retry") AND next_run_at<=UTC_TIMESTAMP()
                      AND (lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP())
                    UNION ALL
                    SELECT COUNT(*) work_count,MIN(created_at) oldest_due_at
                    FROM sale_pack_rebuild_runs
                    WHERE status="pending"
                      AND (lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP())
                 ) available_pack_work'
            )->fetch(PDO::FETCH_NUM);
            $eligible = max(0, (int) ($row[0] ?? 0));
            $total = max(0, (int) Database::connectionFresh()->query(
                'SELECT (SELECT COUNT(*) FROM sale_pack_reconciliation_jobs WHERE status IN ("pending","retry"))
                      +(SELECT COUNT(*) FROM sale_pack_rebuild_runs WHERE status="pending")'
            )->fetchColumn());
            return $this->known($eligible, $total, !empty($row[1]) ? (string) $row[1] : null);
        } catch (Throwable $error) {
            Logger::write('warning', 'No fue posible sondear la reconstrucción de ventas.', [
                'module' => 'cron',
                'error_class' => $error::class,
            ]);
            return $this->unknown();
        }
    }

    /** Mide trabajos de campaña, no el número de campañas contenedoras. @return array<string,mixed> */
    private function manualCampaign(): array
    {
        try {
            $schema = new SchemaInspectorService();
            if (!$schema->hasTable('manual_campaigns') || !$schema->hasTable('manual_campaign_items')) {
                return $this->unknown();
            }
            $row = Database::connectionFresh()->query(
                'SELECT
                    SUM(i.status IN ("pending","waiting","retry")
                        AND (i.next_eligible_at IS NULL OR i.next_eligible_at<=UTC_TIMESTAMP(3))
                        AND (i.lease_expires_at IS NULL OR i.lease_expires_at<UTC_TIMESTAMP(3))) eligible_count,
                    MIN(CASE WHEN i.status IN ("pending","waiting","retry")
                        AND (i.next_eligible_at IS NULL OR i.next_eligible_at<=UTC_TIMESTAMP(3))
                        AND (i.lease_expires_at IS NULL OR i.lease_expires_at<UTC_TIMESTAMP(3))
                        THEN i.next_eligible_at END) oldest_due_at,
                    COUNT(*) total_pending,
                    SUM(i.status="running") running_count,
                    SUM(i.status="failed") attention_count
                 FROM manual_campaign_items i
                 JOIN manual_campaigns c ON c.id=i.manual_campaign_id
                 WHERE c.execution_mode="directed_cli" AND c.status="active"
                   AND i.status IN ("pending","waiting","retry","running","failed")'
            )->fetch(PDO::FETCH_NUM);
            $eligible = max(0, (int) ($row[0] ?? 0));
            $total = max(0, (int) ($row[2] ?? 0));
            $running = max(0, (int) ($row[3] ?? 0));
            $attention = max(0, (int) ($row[4] ?? 0));
            return $this->known(
                $eligible,
                $total,
                !empty($row[1]) ? (string) $row[1] : null,
                'complete',
                $running,
                $attention
            );
        } catch (Throwable $error) {
            Logger::write('warning', 'No fue posible medir los trabajos de la campaña dirigida.', [
                'module' => 'cron',
                'error_class' => $error::class,
            ]);
            return $this->unknown();
        }
    }

    /** @return array<string,mixed> */
    private function questions(): array
    {
        try {
            $settings = new AppSettingsService();
            if (!$settings->bool('questions.sync_enabled', false)
                || !$settings->bool('questions.endpoint_confirmed', false)) {
                return $this->known(0, 0, null);
            }
            $schema = new SchemaInspectorService();
            if (!$schema->hasTable('meli_accounts') || !$schema->hasTable('question_sync_account_state')) {
                return $this->unknown();
            }
            $row = Database::connectionFresh()->query(
                'SELECT COUNT(*),MIN(s.next_sync_at)
                 FROM meli_accounts a
                 LEFT JOIN question_sync_account_state s ON s.meli_account_id=a.id
                 WHERE a.status IN ("conectado","connected")
                   AND (s.next_sync_at IS NULL OR s.next_sync_at<=UTC_TIMESTAMP())'
            )->fetch(PDO::FETCH_NUM);
            $count = max(0, (int) ($row[0] ?? 0));
            $total = max(0, (int) Database::connectionFresh()->query(
                'SELECT COUNT(*) FROM meli_accounts WHERE status IN ("conectado","connected")'
            )->fetchColumn());
            return $this->known($count, $total, !empty($row[1]) ? (string) $row[1] : null);
        } catch (Throwable $error) {
            Logger::write('warning', 'No fue posible sondear preguntas por cuenta.', [
                'module' => 'cron',
                'error_class' => $error::class,
            ]);
            return $this->unknown();
        }
    }

    /** @return array<string,mixed> */
    private function recurringSync(): array
    {
        try {
            return $this->normalize((new RecurringSyncService())->dueSnapshot());
        } catch (Throwable $error) {
            Logger::write('warning', 'No fue posible sondear sincronizaciones programadas.', [
                'module' => 'cron',
                'error_class' => $error::class,
            ]);
            return $this->unknown();
        }
    }

    /** @return array<string,mixed> */
    private function unknown(): array
    {
        return [
            'known' => false,
            'measurement_state' => 'unavailable',
            'work_count' => 0,
            'eligible_count' => 0,
            'total_pending' => 0,
            'waiting_schedule' => 0,
            'running_count' => 0,
            'attention_count' => 0,
            'oldest_due_at' => null,
            'measured_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string,mixed> */
    private function known(
        int $eligible,
        int $total,
        ?string $oldestDueAt,
        string $measurementState = 'complete',
        int $running = 0,
        int $attention = 0
    ): array
    {
        $eligible = max(0, $eligible);
        $total = max($eligible, $total);
        $running = max(0, min($total, $running));
        $attention = max(0, min(max(0, $total - $running), $attention));
        return [
            'known' => true,
            'measurement_state' => $measurementState === 'partial' ? 'partial' : 'complete',
            // Compatibilidad: work_count sigue significando elegibles para el selector.
            'work_count' => $eligible,
            'eligible_count' => $eligible,
            'total_pending' => $total,
            'waiting_schedule' => max(0, $total - $eligible - $running - $attention),
            'running_count' => $running,
            'attention_count' => $attention,
            'oldest_due_at' => $oldestDueAt,
            'measured_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalize(array $row): array
    {
        if (empty($row['known'])) {
            return $this->unknown();
        }
        $eligible = max(0, (int) ($row['eligible_count'] ?? $row['work_count'] ?? 0));
        $total = max($eligible, (int) ($row['total_pending'] ?? $eligible));
        return $this->known(
            $eligible,
            $total,
            !empty($row['oldest_due_at']) ? (string) $row['oldest_due_at'] : null,
            (string) ($row['measurement_state'] ?? 'complete'),
            max(0, (int) ($row['running_count'] ?? 0)),
            max(0, (int) ($row['attention_count'] ?? 0))
        );
    }
}
