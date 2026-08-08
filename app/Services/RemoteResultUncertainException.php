<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * El transporte ocurrió, pero el propietario perdió su permiso antes de poder
 * aprobar el resultado. No debe reintentarse automáticamente.
 */
final class RemoteResultUncertainException extends RuntimeException
{
    public function __construct(
        public readonly string $requestId,
        public readonly ?int $httpStatus = null
    ) {
        parent::__construct(
            'Mercado Libre respondió, pero el resultado requiere revisión porque venció el permiso local.'
        );
    }
}
