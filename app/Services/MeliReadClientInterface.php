<?php

declare(strict_types=1);

namespace App\Services;

interface MeliReadClientInterface
{
    /** @return array<string,mixed> */
    public function get(string $path, array $query = [], array $meta = []): array;
}
