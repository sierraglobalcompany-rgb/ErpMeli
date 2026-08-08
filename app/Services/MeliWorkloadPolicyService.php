<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Devuelve límites operativos conservadores. La telemetría solo puede
 * habilitar el crecimiento hasta el máximo firmado por el perfil.
 */
final class MeliWorkloadPolicyService
{
    public function effectiveBatch(string $operationKey, ?int $requested = null, ?int $accountId = null): int
    {
        $profile = (new MeliOperationProfileRegistry())->get($operationKey);
        $initial = max(1, (int) $profile['initial_batch']);
        $maximum = max($initial, (int) $profile['maximum_batch']);
        $target = $requested === null ? $initial : max(1, min($maximum, $requested));
        $summary = (new MeliOperationTelemetryService())->summaries(168, $accountId)[$operationKey] ?? [];
        $samples = (int) ($summary['remote_count'] ?? 0);
        $successes = (int) ($summary['success_count'] ?? 0);
        $localFailures = (int) ($summary['local_failure_count'] ?? 0);
        $percentile = (new MeliOperationTelemetryService())->percentiles(168, $accountId)[$operationKey] ?? [];
        $settings = new AppSettingsService();

        if ($samples < (int) $profile['sample_threshold']) {
            return min($initial, $target);
        }
        if ($localFailures > 0) {
            return min($initial, $target);
        }
        if ($samples > 0 && ($successes / $samples) < 0.95) {
            return min($initial, $target);
        }
        if ((int) ($percentile['samples'] ?? 0) < (int) $profile['sample_threshold']) {
            return min($initial, $target);
        }
        if ((int) ($percentile['p95_duration_ms'] ?? 0)
                > $settings->int('api.workload.safe_p95_duration_ms', 5000)
            || (int) ($percentile['p95_decoded_bytes'] ?? 0)
                > $settings->int('api.workload.safe_p95_decoded_bytes', 1048576)) {
            return min($initial, $target);
        }
        return min($maximum, $target);
    }

    /** @return array<string,mixed> */
    public function explain(string $operationKey, ?int $accountId = null): array
    {
        $profile = (new MeliOperationProfileRegistry())->get($operationKey);
        $summary = (new MeliOperationTelemetryService())->summaries(168, $accountId)[$operationKey] ?? [];
        $samples = (int) ($summary['remote_count'] ?? 0);
        $profile['observations'] = $samples;
        $profile['verified'] = $samples >= (int) $profile['sample_threshold'];
        $profile['local_failures'] = (int) ($summary['local_failure_count'] ?? 0);
        $profile['percentiles'] = (new MeliOperationTelemetryService())->percentiles(168, $accountId)[$operationKey] ?? null;
        $profile['effective_batch'] = $this->effectiveBatch($operationKey, (int) $profile['maximum_batch'], $accountId);
        return $profile;
    }
}
