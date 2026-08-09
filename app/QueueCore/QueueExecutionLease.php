<?php
declare(strict_types=1);

namespace App\QueueCore;

final readonly class QueueExecutionLease
{
    public function __construct(
        public string $launcher,
        public string $ownerToken,
        public int $generation,
        public int $leaseSeconds,
    ) {
    }
}
