<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

/** Diagnóstico local y read-only de una venta. */
final class FinancialCompletenessService
{
    /** @return array<string,mixed> */
    public function inspectSale(int $companyId, int $accountId, string $saleKey): array
    {
        $state = (new SaleFinancialStateService())->current($companyId, $accountId, $saleKey);
        if ($state === []) {
            return [
                'commercial' => 'missing',
                'logistics' => 'missing',
                'provisional' => 'missing',
                'official' => 'missing',
                'missing_flags' => ['financial_state_missing'],
                'active_job' => null,
                'next_step' => 'local_projection',
                'complete' => false,
            ];
        }

        $missing = json_decode((string) ($state['missing_flags_json'] ?? '[]'), true);
        $missing = is_array($missing) ? array_values(array_filter(array_map('strval', $missing))) : [];
        $job = null;
        if ((new SchemaInspectorService())->hasTable('sale_financial_reconciliation_jobs')) {
            $stmt = Database::connectionFresh()->prepare(
                'SELECT id,status,next_run_at,safe_message,input_version
                 FROM sale_financial_reconciliation_jobs
                 WHERE company_id=? AND meli_account_id=? AND sale_key=? AND input_version=?
                   AND status IN ("pending","running","retry","awaiting_remote")
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$companyId, $accountId, $saleKey, (string) $state['input_version']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $job = is_array($row) ? $row : null;
        }

        $provisional = (string) $state['provisional_status'];
        $official = (string) $state['official_status'];
        $nextStep = 'none';
        if ($provisional === 'missing') {
            $nextStep = 'local_projection';
        } elseif ($official !== 'complete' && $job !== null) {
            $nextStep = 'waiting_billing';
        } elseif ($official !== 'complete') {
            $nextStep = 'billing_capture';
        } elseif (in_array((string) $state['logistics_status'], ['missing', 'partial', 'review'], true)) {
            $nextStep = 'logistics_repair';
        }

        return [
            'commercial' => (string) $state['commercial_status'],
            'logistics' => (string) $state['logistics_status'],
            'provisional' => $provisional,
            'official' => $official,
            'missing_flags' => $missing,
            'active_job' => $job,
            'next_step' => $nextStep,
            'complete' => (string) $state['commercial_status'] === 'complete'
                && in_array((string) $state['logistics_status'], ['complete', 'not_required'], true)
                && $provisional === 'complete'
                && $official === 'complete',
            'input_version' => (string) $state['input_version'],
            'close_impact' => (string) $state['close_impact'],
        ];
    }
}
