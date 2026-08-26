<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class ManualRemoteCallLimitException extends RuntimeException
{
    public function __construct(
        string $message = 'La siguiente consulta continuará después del intervalo configurado.',
        public readonly ?string $nextSafeAt = null
    ) {
        parent::__construct($message);
    }
}
