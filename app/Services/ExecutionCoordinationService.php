<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class ExecutionCoordinationService
{
    public function available(): bool
    {
        return (new SchemaInspectorService())->hasTable('manual_processing_sessions');
    }

    public function queueReservedForManual(string $queueKey, ?int $accountId = null): bool
    {
        if (!$this->available()) {
            return false;
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT 1
             FROM manual_processing_scopes s
             JOIN manual_processing_sessions m ON m.id=s.manual_processing_session_id
             WHERE s.active=1 AND s.queue_key=?
               AND (? IS NULL OR s.meli_account_id IS NULL OR s.meli_account_id=?)
               AND m.status IN ("active","paused","finishing")
               AND (m.status IN ("active","finishing") OR m.grace_until>UTC_TIMESTAMP())
             LIMIT 1'
        );
        $stmt->execute([$queueKey, $accountId, $accountId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param list<string> $queueKeys
     * @return array<string,true>
     */
    public function reservedQueueKeys(array $queueKeys): array
    {
        return $this->reservedQueueKeysReadOnly($queueKeys);
    }

    /**
     * Lectura autoritativa sin reconciliar, liberar ni modificar sesiones.
     * Los previews web y el selector pueden invocarla con absoluta seguridad.
     *
     * @param list<string> $queueKeys
     * @return array<string,true>
     */
    public function reservedQueueKeysReadOnly(array $queueKeys): array
    {
        $queueKeys = array_values(array_unique(array_filter(
            array_map('strval', $queueKeys),
            static fn (string $key): bool => $key !== ''
        )));
        if (!$this->available() || $queueKeys === []) {
            return [];
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT DISTINCT s.queue_key
             FROM manual_processing_scopes s
             JOIN manual_processing_sessions m ON m.id=s.manual_processing_session_id
             WHERE s.active=1
               AND s.queue_key IN (' . implode(',', array_fill(0, count($queueKeys), '?')) . ')
               AND m.status IN ("active","paused","finishing")
               AND (m.status IN ("active","finishing") OR m.grace_until>UTC_TIMESTAMP())'
        );
        $stmt->execute($queueKeys);
        return array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    public function heartbeat(int $sessionId, int $userId): bool
    {
        if (!$this->available()) {
            return false;
        }
        $grace = max(1, min(60, (new AppSettingsService())->int('manual_processing.browser_grace_minutes', 15)));
        $stmt = Database::connectionFresh()->prepare(
            'UPDATE manual_processing_sessions
             SET heartbeat_at=UTC_TIMESTAMP(),grace_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ' . $grace . ' MINUTE),
                 status=IF(status="paused","paused","active"),updated_at=UTC_TIMESTAMP()
             WHERE id=? AND created_by_user_id=? AND status IN ("active","paused")'
        );
        $stmt->execute([$sessionId, $userId]);
        return $stmt->rowCount() === 1;
    }

    public function releaseAbandoned(): int
    {
        return $this->releaseAbandonedCli();
    }

    /**
     * La devolución de sesiones vencidas es mantenimiento automático y solo
     * puede ejecutarse desde CLI. Una lectura HTTP nunca cambia reservas.
     */
    public function releaseAbandonedCli(): int
    {
        if (PHP_SAPI !== 'cli' || !$this->available()) {
            return 0;
        }
        try {
            $pdo = Database::connectionFresh();
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'UPDATE manual_processing_sessions
                 SET status="abandoned",finished_at=UTC_TIMESTAMP(),
                     safe_message="El navegador dejó de informar actividad. Los trabajos regresaron al cron.",
                     updated_at=UTC_TIMESTAMP()
                 WHERE status IN ("active","paused","finishing")
                   AND grace_until IS NOT NULL AND grace_until<UTC_TIMESTAMP()'
            );
            $stmt->execute();
            $count = $stmt->rowCount();
            if ($count > 0) {
                $pdo->exec(
                    'UPDATE manual_processing_scopes s
                     JOIN manual_processing_sessions m ON m.id=s.manual_processing_session_id
                     SET s.active=0,s.updated_at=UTC_TIMESTAMP()
                     WHERE m.status="abandoned" AND s.active=1'
                );
                $pdo->exec(
                    'UPDATE manual_processing_items i
                     JOIN manual_processing_sessions m ON m.id=i.manual_processing_session_id
                     SET i.status="returned",i.lease_owner=NULL,i.lease_expires_at=NULL,i.updated_at=UTC_TIMESTAMP()
                     WHERE m.status="abandoned" AND i.status IN ("pending","waiting","retry","running")'
                );
            }
            $pdo->commit();
            return $count;
        } catch (Throwable) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return 0;
        }
    }

    /** @return array<string,mixed>|null */
    public function activeSession(bool $includePaused = true): ?array
    {
        if (!$this->available()) {
            return null;
        }
        $statuses = $includePaused ? '"active","paused","finishing"' : '"active"';
        $row = Database::connectionFresh()->query(
            'SELECT * FROM manual_processing_sessions
             WHERE status IN (' . $statuses . ') AND grace_until>=UTC_TIMESTAMP()
             ORDER BY started_at ASC,id ASC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
