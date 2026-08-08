<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

final class CronV3MaintenanceProducer
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{ok:bool,created:int,skipped:int,reason:string} */
    public function enqueueDue(): array
    {
        return $this->produce();
    }

    /** @return array{ok:bool,created:int,skipped:int,reason:string} */
    public function produce(): array
    {
        $anchor = $this->anchorAccount();
        if ($anchor === null) {
            return ['ok' => true, 'created' => 0, 'skipped' => 2, 'reason' => 'no_account_anchor'];
        }

        $created = 0;
        $skipped = 0;
        foreach ([
            ['type' => 'notification_spool', 'bucket' => gmdate('YmdHi'), 'priority' => 30, 'payload' => ['limit' => 50, 'runtime_seconds' => 3]],
            ['type' => 'notification_normalize', 'bucket' => gmdate('YmdHi'), 'priority' => 35, 'payload' => ['limit' => 50]],
            ['type' => 'notification_backfill', 'bucket' => gmdate('YmdHi'), 'priority' => 200, 'payload' => ['limit' => 200]],
            ['type' => 'recurring_schedule', 'bucket' => gmdate('YmdHi'), 'priority' => 220, 'payload' => ['limit' => 20]],
            ['type' => 'operational_maintenance', 'bucket' => gmdate('YmdHi', (int) (floor(time() / 300) * 300)), 'priority' => 65000],
            ['type' => 'monthly_report_maintenance', 'bucket' => gmdate('Ymd'), 'priority' => 65010],
        ] as $job) {
            $type = (string) $job['type'];
            if (!$this->ownedByV3($type, 'local') || $this->hasOpenWork($type)) {
                $skipped++;
                continue;
            }
            try {
                $result = CronV3::enqueue(WorkEnvelope::create(
                    (int) $anchor['company_id'],
                    (int) $anchor['meli_account_id'],
                    $type,
                    'local',
                    $type . ':' . (string) $job['bucket'],
                    $type . ':' . (string) $job['bucket'],
                    array_merge([
                        'kind' => $type,
                        'bucket' => (string) $job['bucket'],
                        'technical_only' => true,
                    ], (array) ($job['payload'] ?? [])),
                    'cron_v3:maintenance:' . $type,
                    (int) $job['priority']
                ));
                $created += !empty($result['created']) ? 1 : 0;
            } catch (Throwable) {
                $skipped++;
            }
        }

        return ['ok' => true, 'created' => $created, 'skipped' => $skipped, 'reason' => 'complete'];
    }

    /** @return array{company_id:int,meli_account_id:int}|null */
    private function anchorAccount(): ?array
    {
        try {
            $row = $this->pdo->query(
                'SELECT a.company_id,a.id meli_account_id
                 FROM meli_accounts a
                 ORDER BY a.company_id,a.id LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC);
            return is_array($row)
                ? ['company_id' => (int) $row['company_id'], 'meli_account_id' => (int) $row['meli_account_id']]
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function ownedByV3(string $type, string $lane): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM cron_v3_queue_ownership
             WHERE queue_key=? AND lane=? AND owner_engine="v3" AND enabled=1 LIMIT 1'
        );
        $stmt->execute([$type, $lane]);
        return $stmt->fetchColumn() !== false;
    }

    private function hasOpenWork(string $type): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM cron_v3_work
             WHERE work_type=? AND status IN ("ready","leased","deferred")
               AND available_at<=DATE_ADD(UTC_TIMESTAMP(3), INTERVAL 5 MINUTE)
             LIMIT 1'
        );
        $stmt->execute([$type]);
        return $stmt->fetchColumn() !== false;
    }
}
