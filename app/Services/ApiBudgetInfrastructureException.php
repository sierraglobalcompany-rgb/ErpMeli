<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

final class ApiBudgetInfrastructureException extends RuntimeException
{
    public function __construct(string $message = 'No fue posible verificar el presupuesto preventivo de la API.', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
