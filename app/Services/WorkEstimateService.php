<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class WorkEstimateService
{
    /** @param array<string,mixed> $item @return array{seconds:?int,label:string} */
    public function estimate(array $item): array
    {
        $fallback = isset($item['estimated_seconds']) ? max(1, (int) $item['estimated_seconds']) : null;
        $historical = $this->historicalSeconds((string) ($item['queue_key'] ?? ''));
        $seconds = $historical ?? $fallback;
        if ($seconds === null) {
            return ['seconds' => null, 'label' => 'Todavía no se puede estimar'];
        }
        $seconds = max(1, $seconds);
        return [
            'seconds' => $seconds,
            'label' => $seconds < 60
                ? 'Aproximadamente ' . $seconds . ' s'
                : 'Aproximadamente ' . (int) ceil($seconds / 60) . ' min',
        ];
    }

    private function historicalSeconds(string $queueKey): ?int
    {
        try {
            if (!(new SchemaInspectorService())->hasTable('system_work_queue_run_items')) {
                return null;
            }
            $stmt = Database::connectionFresh()->prepare(
                'SELECT AVG(duration_ms)/1000 avg_seconds,COUNT(*) samples
                 FROM system_work_queue_run_items
                 WHERE queue_key=? AND result="completed" AND duration_ms IS NOT NULL'
            );
            $stmt->execute([$queueKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $minimum = max(1, (new AppSettingsService())->int('automation.eta_min_samples', 3));
            return (int) ($row['samples'] ?? 0) >= $minimum ? max(1, (int) round((float) $row['avg_seconds'])) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
