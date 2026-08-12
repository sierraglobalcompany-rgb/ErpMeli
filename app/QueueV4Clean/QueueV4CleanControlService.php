<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use App\Core\Env;
use App\Services\EmergencyControlService;
use PDO;
use RuntimeException;

final class QueueV4CleanControlService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function activate(int $actorId): array
    {
        if ($actorId < 1 || Env::bool('ML_WRITE_ENABLED', false)) {
            throw new RuntimeException('queue_v4_clean_activation_safety_invalid');
        }
        if (!(new EmergencyControlService())->automationStopped()) {
            throw new RuntimeException('queue_v4_clean_activation_requires_automation_stop');
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
        return [
            'ok' => true,
            'state' => 'ACTIVE',
            'scheduler_created' => false,
            'scheduler_enabled' => true,
            'automation_still_stopped' => true,
        ];
    }

    /** @return array<string,mixed> */
    public function stop(int $actorId): array
    {
        if ($actorId < 1) {
            throw new RuntimeException('queue_v4_clean_actor_invalid');
        }
        $statement = $this->pdo->prepare(
            "UPDATE queue_v4_clean_control
             SET engine_state='STOPPED',scheduler_enabled=0,stopped_at=UTC_TIMESTAMP(3),updated_by=?
             WHERE control_key='primary'"
        );
        $statement->execute([$actorId]);
        return ['ok' => true, 'state' => 'STOPPED', 'scheduler_enabled' => false];
    }
}
