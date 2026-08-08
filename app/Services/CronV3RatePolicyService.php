<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use Throwable;

/**
 * Autoridad humana de ritmo para Cron V3.
 *
 * El launcher conserva CRON_V3_RATE_LIMIT como fallback de arranque, pero el
 * ritmo operativo debe venir de app_settings para que la pantalla Ritmo y el
 * worker remoto hablen de la misma cifra.
 */
final class CronV3RatePolicyService
{
    /** @return array<string,mixed> */
    public function current(int $fallback = 10): array
    {
        $settings = new AppSettingsService();
        $profile = (string) $settings->get('api.rhythm.profile', $settings->get('api.rhythm.mode', 'fast'));
        $target = $settings->int('api.rhythm.target_http_per_minute', $this->profileTarget($profile, $fallback));
        $target = max(1, min(300, $target));
        $current = $settings->int('api.rhythm.current_adaptive_limit', min($target, $fallback));
        $current = max(1, min($target, $current));
        $window = max(10, min(3600, $settings->int('api.rhythm.rolling_window_seconds', 60)));

        return [
            'source' => 'app_settings',
            'profile' => $profile,
            'target_http_per_minute' => $target,
            'current_limit' => $current,
            'window_seconds' => $window,
            'fallback_limit' => $fallback,
            'minimum_interval_ms' => max(1000, min(60000, $settings->int('api.rhythm.minimum_interval_ms', 1000))),
            'ramp_steps' => $this->steps($settings, $profile, $target),
            'adaptive_enabled' => $settings->bool('api.rhythm.adaptive_enabled', true),
        ];
    }

    public function currentLimit(int $fallback = 10): int
    {
        try {
            return (int) $this->current($fallback)['current_limit'];
        } catch (Throwable) {
            return max(1, min(300, $fallback));
        }
    }

    public function windowSeconds(): int
    {
        try {
            return (int) $this->current(10)['window_seconds'];
        } catch (Throwable) {
            return 60;
        }
    }

    private function profileTarget(string $profile, int $fallback): int
    {
        return match ($profile) {
            'conservative' => 10,
            'balanced' => 20,
            'fast' => 30,
            'maximum', 'recovery' => 40,
            default => max(1, min(300, (int) (Env::get('CRON_V3_RATE_LIMIT') ?? $fallback))),
        };
    }

    /** @return list<int> */
    private function steps(AppSettingsService $settings, string $profile, int $target): array
    {
        $raw = trim((string) $settings->get('api.rhythm.ramp_steps', ''));
        if ($raw === '') {
            $raw = match ($profile) {
                'conservative' => '5,10',
                'balanced' => '10,15,20',
                'fast' => '15,20,25,30',
                'maximum', 'recovery' => '15,20,25,30,35,40',
                default => '15,20,25,30,35,40',
            };
        }
        $steps = [];
        foreach (preg_split('/[^0-9]+/', $raw) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            $value = max(1, min(300, (int) $part));
            if ($value <= $target) {
                $steps[$value] = true;
            }
        }
        $steps[$target] = true;
        $values = array_keys($steps);
        sort($values);
        return $values;
    }
}
