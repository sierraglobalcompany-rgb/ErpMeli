<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class RegisteredManualCampaignAdapter implements InteractiveCampaignAdapter
{
    /** @param array<string,mixed> $definition */
    public function __construct(private array $definition)
    {
    }

    public function key(): string
    {
        return (string) $this->definition['key'];
    }

    public function queueKey(): string
    {
        return (string) $this->definition['queue_key'];
    }

    public function supportsExact(): bool
    {
        return !empty($this->definition['exact']);
    }

    public function inspect(string $sourceId, int $accountId): CampaignItemState
    {
        $validSource = ctype_digit($sourceId)
            || ($this->queueKey() === 'catalog_descriptions'
                && preg_match('/^\d+:\d+$/', $sourceId) === 1);
        if (!$this->supportsExact() || !$validSource) {
            return new CampaignItemState(
                false,
                false,
                false,
                (bool) ($this->definition['uses_api'] ?? false),
                (string) ($this->definition['operation_key'] ?? 'local_maintenance'),
                (string) ($this->definition['label'] ?? 'Trabajo'),
                'Esta cola todavía no dispone de un adaptador exacto certificado.'
            );
        }
        $projectionSourceId = $this->queueKey() === 'catalog_descriptions'
            ? explode(':', $sourceId, 2)[0]
            : $sourceId;
        $row = (new WorkQueueProjectionService())->find($this->queueKey(), $projectionSourceId);
        if (!is_array($row)) {
            return new CampaignItemState(
                false,
                true,
                false,
                (bool) ($this->definition['uses_api'] ?? false),
                (string) $this->definition['operation_key'],
                (string) $this->definition['label'],
                'El trabajo ya no está pendiente o dejó de existir.'
            );
        }
        if ($this->queueKey() !== 'catalog_descriptions'
            && $accountId > 0
            && (int) ($row['meli_account_id'] ?? 0) !== $accountId) {
            throw new RuntimeException('El trabajo no pertenece a la cuenta seleccionada.');
        }
        $companyId = 0;
        if ($accountId > 0) {
            $account = (new BusinessScopeContext())->account($accountId);
            $companyId = (int) $account['company_id'];
        }
        $source = (new ManualCampaignSourceInspector())->inspect(
            $this->queueKey(),
            $sourceId,
            $accountId,
            $companyId
        );
        return new CampaignItemState(
            $source->exists,
            $source->terminal,
            $source->eligible,
            $source->usesApi,
            $source->operationKey,
            (string) ($row['human_label'] ?? $this->definition['label']),
            $source->message,
            $source->estimatedCalls,
            $source->estimatedItems,
            $source->sourceState,
            $source->nextEligibleAt,
        );
    }

    public function processExact(
        string $sourceId,
        int $accountId,
        CampaignExecutionContext $context
    ): CampaignItemResult {
        $validSource = ctype_digit($sourceId)
            || ($this->queueKey() === 'catalog_descriptions'
                && preg_match('/^\d+:\d+$/', $sourceId) === 1);
        if (!$this->supportsExact() || !$validSource) {
            throw new RuntimeException('La campaña se detuvo porque esta cola no tiene un adaptador exacto certificado.');
        }
        if ($accountId > 0) {
            $account = \App\Core\Database::connectionFresh()->prepare(
                'SELECT company_id FROM meli_accounts WHERE id=? LIMIT 1'
            );
            $account->execute([$accountId]);
            $companyId = (int) $account->fetchColumn();
            if ($companyId < 1 || $companyId !== $context->companyId) {
                throw new RuntimeException('La cuenta ya no pertenece a la empresa congelada por la campaña.');
            }
        }
        $sourceState = (new ManualCampaignSourceInspector())->inspect(
            $this->queueKey(),
            $sourceId,
            $accountId,
            $context->companyId
        );
        if (!$sourceState->exists) {
            throw new RuntimeException('El recurso exacto ya no existe dentro del alcance congelado por la campaña.');
        }
        $id = (int) explode(':', $sourceId, 2)[0];
        $queueKey = $this->queueKey();
        $result = match ($queueKey) {
            'notification_fallback' => (new NotificationWorkItemService())->processExact(
                $id,
                $accountId,
                $context,
                false
            ),
            // Una campaña representa cada salida remota de forma individual.
            // El cron general conserva sus lotes normales mediante los defaults.
            'orders_sync' => (new SyncQueueService())->processOne($id, $accountId, 1, false),
            'items_sync' => (new MeliItemSyncJobService())->processJob(
                $id,
                1,
                $context->deadline
            ),
            'catalog_descriptions' => (new CatalogDescriptionJobService())->processExactItem(
                $id,
                (int) explode(':', $sourceId, 2)[1],
                $accountId,
                $context->deadline
            ),
            'notification_backfill' => (new NotificationBackfillService())->processRun(
                $id,
                1
            ),
            'sales_audit' => (new SalesAuditRunService())->processExact($id, 1, $context->deadline),
            'sales_repair' => (new SalesAuditExactRepairService())->processManualExact($id),
            'financial_recalc' => (new OrderFinancialRecalcJobService())->processManualExact($id, $accountId),
            'order_enrichment' => (new OrderEnrichmentService())->processManualExact($id, $context->deadline),
            'sale_pack_reconciliation' => (new HistoricalPackReconciliationService())->processManualExact($id),
            'sale_financial_reconciliation' => (new SaleFinancialService())->processManualExact($id),
            'module_jobs' => (new \App\Core\Modules\ModuleKernel())->processExactJob($id, $context->deadline),
            default => throw new RuntimeException('El adaptador exacto no tiene un ejecutor registrado.'),
        };
        $errors = max(0, (int) ($result['errors'] ?? 0));
        $processed = max(0, (int) match ($queueKey) {
            'orders_sync' => $result['orders'] ?? 0,
            'items_sync', 'catalog_descriptions', 'notification_backfill', 'notification_fallback',
            'sales_audit', 'sales_repair', 'financial_recalc', 'order_enrichment',
            'sale_pack_reconciliation', 'sale_financial_reconciliation' => $result['processed'] ?? 0,
            'module_jobs' => $result['processed'] ?? 0,
        });
        $status = (string) ($result['status'] ?? '');
        $phase = (string) ($result['phase'] ?? '');
        $stopReason = (string) ($result['stop_reason'] ?? '');
        $done = $this->resultIsDone($queueKey, $result, $status, $phase, $stopReason);
        $locked = $stopReason === 'locked' || $phase === 'locked';
        if ($errors > 0 || in_array($status, ['error', 'failed'], true)) {
            return new CampaignItemResult(
                'error',
                (string) ($result['message'] ?? 'El recurso necesita revisión.'),
                $processed,
                0,
                0,
                0,
                null,
                isset($result['diagnostic_id']) ? (string) $result['diagnostic_id'] : null
            );
        }
        if (!$done || $locked || in_array($status, ['deferred', 'waiting_budget', 'partial'], true)) {
            $nextEligibleAt = isset($result['next_eligible_at'])
                ? (string) $result['next_eligible_at']
                : (isset($result['next_safe_at'])
                    ? (string) $result['next_safe_at']
                    : gmdate('Y-m-d H:i:s', time() + ($locked ? 15 : 2)));
            $waitReason = $stopReason !== '' ? $stopReason : ($status !== '' ? $status : $phase);
            $primaryCalls = $this->primaryCalls($queueKey, $result, $processed);
            return new CampaignItemResult(
                'deferred',
                (string) ($result['message'] ?? 'El recurso continuará desde su último punto seguro.'),
                $processed,
                $primaryCalls,
                0,
                0,
                $nextEligibleAt,
                isset($result['diagnostic_id']) ? (string) $result['diagnostic_id'] : null,
                $waitReason
            );
        }
        $primaryCalls = $this->primaryCalls($queueKey, $result, $processed);
        return new CampaignItemResult(
            'completed',
            (string) ($result['message'] ?? 'Recurso procesado correctamente.'),
            $processed,
            $primaryCalls,
            0,
            $processed === 0 && $primaryCalls === 0 ? 1 : 0
        );
    }

    /** @param array<string,mixed> $result */
    private function resultIsDone(
        string $queueKey,
        array $result,
        string $status,
        string $phase,
        string $stopReason
    ): bool {
        return match ($queueKey) {
            'notification_fallback' => in_array($status, ['complete', 'completed', 'skipped'], true),
            'orders_sync' => in_array($status, ['complete', 'skipped'], true),
            'items_sync' => $phase === 'complete',
            'catalog_descriptions' => !empty($result['exact_item_done'])
                || $stopReason === 'complete'
                || (int) ($result['job']['remaining_items'] ?? 1) === 0,
            'notification_backfill' => !empty($result['done'])
                || in_array($status, ['ready', 'complete'], true),
            'sales_audit', 'sales_repair', 'financial_recalc', 'order_enrichment',
            'sale_pack_reconciliation', 'sale_financial_reconciliation' =>
                in_array($status, ['complete', 'completed'], true)
                || (int) ($result['completed'] ?? 0) > 0
                || (string) ($result['stop_reason'] ?? '') === 'work_completed',
            'module_jobs' => (int) ($result['processed'] ?? 0) > 0 && (int) ($result['errors'] ?? 0) === 0,
            default => false,
        };
    }

    /** @param array<string,mixed> $result */
    private function primaryCalls(string $queueKey, array $result, int $processed): int
    {
        return match ($queueKey) {
            // Estos valores son una compatibilidad conservadora. El worker
            // sustituye el conteo por api_request_logs antes de cerrar el ítem.
            'items_sync', 'catalog_descriptions', 'orders_sync' => 0,
            'notification_backfill', 'notification_fallback' => 0,
            default => 0,
        };
    }
}
