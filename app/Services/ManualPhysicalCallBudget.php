<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Physical HTTP capacity is independent of resource batch sizes. */
final class ManualPhysicalCallBudget
{
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
        $previewBudget = (int) ($configuration['preview_format'] ?? 2) >= 3
            ? (int) ($configuration['physical_api_call_budget'] ?? 0)
            : max(1, min(15, (int) ($configuration['block_size'] ?? 15)));
        $current = min(CapacityPolicyService::TECHNICAL_MAX, (int) $policy['current'], (int) $policy['ceiling']);
        if ($previewBudget < 1 || $previewBudget > $current) {
            throw new RuntimeException('La capacidad manual se redujo o el cálculo no es válido. Vuelva a calcular los trabajos disponibles.');
        }
        return min(max(1, $requested), $previewBudget, $current);
    }
}
