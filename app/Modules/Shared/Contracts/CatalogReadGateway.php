<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

interface CatalogReadGateway
{
    /** @return list<array<string,mixed>> */
    public function enabledCatalogs(?int $accountId = null): array;
}
