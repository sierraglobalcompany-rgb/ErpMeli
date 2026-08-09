<?php
declare(strict_types=1);

namespace App\QueueCore;

use App\Services\SaleFinancialStateService;
use RuntimeException;

/** Local-only financial projection. It cannot construct Mercado Libre transport. */
final class FinancialProjectionHandler implements QueueHandler
{
    /** @var \Closure():SaleFinancialStateService */
    private \Closure $serviceFactory;

    /** @param null|callable():SaleFinancialStateService $serviceFactory */
    public function __construct(
        private readonly SalePipelineCapabilityRepository $capabilities,
        ?callable $serviceFactory = null,
    ) {
        $this->serviceFactory = $serviceFactory !== null
            ? \Closure::fromCallable($serviceFactory)
            : static fn (): SaleFinancialStateService => new SaleFinancialStateService();
    }

    public function handle(QueueClaim $job, QueueExecutionContext $context): QueueResult
    {
        if (!$context->hasTime(0.5)) {
            return QueueResult::automaticWait('deadline', gmdate('Y-m-d H:i:s', time() + 5));
        }
        $orderId = (int) ($job->payload['order_id'] ?? $job->resourceId ?? 0);
        $capabilityId = (int) ($job->payload['capability_id'] ?? 0);
        if ($orderId < 1 || $capabilityId < 1) {
            return QueueResult::dead('invalid_financial_projection_identity');
        }
        if ($this->capabilities->dependencyCompleted($job, $capabilityId)) {
            return QueueResult::completed(1, 0);
        }
        $state = ($this->serviceFactory)()->projectOrderForQueueCore(
            $job->companyId,
            $job->meliAccountId,
            $orderId
        );
        if (trim((string) ($state['input_version'] ?? '')) === '') {
            throw new RuntimeException('Queue Core financial projection did not produce an input version.');
        }
        if (!$this->capabilities->completeDependency($job, $capabilityId)) {
            throw new RuntimeException('Queue Core financial projection completion fence changed.');
        }
        return QueueResult::completed(1, 0);
    }
}
