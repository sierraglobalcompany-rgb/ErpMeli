<?php

declare(strict_types=1);

namespace App\ValueObjects;

final class PayloadContext
{
    public function __construct(
        public readonly string $entityTable,
        public readonly int $entityId,
        public readonly int $accountId,
    ) {
    }
}
