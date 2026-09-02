<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class ManualCampaignReservationTtlService
{
    public function seconds(): int
    {
        $observed = $this->observedIntervalSeconds();
        if ($observed < 1) {
            $observed = max(60, (new AppSettingsService())->int('cron.main_interval_minutes', 1) * 60);
        }
        $multiplier = max(
            1,
            min(10, (new AppSettingsService())->int('manual_campaign.reservation_interval_multiplier', 3))
        );

        return max(600, min(7200, ($observed * $multiplier) + 120));
    }

    public function observedIntervalSeconds(): int
    {
        try {
            if (!(new SchemaInspectorService())->hasTable('cron_health_checks')) {
                return 0;
            }
            $stmt = Database::connectionFresh()->query(
                'SELECT started_at
                 FROM cron_health_checks
                 WHERE job_name="queue_v4_clean"
                   AND execution_source="scheduled_cli"
                 ORDER BY started_at DESC,id DESC LIMIT 8'
            );
            $timestamps = [];
            $clock = new SystemDatabaseUtcClock();
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
                $timestamp = $clock->timestamp((string) $value);
                if ($timestamp !== null) {
                    $timestamps[] = $timestamp;
                }
            }
            $intervals = [];
            for ($index = 1, $count = count($timestamps); $index < $count; $index++) {
                $gap = $timestamps[$index - 1] - $timestamps[$index];
                if ($gap >= 10 && $gap <= 86400) {
                    $intervals[] = $gap;
                }
            }
            if ($intervals === []) {
                return 0;
            }
            sort($intervals, SORT_NUMERIC);
            $middle = intdiv(count($intervals), 2);
            return count($intervals) % 2 === 1
                ? (int) $intervals[$middle]
                : (int) round(($intervals[$middle - 1] + $intervals[$middle]) / 2);
        } catch (Throwable) {
            return 0;
        }
    }
}
