<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class ExecutionJournalService
{
    /** @return array{id:int,token:string,started_at:float} */
    public function begin(string $component, ?int $campaignId = null, int $windowMs = 20000): array
    {
        $this->recoverInterrupted($component);
        $token = bin2hex(random_bytes(20));
        $stopAt = gmdate('Y-m-d H:i:s.u', time() + max(1, (int) ceil($windowMs / 1000)));
        $pdo = Database::connectionFresh();
        $stmt = $pdo->prepare(
            'INSERT INTO system_execution_runs
             (run_token,component_key,manual_campaign_id,status,stop_acquiring_at)
             VALUES (?,?,?,"running",?)'
        );
        $stmt->execute([$token, $component, $campaignId, $stopAt]);
        return ['id' => (int) $pdo->lastInsertId(), 'token' => $token, 'started_at' => microtime(true)];
    }

    public function heartbeat(int $runId): void
    {
        Database::connectionFresh()->prepare(
            'UPDATE system_execution_runs SET heartbeat_at=UTC_TIMESTAMP(3) WHERE id=? AND status="running"'
        )->execute([$runId]);
    }

    /** @param array<string,mixed> $item */
    public function reserve(int $runId, array $item): int
    {
        $key = hash('sha256', implode('|', [
            $runId,
            (int) ($item['manual_campaign_id'] ?? 0),
            (int) ($item['id'] ?? 0),
            (int) ($item['lease_generation'] ?? 0),
        ]));
        $pdo = Database::connectionFresh();
        $stmt = $pdo->prepare(
            'INSERT INTO system_execution_attempts
             (system_execution_run_id,manual_campaign_id,manual_campaign_item_id,company_id,meli_account_id,
              queue_key,source_id,operation_key,idempotency_key,lease_generation,state)
             VALUES (?,?,?,?,?,?,?,?,?,?,"reserved")
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)'
        );
        $stmt->execute([
            $runId,
            (int) ($item['manual_campaign_id'] ?? 0) ?: null,
            (int) ($item['id'] ?? 0) ?: null,
            (int) ($item['company_id'] ?? 0) ?: null,
            (int) ($item['meli_account_id'] ?? 0) ?: null,
            (string) ($item['queue_key'] ?? ''),
            (string) ($item['source_id'] ?? ''),
            (string) ($item['operation_key'] ?? ''),
            $key,
            (int) ($item['lease_generation'] ?? 0),
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function localStarted(int $attemptId): void
    {
        Database::connectionFresh()->prepare(
            'UPDATE system_execution_attempts
             SET state="local_started",reached_remote=0
             WHERE id=? AND state="reserved"'
        )->execute([$attemptId]);
    }

    public function budgetReserved(int $attemptId): void
    {
        Database::connectionFresh()->prepare(
            'UPDATE system_execution_attempts
             SET state="budget_reserved",budget_reserved=1,reached_remote=0
             WHERE id=? AND state IN ("reserved","local_started")'
        )->execute([$attemptId]);
    }

    /**
     * Persiste la frontera a partir de la cual una salida remota es posible.
     *
     * Debe ejecutarse inmediatamente antes de entregar el control al
     * transporte. Si PHP muere dentro de cURL, la recuperación será
     * conservadora y nunca repetirá automáticamente una operación cuyo
     * resultado remoto ya no puede conocerse.
     */
    public function dispatchStarted(int $attemptId, int $expectedGeneration = 0): bool
    {
        $stmt = Database::connectionFresh()->prepare(
            'UPDATE system_execution_attempts
             SET state="remote_dispatched",reached_remote=1,
                 dispatched_at=COALESCE(dispatched_at,UTC_TIMESTAMP(3))
             WHERE id=? AND (?=0 OR lease_generation=?)
               AND state IN ("budget_reserved","local_started","remote_dispatched")'
        );
        $stmt->execute([$attemptId, $expectedGeneration, $expectedGeneration]);
        if ($stmt->rowCount() === 1) {
            return true;
        }
        // Un segundo intento HTTP protegido puede compartir el mismo intento
        // lógico. La frontera ya persistida sigue siendo válida e idempotente.
        $verify = Database::connectionFresh()->prepare(
            'SELECT 1 FROM system_execution_attempts
             WHERE id=? AND (?=0 OR lease_generation=?) AND state="remote_dispatched" LIMIT 1'
        );
        $verify->execute([$attemptId, $expectedGeneration, $expectedGeneration]);
        return $verify->fetchColumn() !== false;
    }

    /**
     * Compensa exclusivamente un intento que todavía no llegó al transporte.
     * El CAS evita tocar un intento que ya recibió respuesta o fue aprobado.
     */
    public function dispatchCancelledBeforeRemote(int $attemptId, int $expectedGeneration): bool
    {
        $stmt = Database::connectionFresh()->prepare(
            'UPDATE system_execution_attempts
             SET state="local_started",budget_reserved=0,reached_remote=0,dispatched_at=NULL
             WHERE id=? AND lease_generation=?
               AND state IN ("budget_reserved","remote_dispatched") AND response_at IS NULL'
        );
        $stmt->execute([$attemptId, $expectedGeneration]);
        return $stmt->rowCount() === 1;
    }

    /** Compatibilidad para consumidores anteriores. */
    public function dispatched(int $attemptId): void
    {
        $this->dispatchStarted($attemptId);
    }

    public function response(
        int $attemptId,
        int $primaryCalls,
        int $derivedCalls,
        ?int $httpStatus = null,
        ?CampaignItemResult $result = null
    ): void
    {
        Database::connectionFresh()->prepare(
            'UPDATE system_execution_attempts
             SET state="response_received",reached_remote=?,primary_calls=?,derived_calls=?,
                 response_status=?,response_fingerprint=?,checkpoint_json=?,safe_message=?,
                 response_at=UTC_TIMESTAMP(3)
             WHERE id=? AND state IN ("reserved","local_started","budget_reserved","remote_dispatched","response_received")'
        )->execute([
            ($primaryCalls + $derivedCalls) > 0 ? 1 : 0,
            max(0, $primaryCalls),
            max(0, $derivedCalls),
            $httpStatus,
            $result === null ? null : hash('sha256', implode('|', [
                $result->status,
                (string) $result->processed,
                (string) $result->avoidedCalls,
                (string) $result->nextEligibleAt,
                Logger::redactString($result->message),
            ])),
            $result === null ? null : json_encode([
                'status' => $result->status,
                'processed' => $result->processed,
                'avoided_calls' => $result->avoidedCalls,
                'next_eligible_at' => $result->nextEligibleAt,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $result === null ? null : mb_substr(Logger::redactString($result->message), 0, 500),
            $attemptId,
        ]);
    }

    /**
     * Se conserva para consumidores externos. Las campañas usan
     * ManualCampaignService::complete() con contexto de aprobación para que
     * resultado y diario se confirmen en una sola transacción.
     */
    public function approve(int $runId, int $attemptId, int $campaignId, int $sequence, string $message): void
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE system_execution_attempts
                 SET state="approved",safe_message=?,result_applied_at=UTC_TIMESTAMP(3),
                     approved_at=UTC_TIMESTAMP(3),completed_at=UTC_TIMESTAMP(3),approval_sequence=?
                 WHERE id=? AND state="response_received"'
            )->execute([mb_substr(Logger::redactString($message), 0, 500), $sequence, $attemptId]);
            $pdo->prepare(
                'UPDATE manual_campaigns
                 SET last_approved_step=GREATEST(last_approved_step,?),uncertain_attempts=0,
                     version_no=version_no+1
                 WHERE id=?'
            )->execute([$sequence, $campaignId]);
            $pdo->prepare(
                'UPDATE system_execution_runs SET approved_attempts=approved_attempts+1,heartbeat_at=UTC_TIMESTAMP(3)
                 WHERE id=? AND status="running"'
            )->execute([$runId]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public function finish(int $runId, float $startedAt, string $reason = 'completed'): void
    {
        $duration = max(0, (int) round((microtime(true) - $startedAt) * 1000));
        Database::connectionFresh()->prepare(
            'UPDATE system_execution_runs
             SET status="completed",finished_at=UTC_TIMESTAMP(3),heartbeat_at=UTC_TIMESTAMP(3),
                 observed_runtime_ms=?,end_reason=?
             WHERE id=? AND status="running"'
        )->execute([$duration, mb_substr($reason, 0, 80), $runId]);
    }

    public function fail(int $runId, float $startedAt, string $message, ?string $diagnostic = null): void
    {
        $duration = max(0, (int) round((microtime(true) - $startedAt) * 1000));
        Database::connectionFresh()->prepare(
            'UPDATE system_execution_runs
             SET status="failed",finished_at=UTC_TIMESTAMP(3),heartbeat_at=UTC_TIMESTAMP(3),
                 observed_runtime_ms=?,end_reason="error",safe_message=?,diagnostic_id=?
             WHERE id=? AND status="running"'
        )->execute([$duration, mb_substr(Logger::redactString($message), 0, 500), $diagnostic, $runId]);
    }

    private function recoverInterrupted(string $component): void
    {
        $pdo = Database::connectionFresh();
        $pdo->beginTransaction();
        try {
            $runs = $pdo->prepare(
                'SELECT id,manual_campaign_id FROM system_execution_runs
                 WHERE component_key=? AND status="running"
                   AND heartbeat_at<DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 2 MINUTE)
                 FOR UPDATE'
            );
            $runs->execute([$component]);
            foreach ($runs->fetchAll(PDO::FETCH_ASSOC) as $run) {
                $pdo->prepare(
                    'UPDATE system_execution_attempts
                     SET state="failed",
                         safe_message="La ejecución terminó antes de salir hacia Mercado Libre."
                     WHERE system_execution_run_id=? AND state IN ("reserved","local_started","budget_reserved")'
                )->execute([(int) $run['id']]);
                $pdo->prepare(
                    'UPDATE system_execution_attempts
                     SET state="uncertain",
                         safe_message="El servidor interrumpió la ejecución antes de confirmar el resultado."
                     WHERE system_execution_run_id=? AND state="remote_dispatched"'
                )->execute([(int) $run['id']]);
                $pdo->prepare(
                    'UPDATE system_execution_attempts
                     SET state="uncertain",
                         safe_message="El servidor recibió una respuesta, pero no guardó un resultado local aprobado. Se comprobará de nuevo con protección de presupuesto."
                     WHERE system_execution_run_id=? AND state="response_received"'
                )->execute([(int) $run['id']]);
                $uncertain = $pdo->prepare(
                    'SELECT COUNT(*) FROM system_execution_attempts
                     WHERE system_execution_run_id=? AND state="uncertain"'
                );
                $uncertain->execute([(int) $run['id']]);
                $count = (int) $uncertain->fetchColumn();
                $pdo->prepare(
                    'UPDATE manual_campaign_items i
                     JOIN system_execution_attempts a ON a.manual_campaign_item_id=i.id
                     SET i.status="retry",i.lease_owner=NULL,i.lease_expires_at=NULL,
                         i.next_eligible_at=IF(
                           a.state="uncertain",
                           DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 5 MINUTE),
                           UTC_TIMESTAMP(3)
                         ),
                         i.result_summary=IF(
                           a.state="uncertain",
                           "El resultado anterior no pudo confirmarse. Se comprobará de nuevo antes de continuar.",
                           "La ejecución terminó antes de consultar. El recurso vuelve a estar disponible."
                         )
                     WHERE a.system_execution_run_id=? AND i.status="running"'
                )->execute([(int) $run['id']]);
                $pdo->prepare(
                    'UPDATE system_execution_runs
                     SET status="interrupted",finished_at=UTC_TIMESTAMP(3),uncertain_attempts=?,
                         end_reason="heartbeat_expired",
                         safe_message="La ejecución anterior fue interrumpida; se conservaron los resultados aprobados."
                     WHERE id=?'
                )->execute([$count, (int) $run['id']]);
                if (!empty($run['manual_campaign_id'])) {
                    $pdo->prepare(
                        'UPDATE manual_campaigns
                         SET interrupted_runs=interrupted_runs+1,uncertain_attempts=uncertain_attempts+?,
                              observed_safe_window_ms=GREATEST(5000,COALESCE(observed_safe_window_ms,20000)-5000),
                              last_engine_state=IF(status="active","recovered_interruption",last_engine_state),
                              safe_message=IF(
                                  status="active",
                                  "La ejecución anterior fue interrumpida. Los resultados inciertos se aislarán y la campaña continuará con los demás recursos.",
                                  safe_message
                              ),
                              next_action_at=IF(status="active",UTC_TIMESTAMP(3),next_action_at),
                              current_item_id=NULL,current_operation_id=NULL,version_no=version_no+1
                         WHERE id=?'
                    )->execute([$count, (int) $run['manual_campaign_id']]);
                }
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }
}
