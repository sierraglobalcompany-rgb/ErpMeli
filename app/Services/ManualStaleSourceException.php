<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class ManualStaleSourceException extends RuntimeException
{
    public const REASON='STALE_SOURCE';

    public function __construct()
    {
        parent::__construct(self::REASON.': La fuente cambió antes de ejecutar el paso.');
    }
}
