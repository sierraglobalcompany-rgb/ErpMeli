<?php

declare(strict_types=1);

namespace App\ValueObjects;

final readonly class JobLease
{
    public function __construct(
        public string $jobType,
        public string $leaseToken,
        public float $deadline,
        public ?int $accountId = null,
        public ?int $jobId = null
    ) {
    }

    public function hasTime(int $minimumMilliseconds = 250): bool
    {
        return (($this->deadline - microtime(true)) * 1000) >= $minimumMilliseconds;
    }
}
