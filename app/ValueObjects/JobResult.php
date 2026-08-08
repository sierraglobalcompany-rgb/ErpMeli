<?php

declare(strict_types=1);

namespace App\ValueObjects;

final readonly class JobResult
{
    /** @param array<string,mixed> $metrics */
    public function __construct(
        public string $status,
        public int $processed = 0,
        public int $errors = 0,
        public ?string $nextRunAt = null,
        public ?string $pauseReason = null,
        public array $metrics = []
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->metrics + [
            'status' => $this->status,
            'processed' => $this->processed,
            'errors' => $this->errors,
            'next_run_at' => $this->nextRunAt,
            'stop_reason' => $this->pauseReason,
        ];
    }
}
