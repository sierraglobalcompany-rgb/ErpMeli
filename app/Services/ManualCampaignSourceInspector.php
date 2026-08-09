<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

/**
 * Comprueba la tabla fuente antes de reservar o ejecutar.
 * La proyección descubre candidatos; este inspector decide si siguen vigentes.
 */
final class ManualCampaignSourceInspector
{
    public function inspect(string $queueKey, string|int $sourceId, int $accountId, int $companyId = 0): CampaignItemState
    {
        return match ($queueKey) {
            'notification_fallback' => (new NotificationWorkItemService())->inspectExact(
                (int) $sourceId,
                $accountId,
                $companyId
            ),
            'orders_sync' => $this->orders((int) $sourceId, $accountId, $companyId),
            'items_sync' => $this->items((int) $sourceId, $accountId, $companyId),
            'catalog_descriptions' => $this->descriptions((string) $sourceId, $accountId, $companyId),
            'sales_audit' => $this->directJob(
                'SELECT j.status,j.next_run_at,j.lock_expires_at,r.meli_account_id,a.company_id,
                        GREATEST(COALESCE(j.remote_reported_total,0)-COALESCE(j.next_offset,0),1) pending_count
                 FROM sync_sales_audit_jobs j
                 JOIN sync_sales_audit_runs r ON r.id=j.sync_sales_audit_run_id
                 JOIN meli_accounts a ON a.id=r.meli_account_id
                 WHERE j.id=? LIMIT 1',
                (int) $sourceId, $accountId, $companyId, 'sales_audit', 'Comprobar ventas',
                ['complete', 'cancelled'], ['pending', 'waiting_budget']
            ),
            'sales_repair' => $this->directJob(
                'SELECT j.status,j.next_run_at,j.lock_expires_at,j.meli_account_id,a.company_id,
                        GREATEST(j.total_items-j.processed_items,1) pending_count
                 FROM sync_sales_repair_jobs j JOIN meli_accounts a ON a.id=j.meli_account_id
                 WHERE j.id=? LIMIT 1',
                (int) $sourceId, $accountId, $companyId, 'order_exact', 'Recuperar ventas faltantes',
                ['complete', 'cancelled'], ['pending', 'retry', 'waiting_budget']
            ),
            'financial_recalc' => $this->directJob(
                'SELECT j.status,j.created_at next_run_at,NULL lock_expires_at,j.meli_account_id,a.company_id,
                        GREATEST(j.total_items-j.processed_items,1) pending_count
                FROM order_financial_recalc_jobs j JOIN meli_accounts a ON a.id=j.meli_account_id
                 WHERE j.id=? LIMIT 1',
                (int) $sourceId, $accountId, $companyId, 'local_financial', 'Recalcular finanzas',
                ['complete', 'cancelled'], ['pending'], false
            ),
            'order_enrichment' => $this->directJob(
                'SELECT j.status,j.next_run_at,
                        IF(j.locked_at IS NULL,NULL,DATE_ADD(j.locked_at,INTERVAL 10 MINUTE)) lock_expires_at,
                        j.meli_account_id,a.company_id,1 pending_count
                 FROM order_resource_enrichment_jobs j JOIN meli_accounts a ON a.id=j.meli_account_id
                 WHERE j.id=? LIMIT 1',
                (int) $sourceId, $accountId, $companyId, 'shipment_exact', 'Completar orden',
                ['complete', 'cancelled'], ['pending', 'retry']
            ),
            'sale_pack_reconciliation' => $this->directJob(
                'SELECT j.status,j.next_run_at,j.lease_expires_at lock_expires_at,
                        j.meli_account_id,j.company_id,1 pending_count
                 FROM sale_pack_reconciliation_jobs j WHERE j.id=? LIMIT 1',
                (int) $sourceId, $accountId, $companyId, 'pack_exact', 'Reconstruir venta agrupada',
                ['complete', 'cancelled'], ['pending', 'retry']
            ),
            'sale_financial_reconciliation' => $this->directJob(
                'SELECT j.status,j.next_run_at,j.lease_expires_at lock_expires_at,
                        j.meli_account_id,j.company_id,1 pending_count
                 FROM sale_financial_reconciliation_jobs j WHERE j.id=? LIMIT 1',
                (int) $sourceId, $accountId, $companyId, 'billing_orders', 'Conciliar venta',
                ['complete', 'cancelled'], ['pending', 'retry', 'awaiting_remote']
            ),
            'module_jobs' => $this->directJob(
                'SELECT j.status,j.next_run_at,j.lock_expires_at,
                        j.meli_account_id,a.company_id,GREATEST(j.progress_total-j.progress_current,1) pending_count
                 FROM system_module_jobs j JOIN meli_accounts a ON a.id=j.meli_account_id
                 WHERE j.id=? LIMIT 1',
                (int) $sourceId, $accountId, $companyId, 'insights', 'Actualizar módulo',
                ['completed', 'cancelled'], ['pending', 'retry']
            ),
            default => new CampaignItemState(
                false,
                false,
                false,
                false,
                'local_maintenance',
                'Trabajo',
                'Este tipo de trabajo no tiene verificación directa certificada.',
                0,
                1,
                'unsupported'
            ),
        };
    }

    private function orders(int $id, int $accountId, int $companyId): CampaignItemState
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT ch.status,ch.next_run_at,ch.estimated_total,ch.processed_count,ch.meli_account_id,
                    a.company_id,ch.date_from,ch.date_to
             FROM sync_batch_chunks ch
             JOIN meli_accounts a ON a.id=ch.meli_account_id
             WHERE ch.id=? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $this->state(
            $row,
            $accountId,
            $companyId,
            'orders_search',
            'Sincronizar órdenes',
            ['complete', 'empty', 'cancelled'],
            ['queued', 'partial'],
            (int) ($row['estimated_total'] ?? 1),
            (string) ($row['next_run_at'] ?? '')
        );
    }

    private function items(int $id, int $accountId, int $companyId): CampaignItemState
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT j.phase status,j.next_run_at,j.meli_account_id,a.company_id,
                    (SELECT COUNT(*) FROM meli_item_sync_job_items i
                     WHERE i.meli_item_sync_job_id=j.id AND i.status IN ("pending","error")) pending_count
             FROM meli_item_sync_jobs j
             JOIN meli_accounts a ON a.id=j.meli_account_id
             WHERE j.id=? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $this->state(
            $row,
            $accountId,
            $companyId,
            'item_detail',
            'Actualizar productos',
            ['complete', 'cancelled'],
            ['discovering', 'details', 'partial'],
            (int) ($row['pending_count'] ?? 1),
            (string) ($row['next_run_at'] ?? '')
        );
    }

    private function descriptions(string $sourceId, int $accountId, int $companyId): CampaignItemState
    {
        if (preg_match('/^(\d+):(\d+)$/', $sourceId, $matches) !== 1) {
            return new CampaignItemState(false, false, false, true, 'item_description', 'Consultar descripción', 'La descripción no tiene una identidad exacta.', 0, 1, 'unsupported');
        }
        $jobId = (int) $matches[1];
        $itemId = (int) $matches[2];
        $stmt = Database::connectionFresh()->prepare(
            'SELECT i.status,j.next_run_at,j.lock_expires_at,
                    i.meli_account_id,a.company_id,1 pending_count
             FROM catalog_description_job_items i
             JOIN catalog_description_jobs j ON j.id=i.catalog_description_job_id
             JOIN meli_accounts a ON a.id=i.meli_account_id
             WHERE j.id=? AND i.id=? LIMIT 1'
        );
        $stmt->execute([$jobId, $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && in_array((string) $row['status'], ['confirmed', 'unavailable', 'skipped'], true)) {
            return new CampaignItemState(
                true,
                true,
                false,
                true,
                'item_description',
                'Consultar descripción',
                'Las descripciones de esta cuenta ya fueron resueltas.',
                0,
                0,
                'completed_elsewhere'
            );
        }
        return $this->state(
            $row,
            $accountId,
            $companyId,
            'item_description',
            'Consultar descripción',
            ['confirmed', 'unavailable', 'skipped'],
            ['pending'],
            (int) ($row['pending_count'] ?? 1),
            (string) ($row['next_run_at'] ?? '')
        );
    }

    /**
     * @param list<string> $terminal
     * @param list<string> $ready
     */
    private function directJob(
        string $sql,
        int $sourceId,
        int $accountId,
        int $companyId,
        string $operation,
        string $label,
        array $terminal,
        array $ready,
        bool $usesApi = true
    ): CampaignItemState {
        $stmt = Database::connectionFresh()->prepare($sql);
        $stmt->execute([$sourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $this->state(
            $row,
            $accountId,
            $companyId,
            $operation,
            $label,
            $terminal,
            $ready,
            (int) ($row['pending_count'] ?? 1),
            (string) ($row['next_run_at'] ?? ''),
            $usesApi
        );
    }

    /**
     * @param array<string,mixed>|false $row
     * @param list<string> $terminal
     * @param list<string> $ready
     */
    private function state(
        array|false $row,
        int $accountId,
        int $companyId,
        string $operation,
        string $label,
        array $terminal,
        array $ready,
        int $items,
        string $nextAt,
        bool $usesApi = true
    ): CampaignItemState {
        if (!is_array($row)) {
            return new CampaignItemState(false, true, false, $usesApi, $operation, $label, 'El trabajo ya no existe.', 0, 0, 'missing');
        }
        if ($accountId > 0 && (int) ($row['meli_account_id'] ?? 0) !== $accountId) {
            throw new RuntimeException('El trabajo no pertenece a la cuenta congelada por la campaña.');
        }
        if ($companyId > 0 && (int) ($row['company_id'] ?? 0) !== $companyId) {
            throw new RuntimeException('El trabajo no pertenece a la empresa congelada por la campaña.');
        }
        $status = strtolower((string) ($row['status'] ?? ''));
        if (in_array($status, $terminal, true)) {
            return new CampaignItemState(true, true, false, $usesApi, $operation, $label, 'Automatización ya completó este trabajo.', 0, $items, 'completed_elsewhere');
        }
        if (!in_array($status, $ready, true)) {
            $recoverable = in_array($status, ['error', 'failed'], true);
            return new CampaignItemState(
                true,
                false,
                false,
                $usesApi,
                $operation,
                $label,
                $recoverable ? 'El trabajo tiene un error que debe revisarse.' : 'El trabajo está pausado.',
                0,
                $items,
                $recoverable ? 'action_required' : 'paused'
            );
        }
        $clock = new SystemDatabaseUtcClock();
        if ($nextAt !== '' && !$clock->isDue($nextAt)) {
            $displayAt = $clock->toBogota($nextAt) ?? DateTimePresenter::formatQueue($nextAt, 'd/m/Y H:i:s');
            return new CampaignItemState(
                true,
                false,
                false,
                $usesApi,
                $operation,
                $label,
                'Disponible el ' . $displayAt . ' hora Bogotá.',
                0,
                $items,
                'future',
                $nextAt
            );
        }
        if (!empty($row['lock_expires_at']) && !$clock->isDue((string) $row['lock_expires_at'])) {
            return new CampaignItemState(
                true,
                false,
                false,
                $usesApi,
                $operation,
                $label,
                'Otro proceso está terminando este trabajo.',
                0,
                $items,
                'locked',
                (string) $row['lock_expires_at']
            );
        }
        return new CampaignItemState(true, false, true, $usesApi, $operation, $label, 'Listo para procesar.', 1, max(1, $items), 'ready');
    }
}
