<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

final class AppSettingsService
{
    /** @var array<string, string|null> */
    private static array $cache = [];

    /** @var array<string, true> */
    private static array $loaded = [];

    public function get(string $key, ?string $default = null): ?string
    {
        if (isset(self::$loaded[$key])) {
            return self::$cache[$key] ?? $default;
        }

        try {
            $stmt = Database::connection()->prepare('SELECT setting_value,is_encrypted FROM app_settings WHERE setting_key=:key LIMIT 1');
            $stmt->execute(['key' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $value = Env::get($key, $default);
                self::$loaded[$key] = true;
                self::$cache[$key] = $value;
                return $value;
            }
            $value = $row['setting_value'];
            if ($value === null) {
                self::$loaded[$key] = true;
                self::$cache[$key] = $default;
                return $default;
            }
            $resolved = (int) $row['is_encrypted'] === 1 ? Crypto::decrypt((string) $value) : (string) $value;
            self::$loaded[$key] = true;
            self::$cache[$key] = $resolved;
            return $resolved;
        } catch (Throwable) {
            $value = Env::get($key, $default);
            self::$loaded[$key] = true;
            self::$cache[$key] = $value;
            return $value;
        }
    }

    /**
     * Carga varias claves en una sola consulta y conserva el fallback de entorno.
     *
     * @param array<string, string|null> $defaults
     * @return array<string, string|null>
     */
    public function getMany(array $defaults): array
    {
        $missing = [];
        foreach ($defaults as $key => $default) {
            if (!isset(self::$loaded[$key])) {
                $missing[$key] = $default;
            }
        }

        if ($missing !== []) {
            try {
                $placeholders = implode(',', array_fill(0, count($missing), '?'));
                $stmt = Database::connection()->prepare(
                    'SELECT setting_key,setting_value,is_encrypted FROM app_settings WHERE setting_key IN (' . $placeholders . ')'
                );
                $stmt->execute(array_keys($missing));
                $found = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $key = (string) $row['setting_key'];
                    $value = $row['setting_value'];
                    $found[$key] = true;
                    self::$loaded[$key] = true;
                    self::$cache[$key] = $value === null
                        ? ($missing[$key] ?? null)
                        : ((int) $row['is_encrypted'] === 1 ? Crypto::decrypt((string) $value) : (string) $value);
                }
                foreach ($missing as $key => $default) {
                    if (isset($found[$key])) {
                        continue;
                    }
                    self::$loaded[$key] = true;
                    self::$cache[$key] = Env::get($key, $default);
                }
            } catch (Throwable) {
                foreach ($missing as $key => $default) {
                    self::$loaded[$key] = true;
                    self::$cache[$key] = Env::get($key, $default);
                }
            }
        }

        $values = [];
        foreach ($defaults as $key => $default) {
            $values[$key] = self::$cache[$key] ?? $default;
        }
        return $values;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key, (string) $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return filter_var($this->get($key, $default ? '1' : '0'), FILTER_VALIDATE_BOOL);
    }

    public function set(string $key, ?string $value, string $group = 'general', bool $encrypted = false): void
    {
        $stored = $value;
        if ($value !== null && $encrypted) {
            $stored = Crypto::encrypt($value);
        }
        $stmt = Database::connection()->prepare(
            'INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
             VALUES (:key,:value,:encrypted,:group_name)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=VALUES(is_encrypted),setting_group=VALUES(setting_group)'
        );
        $stmt->execute(['key' => $key, 'value' => $stored, 'encrypted' => $encrypted ? 1 : 0, 'group_name' => $group]);
        self::$loaded[$key] = true;
        self::$cache[$key] = $value;
    }

    public function allByGroup(string $group): array
    {
        try {
            $stmt = Database::connection()->prepare('SELECT setting_key,setting_value,is_encrypted,updated_at FROM app_settings WHERE setting_group=:group_name ORDER BY setting_key');
            $stmt->execute(['group_name' => $group]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    public static function clearCache(?string $key = null): void
    {
        if ($key === null) {
            self::$cache = [];
            self::$loaded = [];
            return;
        }
        unset(self::$cache[$key], self::$loaded[$key]);
    }
}
