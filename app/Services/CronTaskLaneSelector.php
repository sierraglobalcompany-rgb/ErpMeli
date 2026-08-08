<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Reparte un ciclo corto entre trabajo urgente, campaña dirigida y trabajo local.
 *
 * El orden recibido ya viene puntuado por antigüedad. Este selector conserva
 * ese orden dentro de cada carril y evita que el backlog normal de API deje
 * indefinidamente sin turno a una campaña activa.
 */
final class CronTaskLaneSelector
{
    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    public function select(array $items, int $maxTasks, int $maxApiTasks): array
    {
        $maxTasks = max(1, min(10, $maxTasks));
        $maxApiTasks = max(0, min($maxTasks, $maxApiTasks));
        $lanes = [
            'urgent' => [],
            'directed' => [],
            'local' => [],
            'normal' => [],
        ];

        foreach ($items as $item) {
            $lane = (string) ($item['lane'] ?? (!empty($item['api']) ? 'normal' : 'local'));
            if (!isset($lanes[$lane])) {
                $lane = !empty($item['api']) ? 'normal' : 'local';
            }
            $lanes[$lane][] = $item;
        }
        $selected = [];
        $keys = [];
        $apiCount = 0;
        $append = static function (array $item) use (
            &$selected,
            &$keys,
            &$apiCount,
            $maxTasks,
            $maxApiTasks
        ): bool {
            $key = (string) ($item['key'] ?? '');
            if ($key === '' || isset($keys[$key]) || count($selected) >= $maxTasks) {
                return false;
            }
            if (!empty($item['api']) && $apiCount >= $maxApiTasks) {
                return false;
            }
            $selected[] = $item;
            $keys[$key] = true;
            if (!empty($item['api'])) {
                $apiCount++;
            }
            return true;
        };

        // El spool es local y acotado, pero no se agrega todavía: primero se
        // reserva la capacidad remota. Así no consume el tiempo dirigido.
        $spool = null;
        foreach ($lanes['local'] as $position => $item) {
            if ((string) ($item['key'] ?? '') !== 'notification_spool') {
                continue;
            }
            $spool = $item;
            unset($lanes['local'][$position]);
            break;
        }

        // Una campaña dirigida que ya perdió un ciclo saludable adquiere una
        // deuda explícita. La deuda prevalece incluso cuando la instalación
        // admite una sola tarea por invocación; de otro modo competir por
        // last_finished_at contra todas las colas puede dejarla sin servicio
        // durante decenas de ciclos.
        $directedDebt = array_values(array_filter(
            $lanes['directed'],
            static fn (array $item): bool => (int) ($item['_directed_missed_cycles'] ?? 0) > 0
        ));
        usort($directedDebt, static fn (array $left, array $right): int =>
            ((int) ($right['_directed_missed_cycles'] ?? 0) <=> (int) ($left['_directed_missed_cycles'] ?? 0))
            ?: (self::lastFinishedTimestamp($left) <=> self::lastFinishedTimestamp($right))
        );
        if ($maxApiTasks > 0 && isset($directedDebt[0])) {
            $debtKey = (string) ($directedDebt[0]['key'] ?? '');
            if ($append($directedDebt[0])) {
                $lanes['directed'] = array_values(array_filter(
                    $lanes['directed'],
                    static fn (array $item): bool => (string) ($item['key'] ?? '') !== $debtKey
                ));
            }
        }

        if (count($selected) >= $maxTasks) {
            return $selected;
        }

        // El cupo API es global: urgencias, campañas y tareas normales compiten
        // por el mismo límite. La tarea que lleva más tiempo sin finalizar gana;
        // el desempate conserva campaña, urgencia y trabajo normal en ese orden.
        $apiCandidates = array_merge($lanes['directed'], $lanes['urgent'], $lanes['normal']);
        usort($apiCandidates, static function (array $left, array $right): int {
            $leftFinished = self::lastFinishedTimestamp($left);
            $rightFinished = self::lastFinishedTimestamp($right);
            if ($leftFinished !== $rightFinished) {
                return $leftFinished <=> $rightFinished;
            }
            $laneRank = ['directed' => 0, 'urgent' => 1, 'normal' => 2];
            $leftLane = (string) ($left['lane'] ?? 'normal');
            $rightLane = (string) ($right['lane'] ?? 'normal');
            return (($laneRank[$leftLane] ?? 3) <=> ($laneRank[$rightLane] ?? 3))
                ?: ((int) ($right['_score'] ?? 0) <=> (int) ($left['_score'] ?? 0))
                ?: ((int) ($left['priority'] ?? 100) <=> (int) ($right['priority'] ?? 100));
        });

        // Con una ventana configurada para una sola tarea, el spool no puede
        // ocupar todos los ciclos ni quedar abandonado. Compite con el mejor
        // candidato API por la última finalización persistida; al completar
        // uno, el otro queda naturalmente primero en el ciclo siguiente.
        if ($maxTasks === 1 && $spool !== null) {
            $singleCandidates = [$spool];
            if ($maxApiTasks > 0 && isset($apiCandidates[0])) {
                $singleCandidates[] = $apiCandidates[0];
            }
            usort($singleCandidates, static function (array $left, array $right): int {
                $finished = self::lastFinishedTimestamp($left) <=> self::lastFinishedTimestamp($right);
                if ($finished !== 0) {
                    return $finished;
                }
                return ((string) ($left['key'] ?? '') === 'notification_spool' ? 0 : 1)
                    <=> ((string) ($right['key'] ?? '') === 'notification_spool' ? 0 : 1);
            });
            $append($singleCandidates[0]);
            return $selected;
        }

        // Solo fijamos la ronda completa cuando realmente caben los tres
        // carriles remotos después del trabajo local ya admitido. Con uno o
        // dos cupos disponibles debe regir la antigüedad global; de lo
        // contrario urgencias ocuparía siempre el único hueco y una campaña o
        // cola normal podría quedar sin servicio indefinidamente.
        $availableApiSlots = min(
            max(0, $maxApiTasks - $apiCount),
            max(0, $maxTasks - count($selected) - ($spool !== null ? 1 : 0))
        );
        if ($availableApiSlots >= 3) {
            foreach (['directed', 'urgent', 'normal'] as $lane) {
                if (isset($lanes[$lane][0])) {
                    $append($lanes[$lane][0]);
                }
            }
        }
        foreach ($apiCandidates as $item) {
            if (count($selected) >= $maxTasks - ($spool !== null ? 1 : 0) || $apiCount >= $maxApiTasks) {
                break;
            }
            $append($item);
        }

        if ($spool !== null && count($selected) < $maxTasks) {
            $append($spool);
        }

        // Los trabajos locales aprovechan el ciclo sin consumir presupuesto API.
        foreach ($lanes['local'] as $item) {
            if (count($selected) >= $maxTasks) {
                break;
            }
            $append($item);
        }

        return $selected;
    }

    /** @param array<string,mixed> $item */
    private static function lastFinishedTimestamp(array $item): int
    {
        $value = $item['_last_finished_at']
            ?? ($item['runtime_state']['last_finished_at'] ?? null);
        if (!is_string($value) || trim($value) === '') {
            return 0;
        }
        return (new SystemDatabaseUtcClock())->timestamp($value) ?? 0;
    }
}
