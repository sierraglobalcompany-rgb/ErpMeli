<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class ApiManualPauseException extends RuntimeException
{
    public function __construct(
        public readonly string $scope,
        public readonly ?int $accountId,
        public readonly ?string $resumeAt,
        string $message
    ) {
        parent::__construct($message);
    }
}
