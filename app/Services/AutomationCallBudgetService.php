<?php

declare(strict_types=1);

namespace App\Services;

final class AutomationCallBudgetService
{
    public const SETTING_KEY = 'automation.max_api_calls_per_cycle';
    public const DEFAULT = 1;
    public const MIN = 1;
    public const HARD_MAX = CapacityPolicyService::TECHNICAL_MAX;

    public function __construct(
        private readonly AppSettingsService $settings = new AppSettingsService(),
        private readonly ?CapacityPolicyService $policy = null,
    ) {}

    /** @return array{max_calls:int,requested_max_calls:int,configured_max_calls:int,ceiling:int,max_calls_source:string,control_unit:string} */
    public function resolve(?int $cliMaxCalls = null, ?int $legacyMaxJobs = null): array
    {
        $policy = ($this->policy ?? new CapacityPolicyService())->snapshot('automation');
        $configured = $policy['current'];
        $ceiling = $policy['ceiling'];
        if ($cliMaxCalls !== null) {
            return $this->result($cliMaxCalls, 'CLI_MAX_CALLS_OVERRIDE', $configured, $ceiling);
        }

        $rawConfigured = $this->settings->get(self::SETTING_KEY, null);
        if ($rawConfigured !== null && is_numeric($rawConfigured)) {
            return $this->result($configured, 'ERP_SETTINGS', $configured, $ceiling);
        }

        return $this->result($configured, 'SAFE_DEFAULT', $configured, $ceiling);
    }

    /** @return array{max_calls:int,requested_max_calls:int,configured_max_calls:int,ceiling:int,max_calls_source:string,control_unit:string} */
    private function result(int $value, string $source, int $configured, int $ceiling): array
    {
        $requested = $this->bound($value, self::HARD_MAX);
        $configured = $this->bound($configured, $ceiling);
        return [
            'max_calls' => min($requested, $configured, $ceiling, self::HARD_MAX),
            'requested_max_calls' => $requested,
            'configured_max_calls' => $configured,
            'ceiling' => $ceiling,
            'max_calls_source' => $source,
            'control_unit' => 'PHYSICAL_API_CALL',
        ];
    }

    private function bound(int $value, int $ceiling): int
    {
        return max(self::MIN, min(self::HARD_MAX, $ceiling, $value));
    }
}
