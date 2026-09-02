<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Queue V4 proved that cURL was not started; retry is safe and non-failing. */
final class QueueV4PreTransportDeferredException extends RuntimeException
{
    public function __construct(public readonly string $nextSafeAt)
    {
        parent::__construct('Queue V4 aplazó una lectura antes del transporte físico.');
    }
}
