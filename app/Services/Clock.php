<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

final class Clock
{
    private DateTimeZone $utc;
    private DateTimeZone $local;

    public function __construct(?string $timezone = null)
    {
        $this->utc = new DateTimeZone('UTC');
        $this->local = new DateTimeZone($timezone ?: DateTimePresenter::timezone());
    }

    public function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->utc);
    }

    public function nowLocal(): DateTimeImmutable
    {
        return $this->nowUtc()->setTimezone($this->local);
    }

    public function localToUtc(DateTimeImmutable $value): DateTimeImmutable
    {
        return $value->setTimezone($this->utc);
    }

    public function utcToLocal(DateTimeImmutable $value): DateTimeImmutable
    {
        return $value->setTimezone($this->local);
    }

    public function localTimezone(): DateTimeZone
    {
        return $this->local;
    }
}
