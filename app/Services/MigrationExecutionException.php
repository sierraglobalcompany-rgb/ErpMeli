<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

final class MigrationExecutionException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $diagnosticId,
        private readonly ?string $migrationKey,
        private readonly string $stage,
        private readonly bool $safeToRetry,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function diagnosticId(): string
    {
        return $this->diagnosticId;
    }

    public function migrationKey(): ?string
    {
        return $this->migrationKey;
    }

    public function stage(): string
    {
        return $this->stage;
    }

    public function safeToRetry(): bool
    {
        return $this->safeToRetry;
    }
}
