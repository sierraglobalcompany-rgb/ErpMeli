<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class ApiManualPauseService
{
    /** @var list<int> */
    public const ALLOWED_MINUTES = [15, 60, 240, 1440];

    public function assertAllowed(?int $accountId): void
    {
        $pause = $this->effectivePause($accountId);
        if ($pause === null) {
            return;
        }
        $until = $pause['paused_until'] ?? null;
        $when = is_string($until) && $until !== '' ? ' hasta ' . $until . ' UTC' : ' indefinidamente';
        throw new ApiManualPauseException(
            (string) $pause['scope'],
            $accountId,
            is_string($until) ? $until : null,
            'Consultas pausadas manualmente por seguridad Mercado Libre' . $when . '.'
        );
    }

    /** @return array<string,mixed>|null */
    public function effectivePause(?int $accountId): ?array
    {
        if (!$this->available()) {
            return null;
        }
        $sql = "SELECT p.*,a.account_name,u.name paused_by_name
                FROM api_manual_pauses p
                LEFT JOIN meli_accounts a ON a.id=p.meli_account_id
                LEFT JOIN users u ON u.id=p.created_by
                WHERE p.status='active'
                  AND (p.paused_until IS NULL OR p.paused_until>UTC_TIMESTAMP())
                  AND (p.scope='app'";
        $params = [];
        if ($accountId !== null && $accountId > 0) {
            $sql .= " OR (p.scope='account' AND p.meli_account_id=?)";
            $params[] = $accountId;
        }
        $sql .= ") ORDER BY CASE WHEN p.scope='app' THEN 0 ELSE 1 END,p.created_at DESC LIMIT 1";
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function active(?array $accountIds = null, ?bool $includeApplication = null): array
    {
        if (!$this->available()) {
            return [];
        }
        $params = [];
        $scope = '';
        if ($accountIds !== null) {
            $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds), static fn (int $id): bool => $id > 0)));
            if ($includeApplication === null) {
                try {
                    $includeApplication = (new ApiHealthAccessScope())->snapshot()['application'];
                } catch (Throwable) {
                    $includeApplication = false;
                }
            }
            $allowed = [];
            if ($includeApplication) {
                $allowed[] = "p.scope='app'";
            }
            if ($accountIds !== []) {
                $allowed[] = "(p.scope='account' AND p.meli_account_id IN (" . implode(',', array_fill(0, count($accountIds), '?')) . '))';
            }
            $scope = $allowed === [] ? ' AND 1=0' : ' AND (' . implode(' OR ', $allowed) . ')';
            $params = $accountIds;
        }
        $stmt = Database::connection()->prepare(
            "SELECT p.*,a.account_name,u.name paused_by_name
             FROM api_manual_pauses p
             LEFT JOIN meli_accounts a ON a.id=p.meli_account_id
             LEFT JOIN users u ON u.id=p.created_by
             WHERE p.status='active' AND (p.paused_until IS NULL OR p.paused_until>UTC_TIMESTAMP())
             " . $scope . "
             ORDER BY CASE WHEN p.scope='app' THEN 0 ELSE 1 END,p.created_at DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{available:bool,active:bool,global:bool,global_details_visible:bool,redacted_application_pause:bool,count:int,pauses:list<array<string,mixed>>} */
    public function summary(?array $accountIds = null, ?bool $includeApplication = null): array
    {
        $available = $this->available();
        if ($includeApplication === null && $accountIds !== null) {
            try {
                $includeApplication = (new ApiHealthAccessScope())->snapshot()['application'];
            } catch (Throwable) {
                $includeApplication = false;
            }
        }
        $pauses = $this->active($accountIds, $includeApplication);
        $visibleGlobal = (bool) array_filter($pauses, static fn (array $row): bool => ($row['scope'] ?? '') === 'app');
        // Una parada de aplicación afecta también a cuentas de alcance parcial,
        // pero su motivo, autor e identificador pertenecen al ámbito global. Solo
        // revelamos que existe la protección, nunca sus metadatos.
        $redactedGlobal = $available
            && $accountIds !== null
            && $accountIds !== []
            && $includeApplication === false
            && $this->applicationPauseActive();
        return [
            'available' => $available,
            'active' => $pauses !== [] || $redactedGlobal,
            'global' => $visibleGlobal || $redactedGlobal,
            'global_details_visible' => $visibleGlobal,
            'redacted_application_pause' => $redactedGlobal,
            'count' => count($pauses) + ($redactedGlobal ? 1 : 0),
            'pauses' => $pauses,
        ];
    }

    private function applicationPauseActive(): bool
    {
        try {
            $stmt = Database::connection()->query(
                "SELECT 1 FROM api_manual_pauses
                 WHERE status='active' AND scope='app'
                   AND (paused_until IS NULL OR paused_until>UTC_TIMESTAMP())
                 LIMIT 1"
            );
            return $stmt->fetchColumn() !== false;
        } catch (Throwable) {
            return false;
        }
    }

    public function pause(?int $accountId, ?int $minutes, string $reason, ?int $userId): int
    {
        if (!$this->available()) {
            throw new \RuntimeException('Complete la migración 086 antes de usar la pausa manual.');
        }
        if ($minutes !== null && !in_array($minutes, self::ALLOWED_MINUTES, true)) {
            throw new \InvalidArgumentException('La duración de pausa no es válida.');
        }
        $scope = $accountId !== null && $accountId > 0 ? 'account' : 'app';
        if ($scope === 'account') {
            $exists = Database::connection()->prepare('SELECT COUNT(*) FROM meli_accounts WHERE id=?');
            $exists->execute([$accountId]);
            if ((int) $exists->fetchColumn() === 0) {
                throw new \InvalidArgumentException('La cuenta Mercado Libre seleccionada no existe.');
            }
        }
        $reason = trim($reason);
        if ($reason === '') {
            $reason = 'Pausa preventiva solicitada por administración';
        }
        $reason = mb_substr($reason, 0, 500);
        $pausedUntil = $minutes === null
            ? null
            : (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $close = $pdo->prepare(
                "UPDATE api_manual_pauses SET status='replaced',resumed_at=UTC_TIMESTAMP(),
                 resume_reason='Reemplazada por una pausa nueva',resumed_by=?
                 WHERE status='active' AND scope=? AND ((? IS NULL AND meli_account_id IS NULL) OR meli_account_id=?)"
            );
            $close->execute([$userId, $scope, $accountId, $accountId]);
            $insert = $pdo->prepare(
                'INSERT INTO api_manual_pauses
                 (scope,meli_account_id,status,pause_mode,paused_until,reason,created_by,created_at)
                 VALUES (?,?,"active",?,?,?, ?,UTC_TIMESTAMP())'
            );
            $insert->execute([
                $scope,
                $accountId,
                $minutes === null ? 'indefinite' : 'timed',
                $pausedUntil,
                $reason,
                $userId,
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
            try {
                AuditService::record('api_manual_pause_created', 'api', 'api_manual_pause', $id, $accountId, null, [
                    'scope' => $scope,
                    'meli_account_id' => $accountId,
                    'paused_until' => $pausedUntil,
                ]);
                (new ReadModelCacheService())->clear();
            } catch (Throwable) {
                // La bitácora secundaria no debe revertir una pausa ya confirmada.
            }
            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function resume(int $pauseId, string $reason, ?int $userId): bool
    {
        if (!$this->available() || $pauseId <= 0) {
            return false;
        }
        $stmt = Database::connection()->prepare(
            "UPDATE api_manual_pauses
             SET status='resumed',resumed_at=UTC_TIMESTAMP(),resumed_by=?,resume_reason=?
             WHERE id=? AND status='active'"
        );
        $stmt->execute([$userId, mb_substr(trim($reason) ?: 'Reanudada por administración', 0, 500), $pauseId]);
        $changed = $stmt->rowCount() > 0;
        if ($changed) {
            try {
                AuditService::record('api_manual_pause_resumed', 'api', 'api_manual_pause', $pauseId);
                (new ReadModelCacheService())->clear();
            } catch (Throwable) {
                // La bitácora secundaria no debe impedir la reanudación confirmada.
            }
        }
        return $changed;
    }

    /** @return array<string,mixed>|null */
    public function findActive(int $pauseId): ?array
    {
        if (!$this->available() || $pauseId <= 0) {
            return null;
        }
        $stmt = Database::connection()->prepare(
            "SELECT p.*,a.company_id FROM api_manual_pauses p
             LEFT JOIN meli_accounts a ON a.id=p.meli_account_id
             WHERE p.id=? AND p.status='active' LIMIT 1"
        );
        $stmt->execute([$pauseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Limpieza explícita para mantenimiento CLI. Las lecturas HTTP no deben
     * cambiar el estado de una pausa solo por abrir Salud API.
     */
    public function expireFinished(): int
    {
        try {
            return (int) Database::connection()->exec(
                "UPDATE api_manual_pauses SET status='expired',resumed_at=COALESCE(resumed_at,UTC_TIMESTAMP()),
                 resume_reason=COALESCE(resume_reason,'Pausa finalizada automáticamente')
                 WHERE status='active' AND paused_until IS NOT NULL AND paused_until<=UTC_TIMESTAMP()"
            );
        } catch (Throwable) {
            // Una tarea secundaria de estado no debe romper las pantallas.
            return 0;
        }
    }

    private function available(): bool
    {
        try {
            return (new SchemaInspectorService())->hasTable('api_manual_pauses');
        } catch (Throwable) {
            return false;
        }
    }
}
