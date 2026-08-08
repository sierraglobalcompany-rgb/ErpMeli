<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Nombre público de la autoridad de alcance. Mantiene BusinessScopeContext
 * como adaptador compatible para los servicios existentes y ofrece una única
 * entrada para código nuevo.
 */
final class AuthorizedBusinessScope
{
    public function __construct(private readonly BusinessScopeContext $context = new BusinessScopeContext()) {}

    /** @return list<int> */
    public function companyIds(?int $userId = null): array
    {
        return $this->context->companyIds($userId);
    }

    /** @return list<int> */
    public function accountIds(?int $userId = null, int $companyId = 0): array
    {
        return $this->context->accountIds($userId, $companyId);
    }

    /** @return array<string,mixed> */
    public function account(int $accountId, int $companyId = 0, ?int $userId = null): array
    {
        return $this->context->account($accountId, $companyId, $userId);
    }

    /** @return array{sql:string,params:list<int>} */
    public function accountPredicate(string $column, ?int $userId = null, int $companyId = 0): array
    {
        return $this->context->accountPredicate($column, $userId, $companyId);
    }
}
