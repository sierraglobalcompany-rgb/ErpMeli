<?php

declare(strict_types=1);

namespace App\QueueCore;

use PDO;

final class HistoricalAdmissionPolicy
{
    public const ABSOLUTE_HARD_CAP = 50;

    public function __construct(private readonly int $requestedCap = 10)
    {
    }

    public function effectiveCap(): int
    {
        return max(1, min(self::ABSOLUTE_HARD_CAP, $this->requestedCap));
    }

    public function outstanding(PDO $pdo): int
    {
        return max(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM queue_core_jobs
             WHERE queue_domain='operational' AND lane='historical_backfill'
               AND state IN ('pending','claimed','running','retry_wait','waiting_oauth')"
        )->fetchColumn());
    }

    public function available(PDO $pdo, int $requested): int
    {
        return max(0, min(
            max(1, min(HistoricalIngestionService::MAX_PER_CYCLE, $requested)),
            $this->effectiveCap() - $this->outstanding($pdo),
        ));
    }
}
