<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $publicMessage,
        public readonly bool $safe = true
    ) {
        parent::__construct($publicMessage);
    }
}
