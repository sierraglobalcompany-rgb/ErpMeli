<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Throwable;

final class OperationalMaintenanceService
{
    /**
     * @return array<string,mixed>
     */
    public function run(int $limit = 500): array
    {
        $result = [
            'processed' => 0,
            'empty_batches' => 0,
            'unknown_topics' => 0,
            'ignored_topics' => 0,
            'compacted_events' => 0,
            'workload_samples_removed' => 0,
            'legacy_notifications_normalized' => 0,
            'legacy_notifications_skipped' => true,
            'maintenance_steps_compacted' => 0,
            'maintenance_steps_compaction_skipped' => true,
            'storage_retention' => [],
            'warnings' => 0,
            'errors' => 0,
            'safe_message' => null,
        ];
        try {
            $result['empty_batches'] = Database::connection()->exec(
                'UPDATE sync_batches b
                 SET b.status="empty",b.updated_at=UTC_TIMESTAMP()
                 WHERE b.status IN ("draft","queued","running","partial")
                   AND NOT EXISTS (
                     SELECT 1 FROM sync_batch_chunks ch WHERE ch.sync_batch_id=b.id
                   )'
            );
        } catch (Throwable) {
            $result['warnings']++;
        }

        try {
            $ids = Database::connection()->query(
                'SELECT e.id
                 FROM meli_notification_events e
                 LEFT JOIN notification_rules r ON r.topic=e.topic
                 WHERE e.status IN ("received","queued")
                   AND r.id IS NULL
                 ORDER BY e.id ASC
                 LIMIT ' . max(1, min(5000, $limit))
            )->fetchAll(\PDO::FETCH_COLUMN);
            if ($ids !== []) {
                $result['unknown_topics'] = Database::connection()->exec(
                    'UPDATE meli_notification_events
                     SET status="unknown_topic",processed_at=UTC_TIMESTAMP(),
                         error_message="Tópico no reconocido; conservado para diagnóstico."
                     WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')'
                );
            }
        } catch (Throwable) {
            $result['warnings']++;
        }

        try {
            $ids = Database::connection()->query(
                'SELECT e.id
                 FROM meli_notification_events e
                 JOIN notification_rules r ON r.topic=e.topic AND r.enabled=0
                 WHERE e.status IN ("received","queued")
                 ORDER BY e.id ASC
                 LIMIT ' . max(1, min(5000, $limit))
            )->fetchAll(\PDO::FETCH_COLUMN);
            if ($ids !== []) {
                $result['ignored_topics'] = Database::connection()->exec(
                    'UPDATE meli_notification_events
                     SET status="ignored",processed_at=UTC_TIMESTAMP(),
                         error_message="Tópico conocido desactivado por configuración."
                     WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')'
                );
            }
        } catch (Throwable) {
            $result['warnings']++;
        }

        $result['workload_samples_removed'] = 0;
        try {
            $result['incident_materialization'] = (new ApiIncidentMaterializerService())->refreshStep(
                max(1, min(500, $limit))
            );
        } catch (Throwable) {
            $result['incident_materialization'] = ['processed' => 0, 'complete' => false];
            $result['warnings']++;
        }
        try {
            $result['storage_retention'] = (new TechnicalRetentionCliService())->runStep(
                max(1, min(500, $limit))
            );
            if ((int) ($result['storage_retention']['errors'] ?? 0) > 0) {
                $result['warnings']++;
            }
        } catch (Throwable) {
            $result['storage_retention'] = ['processed' => 0, 'errors' => 1, 'skipped' => false];
            $result['warnings']++;
        }
        $result['processed'] = $result['empty_batches']
            + $result['unknown_topics']
            + $result['ignored_topics']
            + $result['compacted_events']
            + $result['workload_samples_removed']
            + $result['legacy_notifications_normalized']
            + $result['maintenance_steps_compacted']
            + (int) $result['incident_materialization']['processed']
            + (int) ($result['storage_retention']['processed'] ?? 0);
        $result['errors'] = $result['warnings'];
        if ($result['warnings'] > 0) {
            $result['safe_message'] = 'Una o más comprobaciones locales no pudieron completarse; revise el diagnóstico.';
        }
        return $result;
    }
}
