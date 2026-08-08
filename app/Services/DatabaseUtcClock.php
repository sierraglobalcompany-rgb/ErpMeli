<?php

declare(strict_types=1);

namespace App\Services;

interface DatabaseUtcClock
{
    public function timestamp(?string $databaseValue): ?int;

    public function isDue(?string $databaseValue): bool;

    public function toBogota(?string $databaseValue, string $format = 'd/m/Y H:i:s'): ?string;
}
