<?php

declare(strict_types=1);

namespace App\Work;

final readonly class DrainResult
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public bool $ok,
        public string $status,
        public int $physicalApiCalls,
        public int $completed,
        public int $deferred,
        public array $metadata = [],
    ) {
    }
}
