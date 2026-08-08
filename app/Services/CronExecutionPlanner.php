<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Selecciona trabajo de Cron de forma incremental.
 *
 * La campaña dirigida se mide y reclama antes de sondear el resto de colas.
 * Después de cada resultado el llamador vuelve a medir exclusivamente la cola
 * ejecutada; nunca se publica una selección antes de obtener el claim cercado.
 */
final class CronExecutionPlanner
{
    /** @var array<string,array<string,mixed>> */
    private array $definitions = [];
    /** @var array<string,true> */
    private array $exhausted = [];
    /** @var array<string,int> */
    private array $claimsByKey = [];
    /** @var list<string> */
    private array $candidateKeys = [];
    /** @var list<string> */
    private array $claimedKeys = [];
    /** @var array<string,true> */
    private array $observedKeys = [];
    /** @var list<string> */
    private array $laneOrder = ['directed', 'urgent', 'normal', 'local'];
    private int $laneCursor = 0;
    private int $candidateCount = 0;
    private int $claimCount = 0;
    /** @var array<string,string> */
    private array $lastNotStartedReasons = [];

    /**
     * @param list<array<string,mixed>> $definitions
     */
    public function __construct(
        array $definitions,
        private readonly CronTaskStateService $taskState,
        private readonly CronWorkAvailabilityService $availability,
        private readonly CronLaneBudgetService $laneBudgets,
        private readonly int $maxClaims,
        private readonly bool $backlogAware = true
    ) {
        foreach ($definitions as $definition) {
            $key = (string) ($definition['key'] ?? '');
            if ($key !== '') {
                $this->definitions[$key] = $definition;
            }
        }
    }

    /**
     * @return array{state:string,definition:array<string,mixed>,reason:?string}|null
     */
    public function next(string $runToken): ?array
    {
        if ($this->claimCount >= max(1, min(40, $this->maxClaims))) {
            return null;
        }

        while (true) {
            $pool = $this->nextPool();
            if ($pool === []) {
                return null;
            }
            $keys = array_keys($pool);
            $measurements = $this->backlogAware
                ? $this->availability->snapshotCached($keys)
                : [];
            foreach ($keys as $observedKey) {
                $this->observedKeys[$observedKey] = true;
            }
            $measured = [];
            foreach ($pool as $key => $definition) {
                $measured[] = array_merge($definition, $measurements[$key] ?? [
                    'known' => !$this->backlogAware,
                    'measurement_state' => $this->backlogAware ? 'unavailable' : 'complete',
                    'work_count' => $this->backlogAware ? 0 : 1,
                    'eligible_count' => $this->backlogAware ? 0 : 1,
                    'total_pending' => $this->backlogAware ? 0 : 1,
                    'oldest_due_at' => null,
                ]);
            }

            // due(..., 1, 1) solo propone un candidato. El claim ocurre debajo
            // y es lo que convierte la propuesta en una selección real.
            $due = $this->taskState->due($measured, 1, 1);
            $this->advanceLane($pool);
            if ($due === []) {
                foreach ($keys as $key) {
                    $this->exhausted[$key] = true;
                }
                continue;
            }

            $definition = $due[0];
            $key = (string) $definition['key'];
            $this->candidateCount++;
            $this->candidateKeys[] = $key;
            $lane = (string) ($definition['lane'] ?? (!empty($definition['api']) ? 'normal' : 'local'));
            $budgetLane = $key === 'notification_spool' ? 'spool' : $lane;

            if (!$this->laneBudgets->canStart($budgetLane)) {
                // Una cola que no cabe no puede cerrar todo el ciclo: otras
                // funciones más cortas pueden aprovechar el minuto y descargar
                // backlog. Solo agotamos esta función en esta invocación y la
                // dejamos trazada como planned_not_started.
                $this->exhausted[$key] = true;
                $this->lastNotStartedReasons[$key] = 'not_started_deadline';
                $this->taskState->notStarted($key, 'not_started_deadline', $lane === 'directed');
                return [
                    'state' => 'not_started',
                    'definition' => $definition,
                    'reason' => 'not_started_deadline',
                ];
            }
            if (!$this->taskState->claim($key, $runToken, $lane === 'directed')) {
                // Un claim perdido no debe crear un bucle caliente dentro de
                // la misma invocación. Otro proceso posee esa función.
                $this->exhausted[$key] = true;
                $this->lastNotStartedReasons[$key] = 'claim_lost';
                $this->taskState->notStarted($key, 'claim_lost', $lane === 'directed');
                return [
                    'state' => 'not_started',
                    'definition' => $definition,
                    'reason' => 'claim_lost',
                ];
            }

            $this->claimCount++;
            $this->claimsByKey[$key] = ($this->claimsByKey[$key] ?? 0) + 1;
            $this->claimedKeys[] = $key;
            return ['state' => 'claimed', 'definition' => $definition, 'reason' => null];
        }
    }

    /** @return array<string,mixed> */
    public function remeasure(string $queueKey): array
    {
        return $this->availability->snapshotCached([$queueKey], true)[$queueKey] ?? [
            'known' => false,
            'measurement_state' => 'unavailable',
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public function availabilitySnapshot(): array
    {
        return $this->availability->snapshotCached(array_keys($this->definitions));
    }

    /**
     * Devuelve únicamente las colas realmente observadas por este planner.
     * El entrypoint puede persistir este delta después de cada ciclo y dejar
     * el checkpoint global para una cadencia separada, sin volver a sondear
     * todas las definiciones en el camino crítico.
     *
     * @return array<string,array<string,mixed>>
     */
    public function observedAvailabilitySnapshot(bool $force = false): array
    {
        $keys = array_keys($this->observedKeys);
        if ($keys === []) {
            return [];
        }
        return $this->availability->snapshotCached($keys, $force);
    }

    public function candidateCount(): int
    {
        return $this->candidateCount;
    }

    /** @return array<string,string> */
    public function notStartedReasons(): array
    {
        return $this->lastNotStartedReasons;
    }

    /** @return list<string> */
    public function candidateKeys(): array
    {
        return $this->candidateKeys;
    }

    /** @return list<string> */
    public function claimedKeys(): array
    {
        return $this->claimedKeys;
    }

    /** @return array<string,array<string,mixed>> */
    private function nextPool(): array
    {
        $remaining = array_filter(
            $this->definitions,
            fn (array $definition, string $key): bool => !isset($this->exhausted[$key])
                && ($this->claimsByKey[$key] ?? 0) < max(1, min(40, $this->maxClaims)),
            ARRAY_FILTER_USE_BOTH
        );
        if ($remaining === []) {
            return [];
        }
        $laneCount = count($this->laneOrder);
        for ($offset = 0; $offset < $laneCount; $offset++) {
            $index = ($this->laneCursor + $offset) % $laneCount;
            $lane = $this->laneOrder[$index];
            $pool = array_filter(
                $remaining,
                fn (array $definition): bool => $this->lane($definition) === $lane
            );
            if ($pool !== []) {
                $this->laneCursor = $index;
                return $pool;
            }
        }
        return [];
    }

    /** @param array<string,array<string,mixed>> $pool */
    private function advanceLane(array $pool): void
    {
        if ($pool === []) {
            return;
        }
        $lane = $this->lane(reset($pool));
        $index = array_search($lane, $this->laneOrder, true);
        $this->laneCursor = $index === false ? 0 : (($index + 1) % count($this->laneOrder));
    }

    /** @param array<string,mixed> $definition */
    private function lane(array $definition): string
    {
        $lane = (string) ($definition['lane'] ?? (!empty($definition['api']) ? 'normal' : 'local'));
        return in_array($lane, $this->laneOrder, true) ? $lane : 'normal';
    }

}
