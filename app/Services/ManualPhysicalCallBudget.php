<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Physical HTTP capacity is independent of resource batch sizes. */
final class ManualPhysicalCallBudget
{
    /**
     * Explicit technical launchers share the physical counter with any caller.
     * A whole technical contract must fit before its callback can mutate state.
     * @template T @param callable():T $callback @return T
     */
    public static function withinTechnical(int $necessaryCalls, callable $callback): mixed
    {
        if ($necessaryCalls < 1 || $necessaryCalls > 100) {
            throw new RuntimeException('physical_budget_technical_contract_invalid');
        }
        $state = \App\QueueV4Clean\QueueV4CleanCycleBudget::snapshot();
        $owned = $state['limit'] === 0;
        $deadline = min(microtime(true) + 45, $state['deadline'] ?? INF, CronDeadlineContext::deadline() ?? INF);
        if ($owned) {
            $policy = (new CapacityPolicyService())->snapshot('manual');
            $limit = min($necessaryCalls, (int) $policy['current'], (int) $policy['ceiling']);
            if ($limit < $necessaryCalls) {
                throw new RuntimeException('physical_budget_insufficient');
            }
            \App\QueueV4Clean\QueueV4CleanCycleBudget::start($limit, 'manual', $deadline);
        }
        try {
            return CronDeadlineContext::within($deadline, static function () use ($necessaryCalls, $callback): mixed {
                \App\QueueV4Clean\QueueV4CleanCycleBudget::assertActive();
                if (\App\QueueV4Clean\QueueV4CleanCycleBudget::remaining() < $necessaryCalls) {
                    throw new RuntimeException('physical_budget_insufficient');
                }
                return $callback();
            });
        } finally {
            if ($owned) {
                \App\QueueV4Clean\QueueV4CleanCycleBudget::clear();
            }
        }
    }

    /** @return array{physical_api_call_budget:int,capacity_revision:string} */
    public static function previewConfiguration(array $configuration, array $policy): array
    {
        $value = $configuration['physical_api_call_budget'] ?? $policy['current'];
        if (!(is_int($value) || (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1))
            || (int) $value < 1 || (int) $value > min(CapacityPolicyService::TECHNICAL_MAX, (int) $policy['current'], (int) $policy['ceiling'])) {
            throw new RuntimeException('La capacidad solicitada supera la política manual vigente o no es válida. Vuelva a calcular.');
        }
        if (isset($configuration['capacity_revision'])
            && !hash_equals((string) $policy['revision'], (string) $configuration['capacity_revision'])) {
            throw new RuntimeException('La capacidad manual cambió. Vuelva a calcular los trabajos disponibles.');
        }
        return ['physical_api_call_budget'=>(int) $value, 'capacity_revision'=>(string) $policy['revision']];
    }

    public static function resolve(array $configuration, int $requested, array $policy): int
    {
        if ((int) ($configuration['preview_format'] ?? 0) !== 4
            || !is_int($configuration['physical_api_call_budget'] ?? null)
            || !is_string($configuration['capacity_revision'] ?? null)
            || $configuration['capacity_revision'] === '') {
            throw new RuntimeException('El cálculo no certifica la capacidad física manual. Vuelva a calcular los trabajos disponibles.');
        }
        $previewBudget = $configuration['physical_api_call_budget'];
        $current = min(CapacityPolicyService::TECHNICAL_MAX, (int) $policy['current'], (int) $policy['ceiling']);
        if ($previewBudget < 1 || $previewBudget > $current) {
            throw new RuntimeException('La capacidad manual se redujo o el cálculo no es válido. Vuelva a calcular los trabajos disponibles.');
        }
        return min(max(1, $requested), $previewBudget, $current);
    }
}
