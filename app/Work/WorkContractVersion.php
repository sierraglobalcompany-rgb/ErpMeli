<?php

declare(strict_types=1);

namespace App\Work;

final class WorkContractVersion
{
    public const CURRENT = 1;
    public const CONTROL_UNIT = 'PHYSICAL_API_CALL';

    /** @var list<string> */
    public const SUPPORTED_WORK_TYPES = [
        'fresh_orders_discovery',
        'order_exact',
        'domain_exact',
        'financial_recalc',
        'financial_reconciliation',
        'notification_work_item',
        'order_enrichment_pack',
    ];

    public static function supports(string $workType): bool
    {
        return in_array($workType, self::SUPPORTED_WORK_TYPES, true);
    }
}
