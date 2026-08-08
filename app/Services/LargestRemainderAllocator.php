<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Distribuye centavos sin perder ni crear dinero.
 */
final class LargestRemainderAllocator
{
    /**
     * @param array<int,float|int> $weights
     * @return array<int,int> centavos por clave
     */
    public function allocate(int $totalCents, array $weights): array
    {
        if ($weights === []) {
            return [];
        }
        $sign = $totalCents < 0 ? -1 : 1;
        $absolute = abs($totalCents);
        $normalized = [];
        foreach ($weights as $key => $weight) {
            $normalized[$key] = max(0.0, (float) $weight);
        }
        $weightTotal = array_sum($normalized);
        if ($weightTotal <= 0.0) {
            $normalized = array_fill_keys(array_keys($normalized), 1.0);
            $weightTotal = (float) count($normalized);
        }
        $allocated = [];
        $remainders = [];
        $used = 0;
        foreach ($normalized as $key => $weight) {
            $exact = $absolute * ($weight / $weightTotal);
            $floor = (int) floor($exact);
            $allocated[$key] = $floor;
            $remainders[$key] = $exact - $floor;
            $used += $floor;
        }
        uasort($remainders, static function (float $left, float $right): int {
            $comparison = $right <=> $left;
            return $comparison;
        });
        $remaining = $absolute - $used;
        foreach (array_keys($remainders) as $key) {
            if ($remaining <= 0) {
                break;
            }
            $allocated[$key]++;
            $remaining--;
        }
        foreach ($allocated as $key => $value) {
            $allocated[$key] = $value * $sign;
        }
        ksort($allocated);
        return $allocated;
    }
}
