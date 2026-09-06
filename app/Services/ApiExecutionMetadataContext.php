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
    private static ?string $technicalOperation = null;

    /** Process-local capability installed only by an authorized technical launcher. */
    public static function withTechnicalOperation(string $operation, callable $callback): mixed
    {
        if (!in_array($operation,['readiness','emergency_canary','emergency_oauth','initial_oauth','oauth_profile'],true)) {
            throw new \RuntimeException('technical_transport_operation_invalid');
        }
        $previous = self::$technicalOperation;
        self::$technicalOperation = $operation;
        try { return $callback(); }
        finally { self::$technicalOperation = $previous; }
    }

    public static function technicalOperation(): ?string
    {
        return self::$technicalOperation;
    }

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
        $previousManualLimit = self::manualPhysicalHttpLimitFrom($previous);
        self::$current = array_replace(self::$current, $metadata);
        $currentManualLimit = self::manualPhysicalHttpLimit();
        if (($currentManualLimit > 0 && $previousManualLimit < 1)
            || ($currentManualLimit < 1 && MeliTransportSourcePolicy::isSingleDispatch((string) ($metadata['source'] ?? '')))) {
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
        if (\App\QueueV4Clean\QueueV4CleanCycleBudget::snapshot()['limit'] > 0) {
            // The outer physical owner is authoritative; metadata is not a
            // second counter and cannot charge a request that never reached cURL.
            \App\QueueV4Clean\QueueV4CleanCycleBudget::assertActive();
            return;
        }
        $manualMaximum = self::manualPhysicalHttpLimit();
        if ($manualMaximum > 0) {
            if (self::$remoteCalls >= $manualMaximum) {
                throw new ManualRemoteCallLimitException(
                    'La siguiente consulta continuará después del intervalo configurado.',
                    self::manualNextSafeAt()
                );
            }
            self::$remoteCalls++;
            return;
        }
        if (!MeliTransportSourcePolicy::isSingleDispatch((string) (self::$current['source'] ?? ''))) {
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

    private static function manualPhysicalHttpLimit(): int
    {
        return self::manualPhysicalHttpLimitFrom(self::$current);
    }

    /** @param array<string,scalar|null> $metadata */
    private static function manualPhysicalHttpLimitFrom(array $metadata): int
    {
        return max(0, (int) ($metadata['manual_physical_http_burst_limit'] ?? 0));
    }

    private static function manualNextSafeAt(): ?string
    {
        $value = self::$current['manual_next_safe_at'] ?? null;
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
