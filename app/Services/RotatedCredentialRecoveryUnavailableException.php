<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * La respuesta OAuth fue conocida, pero ni el escrow durable ni MariaDB
 * pudieron conservar las credenciales rotadas. Nunca debe reintentarse HTTP.
 */
final class RotatedCredentialRecoveryUnavailableException extends RuntimeException
{
}
