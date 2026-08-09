<?php
declare(strict_types=1);

namespace App\QueueCore;

use RuntimeException;

final class ManualFifoBusyException extends RuntimeException
{
    public const REASON='MANUAL_FIFO_BUSY';

    public function __construct()
    {
        parent::__construct(self::REASON.': Existe un paso manual anterior. Revíselo antes de iniciar otro.');
    }
}
