<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class HttpRetryAfterParser
{
    public static function seconds(?string $value, ?int $now = null, int $maximum = 86400): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $maximum = max(1, $maximum);
        if (preg_match('/^\d+$/', $value) === 1) {
            return max(1, min($maximum, (int) $value));
        }
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
        $seconds = $date->getTimestamp() - ($now ?? time());
        return max(1, min($maximum, $seconds));
    }
}
