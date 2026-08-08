<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

interface ItemReadGateway
{
    /** @return list<array<string,mixed>> */
    public function items(int $accountId, int $limit = 50, int $offset = 0): array;

    public function findItem(int $accountId, string $externalItemId): ?array;

    public function countItems(int $accountId): int;

    /** @param list<string> $externalItemIds @return array<string,array<string,mixed>> */
    public function itemsByIds(int $accountId, array $externalItemIds): array;

    /** @return list<array{category_id:string,total:int}> */
    public function topCategories(int $accountId, int $limit = 5): array;
}
