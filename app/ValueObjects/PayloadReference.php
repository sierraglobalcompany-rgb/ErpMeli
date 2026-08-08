<?php

declare(strict_types=1);

namespace App\ValueObjects;

final class PayloadReference
{
    public function __construct(
        public readonly int $objectId,
        public readonly string $sha256,
        public readonly string $storageName,
        public readonly int $originalBytes,
        public readonly int $storedBytes,
    ) {
    }
}
