<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use App\Core\Env;
use App\Services\EmergencyControlService;
use PDO;
use RuntimeException;
use Throwable;

final class QueueV4CleanControlService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function activate(int $actorId, int $runId, string $token): array
    {
        if ($actorId < 1 || Env::bool('ML_WRITE_ENABLED', false)) {
            throw new RuntimeException('queue_v4_clean_activation_safety_invalid');
        }
        if (!(new EmergencyControlService())->automationStopped()) {
            throw new RuntimeException('queue_v4_clean_activation_requires_automation_stop');
        }
        $readiness = new QueueV4CleanReadinessService($this->pdo);
        $readiness->assertActivationContext($actorId, $runId, $token);
        $issues = $readiness->activationIssues();
        if ($issues !== []) {
            throw new RuntimeException('queue_v4_clean_activation_preconditions_invalid:' . implode(',', $issues));
        }
        $this->pdo->beginTransaction();
        try {
            $control = $this->pdo->query(
                "SELECT engine_state,readiness_state,readiness_passed_accounts,scheduler_enabled
                 FROM queue_v4_clean_control WHERE control_key='primary' FOR UPDATE"
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($control)
                || !in_array((string) $control['engine_state'], ['CERTIFIED', 'STOPPED'], true)
                || (string) $control['readiness_state'] !== 'CERTIFIED'
                || (int) $control['readiness_passed_accounts'] !== 3
                || (int) $control['scheduler_enabled'] !== 0
            ) {
                throw new RuntimeException('queue_v4_clean_not_certified');
            }
            // No historical MAX(CERTIFIED) fallback: this exact fresh session must
            // still own the globally newest run under the primary control lock.
            $accounts = $readiness->assertActivationContext($actorId, $runId, $token)['accounts'];
            if (count($accounts) !== 3) {
                throw new RuntimeException('queue_v4_clean_activation_account_set_invalid');
            }
            foreach ($accounts as $account) {
                $this->initializePendingAuthority((int) $account['company_id'], (int) $account['meli_account_id']);
            }
            $statement = $this->pdo->prepare(
                "UPDATE queue_v4_clean_control
                 SET engine_state='ACTIVE',scheduler_enabled=1,activated_at=UTC_TIMESTAMP(3),updated_by=?
                 WHERE control_key='primary' AND engine_state IN ('CERTIFIED','STOPPED')
                   AND readiness_state='CERTIFIED' AND readiness_passed_accounts=3
                   AND scheduler_enabled=0"
            );
            $statement->execute([$actorId]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('queue_v4_clean_not_certified');
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return [
            'ok' => true,
            'state' => 'ACTIVE',
            'scheduler_created' => false,
            'scheduler_enabled' => true,
            'automation_still_stopped' => true,
        ];
    }

    private function initializePendingAuthority(int $companyId, int $accountId): void
    {
        $select = $this->pdo->prepare(
            'SELECT producer_key,last_job_id FROM queue_v4_clean_checkpoints
             WHERE producer_key IN ("inventory_pending_floor","inventory_pending_cursor")
               AND company_id=? AND meli_account_id=? ORDER BY producer_key FOR UPDATE'
        );
        $select->execute([$companyId, $accountId]);
        $rows = $select->fetchAll(PDO::FETCH_KEY_PAIR);
        if (isset($rows['inventory_pending_cursor']) !== isset($rows['inventory_pending_floor'])) {
            throw new RuntimeException('queue_v4_clean_inventory_pending_authority_incomplete');
        }
        if (isset($rows['inventory_pending_floor'], $rows['inventory_pending_cursor'])) {
            return;
        }
        $maximum = $this->pdo->prepare(
            'SELECT COALESCE(MAX(o.id),0) FROM meli_orders o
             JOIN meli_accounts a ON a.id=o.meli_account_id AND a.company_id=?
             WHERE o.meli_account_id=?'
        );
        $maximum->execute([$companyId, $accountId]);
        $floor = (int) $maximum->fetchColumn();
        $insert = $this->pdo->prepare(
            'INSERT INTO queue_v4_clean_checkpoints
             (producer_key,company_id,meli_account_id,last_job_id,next_due_at)
             VALUES (?,?,?,?,UTC_TIMESTAMP(3))'
        );
        $insert->execute(['inventory_pending_floor', $companyId, $accountId, $floor]);
        $insert->execute(['inventory_pending_cursor', $companyId, $accountId, $floor]);
    }

    /** @return array<string,mixed> */
    public function stop(int $actorId): array
    {
        if ($actorId < 1) {
            throw new RuntimeException('queue_v4_clean_actor_invalid');
        }
        $this->pdo->beginTransaction();
        try {
            (new QueueV4CleanRepository($this->pdo))->control(true);
            // Stop never waits for the HTTP GET_LOCK. The control row fences
            // finalization; any already transmitted response remains obsolete.
            $runId = (int)$this->pdo->query('SELECT COALESCE(MAX(id),0) FROM queue_v4_clean_readiness_runs')->fetchColumn();
            $this->pdo->prepare("UPDATE queue_v4_clean_readiness_runs SET state='FAILED',failure_class='stopped',finished_at=UTC_TIMESTAMP(3) WHERE id=? AND state='TESTING'")->execute([$runId]);
            $this->pdo->prepare(
                "UPDATE queue_v4_clean_control SET engine_state='STOPPED',scheduler_enabled=0,
                 readiness_state='FAILED',readiness_passed_accounts=0,readiness_error_class='stopped',
                 certified_at=NULL,stopped_at=UTC_TIMESTAMP(3),updated_by=? WHERE control_key='primary'"
            )->execute([$actorId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
        return ['ok' => true, 'state' => 'STOPPED', 'scheduler_enabled' => false];
    }
}
