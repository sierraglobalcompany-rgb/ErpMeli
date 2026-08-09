<?php
declare(strict_types=1);

namespace App\QueueCore;

use RuntimeException;

/** A local fence proved that cURL never started. */
final class QueueCorePreRemoteBlockedException extends RuntimeException
{
}
