<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

final class MeliDateRangeService
{
    /**
     * @return array{local_from:DateTimeImmutable,local_to:DateTimeImmutable,utc_from:DateTimeImmutable,utc_to:DateTimeImmutable,timezone:string}
     */
    public function localMonth(int $year, int $month): array
    {
        $tz = new DateTimeZone(DateTimePresenter::timezone());
        $from = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
        return $this->fromLocal($from, $from->modify('first day of next month'));
    }

    /**
     * @return array{local_from:DateTimeImmutable,local_to:DateTimeImmutable,utc_from:DateTimeImmutable,utc_to:DateTimeImmutable,timezone:string}
     */
    public function localDay(string $date): array
    {
        $tz = new DateTimeZone(DateTimePresenter::timezone());
        $from = new DateTimeImmutable($date . ' 00:00:00', $tz);
        return $this->fromLocal($from, $from->modify('+1 day'));
    }

    /**
     * @return array{local_from:DateTimeImmutable,local_to:DateTimeImmutable,utc_from:DateTimeImmutable,utc_to:DateTimeImmutable,timezone:string}
     */
    public function fromLocal(DateTimeImmutable $localFrom, DateTimeImmutable $localTo): array
    {
        $tz = new DateTimeZone(DateTimePresenter::timezone());
        $localFrom = $localFrom->setTimezone($tz);
        $localTo = $localTo->setTimezone($tz);
        return [
            'local_from' => $localFrom,
            'local_to' => $localTo,
            'utc_from' => $localFrom->setTimezone(new DateTimeZone('UTC')),
            'utc_to' => $localTo->setTimezone(new DateTimeZone('UTC')),
            'timezone' => $tz->getName(),
        ];
    }
}
