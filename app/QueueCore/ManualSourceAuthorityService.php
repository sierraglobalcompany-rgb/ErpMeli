<?php

declare(strict_types=1);

namespace App\QueueCore;

use App\Core\Database;
use App\Services\CampaignItemState;
use PDO;
use RuntimeException;

/**
 * Relee la autoridad fuente inmediatamente antes de materializar el trabajo.
 * Nunca usa textos, horarios de reintento ni timestamps del preview.
 */
final class ManualSourceAuthorityService
{
    /** @var array<string,list<string>> */
    private const DURABLE_FIELDS = [
        'notification_fallback' => ['status', 'resource_type', 'remote_resource_id', 'latest_event_id', 'occurrence_count', 'rerun_requested'],
        'orders_sync' => ['status', 'sync_type', 'date_from', 'date_to', 'cursor_offset', 'processed_count', 'estimated_total'],
        'items_sync' => ['phase', 'search_mode', 'cursor_value', 'offset_value', 'discovered_count', 'processed_count', 'error_count'],
        'catalog_descriptions' => ['item_status', 'external_item_id', 'meli_item_id', 'job_status'],
        'sales_audit' => ['status', 'sync_sales_audit_run_id', 'next_offset', 'remote_reported_total', 'page_limit'],
        'sales_repair' => ['status', 'processed_items', 'total_items', 'sync_sales_audit_id', 'input_version'],
        'financial_recalc' => ['status', 'processed_items', 'total_items', 'source_type', 'source_id', 'input_version'],
        'order_enrichment' => ['status', 'resource_type', 'external_resource_id', 'meli_order_id', 'input_version'],
        'sale_pack_reconciliation' => ['status', 'operation', 'external_resource_id', 'meli_pack_id', 'input_version'],
        'sale_financial_reconciliation' => ['status', 'sale_key', 'external_sale_id', 'input_version'],
        'module_jobs' => ['status', 'module_id', 'job_type', 'stage', 'progress_current', 'progress_total', 'payload_json', 'checkpoint_json'],
    ];

    public function inspect(
        string $queueKey,
        string $sourceId,
        int $accountId,
        int $companyId,
        CampaignItemState $state
    ): ManualSourceAuthority {
        if ($accountId < 1 || $companyId < 1) {
            throw new RuntimeException('La fuente exacta requiere empresa y cuenta autorizadas.');
        }
        $row = $this->sourceRow($queueKey, $sourceId);
        if ($row === null) {
            throw new RuntimeException('La fuente exacta dejó de existir antes de reservar el trabajo.');
        }
        $rowAccount = (int) ($row['meli_account_id'] ?? 0);
        $rowCompany = (int) ($row['_scope_company_id'] ?? $row['company_id'] ?? 0);
        if ($rowAccount !== $accountId) {
            throw new RuntimeException('La fuente exacta no pertenece a la cuenta seleccionada.');
        }
        if ($rowCompany !== $companyId) {
            throw new RuntimeException('La fuente exacta no pertenece a la empresa seleccionada.');
        }

        if ($queueKey === 'module_jobs') {
            return new ManualSourceAuthority(
                $this->fingerprint($queueKey, $sourceId, $row),
                false,
                'local_maintenance',
                null,
                true,
                'El trabajo modular puede cambiar de endpoint por etapa y todavía no tiene un contrato exacto de una sola llamada.'
            );
        }

        $operation = $this->operation($queueKey, $row, $state->operationKey);
        $usesApi = $queueKey === 'financial_recalc' ? false : $state->usesApi;
        $contract = $usesApi
            ? (new ManualRemoteCapabilityRegistry())->forSource($queueKey, $operation, $row)
            : null;
        if ($usesApi && $contract === null) {
            return new ManualSourceAuthority(
                $this->fingerprint($queueKey, $sourceId, $row),
                true,
                $operation,
                null,
                true,
                'La fuente exacta no tiene un método y endpoint remoto certificados.'
            );
        }
        return new ManualSourceAuthority(
            $this->fingerprint($queueKey, $sourceId, $row),
            $usesApi,
            $operation,
            $contract
        );
    }

    /** @return array<string,mixed>|null */
    private function sourceRow(string $queueKey, string $sourceId): ?array
    {
        $pdo = Database::connectionFresh();
        if ($queueKey === 'catalog_descriptions') {
            if (preg_match('/^(\d+):(\d+)$/', $sourceId, $ids) !== 1) {
                return null;
            }
            $stmt = $pdo->prepare(
                'SELECT i.*,i.status item_status,i.attempts item_attempts,j.status job_status,
                        a.company_id _scope_company_id
                 FROM catalog_description_job_items i
                 JOIN catalog_description_jobs j ON j.id=i.catalog_description_job_id
                 JOIN meli_accounts a ON a.id=i.meli_account_id
                 WHERE j.id=? AND i.id=? LIMIT 1'
            );
            $stmt->execute([(int) $ids[1], (int) $ids[2]]);
        } elseif ($queueKey === 'sales_audit') {
            if (!ctype_digit($sourceId)) {
                return null;
            }
            $stmt = $pdo->prepare(
                'SELECT w.*,r.meli_account_id,a.company_id _scope_company_id
                 FROM sync_sales_audit_jobs w
                 JOIN sync_sales_audit_runs r ON r.id=w.sync_sales_audit_run_id
                 JOIN meli_accounts a ON a.id=r.meli_account_id
                 WHERE w.id=? LIMIT 1'
            );
            $stmt->execute([(int) $sourceId]);
        } else {
            if (!ctype_digit($sourceId)) {
                return null;
            }
            $definition = match ($queueKey) {
                'notification_fallback' => ['meli_notification_work_items', 'w', 'w.meli_account_id'],
                'orders_sync' => ['sync_batch_chunks', 'w', 'w.meli_account_id'],
                'items_sync' => ['meli_item_sync_jobs', 'w', 'w.meli_account_id'],
                'sales_repair' => ['sync_sales_repair_jobs', 'w', 'w.meli_account_id'],
                'financial_recalc' => ['order_financial_recalc_jobs', 'w', 'w.meli_account_id'],
                'order_enrichment' => ['order_resource_enrichment_jobs', 'w', 'w.meli_account_id'],
                'sale_pack_reconciliation' => ['sale_pack_reconciliation_jobs', 'w', 'w.meli_account_id'],
                'sale_financial_reconciliation' => ['sale_financial_reconciliation_jobs', 'w', 'w.meli_account_id'],
                'module_jobs' => ['system_module_jobs', 'w', 'w.meli_account_id'],
                default => null,
            };
            if ($definition === null) {
                return null;
            }
            [$table, $alias, $accountColumn] = $definition;
            $stmt = $pdo->prepare(
                "SELECT {$alias}.*,a.company_id _scope_company_id
                 FROM {$table} {$alias}
                 JOIN meli_accounts a ON a.id={$accountColumn}
                 WHERE {$alias}.id=? LIMIT 1"
            );
            $stmt->execute([(int) $sourceId]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $row */
    private function operation(string $queueKey, array $row, string $fallback): string
    {
        if ($queueKey === 'order_enrichment') {
            return match (strtolower((string) ($row['resource_type'] ?? ''))) {
                'shipment' => 'shipment_exact',
                'pack' => 'pack_exact',
                default => 'unsupported',
            };
        }
        if ($queueKey === 'items_sync') {
            return (string) ($row['phase'] ?? '') === 'discovering'
                ? 'items_discovery'
                : 'item_detail';
        }
        return $queueKey === 'sales_audit' ? 'sales_audit' : $fallback;
    }

    /** @param array<string,mixed> $row */
    private function fingerprint(string $queueKey, string $sourceId, array $row): string
    {
        $evidence = [
            'queue_key' => $queueKey,
            'source_id' => $sourceId,
            'meli_account_id' => (int) ($row['meli_account_id'] ?? 0),
            'company_id' => (int) ($row['_scope_company_id'] ?? $row['company_id'] ?? 0),
        ];
        foreach (self::DURABLE_FIELDS[$queueKey] ?? [] as $field) {
            if (array_key_exists($field, $row)) {
                $evidence[$field] = $this->canonical($row[$field]);
            }
        }
        ksort($evidence, SORT_STRING);
        return hash('sha256', json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            if (!is_string($value)) {
                return $value;
            }
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $this->canonical($decoded) : $value;
        }
        if (array_is_list($value)) {
            return array_map($this->canonical(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }
        return $value;
    }
}
