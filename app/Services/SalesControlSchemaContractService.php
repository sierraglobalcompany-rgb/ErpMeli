<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Contrato mínimo de esquema para que Control de ventas degrade de forma segura.
 *
 * La comprobación es solo de lectura y evita ejecutar consultas del módulo
 * cuando una actualización quedó incompleta.
 */
final class SalesControlSchemaContractService
{
    /** @var array<string,list<string>> */
    private const REQUIRED = [
        'companies' => ['id', 'name', 'deleted_at'],
        'meli_accounts' => ['id', 'company_id', 'account_name', 'meli_user_id', 'site_id', 'status'],
        'sync_sales_audit_runs' => [
            'id', 'company_id', 'meli_account_id', 'period_year', 'period_month',
            'mode', 'status', 'remote_coverage', 'snapshot_hash',
            'requested_from_utc', 'requested_to_utc', 'historical_window_starts_at',
            'effective_coverage_from_utc', 'effective_coverage_to_utc',
            'temporal_coverage_state', 'temporal_coverage_reason', 'coverage_contract_version',
        ],
        'sync_sales_audit_jobs' => [
            'id', 'sync_sales_audit_run_id', 'meli_account_id', 'company_id',
            'status', 'started_at', 'next_run_at', 'lease_generation',
        ],
        'sync_sales_audit_run_orders' => [
            'id', 'sync_sales_audit_run_id', 'meli_account_id',
            'external_order_id', 'classification',
        ],
        'sales_control_years' => ['id', 'company_id', 'meli_account_id', 'control_year'],
        'sales_control_months' => [
            'id', 'sales_control_year_id', 'company_id', 'meli_account_id',
            'period_year', 'period_month', 'status',
        ],
        'sales_control_captures' => [
            'id', 'sales_control_month_id', 'company_id', 'meli_account_id',
            'sync_sales_audit_run_id', 'requested_from_utc', 'requested_to_utc',
            'snapshot_hash',
            'effective_coverage_from_utc', 'effective_coverage_to_utc',
            'temporal_coverage_state', 'temporal_coverage_reason', 'coverage_contract_version',
        ],
        'sales_control_fiscal_items' => [
            'id', 'sales_control_month_id', 'company_id', 'meli_account_id',
            'meli_order_id', 'fiscal_status',
        ],
        'sales_control_fiscal_snapshots' => [
            'id', 'company_id', 'meli_account_id', 'meli_order_id', 'encrypted_payload',
        ],
        'sales_control_closes' => [
            'id', 'sales_control_month_id', 'company_id', 'meli_account_id',
            'audit_run_id', 'verification_run_id', 'evidence_hash',
            'coverage_contract_version', 'temporal_coverage_state',
        ],
    ];

    /** @return array{ready:bool,base_ready:bool,missing:list<string>,message:string} */
    public function inspect(): array
    {
        $schema = new SchemaInspectorService();
        $missing = $schema->missingRequirements(self::REQUIRED);
        $baseReady = array_filter(
            $missing,
            static fn (string $item): bool => $item === 'companies'
                || $item === 'meli_accounts'
                || str_starts_with($item, 'companies.')
                || str_starts_with($item, 'meli_accounts.')
        ) === [];

        return [
            'ready' => $missing === [],
            'base_ready' => $baseReady,
            'missing' => $missing,
            'message' => $missing === []
                ? 'Control de ventas está listo.'
                : 'La base de datos necesita completar la actualización antes de usar Control de ventas.',
        ];
    }
}
