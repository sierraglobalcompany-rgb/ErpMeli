<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class OAuthRefreshRequiredException extends RuntimeException
{
    public function __construct(public readonly int $accountId)
    {
        parent::__construct('La autorizacion requiere un trabajo OAuth independiente antes de continuar.');
    }
}
