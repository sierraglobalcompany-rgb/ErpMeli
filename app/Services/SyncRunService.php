<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use DateTimeImmutable;

final class SyncRunService
{
    public function start(int $accountId, string $type, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): int
    {
        $stmt = Database::connection()->prepare(
            "INSERT INTO meli_sync_runs (meli_account_id,sync_type,date_from,date_to,status,started_at,created_by)
             VALUES (:account,:type,:from,:to,'running',NOW(),:user)"
        );
        $stmt->execute([
            'account' => $accountId,
            'type' => $type,
            'from' => $from?->format('Y-m-d H:i:s'),
            'to' => $to?->format('Y-m-d H:i:s'),
            'user' => Auth::id(),
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    public function succeed(int $runId, int $processed): void
    {
        $this->finish($runId, 'success', $processed, 0, null);
    }

    public function fail(int $runId, int $processed, string $error, int $errorCount = 1): void
    {
        $this->finish($runId, 'error', $processed, $errorCount, $error);
    }

    private function finish(int $runId, string $status, int $processed, int $errors, ?string $message): void
    {
        if ($runId < 1) {
            return;
        }
        Database::connection()->prepare(
            'UPDATE meli_sync_runs SET status=:status,processed_count=:processed,error_count=:errors,last_error=:error,finished_at=NOW() WHERE id=:id'
        )->execute([
            'status' => $status,
            'processed' => max(0, $processed),
            'errors' => max(0, $errors),
            'error' => $message ? mb_substr($message, 0, 500) : null,
            'id' => $runId,
        ]);
    }
}
