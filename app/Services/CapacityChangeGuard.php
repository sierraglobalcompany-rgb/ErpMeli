<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\QueueV4Clean\QueueV4CleanHealthSnapshotService;
use PDO;

/** Authorization and operational evidence required by a global capacity change. */
final class CapacityChangeGuard
{
    public function __construct(private readonly ?PDO $connection = null) {}

    public function assertGlobalAuthorization(): void
    {
        try {
            $userId = (int) (Auth::id() ?? 0);
            if ($userId <= 0 || $this->hasUnauthorizedAffectedTenant($userId)) {
                throw new HttpException(403, 'No tiene permisos para modificar la capacidad global.');
            }
        } catch (HttpException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new HttpException(403, 'No tiene permisos para modificar la capacidad global.');
        }
    }

    /** @return array{allowed:bool,message:string} */
    public function increaseGate(): array
    {
        try {
            $scope = $this->affectedScope();
            $snapshot = (new QueueV4CleanHealthSnapshotService($this->pdo()))->snapshot(
                null,
                $scope['company_ids'],
                $scope['account_ids'],
            );
            $totals = is_array($snapshot['totals'] ?? null) ? $snapshot['totals'] : [];
            $oauthStates = is_array($snapshot['oauth']['states'] ?? null) ? $snapshot['oauth']['states'] : [];
            $complete = ($snapshot['ok'] ?? false) === true
                && ($snapshot['protocol'] ?? null) === 'complete'
                && ($snapshot['snapshot_state'] ?? null) === 'complete';
            $blocked = !$complete
                || ($snapshot['state'] ?? null) !== 'healthy'
                || (int) ($totals['dead'] ?? 0) > 0
                || (int) ($totals['stale_running'] ?? 0) > 0
                || (int) ($oauthStates['REMOTE_UNCERTAIN'] ?? 0) > 0
                || (int) ($oauthStates['RECONNECT_REQUIRED'] ?? 0) > 0
                || (int) ($oauthStates['FAILED'] ?? 0) > 0
                || $this->hasAffectedOAuthBlocker()
                || $this->hasRecentRemote429($scope);

            return $blocked
                ? ['allowed' => false, 'message' => 'No se pudo certificar una salud global completa para aumentar la capacidad.']
                : ['allowed' => true, 'message' => 'La salud global está certificada para aumentar la capacidad.'];
        } catch (\Throwable) {
            return ['allowed' => false, 'message' => 'No se pudo certificar una salud global completa para aumentar la capacidad.'];
        }
    }

    private function hasUnauthorizedAffectedTenant(int $userId): bool
    {
        $sql = 'SELECT EXISTS(
                    SELECT 1
                    FROM (' . $this->affectedTenantSql() . ') affected
                    LEFT JOIN meli_accounts account
                      ON account.id=affected.meli_account_id AND account.company_id=affected.company_id
                    LEFT JOIN user_company_access company_access
                      ON company_access.user_id=? AND company_access.company_id=affected.company_id
                    LEFT JOIN user_meli_account_access account_access
                      ON account_access.user_id=? AND account_access.meli_account_id=affected.meli_account_id
                    WHERE company_access.user_id IS NULL
                       OR (affected.meli_account_id IS NOT NULL AND (
                           account.id IS NULL
                           OR (
                           EXISTS (SELECT 1 FROM user_meli_account_access configured WHERE configured.user_id=?)
                           AND account_access.user_id IS NULL
                           )
                       ))
                    LIMIT 1
                )';
        $statement = $this->pdo()->prepare($sql);
        $statement->execute([$userId, $userId, $userId]);
        return (int) $statement->fetchColumn() === 1;
    }

    /** @return array{company_ids:list<int>,account_ids:list<int>} */
    private function affectedScope(): array
    {
        $rows = $this->pdo()->query(
            'SELECT DISTINCT affected.company_id,affected.meli_account_id
             FROM (' . $this->affectedTenantSql() . ') affected
             ORDER BY affected.company_id,affected.meli_account_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $companyIds = [];
        $accountIds = [];
        foreach ($rows as $row) {
            $companyId = (int) ($row['company_id'] ?? 0);
            $accountId = (int) ($row['meli_account_id'] ?? 0);
            if ($companyId > 0) {
                $companyIds[$companyId] = true;
            }
            if ($accountId > 0) {
                $accountIds[$accountId] = true;
            }
        }
        return [
            'company_ids' => array_map('intval', array_keys($companyIds)),
            'account_ids' => array_map('intval', array_keys($accountIds)),
        ];
    }

    private function hasAffectedOAuthBlocker(): bool
    {
        $statement = $this->pdo()->query(
            'SELECT EXISTS(
                SELECT 1
                FROM (' . $this->affectedTenantSql() . ') affected
                INNER JOIN meli_accounts account
                  ON account.company_id=affected.company_id AND account.id=affected.meli_account_id
                LEFT JOIN meli_tokens token ON token.meli_account_id=account.id
                LEFT JOIN oauth_refresh_operations operation ON operation.id=(
                    SELECT MAX(latest.id)
                    FROM oauth_refresh_operations latest
                    WHERE latest.company_id=affected.company_id
                      AND latest.meli_account_id=affected.meli_account_id
                )
                WHERE affected.meli_account_id IS NOT NULL
                  AND (
                    account.status NOT IN ("conectado","connected")
                    OR token.meli_account_id IS NULL
                    OR account.meli_user_id=""
                    OR token.refresh_token_encrypted=""
                    OR token.expires_at IS NULL
                    OR operation.state IN ("FAILED","REMOTE_UNCERTAIN","RECONNECT_REQUIRED")
                  )
                LIMIT 1
             )'
        );
        return (int) $statement->fetchColumn() === 1;
    }

    /** @param array{company_ids:list<int>,account_ids:list<int>} $scope */
    private function hasRecentRemote429(array $scope): bool
    {
        $where = ['l.scope_kind="application"'];
        $params = [];
        if ($scope['company_ids'] !== []) {
            $where[] = '(l.scope_kind="company" AND l.company_id IN ('
                . implode(',', array_fill(0, count($scope['company_ids']), '?')) . '))';
            array_push($params, ...$scope['company_ids']);
        }
        if ($scope['account_ids'] !== []) {
            $where[] = 'l.meli_account_id IN ('
                . implode(',', array_fill(0, count($scope['account_ids']), '?')) . ')';
            array_push($params, ...$scope['account_ids']);
        }
        $statement = $this->pdo()->prepare(
            'SELECT EXISTS(
                SELECT 1 FROM api_request_logs l
                WHERE l.http_status=429
                  AND l.reached_remote=1
                  AND l.created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)
                  AND (' . implode(' OR ', $where) . ')
                LIMIT 1
             )'
        );
        $statement->execute($params);
        return (int) $statement->fetchColumn() === 1;
    }

    private function affectedTenantSql(): string
    {
        return 'SELECT company.id company_id,account.id meli_account_id
                  FROM companies company
                  LEFT JOIN meli_accounts account ON account.company_id=company.id
                  WHERE company.status=1
                UNION
                SELECT ra.company_id,ra.meli_account_id
                  FROM queue_v4_clean_readiness_accounts ra
                  INNER JOIN queue_v4_clean_readiness_runs rr
                    ON rr.id=ra.readiness_run_id AND rr.state="CERTIFIED"
                  WHERE ra.readiness_run_id=(SELECT MAX(id) FROM queue_v4_clean_readiness_runs WHERE state="CERTIFIED")
                    AND ra.outcome="PASS"
                UNION
                SELECT company_id,meli_account_id FROM queue_v4_clean_jobs
                  WHERE state IN ("ready","running","waiting","review","dead")
                UNION
                SELECT company_id,meli_account_id FROM oauth_refresh_operations
                  WHERE state IN ("SCHEDULED","RUNNING","WAITING","FAILED","REMOTE_UNCERTAIN","RECONNECT_REQUIRED")
                UNION
                SELECT company_id,meli_account_id FROM sync_sales_audit_jobs
                  WHERE status IN ("pending","running","waiting_budget")
                UNION
                SELECT company_id,meli_account_id FROM sync_sales_repair_jobs
                  WHERE source_kind="exact" AND status IN ("pending","running","waiting_budget","retry")';
    }

    private function pdo(): PDO
    {
        return $this->connection ?? Database::connectionFresh();
    }
}
