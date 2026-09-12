<?php
declare(strict_types=1);

// Real AppSettingsService and Crypto; PDO fault doubles only. NOT DB integration.
namespace App\Core {
    final class Database
    {
        public static \SettingsStrictPdo $pdo;
        public static function connection(): \PDO { return self::$pdo; }
    }
}

namespace {
    require __DIR__ . '/k1b_bootstrap.php';

    use App\Core\Database;
    use App\Services\AppSettingsService;

    final class SettingsStrictPdo extends PDO
    {
        public array $rows = [];
        public ?Throwable $error = null;
        public int $queries = 0;
        public function __construct() {}
        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            k1b_assert(str_starts_with($query, 'SELECT ') && str_contains($query, ' FROM app_settings WHERE '), 'unexpected settings SQL');
            return new SettingsStrictStatement($this);
        }
    }

    final class SettingsStrictStatement extends PDOStatement
    {
        private array $params = [];
        public function __construct(private SettingsStrictPdo $pdo) {}
        public function execute(?array $params = null): bool
        {
            ++$this->pdo->queries;
            if ($this->pdo->error !== null) {
                throw $this->pdo->error;
            }
            $this->params = $params ?? [];
            return true;
        }
        public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
        {
            return $this->pdo->rows[$this->params['key']] ?? false;
        }
        public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
        {
            return array_values(array_intersect_key($this->pdo->rows, array_flip($this->params)));
        }
    }

    function settings_fixture(): SettingsStrictPdo
    {
        AppSettingsService::clearCache();
        return Database::$pdo = new SettingsStrictPdo();
    }

    function settings_row(string $value, bool $encrypted = false): array
    {
        return ['setting_key' => 'test.capacity.strict_value', 'setting_value' => $value, 'is_encrypted' => (int) $encrypted];
    }

    function settings_read(AppSettingsService $settings, string $method): mixed
    {
        return $method === 'get'
            ? $settings->get('test.capacity.strict_value', 'fallback')
            : $settings->getMany(['test.capacity.strict_value' => 'fallback'])['test.capacity.strict_value'];
    }

    $tests = [];
    foreach (['get', 'getMany'] as $method) {
        $tests[$method . '_strict_failure_propagates_original'] = static function () use ($method): void {
            $pdo = settings_fixture();
            $expected = $pdo->error = new PDOException('synthetic read fault');
            $caught = null;
            try { settings_read(new AppSettingsService(true), $method); } catch (Throwable $error) { $caught = $error; }
            k1b_assert($caught === $expected, 'strict read must propagate original error');
        };
        $tests[$method . '_strict_bypasses_poisoned_cache_after_recovery'] = static function () use ($method): void {
            $pdo = settings_fixture();
            $pdo->error = new PDOException('synthetic read fault');
            k1b_assert(settings_read(new AppSettingsService(), $method) === 'fallback', 'default read behavior changed');
            $pdo->error = null;
            $pdo->rows['test.capacity.strict_value'] = settings_row('verified');
            k1b_assert(settings_read(new AppSettingsService(true), $method) === 'verified', 'strict read accepted cached fallback');
        };
        $tests[$method . '_strict_bypasses_poisoned_cache_during_failure'] = static function () use ($method): void {
            $pdo = settings_fixture();
            $expected = $pdo->error = new PDOException('synthetic read fault');
            settings_read(new AppSettingsService(), $method);
            $caught = null;
            try { settings_read(new AppSettingsService(true), $method); } catch (Throwable $error) { $caught = $error; }
            k1b_assert($caught === $expected, 'warm fallback cache concealed read failure');
        };
        $tests[$method . '_strict_failure_does_not_cache_fallback'] = static function () use ($method): void {
            $pdo = settings_fixture();
            $pdo->error = new PDOException('synthetic read fault');
            try { settings_read(new AppSettingsService(true), $method); } catch (PDOException) {}
            $pdo->error = null;
            $pdo->rows['test.capacity.strict_value'] = settings_row('verified');
            k1b_assert(settings_read(new AppSettingsService(), $method) === 'verified', 'strict failure contaminated shared cache');
        };
        $tests[$method . '_strict_decrypt_failure_propagates_without_cache_poison'] = static function () use ($method): void {
            $pdo = settings_fixture();
            $pdo->rows['test.capacity.strict_value'] = settings_row('invalid-ciphertext-fixture', true);
            $caught = null;
            try { settings_read(new AppSettingsService(true), $method); } catch (Throwable $error) { $caught = $error; }
            k1b_assert($caught instanceof RuntimeException, 'strict read swallowed decrypt failure');
            $pdo->rows['test.capacity.strict_value'] = settings_row('verified');
            k1b_assert(settings_read(new AppSettingsService(), $method) === 'verified', 'failed decrypt marked value loaded');
        };
        $tests[$method . '_verified_absence_allows_default'] = static function () use ($method): void {
            settings_fixture();
            k1b_assert(settings_read(new AppSettingsService(true), $method) === 'fallback', 'verified absent setting must preserve default');
        };
        $tests[$method . '_default_mode_retains_cached_fallback'] = static function () use ($method): void {
            $pdo = settings_fixture();
            $pdo->error = new PDOException('synthetic read fault');
            k1b_assert(settings_read(new AppSettingsService(), $method) === 'fallback', 'permissive fallback changed');
            $pdo->error = null;
            $pdo->rows['test.capacity.strict_value'] = settings_row('verified');
            k1b_assert(settings_read(new AppSettingsService(), $method) === 'fallback', 'default cache behavior changed');
        };
    }
    $tests['strict_int_and_bool_propagate_failure'] = static function (): void {
        $pdo = settings_fixture();
        $expected = $pdo->error = new PDOException('synthetic read fault');
        foreach (['int', 'bool'] as $method) {
            $caught = null;
            try {
                $settings = new AppSettingsService(true);
                $method === 'int' ? $settings->int('test.capacity.strict_value', 20) : $settings->bool('test.capacity.strict_value', true);
            } catch (Throwable $error) { $caught = $error; }
            k1b_assert($caught === $expected, 'typed reader swallowed failure');
        }
    };
    $tests['strict_group_failure_propagates_default_mode_unchanged'] = static function (): void {
        $pdo = settings_fixture();
        $expected = $pdo->error = new PDOException('synthetic read fault');
        k1b_assert((new AppSettingsService())->allByGroup('test') === [], 'default group fallback changed');
        $caught = null;
        try { (new AppSettingsService(true))->allByGroup('test'); } catch (Throwable $error) { $caught = $error; }
        k1b_assert($caught === $expected, 'strict group read swallowed failure');
    };

    echo "SUPPLEMENTAL REAL SETTINGS/CRYPTO; PDO FAULT DOUBLES; NOT DATABASE INTEGRATION\n";
    $failures = 0;
    foreach ($tests as $name => $test) {
        try {
            $test();
            echo "PASS $name\n";
        } catch (Throwable $error) {
            ++$failures;
            echo "FAIL $name (" . $error::class . ")\n";
        }
    }
    exit($failures === 0 ? 0 : 1);
}
