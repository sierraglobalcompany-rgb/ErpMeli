<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Env;
use App\Services\CronV3Cli;
use App\Services\CronV3DoctorService;

final class CronV3ConfigUnavailablePdo2292 extends PDO
{
    public function __construct()
    {
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        throw new RuntimeException('database unavailable in config authority test');
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$keys = ['CRON_V3_SHADOW_ENABLED', 'CRON_V3_RATE_LIMIT', 'MELI_CLIENT_ID'];
foreach ($keys as $key) {
    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);
}

$fixture = tempnam(sys_get_temp_dir(), 'cron-v3-env-');
if ($fixture === false) {
    throw new RuntimeException('Could not create config.env fixture.');
}
try {
    file_put_contents($fixture, implode(PHP_EOL, [
        'CRON_V3_SHADOW_ENABLED=true',
        'CRON_V3_RATE_LIMIT=17',
        'MELI_CLIENT_ID=test-app-2292',
    ]) . PHP_EOL);
    Env::load($fixture);

    $boolMethod = new ReflectionMethod(CronV3Cli::class, 'envBool');
    $intMethod = new ReflectionMethod(CronV3Cli::class, 'integerEnv');
    $assert($boolMethod->invoke(null, 'CRON_V3_SHADOW_ENABLED', false) === true,
        'CronV3Cli ignored CRON_V3_SHADOW_ENABLED loaded from config.env.');
    $assert($intMethod->invoke(null, 'CRON_V3_RATE_LIMIT', 10, 1, 300) === 17,
        'CronV3Cli ignored CRON_V3_RATE_LIMIT loaded from config.env.');

    $doctor = (new CronV3DoctorService(new CronV3ConfigUnavailablePdo2292()))->snapshot('remote');
    $assert(($doctor['remote_prerequisites']['application_id_defined'] ?? false) === true
        && ($doctor['remote_prerequisites']['application_id_valid'] ?? false) === true,
        'Cron V3 Doctor ignored MELI_CLIENT_ID loaded from config.env.');
} finally {
    @unlink($fixture);
}

$cliSource = (string) file_get_contents(dirname(__DIR__) . '/app/Services/CronV3Cli.php');
$doctorSource = (string) file_get_contents(dirname(__DIR__) . '/app/Services/CronV3DoctorService.php');
$contextSource = (string) file_get_contents(dirname(__DIR__) . '/app/Services/CronV3ExecutionContext.php');
$permitPosition = strpos($contextSource, '$reservation = ($this->permit)();');
$deadlinePosition = strpos($contextSource, 'CronDeadlineContext::assertCanStartRemote(2.0);');
$assert(str_contains($cliSource, 'Env::bool(') && str_contains($cliSource, 'Env::get('),
    'CronV3Cli does not use the canonical Env resolver.');
$assert(str_contains($doctorSource, "Env::get('MELI_CLIENT_ID', '')"),
    'CronV3DoctorService does not use the canonical Env resolver.');
$assert($deadlinePosition !== false && $permitPosition !== false && $deadlinePosition < $permitPosition,
    'Remote context reserves capacity before checking acceptUntil.');

echo "PASS cron_v3_config_authority_2292\n";
