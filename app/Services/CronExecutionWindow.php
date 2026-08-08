<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Reloj inmutable de una invocación CLI.
 *
 * El planner, los carriles y el transporte deben compartir esta misma
 * instancia. Crear un segundo microtime() durante el ciclo vuelve a conceder
 * tiempo que el deadline global ya consumió.
 */
final class CronExecutionWindow
{
    private readonly float $startedAt;
    private readonly float $acceptUntil;
    private readonly float $closeAt;
    private readonly float $deadline;

    public function __construct(
        int $runtimeSeconds,
        int $acceptWorkSeconds,
        ?float $startedAt = null
    ) {
        $runtimeSeconds = max(5, min(120, $runtimeSeconds));
        $acceptWorkSeconds = max(1, min($runtimeSeconds - 1, $acceptWorkSeconds));
        $this->startedAt = $startedAt ?? microtime(true);
        $this->deadline = $this->startedAt + $runtimeSeconds;
        $this->acceptUntil = $this->startedAt + $acceptWorkSeconds;
        // closeAt marca el inicio del cierre seguro. deadline es el límite
        // duro posterior; mantener ambos nombres con el mismo valor ocultaba
        // los diez segundos reservados para persistir y liberar leases.
        $this->closeAt = $this->acceptUntil;
    }

    public function startedAt(): float
    {
        return $this->startedAt;
    }

    public function acceptUntil(): float
    {
        return $this->acceptUntil;
    }

    public function closeAt(): float
    {
        return $this->closeAt;
    }

    public function deadline(): float
    {
        return $this->deadline;
    }

    public function canAcceptWork(int $reserveSeconds = 1): bool
    {
        return microtime(true) + max(0, $reserveSeconds) < $this->acceptUntil;
    }

    public function canStartRemote(float $minimumSeconds = 2.0, ?float $deadline = null): bool
    {
        $effectiveDeadline = min($this->acceptUntil, $deadline ?? $this->deadline);
        return microtime(true) + max(0.1, $minimumSeconds) < $effectiveDeadline;
    }

    public function remainingSeconds(?float $deadline = null): float
    {
        return max(0.0, min($this->deadline, $deadline ?? $this->deadline) - microtime(true));
    }

    /** @return array<string,int|float> */
    public function snapshot(): array
    {
        return [
            'started_at_unix' => $this->startedAt,
            'accept_until_unix' => $this->acceptUntil,
            'close_at_unix' => $this->closeAt,
            'deadline_unix' => $this->deadline,
            'remaining_ms' => (int) floor($this->remainingSeconds() * 1000),
        ];
    }
}
