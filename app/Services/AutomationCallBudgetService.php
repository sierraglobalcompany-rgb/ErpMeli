<?php

declare(strict_types=1);

namespace App\Services;

final class AutomationCallBudgetService
{
    public const SETTING_KEY = 'automation.max_api_calls_per_cycle';
    public const DEFAULT = 1;
    public const MIN = 1;
    public const HARD_MAX = CapacityPolicyService::TECHNICAL_MAX;

    public function __construct(private readonly AppSettingsService $settings = new AppSettingsService())
    {
    }

    /** @return array{max_calls:int,configured_max_calls:int,ceiling:int,max_calls_source:string,control_unit:string} */
    public function resolve(?int $cliMaxCalls = null, ?int $legacyMaxJobs = null): array
    {
        $policy = (new CapacityPolicyService())->snapshot('automation');
        $configured = $policy['current'];
        $ceiling = $policy['ceiling'];
        if ($cliMaxCalls !== null) {
            return $this->result($cliMaxCalls, 'CLI_MAX_CALLS_OVERRIDE', $configured, $ceiling);
        }

        if ($legacyMaxJobs !== null) {
            return $this->result($legacyMaxJobs, 'LEGACY_MAX_JOBS_OVERRIDE', $configured, $ceiling);
        }

        $rawConfigured = $this->settings->get(self::SETTING_KEY, null);
        if ($rawConfigured !== null && is_numeric($rawConfigured)) {
            return $this->result($configured, 'ERP_SETTINGS', $configured, $ceiling);
        }

        return $this->result($configured, 'SAFE_DEFAULT', $configured, $ceiling);
    }

    /** @return array{max_calls:int,configured_max_calls:int,ceiling:int,max_calls_source:string,control_unit:string} */
    private function result(int $value, string $source, int $configured, int $ceiling): array
    {
        return [
            'max_calls' => $this->bound($value, $ceiling),
            'configured_max_calls' => $this->bound($configured, $ceiling),
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
