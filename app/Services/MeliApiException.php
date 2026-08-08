<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class MeliApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $requestId = null,
        public readonly array $response = []
    ) {
        parent::__construct($message);
    }
}
