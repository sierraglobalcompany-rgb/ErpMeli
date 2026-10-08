<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/** Process-local, fail-off configuration for optional API request metadata. */
final class ApiFlightRecorderConfig
{
    private static ?self $processConfig = null;

    private bool $loaded = false;
    private string $requestedMode = 'off';
    private ?DateTimeImmutable $diagnosticUntil = null;
    private \Closure $settingsLoader;
    private \Closure $clock;

    /** @param null|callable():array<string,mixed> $settingsLoader
     *  @param null|callable():DateTimeImmutable $clock
     */
    public function __construct(?callable $settingsLoader = null, ?callable $clock = null)
    {
        $this->settingsLoader = $settingsLoader === null
            ? fn (): array => $this->readSettings()
            : \Closure::fromCallable($settingsLoader);
        $this->clock = $clock === null
            ? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : \Closure::fromCallable($clock);
    }

    public static function current(): self
    {
        return self::$processConfig ??= new self();
    }

    public function effectiveMode(): string
    {
        if (!$this->loaded) {
            $this->loaded = true;
            try {
                $settings = ($this->settingsLoader)();
                $requested = strtolower(trim((string) ($settings['api.trace.mode'] ?? '')));
                if ($requested === 'basic' || $requested === 'diagnostic') {
                    $this->requestedMode = $requested;
                    if ($requested === 'diagnostic') {
                        $this->diagnosticUntil = $this->parseExpiry($settings['api.trace.diagnostic_until'] ?? null);
                    }
                }
            } catch (Throwable) {
                $this->requestedMode = 'off';
                $this->diagnosticUntil = null;
            }
        }

        if ($this->requestedMode !== 'diagnostic') {
            return $this->requestedMode;
        }
        if ($this->diagnosticUntil === null || $this->diagnosticUntil <= ($this->clock)()) {
            return 'basic';
        }
        return 'diagnostic';
    }

    /** @return array<string,mixed> */
    private function readSettings(): array
    {
        $statement = Database::connection()->prepare(
            'SELECT setting_key,setting_value,is_encrypted FROM app_settings WHERE setting_key IN (?,?)'
        );
        $statement->execute(['api.trace.mode', 'api.trace.diagnostic_until']);
        $values = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string) ($row['setting_key'] ?? '');
            if (!in_array($key, ['api.trace.mode', 'api.trace.diagnostic_until'], true)) {
                continue;
            }
            $value = $row['setting_value'] ?? null;
            if ($value !== null && (int) ($row['is_encrypted'] ?? 0) === 1) {
                $value = Crypto::decrypt((string) $value);
            }
            $values[$key] = $value === null ? null : (string) $value;
        }
        return $values;
    }

    private function parseExpiry(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > 64
            || preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})?$/D', $value) !== 1) {
            return null;
        }
        try {
            $expiry = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                return null;
            }
            return $expiry->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
