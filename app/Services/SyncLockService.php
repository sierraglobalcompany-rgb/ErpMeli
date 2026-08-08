<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class SyncLockService
{
    public function acquire(int $accountId, string $syncType, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null, int $ttlMinutes = 30): int
    {
        $pdo = Database::connection();
        $key = self::makeKey($accountId, $syncType, $from, $to);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM meli_sync_locks WHERE lock_key=:key AND locked_until<UTC_TIMESTAMP()')->execute(['key' => $key]);
            $stmt = $pdo->prepare(
                'INSERT INTO meli_sync_locks (lock_key,meli_account_id,sync_type,date_from,date_to,locked_until)
                 VALUES (:key,:account,:type,:from,:to,:locked_until)'
            );
            $stmt->bindValue(':key', $key);
            $stmt->bindValue(':account', $accountId);
            $stmt->bindValue(':type', $syncType);
            $stmt->bindValue(':from', $from?->format('Y-m-d H:i:s'));
            $stmt->bindValue(':to', $to?->format('Y-m-d H:i:s'));
            $stmt->bindValue(':locked_until', gmdate('Y-m-d H:i:s', time() + (max(5, $ttlMinutes) * 60)));
            $stmt->execute();
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (($e instanceof \PDOException && $e->getCode() === '23000') || str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'duplicate')) {
                throw new RuntimeException('Ya hay una sincronización en curso para esta cuenta, tipo y rango.');
            }
            throw $e;
        }
    }

    public function release(int $lockId): void
    {
        if ($lockId > 0) {
            Database::connection()->prepare('DELETE FROM meli_sync_locks WHERE id=:id')->execute(['id' => $lockId]);
        }
    }

    public static function makeKey(int $accountId, string $syncType, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): string
    {
        return hash('sha256', implode('|', [
            $accountId,
            strtolower(trim($syncType)),
            $from?->format('Y-m-d H:i:s') ?? '',
            $to?->format('Y-m-d H:i:s') ?? '',
        ]));
    }
}
