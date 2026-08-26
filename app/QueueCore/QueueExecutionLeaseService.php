<?php
declare(strict_types=1);

namespace App\QueueCore;

use PDO;
use Throwable;

final class QueueExecutionLeaseService
{
    public function __construct(private readonly PDO $pdo) {}

    public function acquire(string $launcher, string $ownerToken, int $leaseSeconds = 60): ?QueueExecutionLease
    {
        $leaseSeconds = max(5, min(300, $leaseSeconds));
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("INSERT IGNORE INTO queue_core_execution_leases (lease_key,generation) VALUES ('global',0)");
            $row = $this->pdo->query("SELECT * FROM queue_core_execution_leases WHERE lease_key='global' FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && $row['owner_token'] !== null && strtotime((string) $row['expires_at'].' UTC') > time()) {
                $this->pdo->rollBack();
                return null;
            }
            $generation = max(0, (int) ($row['generation'] ?? 0)) + 1;
            $stmt = $this->pdo->prepare("UPDATE queue_core_execution_leases SET launcher=?,owner_token=?,generation=?,heartbeat_at=UTC_TIMESTAMP(3),expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ? SECOND) WHERE lease_key='global'");
            $stmt->execute([$launcher, $ownerToken, $generation, $leaseSeconds]);
            if($stmt->rowCount()!==1){
                $this->pdo->rollBack();
                return null;
            }
            $this->pdo->commit();
            return new QueueExecutionLease($launcher, $ownerToken, $generation, $leaseSeconds);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($this->isTransientAcquireRace($error)) {
                return null;
            }
            throw $error;
        }
    }

    public function heartbeat(QueueExecutionLease $lease): bool
    {
        $stmt = $this->pdo->prepare("UPDATE queue_core_execution_leases SET heartbeat_at=UTC_TIMESTAMP(3),expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL ? SECOND) WHERE lease_key='global' AND launcher=? AND owner_token=? AND generation=? AND expires_at>UTC_TIMESTAMP(3)");
        $stmt->execute([$lease->leaseSeconds, $lease->launcher, $lease->ownerToken, $lease->generation]);
        return $stmt->rowCount() === 1;
    }

    public function release(QueueExecutionLease $lease): bool
    {
        $stmt = $this->pdo->prepare("UPDATE queue_core_execution_leases SET launcher=NULL,owner_token=NULL,heartbeat_at=NULL,expires_at=NULL WHERE lease_key='global' AND launcher=? AND owner_token=? AND generation=?");
        $stmt->execute([$lease->launcher, $lease->ownerToken, $lease->generation]);
        return $stmt->rowCount() === 1;
    }

    public function activeLauncher(): ?string
    {
        $value = $this->pdo->query("SELECT launcher FROM queue_core_execution_leases WHERE lease_key='global' AND owner_token IS NOT NULL AND expires_at>UTC_TIMESTAMP(3)")->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    private function isTransientAcquireRace(Throwable $error): bool
    {
        $code = (string) $error->getCode();
        if (in_array($code, ['40001', 'HY000'], true) && str_contains(strtolower($error->getMessage()), 'deadlock')) {
            return true;
        }
        return str_contains(strtolower($error->getMessage()), 'try restarting transaction');
    }
}
