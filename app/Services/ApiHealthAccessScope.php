<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;

/**
 * Alcance explícito para telemetría que puede pertenecer a una cuenta, a una
 * empresa antes de crear la cuenta (OAuth), o a toda la aplicación.
 */
final class ApiHealthAccessScope
{
    public function __construct(private readonly AuthorizedBusinessScope $business = new AuthorizedBusinessScope()) {}

    /** @return array{account_ids:list<int>,company_ids:list<int>,application:bool,selected_account_id:?int} */
    public function snapshot(?int $accountId = null): array
    {
        $accountId = $accountId !== null && $accountId > 0 ? $accountId : null;
        // No se conserva este alcance en memoria estática. PHP-FPM puede
        // reutilizar el proceso después de revocar una empresa o cuenta y un
        // snapshot por userId mantendría permisos obsoletos hasta reciclarlo.
        if ($accountId !== null) {
            $account = $this->business->account($accountId);
            return [
                'account_ids' => [$accountId],
                'company_ids' => [(int) $account['company_id']],
                'application' => false,
                'selected_account_id' => $accountId,
            ];
        }

        return [
            'account_ids' => $this->business->accountIds(),
            'company_ids' => $this->business->companyIds(),
            // El alcance de aplicación es un permiso explícito. Nunca se
            // infiere por la ausencia de empresas o cuentas.
            'application' => $this->hasExplicitApplicationAccess(),
            'selected_account_id' => null,
        ];
    }

    /**
     * @return array{sql:string,params:array<string,int>}
     */
    public function predicate(string $alias, string $prefix, ?int $accountId = null): array
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias) !== 1
            || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $prefix) !== 1) {
            throw new \InvalidArgumentException('Alias de alcance no válido.');
        }

        $scope = $this->snapshot($accountId);
        $parts = [];
        $params = [];
        if ($scope['account_ids'] !== []) {
            $tokens = [];
            foreach ($scope['account_ids'] as $index => $id) {
                $key = $prefix . '_account_' . $index;
                $tokens[] = ':' . $key;
                $params[$key] = $id;
            }
            $parts[] = $alias . '.meli_account_id IN (' . implode(',', $tokens) . ')';
        }

        if ($accountId === null && $scope['company_ids'] !== []) {
            $tokens = [];
            foreach ($scope['company_ids'] as $index => $id) {
                $key = $prefix . '_company_' . $index;
                $tokens[] = ':' . $key;
                $params[$key] = $id;
            }
            $parts[] = '(' . $alias . '.scope_kind="company" AND ' . $alias . '.company_id IN (' . implode(',', $tokens) . '))';
        }

        if ($accountId === null && $scope['application']) {
            $parts[] = $alias . '.scope_kind="application"';
        }

        return $parts === []
            ? ['sql' => '1=0', 'params' => []]
            : ['sql' => '(' . implode(' OR ', $parts) . ')', 'params' => $params];
    }

    /** @return list<string> */
    public function acknowledgementKeys(?int $accountId = null): array
    {
        $scope = $this->snapshot($accountId);
        $keys = array_map(static fn(int $id): string => 'account:' . $id, $scope['account_ids']);
        if ($accountId === null) {
            foreach ($scope['company_ids'] as $id) {
                $keys[] = 'company:' . $id;
            }
            if ($scope['application']) {
                $keys[] = 'application';
            }
        }
        return array_values(array_unique($keys));
    }

    private function hasExplicitApplicationAccess(): bool
    {
        if (Auth::id() === null || Auth::role() !== 'admin' || Auth::isTemporary()) {
            return false;
        }
        $authorized = $this->business->companyIds();
        if ($authorized === []) {
            return false;
        }
        $activeCompanies = array_map(
            'intval',
            Database::connection()->query('SELECT id FROM companies WHERE status=1 ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)
        );
        sort($authorized);
        sort($activeCompanies);
        // La telemetría de aplicación no pertenece a una empresa concreta. Solo
        // puede verla quien recibió concesiones explícitas sobre todas las
        // empresas activas; el rol por sí solo nunca amplía el alcance.
        return $activeCompanies !== [] && $authorized === $activeCompanies;
    }
}
