<?php

declare(strict_types=1);

namespace App\QueueCore;

/** Cálculo puro del sobre de capacidad; no inventa límites ni consulta remoto. */
final class QueueCoreCapacityService
{
    /** @return array<string,float|int> */
    public function calculate(
        float $arrivalResourcesPerMinute,
        float $expectedHttpPerResource,
        float $p50Seconds,
        float $p95Seconds,
        float $effectiveHttpPerMinute,
        int $cadenceSeconds,
        int $runtimeSeconds,
        int $safeCloseSeconds,
        int $maxRemoteJobsPerRun,
    ): array {
        $expectedHttpPerResource = max(0.01, $expectedHttpPerResource);
        $effectiveHttpPerMinute = max(0.0, $effectiveHttpPerMinute);
        $usableSeconds = max(0, $runtimeSeconds - $safeCloseSeconds);
        $runsPerMinute = $cadenceSeconds > 0 ? 60 / $cadenceSeconds : 0.0;
        $latencyBoundPerRun = $p95Seconds > 0 ? (int) floor($usableSeconds / $p95Seconds) : $maxRemoteJobsPerRun;
        $budgetBoundPerRun = $runsPerMinute > 0
            ? (int) floor($effectiveHttpPerMinute / $runsPerMinute)
            : 0;
        $safeJobsPerRun = max(0, min($maxRemoteJobsPerRun, $latencyBoundPerRun, $budgetBoundPerRun));
        $httpPerMinute = $safeJobsPerRun * $runsPerMinute;
        $resourcesPerMinute = $httpPerMinute / $expectedHttpPerResource;
        $margin = $arrivalResourcesPerMinute > 0
            ? ($resourcesPerMinute - $arrivalResourcesPerMinute) / $arrivalResourcesPerMinute
            : ($resourcesPerMinute > 0 ? 1.0 : 0.0);
        return [
            'arrival_rate_resources_per_minute' => round(max(0.0, $arrivalResourcesPerMinute), 4),
            'sustainable_http_per_minute' => round($httpPerMinute, 4),
            'sustainable_resources_per_minute' => round($resourcesPerMinute, 4),
            'safety_margin_ratio' => round($margin, 4),
            'safe_jobs_per_run' => $safeJobsPerRun,
            'usable_seconds_per_run' => $usableSeconds,
            'p50_seconds' => round(max(0.0, $p50Seconds), 4),
            'p95_seconds' => round(max(0.0, $p95Seconds), 4),
        ];
    }

    public function catchupMinutes(int $resources, float $sustainableResourcesPerMinute, float $arrivalResourcesPerMinute): ?float
    {
        $net = $sustainableResourcesPerMinute - $arrivalResourcesPerMinute;
        return $resources > 0 && $net > 0 ? round($resources / $net, 2) : null;
    }
}
