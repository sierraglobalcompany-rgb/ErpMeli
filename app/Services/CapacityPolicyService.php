<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/** Physical request budgets. Reading never installs settings or masks DB errors. */
final class CapacityPolicyService
{
    public const TECHNICAL_MAX = 100;
    public const DEFAULT_CEILING = 55;
    private const KEYS = [
        'automation' => ['automation.max_api_calls_per_cycle', 'automation.api_calls_ceiling'],
        'manual' => ['manual.api_calls_per_step', 'manual.api_calls_ceiling'],
    ];
    private const LEGACY_MANUAL_KEYS = [
        'manual_campaign.default_block_size', 'api.rhythm.mode',
        'api.rhythm.profile', 'api.rhythm.target_http_per_minute',
    ];

    public function __construct(private readonly ?PDO $pdo = null) {}

    /** @return array{module:string,ceiling:int,current:int,revision:string,legacy_derived:bool} */
    public function snapshot(string $module): array
    {
        return $this->read($this->pdo ?? Database::connectionFresh(), $module);
    }

    /**
     * The module advisory lock also serializes the first save, when neither
     * setting exists. Row locks fence existing raw and legacy dependencies.
     * @return array{module:string,ceiling:int,current:int,revision:string,legacy_derived:bool}
     */
    public function save(string $module, mixed $current, mixed $ceiling, string $revision, callable $increaseGate): array
    {
        $this->keys($module);
        ['current' => $current, 'ceiling' => $ceiling] = $this->validatePair($current, $ceiling);
        $pdo = $this->pdo ?? Database::connectionFresh();
        if ($pdo->inTransaction()) {
            throw new RuntimeException('No se puede guardar capacidad dentro de otra transacción.');
        }
        $lockName = 'capacity:' . substr(hash('sha256', (string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 32) . ':' . $module;
        $lock = $pdo->prepare('SELECT GET_LOCK(?,3)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Otro administrador está guardando esta capacidad. Intente de nuevo.');
        }
        try {
            $pdo->beginTransaction();
            $before = $this->read($pdo, $module, true);
            if (!hash_equals($before['revision'], $revision)) {
                throw new RuntimeException('La capacidad cambió. Recargue y confirme los valores actuales.');
            }
            if ($current > $before['current']) {
                $gate = $increaseGate($before, ['module'=>$module, 'current'=>$current, 'ceiling'=>$ceiling]);
                if (!is_array($gate) || ($gate['allowed'] ?? false) !== true) {
                    throw new RuntimeException(is_array($gate) ? (string) ($gate['message'] ?? 'No se pudo certificar la salud del procesamiento.') : 'No se pudo certificar la salud del procesamiento.');
                }
            }
            $stmt = $pdo->prepare(
                'INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group) VALUES (?,?,0,?)
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=0,setting_group=VALUES(setting_group)'
            );
            foreach (array_combine(self::KEYS[$module], [$current, $ceiling]) as $key => $value) {
                $stmt->execute([$key, (string) $value, $module]);
            }
            $after = $this->read($pdo, $module, true);
            $pdo->commit();
            foreach (self::KEYS[$module] as $key) {
                AppSettingsService::clearCache($key);
            }
            return $after;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    /** @return array{current:int,ceiling:int} */
    public function validatePair(mixed $current, mixed $ceiling): array
    {
        $current = $this->integer($current);
        $ceiling = $this->integer($ceiling);
        if ($current > $ceiling) {
            throw new InvalidArgumentException('La capacidad actual no puede superar su techo.');
        }
        return ['current' => $current, 'ceiling' => $ceiling];
    }

    public function requiresManualAdoptionForRhythm(string $profile, int $target): bool
    {
        if ($target < 1 || $target > 300) {
            throw new InvalidArgumentException('El ritmo debe estar entre 1 y 300.');
        }
        $pdo = $this->pdo ?? Database::connectionFresh();
        $before = $this->read($pdo, 'manual');
        if (!$before['legacy_derived']) {
            return false;
        }
        $after = $this->read($pdo, 'manual', false, [
            'api.rhythm.mode' => $profile,
            'api.rhythm.profile' => $profile,
            'api.rhythm.target_http_per_minute' => (string) $target,
        ]);
        return $after['current'] !== $before['current'];
    }

    private function integer(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value))
            || preg_match('/^[1-9][0-9]{0,2}$/D', (string) $value) !== 1
            || (int) $value > self::TECHNICAL_MAX) {
            throw new InvalidArgumentException('Use números enteros entre 1 y 100, sin espacios ni decimales.');
        }
        return (int) $value;
    }

    /** @return list<string> */
    private function keys(string $module): array
    {
        if (!isset(self::KEYS[$module])) {
            throw new InvalidArgumentException('Módulo de capacidad no válido.');
        }
        return $module === 'manual' ? [...self::KEYS[$module], ...self::LEGACY_MANUAL_KEYS] : self::KEYS[$module];
    }

    /** @return array{module:string,ceiling:int,current:int,revision:string,legacy_derived:bool} */
    private function read(PDO $pdo, string $module, bool $locking = false, array $overrides = []): array
    {
        $keys = $this->keys($module);
        $stmt = $pdo->prepare('SELECT setting_key,setting_value,is_encrypted FROM app_settings WHERE setting_key IN ('
            . implode(',', array_fill(0, count($keys), '?')) . ') ORDER BY setting_key' . ($locking ? ' FOR UPDATE' : ''));
        $stmt->execute($keys);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[$row['setting_key']] = $row;
        }
        $raw = $values = [];
        foreach ($keys as $key) {
            $row = $rows[$key] ?? null;
            $value = $row === null ? Env::get($key) : $row['setting_value'];
            if ($value !== null && (int) ($row['is_encrypted'] ?? 0) === 1) {
                $value = Crypto::decrypt((string) $value);
            }
            $values[$key] = $value;
            $raw[$key] = ['row'=>$row, 'environment'=>$row === null ? $value : null];
        }
        foreach ($overrides as $key => $value) {
            if (array_key_exists($key, $values)) {
                $values[$key] = $value;
            }
        }
        [$currentKey, $ceilingKey] = self::KEYS[$module];
        $legacyDerived = $module === 'manual' && $values[$currentKey] === null;
        $ceiling = max(1, min(self::TECHNICAL_MAX, $this->legacyInt($values[$ceilingKey], self::DEFAULT_CEILING)));
        if ($module === 'automation') {
            $current = $this->legacyInt($values[$currentKey], 1);
            // Before explicit adoption, preserve the old effective 15-call cap.
            if ($values[$ceilingKey] === null) {
                $current = min(15, $current);
            }
        } else {
            $current = $legacyDerived
                ? min(15, max(1, $this->legacyInt($values['manual_campaign.default_block_size'], 30)), $this->legacyRhythmTarget($values))
                : $this->legacyInt($values[$currentKey], 1);
        }
        if (!$legacyDerived && $module === 'manual') {
            $raw = array_intersect_key($raw, array_flip(self::KEYS['manual']));
        }
        return ['module'=>$module, 'ceiling'=>$ceiling, 'current'=>max(1, min($ceiling, $current)),
            'revision'=>hash('sha256', json_encode([$module, $raw], JSON_THROW_ON_ERROR)),
            'legacy_derived'=>$legacyDerived];
    }

    private function legacyInt(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    /** Mirrors the legacy calls_per_block target, not the adaptive pacing limit. */
    private function legacyRhythmTarget(array $values): int
    {
        $profiles = ['conservative'=>10, 'balanced'=>20, 'fast'=>30, 'maximum'=>40];
        $profile = (string) ($values['api.rhythm.profile'] ?? '');
        if ($profile === '') {
            $profile = match ((string) ($values['api.rhythm.mode'] ?? 'recovery')) {
                'conservative'=>'conservative', 'balanced'=>'balanced', 'recovery'=>'maximum', default=>'fast',
            };
        }
        if (!isset($profiles[$profile]) && $profile !== 'custom') {
            $profile = 'fast';
        }
        $target = $this->legacyInt($values['api.rhythm.target_http_per_minute'], $profile === 'custom' ? 40 : $profiles[$profile]);
        if ($profile !== 'custom' && !in_array($target, array_values($profiles), true)) {
            $target = $profiles[$profile];
        }
        return max(1, $target);
    }
}
