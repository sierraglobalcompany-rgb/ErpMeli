<?php
declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';

use App\Core\Database;
use App\Services\ApiGuardService;
use App\Services\AppSettingsService;

final class FlightRecorderWriterPdo extends PDO
{
    /** @var list<array{sql:string,params:array}> */
    public array $executed = [];
    public bool $rejectRecorderColumns = false;
    public bool $rejectCurrentScopeColumns = false;

    public function __construct() {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new FlightRecorderWriterStatement($this, $query);
    }
}

final class FlightRecorderWriterStatement extends PDOStatement
{
    /** @var array<string|int,mixed> */
    private array $params = [];

    public function __construct(private FlightRecorderWriterPdo $pdo, private string $sql) {}

    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        if (str_contains($this->sql, 'INSERT INTO api_request_logs')
            && $this->pdo->rejectRecorderColumns
            && str_contains($this->sql, 'trace_id')) {
            throw new PDOException('Unknown column trace_id');
        }
        if (str_contains($this->sql, 'INSERT INTO api_request_logs')
            && $this->pdo->rejectCurrentScopeColumns
            && str_contains($this->sql, 'company_id')) {
            throw new PDOException('Unknown column company_id');
        }
        $this->pdo->executed[] = ['sql' => $this->sql, 'params' => $this->params];
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->sql, 'FROM app_settings')) {
            return ['setting_value' => '1', 'is_encrypted' => 0];
        }
        return false;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return 9001;
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$pdo = new FlightRecorderWriterPdo();
Database::setConnection($pdo);
AppSettingsService::clearCache();
(new ApiGuardService())->recordRequest(
    9011,
    str_repeat('a', 40),
    'GET',
    '/orders/880001',
    200,
    12,
    null,
    1,
    false,
    null,
    null,
    null,
    [
        'company_id' => 9001,
        'flight_recorder_mode' => 'basic',
        'trace_id' => 'queue:run-4:job-21',
        'physical_started_at_process' => '2026-10-07 12:00:00.123',
        'rate_limit_headers_json' => '{"retry-after":"2"}',
    ],
);
$primaryRows = array_values(array_filter($pdo->executed, static fn (array $row): bool => str_contains($row['sql'], 'INSERT INTO api_request_logs')));
$primary = $primaryRows[0] ?? [];
$assert(isset($primary['sql']), 'primary writer attempted');
$assert(str_contains($primary['sql'], 'trace_id')
    && str_contains($primary['sql'], 'physical_started_at_process')
    && str_contains($primary['sql'], 'rate_limit_headers_json'), 'primary writer includes Phase 1 columns');
$assert(($primary['params']['trace_id'] ?? null) === 'queue:run-4:job-21', 'primary writer binds trace ID');
$assert(($primary['params']['physical_started_at_process'] ?? null) === '2026-10-07 12:00:00.123', 'primary writer binds local start');
$assert(($primary['params']['rate_limit_headers_json'] ?? null) === '{"retry-after":"2"}', 'primary writer binds safe header JSON');

$pdo = new FlightRecorderWriterPdo();
$pdo->rejectRecorderColumns = true;
Database::setConnection($pdo);
AppSettingsService::clearCache();
(new ApiGuardService())->recordRequest(
    9011,
    str_repeat('b', 40),
    'GET',
    '/orders/880002',
    200,
    15,
    null,
    1,
    false,
    null,
    null,
    null,
    ['company_id' => 9001, 'flight_recorder_mode' => 'basic'],
);
$inserts = array_values(array_filter($pdo->executed, static fn (array $row): bool => str_contains($row['sql'], 'INSERT INTO api_request_logs')));
$assert(count($inserts) === 1, 'legacy schema fallback persists one request log');
$assert(!str_contains($inserts[0]['sql'], 'trace_id'), 'legacy fallback omits new nullable columns');
$assert(str_contains($inserts[0]['sql'], 'company_id')
    && str_contains($inserts[0]['sql'], 'scope_kind')
    && ($inserts[0]['params']['company'] ?? null) === 9001, 'pre-304 current-schema fallback preserves tenant correlation');
$assert(($inserts[0]['params']['status'] ?? null) === 200, 'legacy fallback preserves known HTTP result');

$pdo = new FlightRecorderWriterPdo();
$pdo->rejectRecorderColumns = true;
$pdo->rejectCurrentScopeColumns = true;
Database::setConnection($pdo);
AppSettingsService::clearCache();
(new ApiGuardService())->recordRequest(
    9011,
    str_repeat('c', 40),
    'GET',
    '/orders/880003',
    200,
    18,
    null,
    1,
    false,
    null,
    null,
    null,
    ['company_id' => 9001, 'flight_recorder_mode' => 'basic'],
);
$inserts = array_values(array_filter($pdo->executed, static fn (array $row): bool => str_contains($row['sql'], 'INSERT INTO api_request_logs')));
$assert(count($inserts) === 1 && !str_contains($inserts[0]['sql'], 'company_id'), 'pre-existing legacy fallback remains available');
$assert(($inserts[0]['params']['status'] ?? null) === 200, 'pre-existing legacy fallback preserves known HTTP result');

echo "FLIGHT_RECORDER_PHASE1_WRITER_OK\n";
