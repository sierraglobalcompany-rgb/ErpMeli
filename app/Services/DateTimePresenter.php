<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use DateTimeImmutable;
use DateTimeZone;

final class DateTimePresenter
{
    public static function timezone(): string
    {
        $timezone = Env::get('APP_TIMEZONE', 'America/Bogota');
        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'America/Bogota';
    }

    public static function format(mixed $value, string $format = 'Y-m-d H:i:s'): string
    {
        if (!$value) {
            return '—';
        }
        try {
            return (new DateTimeImmutable((string) $value))->setTimezone(new DateTimeZone(self::timezone()))->format($format);
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    public static function formatQueue(mixed $value, string $format = 'Y-m-d H:i:s'): string
    {
        if (!$value) {
            return 'Ahora';
        }
        try {
            return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone(self::timezone()))
                ->format($format);
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    public static function monthName(int $month): string
    {
        return [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ][$month] ?? 'Mes';
    }
}
