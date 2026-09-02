<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class StorageMaintenanceService
{
    /**
     * @return array{processed:int,errors:int,retention:array<string,int>,payloads:array<string,int>,archives:array<string,int>}
     */
    public function run(int $limit = 500): array
    {
        if (!$this->acquire('storage_retention')) {
            return [
                'processed' => 0,
                'errors' => 0,
                'retention' => [],
                'payloads' => [],
                'archives' => [],
            ];
        }
        $settings = new AppSettingsService();
        $limit = max(1, min(5000, $limit));
        $retention = [];
        $payloads = [];
        $archives = [];
        $errors = 0;
        try {
            $retention = (new RetentionPolicyService())->run($limit);
            $payloads = (new RemotePayloadMigrationService())->migrateBatch(
                max(1, min($limit, $settings->int('raw_payload.batch_size', 100)))
            );
            $orphanedPayloads = (new FileRemotePayloadStore())->purgeOrphans(
                max(1, min(100, $limit))
            );
            $payloads['orphans_deleted'] = $orphanedPayloads['deleted'];
            $payloads['orphan_errors'] = $orphanedPayloads['errors'];
            $archives = (new ColdArchiveService())->purgeExpired(10);
            $errors = (int) $retention['errors']
                + (int) $payloads['errors']
                + (int) $payloads['orphan_errors']
                + (int) $archives['errors'];
            $processed = (int) $retention['deleted']
                + (int) $retention['archives']
                + (int) $payloads['processed']
                + (int) $payloads['orphans_deleted']
                + (int) $archives['deleted'];
            $this->finish(
                'storage_retention',
                $errors === 0 ? 'completed' : 'partial',
                $processed,
                null,
                $processed > 0 ? 15 : 1440
            );
            return compact('processed', 'errors', 'retention', 'payloads', 'archives');
        } catch (Throwable) {
            $this->finish(
                'storage_retention',
                'failed',
                0,
                'El mantenimiento de almacenamiento no pudo completarse.',
                60
            );
            return [
                'processed' => 0,
                'errors' => 1,
                'retention' => $retention,
                'payloads' => $payloads,
                'archives' => $archives,
            ];
        }
    }

    private function acquire(string $task): bool
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT IGNORE INTO system_maintenance_task_state
                 (task_key,next_run_at,last_result)
                 VALUES (:task,UTC_TIMESTAMP(3),"pending")'
            )->execute(['task' => $task]);
            $stmt = $pdo->prepare(
                'SELECT task_key,next_run_at,last_started_at
                 FROM system_maintenance_task_state
                 WHERE task_key=:task FOR UPDATE'
            );
            $stmt->execute(['task' => $task]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $due = is_array($row)
                && (
                    empty($row['next_run_at'])
                    || strtotime((string) $row['next_run_at']) <= time()
                    || (
                        !empty($row['last_started_at'])
                        && strtotime((string) $row['last_started_at']) < time() - 3600
                    )
                );
            if (!$due) {
                $pdo->rollBack();
                return false;
            }
            $pdo->prepare(
                'UPDATE system_maintenance_task_state
                 SET last_started_at=UTC_TIMESTAMP(3),next_run_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR),
                     last_result="running",safe_error_message=NULL
                 WHERE task_key=:task'
            )->execute(['task' => $task]);
            $pdo->commit();
            return true;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function finish(
        string $task,
        string $result,
        int $affected,
        ?string $message,
        int $nextMinutes
    ): void
    {
        $nextMinutes = max(15, min(1440, $nextMinutes));
        Database::connection()->prepare(
            'UPDATE system_maintenance_task_state
             SET last_completed_at=UTC_TIMESTAMP(3),
                 next_run_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ' . $nextMinutes . ' MINUTE),
                 last_result=:result,last_affected_rows=:affected,safe_error_message=:message
             WHERE task_key=:task'
        )->execute([
            'result' => $result,
            'affected' => max(0, $affected),
            'message' => $message,
            'task' => $task,
        ]);
    }
}
