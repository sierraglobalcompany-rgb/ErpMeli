<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Services\Clock;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DateRange
{
    public function __construct(
        public DateTimeImmutable $fromUtc,
        public DateTimeImmutable $toUtc,
        public string $fromLocal,
        public string $toLocal
    ) {
        if ($toUtc <= $fromUtc) {
            throw new InvalidArgumentException('El fin del rango debe ser posterior al inicio.');
        }
    }

    public static function fromLocalDates(string $from, string $to, Clock $clock): self
    {
        if (
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1
        ) {
            throw new InvalidArgumentException('El rango de fechas no es válido.');
        }
        $fromLocal = new DateTimeImmutable($from . ' 00:00:00', $clock->localTimezone());
        $toExclusiveLocal = (new DateTimeImmutable($to . ' 00:00:00', $clock->localTimezone()))->modify('+1 day');
        return new self(
            $clock->localToUtc($fromLocal),
            $clock->localToUtc($toExclusiveLocal),
            $from,
            $to
        );
    }

    /** @return array{from:string,to:string} */
    public function sqlBindings(): array
    {
        return [
            'from' => $this->fromUtc->format('Y-m-d H:i:s'),
            'to' => $this->toUtc->format('Y-m-d H:i:s'),
        ];
    }
}
