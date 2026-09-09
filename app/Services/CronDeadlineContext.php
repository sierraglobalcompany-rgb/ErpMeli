<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Presupuesto compartido del proceso CLI actual.
 *
 * Los servicios pueden consultar este contexto sin acoplarse al script que
 * inició el cron. Fuera de cron permanece inactivo y no altera requests web.
 */
final class CronDeadlineContext
{
    private static ?CronExecutionWindow $window = null;
    private static ?float $scopedDeadline = null;
    private static int $apiTimeout = 8;
    private static int $connectTimeout = 3;

    public static function start(
        int $runtimeSeconds,
        int $acceptWorkSeconds,
        int $apiTimeoutSeconds,
        int $connectTimeoutSeconds
    ): void {
        self::$window = new CronExecutionWindow($runtimeSeconds, $acceptWorkSeconds);
        self::$apiTimeout = max(2, min(20, $apiTimeoutSeconds));
        self::$connectTimeout = max(1, min(self::$apiTimeout, $connectTimeoutSeconds));
    }

    public static function active(): bool
    {
        return self::$window !== null;
    }

    public static function window(): ?CronExecutionWindow
    {
        return self::$window;
    }

    public static function deadline(): ?float
    {
        return self::$scopedDeadline ?? self::$window?->deadline();
    }

    public static function canAcceptWork(int $reserveSeconds = 1): bool
    {
        return self::$window === null || self::$window->canAcceptWork($reserveSeconds);
    }

    public static function remainingSeconds(): float
    {
        $deadline = self::deadline();
        return $deadline === null ? INF : max(0.0, $deadline - microtime(true));
    }

    public static function canStartRemote(float $minimumSeconds = 2.0, ?float $deadline = null): bool
    {
        $effectiveDeadline = $deadline;
        if (self::deadline() !== null) {
            $effectiveDeadline = $effectiveDeadline === null
                ? self::deadline()
                : min($effectiveDeadline, (float) self::deadline());
        }
        $acceptUntil = self::$window?->acceptUntil();
        if ($acceptUntil !== null) {
            $effectiveDeadline = $effectiveDeadline === null
                ? $acceptUntil
                : min($effectiveDeadline, $acceptUntil);
        }
        return $effectiveDeadline === null
            || microtime(true) + max(0.1, $minimumSeconds) < $effectiveDeadline;
    }

    /**
     * @throws CronDeadlineDeferredException
     */
    public static function assertCanStartRemote(float $minimumSeconds = 2.0, ?float $deadline = null): void
    {
        if (self::canStartRemote($minimumSeconds, $deadline)) {
            return;
        }
        throw new CronDeadlineDeferredException(
            nextSafeAt: gmdate('Y-m-d H:i:s', time() + 5)
        );
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function within(float $deadline, callable $callback): mixed
    {
        $previous = self::$scopedDeadline;
        $globalDeadline = self::deadline();
        self::$scopedDeadline = $globalDeadline === null ? $deadline : min($globalDeadline, $deadline);
        try {
            return $callback();
        } finally {
            self::$scopedDeadline = $previous;
        }
    }

    /** @return array{timeout:int,connect_timeout:int} */
    public static function curlTimeouts(): array
    {
        if (!self::active() && self::deadline() === null) {
            return ['timeout' => 25, 'connect_timeout' => 8];
        }
        $transportDeadline = self::deadline();
        $acceptUntil = self::$window?->acceptUntil();
        if ($acceptUntil !== null) {
            $transportDeadline = $transportDeadline === null
                ? $acceptUntil
                : min($transportDeadline, $acceptUntil);
        }
        $remaining = $transportDeadline === null
            ? PHP_INT_MAX
            : (int) floor(max(0.0, $transportDeadline - microtime(true)));
        if ($remaining < 2) {
            throw new CronDeadlineDeferredException(
                nextSafeAt: gmdate('Y-m-d H:i:s', time() + 5)
            );
        }
        $timeout = max(1, min(self::$apiTimeout, $remaining - 1));
        return [
            'timeout' => $timeout,
            'connect_timeout' => max(1, min(self::$connectTimeout, $timeout)),
        ];
    }

    /** @return array<string,int|float|null> */
    public static function snapshot(): array
    {
        return [
            'started_at_unix' => self::$window?->startedAt(),
            'deadline_unix' => self::$window?->deadline(),
            'accept_until_unix' => self::$window?->acceptUntil(),
            'close_at_unix' => self::$window?->closeAt(),
            'remaining_ms' => is_finite(self::remainingSeconds())
                ? (int) floor(self::remainingSeconds() * 1000)
                : null,
        ];
    }

    public static function clear(): void
    {
        self::$window = null;
        self::$scopedDeadline = null;
    }
}
