<?php

declare(strict_types=1);

namespace App\ValueObjects;

final class QueryTrace
{
    /**
     * @param array<string,int> $sessionCounters
     */
    public function __construct(
        public readonly string $routeHash,
        public readonly string $routeKey,
        public readonly float $startedAt,
        public readonly array $sessionCounters = [],
    ) {
    }
}
