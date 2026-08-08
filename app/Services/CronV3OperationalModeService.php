<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class CronV3OperationalModeService
{
    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    public function enabled(): bool
    {
        return $this->settingBool('cron_v3.operational_mode', false)
            && $this->settingBool('cron_v3.v2_runtime_disabled', true)
            && !$this->settingBool('cron_v3.rollback_enabled', false);
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        return [
            'operational_mode' => $this->settingBool('cron_v3.operational_mode', false),
            'v2_runtime_disabled' => $this->settingBool('cron_v3.v2_runtime_disabled', true),
            'rollback_enabled' => $this->settingBool('cron_v3.rollback_enabled', false),
            'v2_should_skip' => $this->enabled(),
        ];
    }

    private function settingBool(string $key, bool $default): bool
    {
        try {
            $stmt = $this->connection()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            if ($value === false) {
                return $default;
            }
            return filter_var((string) $value, FILTER_VALIDATE_BOOL);
        } catch (Throwable) {
            return $default;
        }
    }

    private function connection(): PDO
    {
        return $this->pdo ?? Database::connectionFresh();
    }
}
