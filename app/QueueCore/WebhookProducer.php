<?php
declare(strict_types=1);

namespace App\QueueCore;

use App\Services\WebhookSpoolService;
use PDO;
use RuntimeException;
use Throwable;

final class WebhookProducer
{
    private readonly WebhookSpoolService $spool;

    public function __construct(
        private readonly PDO $pdo,
        private readonly QueueCoreRepository $repository,
        private readonly WebhookTriggerService $triggers,
        ?WebhookSpoolService $spool = null,
    ) {
        $this->spool = $spool ?? new WebhookSpoolService();
    }

    /** @return array<string,int|bool|string> */
    public function schedule(
        int $limit = 100,
        ?float $deadline = null,
        bool $consumeSpool = true
    ): array
    {
        $limit = max(1, min(500, $limit));
        try {
            $engine = (new QueueEngineControlService($this->pdo))->snapshot();
        } catch (Throwable) {
            return [
                'spooled' => 0, 'quarantined' => 0, 'spool_errors' => 0,
                'reconciled' => 0, 'enqueued' => 0, 'duplicates' => 0,
                'skipped' => true, 'stop_reason' => 'engine_authority_unavailable',
            ];
        }
        if ($engine['active_engine'] !== 'v4') {
            return [
                'spooled' => 0, 'quarantined' => 0, 'spool_errors' => 0,
                'reconciled' => 0, 'enqueued' => 0, 'duplicates' => 0,
                'skipped' => true, 'stop_reason' => 'engine_not_v4',
            ];
        }
        $spool = $consumeSpool
            ? $this->spool->replayToQueueCore($this->triggers, $limit, $deadline)
            : ['processed' => 0, 'quarantined' => 0, 'errors' => 0];
        $reconciled = $this->reconcileInflight($limit);
        $enqueued = 0;
        $duplicates = 0;
        $query = $this->pdo->query(
            "SELECT id,company_id,meli_account_id
             FROM queue_core_webhook_triggers
             WHERE state='pending'
             ORDER BY last_observed_at ASC,id ASC
             LIMIT {$limit}"
        );
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            $this->pdo->beginTransaction();
            try {
                $lock = $this->pdo->prepare(
                    "SELECT * FROM queue_core_webhook_triggers
                     WHERE id=? AND company_id=? AND meli_account_id=? AND state='pending' FOR UPDATE"
                );
                $lock->execute([
                    (int) $candidate['id'], (int) $candidate['company_id'],
                    (int) $candidate['meli_account_id'],
                ]);
                $trigger = $lock->fetch(PDO::FETCH_ASSOC);
                if (!is_array($trigger)) {
                    $this->pdo->commit();
                    continue;
                }
                $watermark = (int) $trigger['desired_watermark'];
                $type = (string) $trigger['resource_type'];
                $resourceId = (string) $trigger['resource_id'];
                $job = new QueueJob(
                    (int) $trigger['company_id'], (int) $trigger['meli_account_id'],
                    $type . '_exact', $type, $resourceId, 'recovery', 50,
                    'webhook:' . $type . ':' . $resourceId,
                    'watermark:' . $watermark,
                    'webhook_v4', 'webhook-trigger:' . (int) $trigger['id'],
                    [
                        'trigger_id' => (int) $trigger['id'],
                        'resource_id' => $resourceId,
                        'scheduled_watermark' => $watermark,
                    ],
                    ['producer' => 'queue_core_webhook', 'occurrence_count' => (int) $trigger['occurrence_count']],
                    5,
                    null,
                    'operational'
                );
                $jobId = $type === 'order'
                    ? $this->repository->enqueueCoalescedExact($job, ['order_exact','webhook_order_exact'])
                    : $this->repository->enqueueCoalescedExact(
                        $job,
                        [$type . '_exact','webhook_' . $type . '_exact']
                    );
                $created = $this->repository->lastEnqueueCreated();
                $update = $this->pdo->prepare(
                    "UPDATE queue_core_webhook_triggers
                     SET state='inflight',scheduled_watermark=?,inflight_job_id=?,scheduled_at=UTC_TIMESTAMP(3)
                     WHERE id=? AND company_id=? AND meli_account_id=? AND state='pending'
                       AND desired_watermark=?"
                );
                $update->execute([
                    $watermark, $jobId, (int) $trigger['id'], (int) $trigger['company_id'],
                    (int) $trigger['meli_account_id'], $watermark,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Webhook trigger changed while scheduling.');
                }
                $this->pdo->commit();
                $created ? $enqueued++ : $duplicates++;
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $error;
            }
        }
        return [
            'spooled' => (int) $spool['processed'],
            'quarantined' => (int) $spool['quarantined'],
            'spool_errors' => (int) $spool['errors'],
            'reconciled' => $reconciled,
            'enqueued' => $enqueued,
            'duplicates' => $duplicates,
        ];
    }

    private function reconcileInflight(int $limit): int
    {
        $stmt = $this->pdo->query(
            "SELECT t.id,t.company_id,t.meli_account_id,t.inflight_job_id,t.scheduled_watermark,j.state
             FROM queue_core_webhook_triggers t
             INNER JOIN queue_core_jobs j ON j.id=t.inflight_job_id
               AND j.company_id=t.company_id AND j.meli_account_id=t.meli_account_id
             WHERE t.state='inflight' AND j.state IN ('completed','review','dead')
             ORDER BY t.id LIMIT {$limit}"
        );
        $count = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((string) $row['state'] === 'completed') {
                if ($this->triggers->complete(
                    (int) $row['id'], (int) $row['company_id'], (int) $row['meli_account_id'],
                    (int) $row['inflight_job_id'], (int) $row['scheduled_watermark']
                )) {
                    $count++;
                }
                continue;
            }
            $update = $this->pdo->prepare(
                "UPDATE queue_core_webhook_triggers
                 SET state=IF(desired_watermark>scheduled_watermark,'pending','quarantined'),
                     last_error_class=?,inflight_job_id=NULL
                 WHERE id=? AND company_id=? AND meli_account_id=?
                   AND state='inflight' AND inflight_job_id=?"
            );
            $update->execute([
                'queue_job_' . (string) $row['state'], (int) $row['id'],
                (int) $row['company_id'], (int) $row['meli_account_id'],
                (int) $row['inflight_job_id'],
            ]);
            $count += $update->rowCount();
        }
        return $count;
    }
}
