<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class ApiBudgetExhaustedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $nextSafeAt = null
    ) {
        parent::__construct($message);
    }
}
