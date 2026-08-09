<?php
declare(strict_types=1);

namespace App\QueueCore;

/**
 * Explicitly disabled B2 boundary for official Billing.
 *
 * Keeping this as a handler-shaped boundary lets the runtime advertise the
 * missing capability honestly without calling the legacy financial queue or
 * issuing remote HTTP. A later wave may inject a certified exact executor.
 */
final class SaleBillingExactHandler implements QueueHandler
{
    public function handle(QueueClaim $job, QueueExecutionContext $context): QueueResult
    {
        return QueueResult::review('financial_remote_disabled');
    }
}
