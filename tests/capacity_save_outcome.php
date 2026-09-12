<?php
declare(strict_types=1);

// Supplemental fault-injection proof of the REAL service. This is NOT DB integration.
require __DIR__ . '/k1b_bootstrap.php';

use App\Services\CapacityPolicyService;

if (!defined('ERP_SHARED_ROOT')) {
    // A file cannot be a storage directory: exercise the REAL logger failing too.
    $unwritableLog = in_array('--unwritable-log', $argv, true);
    define('ERP_SHARED_ROOT', $unwritableLog ? __FILE__ : sys_get_temp_dir() . '/capacity-save-outcome-' . getmypid());
    if ($unwritableLog) {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException('injected local logging failure', 0, $severity, $file, $line);
        });
    }
}

final class CapacityOutcomePdo extends PDO
{
    public array $rows = [
        'manual.api_calls_per_step' => '1',
        'manual.api_calls_ceiling' => '55',
    ];
    public array $savedRows = [];
    public bool $transaction = false;
    public int $writes = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    public int $releases = 0;
    public ?Throwable $writeError = null;
    public ?Throwable $commitError = null;
    public ?Throwable $rollbackError = null;
    public ?Throwable $releaseError = null;
    public bool $commitResult = true;
    public bool $releaseResult = true;
    public ?string $lockName = null;

    public function __construct() {}
    public function inTransaction(): bool { return $this->transaction; }
    public function beginTransaction(): bool
    {
        $this->savedRows = $this->rows;
        $this->transaction = true;
        return true;
    }
    public function commit(): bool
    {
        ++$this->commits;
        if ($this->commitError !== null) {
            // Simulate loss of commit acknowledgement; no retry can infer outcome.
            throw $this->commitError;
        }
        if ($this->commitResult) {
            $this->transaction = false;
        }
        return $this->commitResult;
    }
    public function rollBack(): bool
    {
        ++$this->rollbacks;
        if ($this->rollbackError !== null) {
            throw $this->rollbackError;
        }
        $this->rows = $this->savedRows;
        $this->transaction = false;
        return true;
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        k1b_assert($query === 'SELECT DATABASE()', 'unexpected database query');
        return new CapacityOutcomeStatement($this, $query);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new CapacityOutcomeStatement($this, $query);
    }
}

final class CapacityOutcomeStatement extends PDOStatement
{
    private array $parameters = [];
    public function __construct(private CapacityOutcomePdo $pdo, private string $sql) {}
    public function execute(?array $params = null): bool
    {
        $this->parameters = $params ?? [];
        if ($this->sql === 'SELECT GET_LOCK(?,3)') {
            $expected = 'capacity:' . substr(hash('sha256', 'capacity_outcome_fixture'), 0, 32) . ':manual';
            k1b_assert($this->parameters === [$expected], 'named per-module lock required');
            $this->pdo->lockName = $expected;
        } elseif ($this->sql === 'SELECT RELEASE_LOCK(?)') {
            ++$this->pdo->releases;
            k1b_assert($this->parameters === [$this->pdo->lockName], 'must release acquired lock');
            if ($this->pdo->releaseError !== null) {
                throw $this->pdo->releaseError;
            }
            $this->pdo->lockName = null;
        } elseif (str_starts_with($this->sql, 'INSERT INTO app_settings')) {
            k1b_assert($this->pdo->transaction, 'pair writes require transaction');
            ++$this->pdo->writes;
            if ($this->pdo->writes === 2 && $this->pdo->writeError !== null) {
                throw $this->pdo->writeError;
            }
            [$key, $value, $group] = $this->parameters;
            k1b_assert($group === 'manual' && array_key_exists($key, $this->pdo->rows), 'unexpected module write');
            $this->pdo->rows[$key] = $value;
        } else {
            k1b_assert(str_starts_with($this->sql, 'SELECT setting_key,setting_value,is_encrypted FROM app_settings WHERE setting_key IN ('), 'unexpected SQL');
            k1b_assert(!$this->pdo->transaction || str_ends_with($this->sql, ' FOR UPDATE'), 'save reads require row locks');
        }
        return true;
    }
    public function fetchColumn(int $column = 0): mixed
    {
        if ($this->sql === 'SELECT DATABASE()') {
            return 'capacity_outcome_fixture';
        }
        return $this->sql === 'SELECT RELEASE_LOCK(?)' ? (int) $this->pdo->releaseResult : 1;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = [];
        foreach ($this->parameters as $key) {
            if (isset($this->pdo->rows[$key])) {
                $rows[] = ['setting_key' => $key, 'setting_value' => $this->pdo->rows[$key], 'is_encrypted' => 0];
            }
        }
        return $rows;
    }
}

$tests = [
    'four_argument_increase_without_health_dependency' => static function (): void {
        $pdo = new CapacityOutcomePdo();
        $service = new CapacityPolicyService($pdo);
        $before = $service->snapshot('manual');
        $after = $service->save('manual', 25, 60, $before['revision']);
        k1b_assert($after['current'] === 25 && $after['ceiling'] === 60, 'exact pair not returned');
        k1b_assert($service->snapshot('manual') === $after, 'saved pair not readable');
        k1b_assert($pdo->commits === 1 && $pdo->releases === 1, 'commit and lock release required');
    },
    'write_failure_survives_rollback_and_release_failures' => static function (): void {
        $pdo = new CapacityOutcomePdo();
        $primary = $pdo->writeError = new RuntimeException('primary write fault');
        $pdo->rollbackError = new RuntimeException('rollback fault');
        $pdo->releaseError = new RuntimeException('release fault');
        $service = new CapacityPolicyService($pdo);
        $caught = null;
        try {
            $service->save('manual', 25, 60, $service->snapshot('manual')['revision']);
        } catch (Throwable $error) {
            $caught = $error;
        }
        k1b_assert($caught === $primary, 'cleanup replaced primary write exception');
        k1b_assert($pdo->rollbacks === 1 && $pdo->releases === 1, 'both cleanup operations must be attempted');
        k1b_assert($pdo->commits === 0 && $pdo->writes === 2, 'write failure must not commit or retry');
    },
    'acknowledged_commit_survives_release_failure' => static function (): void {
        $pdo = new CapacityOutcomePdo();
        $pdo->releaseError = new RuntimeException('release fault');
        $service = new CapacityPolicyService($pdo);
        $after = $service->save('manual', 25, 60, $service->snapshot('manual')['revision']);
        k1b_assert($after['current'] === 25 && $after['ceiling'] === 60, 'cleanup hid committed success');
        k1b_assert($service->snapshot('manual') === $after, 'committed pair must remain visible');
        k1b_assert($pdo->commits === 1 && $pdo->rollbacks === 0 && $pdo->releases === 1, 'successful commit must not be undone or retried');
    },
    'uncertain_commit_survives_cleanup_without_retry' => static function (): void {
        $pdo = new CapacityOutcomePdo();
        $primary = $pdo->commitError = new RuntimeException('commit acknowledgement unavailable');
        $pdo->rollbackError = new RuntimeException('rollback fault');
        $pdo->releaseError = new RuntimeException('release fault');
        $service = new CapacityPolicyService($pdo);
        $caught = null;
        try {
            $service->save('manual', 25, 60, $service->snapshot('manual')['revision']);
        } catch (Throwable $error) {
            $caught = $error;
        }
        k1b_assert($caught === $primary, 'uncertain commit must propagate unchanged');
        k1b_assert($pdo->commits === 1 && $pdo->writes === 2, 'uncertain commit must never retry');
        k1b_assert($pdo->rollbacks === 1 && $pdo->releases === 1, 'uncertain commit still attempts cleanup');
    },
    'rollback_failure_preserves_primary' => static function (): void {
        $pdo = new CapacityOutcomePdo();
        $primary = $pdo->writeError = new RuntimeException('primary write fault');
        $pdo->rollbackError = new RuntimeException('rollback fault');
        $service = new CapacityPolicyService($pdo);
        $caught = null;
        try {
            $service->save('manual', 25, 60, $service->snapshot('manual')['revision']);
        } catch (Throwable $error) {
            $caught = $error;
        }
        k1b_assert($caught === $primary, 'rollback replaced primary exception');
        k1b_assert($pdo->rollbacks === 1 && $pdo->releases === 1, 'rollback failure must not skip release');
    },
    'false_commit_is_not_acknowledged_success' => static function (): void {
        $pdo = new CapacityOutcomePdo();
        $pdo->commitResult = false;
        $service = new CapacityPolicyService($pdo);
        $caught = null;
        try {
            $service->save('manual', 25, 60, $service->snapshot('manual')['revision']);
        } catch (Throwable $error) {
            $caught = $error;
        }
        k1b_assert($caught instanceof RuntimeException, 'false commit must not return success');
        k1b_assert($pdo->commits === 1 && $pdo->writes === 2 && $pdo->releases === 1, 'false commit must release without retry');
    },
    'failed_release_result_is_logged_without_hiding_success' => static function (): void {
        $pdo = new CapacityOutcomePdo();
        $pdo->releaseResult = false;
        $service = new CapacityPolicyService($pdo);
        $logFile = App\Core\AppPaths::storage('logs/app.log');
        $beforeLog = is_file($logFile) ? file_get_contents($logFile) : '';
        $after = $service->save('manual', 25, 60, $service->snapshot('manual')['revision']);
        k1b_assert($after['current'] === 25 && $pdo->releases === 1, 'release result must not hide success');
        if (!in_array('--unwritable-log', $GLOBALS['argv'], true)) {
            k1b_assert(is_file($logFile), 'cleanup failure must attempt local logging');
            $log = file_get_contents($logFile);
            k1b_assert(strlen($log) > strlen($beforeLog), 'failed release result must add a local log');
            k1b_assert(is_string($log) && !str_contains($log, 'release fault') && !str_contains($log, 'rollback fault'), 'raw exception details must not be logged');
        }
    },
];
$failures = 0;
echo "SUPPLEMENTAL PDO FAULT INJECTION; NOT DATABASE INTEGRATION\n";
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS $name\n";
    } catch (Throwable $error) {
        ++$failures;
        // Never emit dependency exception messages, which could contain secrets.
        echo "FAIL $name (" . $error::class . ")\n";
    }
}
exit($failures === 0 ? 0 : 1);
