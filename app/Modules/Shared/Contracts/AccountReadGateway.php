<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

interface AccountReadGateway
{
    /** @return list<array<string,mixed>> */
    public function activeAccounts(?int $accountId = null): array;
}
