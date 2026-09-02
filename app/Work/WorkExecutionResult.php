<?php

declare(strict_types=1);

namespace App\Work;

final readonly class WorkExecutionResult
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $state,
        public ?string $reason = null,
        public ?\DateTimeImmutable $nextSafeAt = null,
        public int $physicalApiCalls = 0,
        public array $metadata = [],
    ) {
    }
}
