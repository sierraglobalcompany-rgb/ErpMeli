<?php

declare(strict_types=1);

namespace App\Work;

final readonly class DrainAuthorityToken
{
    public function __construct(
        public string $drainerId,
        public string $ownerToken,
        public int $generation,
        public int $leaseSeconds,
        public string $adapter,
    ) {
    }
}
