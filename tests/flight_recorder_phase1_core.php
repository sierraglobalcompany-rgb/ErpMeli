<?php
declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';

use App\Services\ApiFlightRecorderConfig;
use App\Services\ApiFlightRecorderMetadata;
use App\Core\Database;

final class FlightRecorderConfigPdo extends PDO
{
    public int $selects = 0;
    public array $rows = [];
    public bool $fail = false;

    public function __construct() {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        ++$this->selects;
        if ($this->fail) {
            throw new PDOException('fixture settings query failure');
        }
        return new FlightRecorderConfigStatement($this, $query);
    }
}

final class FlightRecorderConfigStatement extends PDOStatement
{
    public function __construct(private FlightRecorderConfigPdo $pdo, private string $sql) {}

    public function execute(?array $params = null): bool
    {
        if (!str_contains($this->sql, 'SELECT setting_key,setting_value,is_encrypted FROM app_settings')
            || $params !== ['api.trace.mode', 'api.trace.diagnostic_until']) {
            throw new RuntimeException('real settings loader must query both exact keys once');
        }
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->pdo->rows;
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

if (!class_exists(ApiFlightRecorderConfig::class) || !class_exists(ApiFlightRecorderMetadata::class)) {
    throw new RuntimeException('RED: Flight Recorder config and metadata behavior is not implemented.');
}

$utc = new DateTimeZone('UTC');
$now = new DateTimeImmutable('2026-10-07T12:00:00Z', $utc);

$cases = [
    'missing mode defaults off' => [[], 'off'],
    'empty mode defaults off' => [['api.trace.mode' => ''], 'off'],
    'invalid mode defaults off' => [['api.trace.mode' => 'verbose'], 'off'],
    'off remains off' => [['api.trace.mode' => 'off'], 'off'],
    'basic remains basic' => [['api.trace.mode' => 'basic'], 'basic'],
    'diagnostic remains active before expiry' => [
        ['api.trace.mode' => 'diagnostic', 'api.trace.diagnostic_until' => '2026-10-07T12:05:00Z'],
        'diagnostic',
    ],
    'expired diagnostic becomes basic' => [
        ['api.trace.mode' => 'diagnostic', 'api.trace.diagnostic_until' => '2026-10-07T11:59:59Z'],
        'basic',
    ],
    'missing diagnostic expiry becomes basic' => [['api.trace.mode' => 'diagnostic'], 'basic'],
    'invalid diagnostic expiry becomes basic' => [
        ['api.trace.mode' => 'diagnostic', 'api.trace.diagnostic_until' => 'tomorrow'],
        'basic',
    ],
    'diagnostic expiry equal to now becomes basic' => [
        ['api.trace.mode' => 'diagnostic', 'api.trace.diagnostic_until' => '2026-10-07 12:00:00'],
        'basic',
    ],
];

foreach ($cases as $name => [$settings, $expected]) {
    $reads = 0;
    $config = new ApiFlightRecorderConfig(
        static function () use (&$reads, $settings): array { ++$reads; return $settings; },
        static fn (): DateTimeImmutable => $now,
    );
    $assert($config->effectiveMode() === $expected, $name);
    $assert($config->effectiveMode() === $expected && $reads === 1, $name . ' (one cached load)');
}

$reads = 0;
$clockNow = new DateTimeImmutable('2026-10-07T12:04:00Z', $utc);
$expiringConfig = new ApiFlightRecorderConfig(
    static function () use (&$reads): array {
        ++$reads;
        return ['api.trace.mode' => 'diagnostic', 'api.trace.diagnostic_until' => '2026-10-07T12:05:00Z'];
    },
    static function () use (&$clockNow): DateTimeImmutable { return $clockNow; },
);
$assert($expiringConfig->effectiveMode() === 'diagnostic', 'diagnostic is active before expiry');
$clockNow = new DateTimeImmutable('2026-10-07T12:06:00Z', $utc);
$assert($expiringConfig->effectiveMode() === 'basic', 'diagnostic expires within the same process');
$assert($reads === 1, 'diagnostic time advance does not reload settings');

$failedReads = 0;
$failedConfig = new ApiFlightRecorderConfig(
    static function () use (&$failedReads): array { ++$failedReads; throw new RuntimeException('settings unavailable'); },
    static fn (): DateTimeImmutable => $now,
);
$assert($failedConfig->effectiveMode() === 'off', 'settings read failure fails off');
$assert($failedConfig->effectiveMode() === 'off' && $failedReads === 1, 'failed load is cached for lifecycle');

$settingsPdo = new FlightRecorderConfigPdo();
$settingsPdo->rows = [['setting_key' => 'api.trace.mode', 'setting_value' => 'basic', 'is_encrypted' => 0]];
Database::setConnection($settingsPdo);
$realBasicConfig = new ApiFlightRecorderConfig();
$assert($realBasicConfig->effectiveMode() === 'basic' && $realBasicConfig->effectiveMode() === 'basic', 'real settings loader reads basic');
$assert($settingsPdo->selects === 1, 'real settings loader issues one SELECT for both keys');

$settingsPdo = new FlightRecorderConfigPdo();
$settingsPdo->rows = [
    ['setting_key' => 'api.trace.mode', 'setting_value' => 'diagnostic', 'is_encrypted' => 0],
    ['setting_key' => 'api.trace.diagnostic_until', 'setting_value' => '2026-10-07T12:05:00Z', 'is_encrypted' => 0],
];
Database::setConnection($settingsPdo);
$clockNow = new DateTimeImmutable('2026-10-07T12:04:00Z', $utc);
$realDiagnosticConfig = new ApiFlightRecorderConfig(null, static function () use (&$clockNow): DateTimeImmutable { return $clockNow; });
$assert($realDiagnosticConfig->effectiveMode() === 'diagnostic', 'real settings loader reads diagnostic and expiry');
$clockNow = new DateTimeImmutable('2026-10-07T12:06:00Z', $utc);
$assert($realDiagnosticConfig->effectiveMode() === 'basic' && $settingsPdo->selects === 1, 'real loader expires without a second SELECT');

$settingsPdo = new FlightRecorderConfigPdo();
Database::setConnection($settingsPdo);
$assert((new ApiFlightRecorderConfig())->effectiveMode() === 'off', 'missing database settings default off');
$settingsPdo = new FlightRecorderConfigPdo();
$settingsPdo->fail = true;
Database::setConnection($settingsPdo);
$assert((new ApiFlightRecorderConfig())->effectiveMode() === 'off', 'database exception fails off');

$assert(ApiFlightRecorderMetadata::normalizeTraceId('trace:2026-10:run.7') === 'trace:2026-10:run.7', 'valid trace ID preserved');
$assert(ApiFlightRecorderMetadata::normalizeTraceId('') === null, 'empty trace ID becomes null');
$assert(ApiFlightRecorderMetadata::normalizeTraceId(str_repeat('a', 65)) === null, 'oversized trace ID becomes null');
$assert(ApiFlightRecorderMetadata::normalizeTraceId('trace id') === null, 'unsafe trace ID becomes null');
$assert(ApiFlightRecorderMetadata::normalizeTraceId('token:abc/def') === null, 'trace ID with slash becomes null');

$headers = ApiFlightRecorderMetadata::safeResponseHeaders([
    'Retry-After' => ' 12 ',
    'X-RateLimit-Remaining' => '7',
    'CF-Ray' => "ray-1\r\nInjected: yes",
    'Date' => 'Wed, 07 Oct 2026 12:00:00 GMT',
    'X-Request-Id' => 'request-1',
    'X-Correlation-Id' => 'corr-1',
    'Set-Cookie' => 'session=secret',
    'Authorization' => 'Bearer secret-token',
    'X-Unknown' => 'private-value',
]);
$decodedHeaders = is_string($headers) ? json_decode($headers, true) : null;
$assert(is_array($decodedHeaders), 'allowlisted headers serialize as JSON');
$assert(($decodedHeaders['retry-after'] ?? null) === '12', 'retry-after is normalized');
$assert(($decodedHeaders['x-ratelimit-remaining'] ?? null) === '7', 'ratelimit header is normalized');
$assert(($decodedHeaders['cf-ray'] ?? null) === 'ray-1Injected: yes', 'control characters are stripped');
$assert(!isset($decodedHeaders['set-cookie'], $decodedHeaders['authorization'], $decodedHeaders['x-unknown']), 'secret and unknown headers are omitted');
$assert(ApiFlightRecorderMetadata::safeResponseHeaders(['X-Unknown' => 'x']) === null, 'no allowed headers yields null');
$assert(ApiFlightRecorderMetadata::safeResponseHeaders(['Retry-After' => str_repeat('x', 100)]) !== null
    && strlen(json_decode((string) ApiFlightRecorderMetadata::safeResponseHeaders(['Retry-After' => str_repeat('x', 100)]), true)['retry-after']) <= 64,
    'header values are bounded');

$assert(ApiFlightRecorderMetadata::physicalStartUtcMilliseconds(1791374400.1239) === '2026-10-07 12:00:00.123', 'physical start timestamp is UTC with milliseconds');
$assert(ApiFlightRecorderMetadata::physicalStartUtcMilliseconds(null) === null, 'missing physical start remains null');

echo "FLIGHT_RECORDER_PHASE1_CORE_OK\n";
