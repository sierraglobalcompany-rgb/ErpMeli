<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Autoridad explícita para retirar consumidores V2. Tener un handler V3 no
 * significa que todos los productores históricos de su familia ya migraron.
 */
final class CronV3CutoverCapabilityRegistry
{
    /** @var array<string,true> */
    private const CERTIFIED_V2_FAMILIES = [
        'order_enrichment' => true,
        'financial_recalc' => true,
        'sale_financial_reconciliation' => true,
    ];

    public function canTransferV2(string $queueKey): bool
    {
        return isset(self::CERTIFIED_V2_FAMILIES[$queueKey]);
    }

    /** @return list<string> */
    public function certifiedV2Families(): array
    {
        return array_keys(self::CERTIFIED_V2_FAMILIES);
    }
}
