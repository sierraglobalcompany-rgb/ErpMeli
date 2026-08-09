<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;

/**
 * Añade correlación a las consultas ejecutadas por un worker CLI.
 *
 * El contexto vive únicamente durante la llamada proporcionada y siempre se
 * restaura en finally. No contiene tokens, payloads ni datos comerciales.
 */
final class ApiExecutionMetadataContext
{
    /** @var array<string,scalar|null> */
    private static array $current = [];
    private static int $remoteCalls = 0;
    private static int $remoteAttempts = 0;
    private static int $remoteDispatches = 0;
    private static int $remoteBlocked = 0;
    private static int $knownResponses = 0;
    private static int $resourcesReceived = 0;

    /** @return array<string,scalar|null> */
    public static function current(): array
    {
        return self::$current;
    }

    /**
     * @template T
     * @param array<string,scalar|null> $metadata
     * @param callable():T $callback
     * @return T
     * @throws Throwable
     */
    public static function run(array $metadata, callable $callback): mixed
    {
        $previous = self::$current;
        $previousCalls = self::$remoteCalls;
        self::$current = array_replace(self::$current, $metadata);
        if (in_array((string) ($metadata['source'] ?? ''), ['queue_core', 'manual_campaign', 'cron_v3_remote', 'manual_emergency_canary', 'manual_emergency_oauth_refresh'], true)) {
            self::$remoteCalls = 0;
        }
        try {
            return $callback();
        } finally {
            self::$current = $previous;
            self::$remoteCalls = $previousCalls;
        }
    }

    /**
     * Añade metadata autoritativa del transporte sin reiniciar el contador de
     * llamadas del trabajo exterior. Se usa para ligar el token/cuenta real
     * del cliente a la última barrera física.
     *
     * @template T
     * @param array<string,scalar|null> $metadata
     * @param callable():T $callback
     * @return T
     * @throws Throwable
     */
    public static function withTransportMetadata(array $metadata, callable $callback): mixed
    {
        $previous = self::$current;
        self::$current = array_replace(self::$current, $metadata);
        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }

    public static function claimRemoteCall(): void
    {
        if (!in_array((string) (self::$current['source'] ?? ''), ['queue_core', 'manual_campaign', 'cron_v3_remote', 'manual_emergency_canary', 'manual_emergency_oauth_refresh'], true)) {
            return;
        }
        $maximum = 1;
        if (self::$remoteCalls >= $maximum) {
            throw new ManualRemoteCallLimitException(
                'La siguiente consulta continuará después del intervalo configurado.'
            );
        }
        self::$remoteCalls++;
    }

    public static function markRemoteAttempted(): void
    {
        self::$remoteAttempts++;
    }

    public static function markRemoteBlocked(): void
    {
        self::$remoteBlocked++;
    }

    /**
     * Registra únicamente transportes que realmente alcanzaron el punto de
     * salida. Una reserva rechazada por presupuesto o protección no cuenta.
     */
    public static function markRemoteDispatched(): void
    {
        self::$remoteDispatches++;
    }

    /** Registra una respuesta HTTP conocida sin confundirla con el despacho. */
    public static function markRemoteResponseKnown(int $resourcesReceived = 0): void
    {
        self::$knownResponses++;
        self::$resourcesReceived += max(0, $resourcesReceived);
    }

    public static function remoteDispatchCount(): int
    {
        return self::$remoteDispatches;
    }

    public static function remoteAttemptCount(): int
    {
        return self::$remoteAttempts;
    }

    public static function remoteBlockedCount(): int
    {
        return self::$remoteBlocked;
    }

    public static function knownResponseCount(): int
    {
        return self::$knownResponses;
    }

    public static function resourcesReceivedCount(): int
    {
        return self::$resourcesReceived;
    }

    public static function resetRemoteDispatchCount(): void
    {
        self::$remoteAttempts = 0;
        self::$remoteDispatches = 0;
        self::$remoteBlocked = 0;
        self::$knownResponses = 0;
        self::$resourcesReceived = 0;
    }
}
