<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use RuntimeException;

final class WriteGuard
{
    public static function assertAllowed(bool $mutation): void
    {
        if ($mutation && !Env::bool('ML_WRITE_ENABLED', false)) {
            throw new RuntimeException('Operación Mercado Libre bloqueada: ML_WRITE_ENABLED=false.');
        }
    }
}
