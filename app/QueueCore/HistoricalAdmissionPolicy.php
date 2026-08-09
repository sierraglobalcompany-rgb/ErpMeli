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
        if ($this->blockReason($pdo) !== null) {
            return 0;
        }
        return max(0, min(
            max(1, min(HistoricalIngestionService::MAX_PER_CYCLE, $requested)),
            $this->effectiveCap() - $this->outstanding($pdo),
        ));
    }

    public function blockReason(PDO $pdo): ?string
    {
        $engine = (string) ($pdo->query(
            "SELECT active_engine FROM queue_engine_control WHERE control_key='primary' LIMIT 1"
        )->fetchColumn() ?: 'disabled');
        if ($engine !== 'v4') {
            return 'engine_not_v4';
        }
        $operational = (int) $pdo->query(
            "SELECT COUNT(*) FROM queue_core_jobs
             WHERE queue_domain='operational' AND lane<>'historical_backfill'
               AND (state IN ('claimed','running','waiting_oauth')
                 OR (state='pending' AND available_at<=UTC_TIMESTAMP(3))
                 OR (state='retry_wait' AND next_attempt_at<=UTC_TIMESTAMP(3)))"
        )->fetchColumn();
        if ($operational > 0) {
            return 'operational_freshness_work_pending';
        }
        $unsafe = (int) $pdo->query(
            "SELECT COUNT(*) FROM queue_core_jobs
             WHERE queue_domain='operational'
               AND (state IN ('review','dead')
                 OR dispatch_state='DISPATCHED_RESULT_UNCERTAIN')"
        )->fetchColumn();
        if ($unsafe > 0) {
            return 'queue_health_not_green';
        }
        $unfresh = (int) $pdo->query(
            "SELECT COUNT(*) FROM meli_accounts a
             LEFT JOIN queue_core_producer_checkpoints cp
               ON cp.producer_key='fresh_orders' AND cp.company_id=a.company_id
              AND cp.meli_account_id=a.id
             WHERE a.status IN ('conectado','connected')
               AND (cp.watermark_at IS NULL OR cp.last_error_class IS NOT NULL
                 OR cp.watermark_at<DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 5 MINUTE))"
        )->fetchColumn();
        return $unfresh > 0 ? 'freshness_not_green' : null;
    }
}
