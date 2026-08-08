<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * Opciones compartidas por el layout. Evita consultas SQL dentro de las vistas.
 */
final class RequestContextService
{
    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /**
     * @return array{companies: array<int,array<string,mixed>>, accounts: array<int,array<string,mixed>>}
     */
    public function options(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $scope = new BusinessScopeContext();
        $companyIds = $scope->companyIds();
        $accountIds = $scope->accountIds();
        if ($companyIds === []) {
            return self::$cache = ['companies' => [], 'accounts' => []];
        }
        $companySql = implode(',', array_fill(0, count($companyIds), '?'));
        $companyStmt = Database::connection()->prepare(
            'SELECT id,name,nit,legal_name,status
             FROM companies
             WHERE deleted_at IS NULL AND status=1 AND id IN (' . $companySql . ')
             ORDER BY name,id'
        );
        $companyStmt->execute($companyIds);
        $companies = $companyStmt->fetchAll(PDO::FETCH_ASSOC);

        $accounts = [];
        if ($accountIds !== []) {
            $accountSql = implode(',', array_fill(0, count($accountIds), '?'));
            $accountStmt = Database::connection()->prepare(
                'SELECT id,account_name,company_id
                 FROM meli_accounts
                 WHERE id IN (' . $accountSql . ')
                 ORDER BY account_name,id'
            );
            $accountStmt->execute($accountIds);
            $accounts = $accountStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return self::$cache = ['companies' => $companies, 'accounts' => $accounts];
    }

    public static function clear(): void
    {
        self::$cache = null;
    }
}
