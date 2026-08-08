<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class SystemDatabaseUtcClock implements DatabaseUtcClock
{
    private DateTimeZone $utc;
    private DateTimeZone $bogota;

    public function __construct()
    {
        $this->utc = new DateTimeZone('UTC');
        $this->bogota = new DateTimeZone(DateTimePresenter::timezone());
    }

    public function timestamp(?string $databaseValue): ?int
    {
        $value = trim((string) $databaseValue);
        if ($value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value, $this->utc))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    public function isDue(?string $databaseValue): bool
    {
        $timestamp = $this->timestamp($databaseValue);
        return $timestamp === null || $timestamp <= time();
    }

    public function toBogota(?string $databaseValue, string $format = 'd/m/Y H:i:s'): ?string
    {
        $value = trim((string) $databaseValue);
        if ($value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value, $this->utc))->setTimezone($this->bogota)->format($format);
        } catch (Throwable) {
            return null;
        }
    }
}
