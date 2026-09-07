<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

final class AutomationCliCapacityArgumentParser
{
    /**
     * @param array<string,mixed> $options
     * @return array{max_calls:?int,legacy_max_jobs:?int}
     */
    public function parse(array $options): array
    {
        $hasMaxCalls = array_key_exists('max-calls', $options);
        $hasMaxJobs = array_key_exists('max-jobs', $options);

        if ($hasMaxJobs) {
            throw new InvalidArgumentException('legacy_capacity_argument_removed');
        }

        return [
            'max_calls' => $hasMaxCalls ? $this->parseOne($options['max-calls']) : null,
            'legacy_max_jobs' => null,
        ];
    }

    private function parseOne(mixed $raw): int
    {
        if (!is_int($raw) && !is_string($raw)) {
            throw new InvalidArgumentException('invalid_capacity_argument');
        }

        $value = trim((string) $raw);
        if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
            throw new InvalidArgumentException('invalid_capacity_argument');
        }

        return max(
            AutomationCallBudgetService::MIN,
            min(AutomationCallBudgetService::HARD_MAX, (int) $value)
        );
    }
}
