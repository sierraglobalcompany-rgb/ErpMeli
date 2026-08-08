<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * Cuenta únicamente solicitudes observadas por ApiGuardService.
 * Una fila bloqueada antes del transporte no se presenta como consulta enviada.
 */
final class ManualCampaignCallCounter
{
    /**
     * @return array{primary:int,derived:int,outbound:int,policy_delay:bool,remote_rate_limit:bool,next_eligible_at:?string}
     */
    public function summarize(int $campaignItemId, int $leaseGeneration): array
    {
        if ($campaignItemId < 1 || $leaseGeneration < 1) {
            return ['primary' => 0, 'derived' => 0, 'outbound' => 0, 'policy_delay' => false, 'remote_rate_limit' => false, 'next_eligible_at' => null];
        }
        try {
            $stmt = Database::connectionFresh()->prepare(
                'SELECT
                    SUM(reached_remote=1 AND operation_key<>"oauth") primary_calls,
                    SUM(reached_remote=1 AND operation_key="oauth") derived_calls,
                    SUM(reached_remote=1) outbound_calls,
                    MAX(outcome_class="policy_delay" AND reached_remote=0) policy_delay,
                    MAX(http_status=429 AND reached_remote=1) remote_rate_limit,
                    MAX(CASE
                        WHEN (outcome_class="policy_delay" OR http_status=429)
                             AND retry_after_seconds IS NOT NULL
                        THEN DATE_ADD(created_at,INTERVAL retry_after_seconds SECOND)
                        ELSE NULL
                    END) next_eligible_at
                 FROM api_request_logs
                 WHERE source_queue_key="manual_campaign" AND source_work_id=?'
            );
            $stmt->execute([$campaignItemId . ':' . $leaseGeneration]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new \RuntimeException('No result');
            }
            return [
                'primary' => max(0, (int) ($row['primary_calls'] ?? 0)),
                'derived' => max(0, (int) ($row['derived_calls'] ?? 0)),
                'outbound' => max(0, (int) ($row['outbound_calls'] ?? 0)),
                'policy_delay' => !empty($row['policy_delay']),
                'remote_rate_limit' => !empty($row['remote_rate_limit']),
                'next_eligible_at' => !empty($row['next_eligible_at'])
                    ? (string) $row['next_eligible_at']
                    : null,
            ];
        } catch (Throwable) {
            // Si la telemetría no está disponible, fallamos de forma
            // conservadora: nunca inventamos una llamada remota.
            return ['primary' => 0, 'derived' => 0, 'outbound' => 0, 'policy_delay' => false, 'remote_rate_limit' => false, 'next_eligible_at' => null];
        }
    }
}
