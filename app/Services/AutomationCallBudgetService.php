<?php

declare(strict_types=1);

namespace App\Services;

final class AutomationCallBudgetService
{
    public const SETTING_KEY = 'automation.max_api_calls_per_cycle';
    public const DEFAULT = 1;
    public const MIN = 1;
    public const HARD_MAX = 15;

    public function __construct(private readonly AppSettingsService $settings = new AppSettingsService())
    {
    }

    /** @return array{max_calls:int,configured_max_calls:int,max_calls_source:string,control_unit:string} */
    public function resolve(?int $cliMaxCalls = null, ?int $legacyMaxJobs = null): array
    {
        if ($cliMaxCalls !== null) {
            return $this->result($cliMaxCalls, 'CLI_MAX_CALLS_OVERRIDE', $this->configured());
        }

        if ($legacyMaxJobs !== null) {
            return $this->result($legacyMaxJobs, 'LEGACY_MAX_JOBS_OVERRIDE', $this->configured());
        }

        $rawConfigured = $this->settings->get(self::SETTING_KEY, null);
        if ($rawConfigured !== null && is_numeric($rawConfigured)) {
            $configured = $this->bound((int) $rawConfigured);
            return $this->result($configured, 'ERP_SETTINGS', $configured);
        }

        return $this->result(self::DEFAULT, 'SAFE_DEFAULT', self::DEFAULT);
    }

    private function configured(): int
    {
        return $this->bound($this->settings->int(self::SETTING_KEY, self::DEFAULT));
    }

    /** @return array{max_calls:int,configured_max_calls:int,max_calls_source:string,control_unit:string} */
    private function result(int $value, string $source, int $configured): array
    {
        return [
            'max_calls' => $this->bound($value),
            'configured_max_calls' => $this->bound($configured),
            'max_calls_source' => $source,
            'control_unit' => 'PHYSICAL_API_CALL',
        ];
    }

    private function bound(int $value): int
    {
        return max(self::MIN, min(self::HARD_MAX, $value));
    }
}
