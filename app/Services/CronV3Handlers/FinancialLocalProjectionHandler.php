<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Services\CronV3ExecutionContext;
use App\Services\SaleFinancialStateService;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Closure;
use InvalidArgumentException;

final class FinancialLocalProjectionHandler implements CronV3WorkHandler
{
    /** @var Closure(WorkEnvelope):array<string,mixed> */
    private readonly Closure $project;

    /** @param null|callable(WorkEnvelope):array<string,mixed> $project */
    public function __construct(?callable $project = null)
    {
        $this->project = $project !== null
            ? Closure::fromCallable($project)
            : static function (WorkEnvelope $work): array {
                $service = new SaleFinancialStateService();
                $orderId = (int) ($work->payload['meli_order_id'] ?? 0);
                if ($orderId > 0) {
                    return $service->projectOrder($orderId);
                }
                $saleKey = trim((string) ($work->payload['sale_key'] ?? ''));
                if ($saleKey === '') {
                    throw new InvalidArgumentException('La proyección requiere meli_order_id o sale_key.');
                }
                return $service->projectSale($work->companyId, $work->meliAccountId, $saleKey);
            };
    }

    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $result = ($this->project)($work);

        return WorkResult::completed([
            'sale_key' => (string) ($result['sale_key'] ?? $work->payload['sale_key'] ?? ''),
            'provisional_status' => (string) ($result['provisional_status'] ?? 'unknown'),
            'official_status' => (string) ($result['official_status'] ?? 'unknown'),
        ]);
    }
}
