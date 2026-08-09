<?php
declare(strict_types=1);

namespace App\QueueCore;

use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class WebhookTriggerService
{
    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    /**
     * @param array<string,mixed> $validation
     * @return array{accepted:bool,terminal:bool,reason:string,trigger_id:?int,company_id:?int,account_id:?int,resource_type:?string,resource_id:?string,watermark:?int}
     */
    public function observe(array $validation): array
    {
        $userId = (int) ($validation['user_id'] ?? 0);
        $classification = is_array($validation['classification'] ?? null)
            ? $validation['classification']
            : [];
        $type = (string) ($classification['resource_type'] ?? '');
        $resourceId = trim((string) ($classification['resource_id'] ?? ''));
        if ($userId < 1 || !in_array($type, ['order', 'pack', 'shipment'], true)
            || $resourceId === '' || !ctype_digit($resourceId)) {
            return $this->rejected('unsupported_webhook_resource');
        }

        $pdo = $this->connection();
        $account = $pdo->prepare(
            "SELECT a.id,a.company_id
             FROM meli_accounts a
             INNER JOIN companies c ON c.id=a.company_id AND c.status=1
             WHERE a.meli_user_id=? AND a.status IN ('conectado','connected')
             ORDER BY a.company_id,a.id LIMIT 2"
        );
        $account->execute([$userId]);
        $rows = $account->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            return $this->rejected('unknown_or_ambiguous_account');
        }
        $accountId = (int) ($rows[0]['id'] ?? 0);
        $companyId = (int) ($rows[0]['company_id'] ?? 0);
        if ($accountId < 1 || $companyId < 1) {
            return $this->rejected('unknown_or_ambiguous_account');
        }

        $insert = $pdo->prepare(
            "INSERT INTO queue_core_webhook_triggers
             (company_id,meli_account_id,resource_type,resource_id,state,desired_watermark,
              scheduled_watermark,completed_watermark,occurrence_count,last_observed_at)
             VALUES (?,?,?,?,'pending',1,0,0,1,UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE
               desired_watermark=desired_watermark+1,
               occurrence_count=occurrence_count+1,
               state=IF(state='inflight','inflight','pending'),
               last_error_class=NULL,last_observed_at=UTC_TIMESTAMP(3),id=LAST_INSERT_ID(id)"
        );
        $insert->execute([$companyId, $accountId, $type, $resourceId]);
        $id = (int) $pdo->lastInsertId();
        if ($id < 1) {
            $lookup = $pdo->prepare(
                'SELECT id FROM queue_core_webhook_triggers
                 WHERE company_id=? AND meli_account_id=? AND resource_type=? AND resource_id=?'
            );
            $lookup->execute([$companyId, $accountId, $type, $resourceId]);
            $id = (int) $lookup->fetchColumn();
        }
        $watermark = $this->trigger($id, $companyId, $accountId)['desired_watermark'] ?? null;
        return [
            'accepted' => $id > 0,
            'terminal' => $id < 1,
            'reason' => $id > 0 ? 'observed' : 'trigger_persistence_failed',
            'trigger_id' => $id > 0 ? $id : null,
            'company_id' => $companyId,
            'account_id' => $accountId,
            'resource_type' => $type,
            'resource_id' => $resourceId,
            'watermark' => is_numeric($watermark) ? (int) $watermark : null,
        ];
    }

    /**
     * Atomically binds one durable spool observation to the trigger generation
     * it advanced. A crash can expose neither a trigger-only observation nor a
     * materialized lifecycle row without its trigger.
     *
     * @param array<string,mixed> $validation
     * @return array<string,mixed>
     */
    public function observeSpool(array $validation, string $spoolKey): array
    {
        $pdo = $this->connection();
        if ($pdo->inTransaction()) {
            throw new RuntimeException('Webhook spool observation requires its own transaction.');
        }
        $pdo->beginTransaction();
        try {
            $result = $this->observe($validation);
            if (!empty($result['accepted'])) {
                $this->spoolLifecycle()->markMaterialized(
                    $spoolKey,
                    (int) $result['company_id'],
                    (int) $result['account_id'],
                    (int) $result['trigger_id'],
                    (int) $result['watermark'],
                );
            }
            $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            self::rollbackIfActive($pdo);
            throw $error;
        }
    }

    /** @return array<string,mixed>|null */
    public function trigger(int $id, int $companyId, int $accountId): ?array
    {
        $stmt = $this->connection()->prepare(
            'SELECT * FROM queue_core_webhook_triggers
             WHERE id=? AND company_id=? AND meli_account_id=? LIMIT 1'
        );
        $stmt->execute([$id, $companyId, $accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function complete(
        int $triggerId,
        int $companyId,
        int $accountId,
        int $jobId,
        int $scheduledWatermark
    ): bool {
        $pdo = $this->connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare(
                "UPDATE queue_core_webhook_triggers
                 SET completed_watermark=GREATEST(completed_watermark,?),
                     state=IF(desired_watermark>?, 'pending', 'idle'),
                     inflight_job_id=NULL,last_error_class=NULL,completed_at=UTC_TIMESTAMP(3)
                 WHERE id=? AND company_id=? AND meli_account_id=?
                   AND state='inflight' AND inflight_job_id=? AND scheduled_watermark=?"
            );
            $stmt->execute([
                $scheduledWatermark, $scheduledWatermark, $triggerId, $companyId,
                $accountId, $jobId, $scheduledWatermark,
            ]);
            if ($stmt->rowCount() !== 1) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return false;
            }
            $this->spoolLifecycle()->resolveForTrigger(
                $triggerId,
                $companyId,
                $accountId,
                $scheduledWatermark,
            );
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return true;
        } catch (Throwable $error) {
            if ($ownsTransaction) {
                self::rollbackIfActive($pdo);
            }
            throw $error;
        }
    }

    public function spoolLifecycle(): WebhookSpoolLifecycleService
    {
        return new WebhookSpoolLifecycleService($this->connection());
    }

    private function connection(): PDO
    {
        return $this->pdo ?? Database::connectionFresh();
    }

    private static function rollbackIfActive(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    /** @return array{accepted:false,terminal:true,reason:string,trigger_id:null,company_id:null,account_id:null,resource_type:null,resource_id:null,watermark:null} */
    private function rejected(string $reason): array
    {
        return [
            'accepted' => false, 'terminal' => true, 'reason' => $reason,
            'trigger_id' => null, 'company_id' => null, 'account_id' => null,
            'resource_type' => null, 'resource_id' => null, 'watermark' => null,
        ];
    }
}
