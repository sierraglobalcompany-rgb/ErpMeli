<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use PDO;

/**
 * Convierte "todas" en un conjunto explícitamente autorizado.
 * Ningún repositorio de negocio debe interpretar 0 como ausencia de filtro.
 */
final class BusinessScopeContext
{
    /** @return list<int> */
    public function companyIds(?int $userId = null): array
    {
        $userId ??= (int) (Auth::id() ?? 0);
        if ($userId <= 0) {
            return [];
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT uca.company_id
             FROM user_company_access uca
             JOIN companies c ON c.id=uca.company_id AND c.status=1
             WHERE uca.user_id=?
             ORDER BY uca.company_id'
        );
        $stmt->execute([$userId]);
        return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    /** @return list<int> */
    public function accountIds(?int $userId = null, int $companyId = 0): array
    {
        $userId ??= (int) (Auth::id() ?? 0);
        $companies = $this->companyIds($userId);
        if ($companyId > 0) {
            if (!in_array($companyId, $companies, true)) {
                return [];
            }
            $companies = [$companyId];
        }
        if ($companies === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($companies), '?'));
        $pdo = Database::connectionFresh();
        $hasAccountAcl = (new SchemaInspectorService())->hasTable('user_meli_account_access');
        if ($hasAccountAcl && $userId > 0) {
            // Compatibilidad segura: una instalación actualizada conserva el
            // acceso explícito por empresa mientras el usuario no tenga ACL de
            // cuenta. En cuanto existe una asignación, el conjunto se reduce a
            // esas cuentas y nunca vuelve a ampliarse por un filtro recibido.
            $stmt = $pdo->prepare(
                'SELECT a.id
                 FROM meli_accounts a
                 LEFT JOIN user_meli_account_access uma
                   ON uma.meli_account_id=a.id AND uma.user_id=?
                 WHERE a.company_id IN (' . $placeholders . ')
                   AND (
                       NOT EXISTS (
                           SELECT 1 FROM user_meli_account_access configured
                           WHERE configured.user_id=?
                       )
                       OR uma.user_id IS NOT NULL
                   )
                 ORDER BY a.id'
            );
            $stmt->execute(array_merge([$userId], $companies, [$userId]));
        } else {
            $stmt = $pdo->prepare(
                'SELECT id FROM meli_accounts WHERE company_id IN (' . $placeholders . ') ORDER BY id'
            );
            $stmt->execute($companies);
        }
        return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    /** @return array<string,mixed> */
    public function account(int $accountId, int $companyId = 0, ?int $userId = null): array
    {
        if ($accountId <= 0) {
            throw new HttpException(404, 'No se encontró la cuenta solicitada.');
        }
        $accounts = $this->accountIds($userId, $companyId);
        if (!in_array($accountId, $accounts, true)) {
            throw new HttpException(404, 'No se encontró la cuenta solicitada.');
        }
        $stmt = Database::connectionFresh()->prepare(
            'SELECT a.id,a.company_id,a.account_name,a.meli_user_id,a.site_id,a.status,c.name company_name
             FROM meli_accounts a
             JOIN companies c ON c.id=a.company_id AND c.status=1
             WHERE a.id=?
             LIMIT 1'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new HttpException(404, 'No se encontró la cuenta solicitada.');
        }
        return $row;
    }

    /** @return array{sql:string,params:list<int>} */
    public function accountPredicate(string $column, ?int $userId = null, int $companyId = 0): array
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $column) !== 1) {
            throw new \InvalidArgumentException('Columna de alcance no válida.');
        }
        $ids = $this->accountIds($userId, $companyId);
        if ($ids === []) {
            return ['sql' => '1=0', 'params' => []];
        }
        return [
            'sql' => $column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            'params' => $ids,
        ];
    }
}
