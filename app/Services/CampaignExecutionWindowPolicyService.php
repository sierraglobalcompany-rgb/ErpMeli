<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Una sola autoridad para reservar una ventana dirigida que realmente pueda
 * contener la siguiente operación y su margen de cierre local.
 */
final class CampaignExecutionWindowPolicyService
{
    public function __construct(private readonly AppSettingsService $settings = new AppSettingsService()) {}

    public function directedLaneSeconds(): int
    {
        return self::minimumLaneSeconds(
            $this->settings->int('cron.directed_lane_seconds', 15),
            $this->settings->int('cron.directed_operation_reserve_seconds', 15),
            $this->guardMilliseconds()
        );
    }

    public function fits(int $windowMilliseconds, float $operationReserveSeconds): bool
    {
        return self::operationFits($windowMilliseconds, $operationReserveSeconds, $this->guardMilliseconds());
    }

    public function guardMilliseconds(): int
    {
        return max(500, min(3000, $this->settings->int('cron.directed_lane_guard_ms', 1500)));
    }

    public static function minimumLaneSeconds(
        int $configuredSeconds,
        int $maximumOperationReserveSeconds,
        int $guardMilliseconds
    ): int {
        $configuredSeconds = max(5, min(20, $configuredSeconds));
        $maximumOperationReserveSeconds = max(2, min(15, $maximumOperationReserveSeconds));
        $guardMilliseconds = max(500, min(3000, $guardMilliseconds));
        $safeMinimum = (int) ceil($maximumOperationReserveSeconds + ($guardMilliseconds / 1000));

        return max($configuredSeconds, min(20, $safeMinimum));
    }

    public static function operationFits(
        int $windowMilliseconds,
        float $operationReserveSeconds,
        int $guardMilliseconds = 1500
    ): bool {
        $windowMilliseconds = max(0, $windowMilliseconds);
        $operationReserveSeconds = max(0.0, min(15.0, $operationReserveSeconds));
        $guardMilliseconds = max(500, min(3000, $guardMilliseconds));
        $required = (int) ceil($operationReserveSeconds * 1000) + $guardMilliseconds;

        return $windowMilliseconds >= $required;
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @return array<string,mixed>|null
     */
    public static function firstFittingCandidate(
        array $candidates,
        int $windowMilliseconds,
        int $guardMilliseconds = 1500
    ): ?array {
        foreach ($candidates as $candidate) {
            if (self::operationFits(
                $windowMilliseconds,
                (float) ($candidate['reserve_seconds'] ?? 7.0),
                $guardMilliseconds
            )) {
                return $candidate;
            }
        }
        return null;
    }
}
