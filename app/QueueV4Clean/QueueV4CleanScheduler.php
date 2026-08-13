<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use App\Core\Env;
use App\Services\EmergencyControlService;
use PDO;

final class QueueV4CleanScheduler
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function run(int $maxJobs = QueueV4CleanWorker::DEFAULT_MAX_JOBS, int $runtimeSeconds = 45): array
    {
        QueueV4CleanOAuthStageContext::reset();
        $repository = new QueueV4CleanRepository($this->pdo);
        $control = $repository->control();
        if ((string) $control['engine_state'] !== 'ACTIVE'
            || (int) $control['scheduler_enabled'] !== 1
            || (new EmergencyControlService())->automationStopped()
            || Env::bool('ML_WRITE_ENABLED', false)) {
            return ['ok' => true, 'status' => 'stopped', 'processed' => 0];
        }
        $owner = bin2hex(random_bytes(16));
        $lease = $this->pdo->prepare(
            "UPDATE queue_v4_clean_leases
             SET owner_ref=?,acquired_at=UTC_TIMESTAMP(3),heartbeat_at=UTC_TIMESTAMP(3),
                 expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 60 SECOND)
             WHERE lease_key='scheduler' AND (owner_ref IS NULL OR expires_at<UTC_TIMESTAMP(3))"
        );
        $lease->execute([$owner]);
        if ($lease->rowCount() !== 1) {
            return ['ok' => true, 'status' => 'busy', 'processed' => 0];
        }
        try {
            $oauth = (new QueueV4CleanOAuthSupervisor(
                $this->pdo,
                new QueueV4CleanOAuthOperationRepository($this->pdo),
            ))->run($owner);
            if (($oauth['abort_scheduler'] ?? false) === true) {
                return [
                    'ok' => false,
                    'status' => (string) ($oauth['status'] ?? 'oauth_control_plane_blocked'),
                    'oauth' => $oauth,
                    'producer' => ['skipped' => true],
                    'worker' => ['skipped' => true],
                ];
            }
            $producer = (new QueueV4CleanProducer($this->pdo, $repository))->produce();
            $worker = (new QueueV4CleanWorker($this->pdo, $repository))->run('scheduler', $maxJobs, $runtimeSeconds);
            $this->pdo->exec(
                "UPDATE queue_v4_clean_control SET last_scheduler_at=UTC_TIMESTAMP(3) WHERE control_key='primary'"
            );
            return ['ok' => true, 'status' => 'completed', 'oauth' => $oauth, 'producer' => $producer, 'worker' => $worker];
        } finally {
            $release = $this->pdo->prepare(
                "UPDATE queue_v4_clean_leases
                 SET owner_ref=NULL,acquired_at=NULL,heartbeat_at=NULL,expires_at=NULL
                 WHERE lease_key='scheduler' AND owner_ref=?"
            );
            $release->execute([$owner]);
        }
    }
}
