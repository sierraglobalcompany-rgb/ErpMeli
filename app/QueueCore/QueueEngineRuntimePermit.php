<?php

declare(strict_types=1);

namespace App\QueueCore;

final readonly class QueueEngineRuntimePermit
{
    public function __construct(
        public string $engine,
        public string $lane,
        public int $generation,
        public string $lockName,
    ) {
    }
}
