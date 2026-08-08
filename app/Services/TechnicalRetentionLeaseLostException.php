<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class TechnicalRetentionLeaseLostException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('El lease cercado de retención dejó de pertenecer a esta ejecución.');
    }
}
