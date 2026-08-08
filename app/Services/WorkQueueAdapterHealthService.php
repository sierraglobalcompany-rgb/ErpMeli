<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Throwable;

final class WorkQueueAdapterHealthService
{
    public function success(string $queueKey): void
    {
        $this->write(
            'INSERT INTO system_work_queue_adapter_health
                (queue_key,status,last_checked_at,last_success_at,consecutive_failures,safe_error_message,diagnostic_id)
             VALUES (:queue_key,"healthy",UTC_TIMESTAMP(),UTC_TIMESTAMP(),0,NULL,NULL)
             ON DUPLICATE KEY UPDATE status="healthy",last_checked_at=UTC_TIMESTAMP(),
                last_success_at=UTC_TIMESTAMP(),consecutive_failures=0,safe_error_message=NULL,diagnostic_id=NULL',
            ['queue_key' => $queueKey]
        );
    }

    public function unavailable(string $queueKey, string $message): void
    {
        $this->recordFailure($queueKey, 'unavailable', $message, null);
    }

    public function failure(string $queueKey, string $message, ?string $diagnosticId): void
    {
        $this->recordFailure($queueKey, 'error', $message, $diagnosticId);
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        try {
            return Database::connection()->query(
                'SELECT * FROM system_work_queue_adapter_health ORDER BY status="healthy",queue_key'
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    private function recordFailure(string $queueKey, string $status, string $message, ?string $diagnosticId): void
    {
        $this->write(
            'INSERT INTO system_work_queue_adapter_health
                (queue_key,status,last_checked_at,last_failure_at,consecutive_failures,safe_error_message,diagnostic_id)
             VALUES (:queue_key,:status,UTC_TIMESTAMP(),UTC_TIMESTAMP(),1,:message,:diagnostic_id)
             ON DUPLICATE KEY UPDATE status=VALUES(status),last_checked_at=UTC_TIMESTAMP(),
                last_failure_at=UTC_TIMESTAMP(),consecutive_failures=consecutive_failures+1,
                safe_error_message=VALUES(safe_error_message),diagnostic_id=VALUES(diagnostic_id)',
            [
                'queue_key' => $queueKey,
                'status' => $status,
                'message' => mb_substr(Logger::redactString($message), 0, 500),
                'diagnostic_id' => $diagnosticId,
            ]
        );
    }

    /** @param array<string,mixed> $params */
    private function write(string $sql, array $params): void
    {
        try {
            $stmt = Database::connection()->prepare($sql);
            $stmt->execute($params);
        } catch (Throwable) {
            // La observabilidad nunca debe bloquear la cola.
        }
    }
}
