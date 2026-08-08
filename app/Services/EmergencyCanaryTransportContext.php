<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;

/**
 * Canal efímero y no observable para el secreto de la reserva canaria.
 *
 * Este contexto nunca se mezcla con ApiExecutionMetadataContext: no llega a
 * presupuesto, ritmo, telemetría, journal, Logger ni mensajes públicos.
 */
final class EmergencyCanaryTransportContext
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
            throw new \RuntimeException('La reserva canaria privada no está disponible.');
        }
        $previous = self::$reservationNonce;
        self::$reservationNonce = $reservationNonce;
        try {
            return $callback();
        } finally {
            self::$reservationNonce = $previous;
        }
    }

    /** Únicamente la barrera física debe leer este valor. */
    public static function reservationNonce(): string
    {
        return self::$reservationNonce ?? '';
    }
}
