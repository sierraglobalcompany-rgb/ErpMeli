<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

/** Canal efímero y no observable para la reserva OAuth de emergencia. */
final class EmergencyOAuthRefreshTransportContext
{
    private static ?string $reservationNonce = null;

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     * @throws Throwable
     */
    public static function run(string $reservationNonce, callable $callback): mixed
    {
        if ($reservationNonce === '') {
            throw new RuntimeException('La reserva OAuth privada no está disponible.');
        }
        $previous = self::$reservationNonce;
        self::$reservationNonce = $reservationNonce;
        try {
            return $callback();
        } finally {
            self::$reservationNonce = $previous;
        }
    }

    /** Únicamente la barrera física puede leer este valor. */
    public static function reservationNonce(): string
    {
        return self::$reservationNonce ?? '';
    }
}
