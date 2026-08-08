<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class WorkRemediationService
{
    /** @return list<array<string,mixed>> */
    public function groups(?int $userId = null): array
    {
        $accounts = (new BusinessScopeContext())->accountIds($userId);
        if ($accounts === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($accounts), '?'));
        $historyAvailable = (new SchemaInspectorService())->hasTable('system_work_historical_reconciliations');
        $historyJoin = $historyAvailable
            ? ' LEFT JOIN system_work_historical_reconciliations h
                    ON h.company_id=p.company_id AND h.meli_account_id=p.meli_account_id
                   AND h.queue_key=p.queue_key AND h.source_id=p.source_id
                   AND h.observed_source_status=p.source_status
                   AND (h.observed_source_updated_at IS NULL OR p.source_updated_at IS NULL
                        OR h.observed_source_updated_at=p.source_updated_at) '
            : '';
        $code = $historyAvailable
            ? 'COALESCE(NULLIF(h.normalized_error_code,""),NULLIF(p.normalized_error_code,""),"")'
            : 'COALESCE(p.normalized_error_code,"")';
        $remote = $historyAvailable ? 'COALESCE(h.reached_remote,p.reached_remote)' : 'p.reached_remote';
        $resolution = 'CASE
                 WHEN ' . $code . '="http_404" THEN "expected_absence"
                 WHEN ' . $code . '="remote_result_uncertain" OR ' . $remote . '=1 THEN "remote_result_uncertain"
                 WHEN ' . $remote . '=0 THEN "retryable_local"
                 ELSE "legacy_needs_diagnosis"
               END';
        $stmt = Database::connectionFresh()->prepare(
            'SELECT
               p.queue_key,' . $resolution . ' resolution_key,
               COUNT(*) affected,
               MIN(p.created_at_source) first_seen,
               MAX(p.source_updated_at) last_seen,
               SUM(COALESCE(' . $remote . ',0)=1) reached_remote_count
             FROM system_work_queue_projection p' . $historyJoin . '
             WHERE p.display_status="error"
               AND p.meli_account_id IN (' . $placeholders . ')
               AND ' . $code . ' NOT IN
                   ("cron_deadline_deferred","deadline_reached","not_started_deadline","waiting_budget",
                    "waiting_rhythm","waiting_api","api_budget_exhausted","api_manual_pause",
                    "account_paused","account_stopped","lock_busy","lease_busy")
               AND COALESCE(p.remediation_key,"") NOT LIKE "waiting\\_%"
             GROUP BY p.queue_key,resolution_key
             ORDER BY FIELD(resolution_key,
               "legacy_needs_diagnosis","retryable_local","expected_absence","remote_result_uncertain"),p.queue_key'
        );
        $stmt->execute($accounts);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $definitions = (new WorkQueueRegistry())->definitionsByKey();
        return array_map(function (array $row) use ($definitions): array {
            $key = (string) $row['resolution_key'];
            $queue = (string) $row['queue_key'];
            return $row + $this->definition($key) + [
                'queue_label' => (string) ($definitions[$queue]['label'] ?? $queue),
                'review_url' => '/settings/cron/queue?' . http_build_query([
                    'group' => 'attention',
                    'type' => $queue,
                    'resolution' => $key,
                ]),
            ];
        }, $rows);
    }

    /** @return array<string,mixed> */
    private function definition(string $key): array
    {
        return match ($key) {
            'retryable_local' => [
                'title' => 'Error local con reintento exacto disponible',
                'cause' => 'La evidencia confirma que Mercado Libre no fue consultado.',
                'impact' => 'Cada trabajo puede reprogramarse individualmente sin afectar los demás.',
                'risk' => 'Ninguno',
                'action' => 'Revisar trabajos',
                'automatic' => false,
            ],
            'expected_absence' => [
                'title' => 'Dato remoto que ya no está disponible',
                'cause' => 'Existe un 404 confirmado para estos recursos.',
                'impact' => 'Puede cerrar cada trabajo como ausencia esperada sin borrar información comercial.',
                'risk' => 'Ninguno',
                'action' => 'Revisar trabajos',
                'automatic' => false,
            ],
            'remote_result_uncertain' => [
                'title' => 'Resultado remoto que no debe repetirse a ciegas',
                'cause' => 'La evidencia confirma o no descarta que el transporte haya comenzado.',
                'impact' => 'Cada recurso permanece bloqueado hasta una decisión exacta.',
                'risk' => 'Medio',
                'action' => 'Revisar trabajos',
                'automatic' => false,
            ],
            default => [
                'title' => 'Registro anterior a la trazabilidad completa',
                'cause' => 'La evidencia histórica todavía no confirma si comenzó el transporte.',
                'impact' => 'Abra cada trabajo y ejecute el diagnóstico local antes de decidir.',
                'risk' => 'Por comprobar',
                'action' => 'Diagnosticar trabajos',
                'automatic' => false,
            ],
        };
    }
}
