<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

interface OrderReadGateway
{
    /** @return list<array<string,mixed>> */
    public function recentOrders(int $accountId, int $limit = 50): array;

    /**
     * Totales locales por día UTC dentro del rango half-open [desde, hasta).
     *
     * @return list<array{observed_on:string,orders_count:int,units_sold:int}>
     */
    public function dailyOrderTotals(int $accountId, string $fromUtc, string $toUtc): array;
}
