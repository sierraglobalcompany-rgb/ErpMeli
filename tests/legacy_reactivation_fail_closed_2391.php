<?php

declare(strict_types=1);

use App\Core\HttpException;

$root = dirname(__DIR__);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$run = static function (array $command, string $cwd, ?array $environment = null): array {
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        $environment,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('test_process_unavailable');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
};
$remove = static function (string $path) use (&$remove): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $remove($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
};

$temporary = sys_get_temp_dir() . '/erp-meli-2391-legacy-reactivation-' . bin2hex(random_bytes(6));
$jobs = $temporary . '/jobs';
@mkdir($jobs, 0700, true);
$sentinel = $temporary . '/BOOTSTRAP_REACHED';
file_put_contents(
    $temporary . '/bootstrap.php',
    '<?php file_put_contents(' . var_export($sentinel, true) . ", 'root'); exit(99);\n",
);
file_put_contents(
    $jobs . '/_bootstrap.php',
    '<?php file_put_contents(' . var_export($sentinel, true) . ", 'jobs'); exit(99);\n",
);

$retiredEntrypoints = [
    'cron_v3_local.php' => 'cron_v3_local',
    'cron_v3_remote.php' => 'cron_v3_remote',
    'cron_v4.php' => 'queue_core_cron_v4',
    'queue_core_canary.php' => 'queue_core_canary',
];
foreach (array_keys($retiredEntrypoints) as $file) {
    copy($root . '/jobs/' . $file, $jobs . '/' . $file);
}
copy($root . '/jobs/queue_engine_control.php', $jobs . '/queue_engine_control.php');
copy($root . '/jobs/queue_core_historical.php', $jobs . '/queue_core_historical.php');

$profiles = [
    'disabled' => [
        'CRON_V3_ENABLED' => 'false',
        'CRON_V3_SHADOW_ENABLED' => 'false',
        'CRON_V4_ENABLED' => 'false',
        'ERP_QUEUE_ENGINE' => 'disabled',
    ],
    'v3_all_enabled' => [
        'CRON_V3_ENABLED' => 'true',
        'CRON_V3_SHADOW_ENABLED' => 'true',
        'CRON_V4_ENABLED' => 'true',
        'ERP_QUEUE_ENGINE' => 'v3',
    ],
    'v4_all_enabled' => [
        'CRON_V3_ENABLED' => 'true',
        'CRON_V3_SHADOW_ENABLED' => 'true',
        'CRON_V4_ENABLED' => 'true',
        'ERP_QUEUE_ENGINE' => 'v4',
        'ML_WRITE_ENABLED' => 'true',
    ],
];

$webMutations = [
    'prepareCronV3SafeConfig' => '/settings/cron/v3-setup/prepare-safe-config',
    'enableCronV3Shadow' => '/settings/cron/v3-setup/enable-shadow',
    'prepareCronV3Canary' => '/settings/cron/v3-canary/prepare',
    'enableCronV3LocalCanary' => '/settings/cron/v3-canary/enable-local',
    'enableCronV3RemoteCanary' => '/settings/cron/v3-canary/enable-remote',
    'rollbackCronV3Canary' => '/settings/cron/v3-canary/rollback',
];

try {
    foreach ($profiles as $profile => $values) {
        $environment = array_merge($_ENV, $values);
        foreach ($retiredEntrypoints as $file => $component) {
            $result = $run([PHP_BINARY, $jobs . '/' . $file], $temporary, $environment);
            $assert($result['exit'] === 0, 'retired_entrypoint_exit_invalid:' . $profile . ':' . $file);
            $assert(
                trim($result['stdout']) === 'LEGACY_AUTOMATION_RETIRED component=' . $component
                    . ' remote=false http=0',
                'retired_entrypoint_receipt_invalid:' . $profile . ':' . $file,
            );
            $assert($result['stderr'] === '', 'retired_entrypoint_stderr_not_empty:' . $profile . ':' . $file);
            $assert(!is_file($sentinel), 'retired_entrypoint_reached_bootstrap:' . $profile . ':' . $file);
        }
    }

    foreach (['v3', 'v4'] as $engine) {
        $result = $run([
            PHP_BINARY,
            $jobs . '/queue_engine_control.php',
            '--set=' . $engine,
            '--expected-generation=7',
        ], $temporary, array_merge($_ENV, $profiles['v4_all_enabled']));
        $payload = json_decode($result['stdout'], true, 16, JSON_THROW_ON_ERROR);
        $assert($result['exit'] === 0, 'engine_activation_exit_invalid:' . $engine);
        $assert(($payload['status'] ?? null) === 'legacy_engine_activation_retired', 'engine_activation_not_retired:' . $engine);
        $assert(($payload['remote'] ?? null) === false && ($payload['http'] ?? null) === 0, 'engine_activation_remote_authority_invalid:' . $engine);
        $assert(!is_file($sentinel), 'engine_activation_reached_bootstrap:' . $engine);
    }
    foreach (['preparing', 'ready', 'certified'] as $readiness) {
        $result = $run([
            PHP_BINARY,
            $jobs . '/queue_engine_control.php',
            '--readiness=' . $readiness,
            '--expected-generation=7',
        ], $temporary, array_merge($_ENV, $profiles['v3_all_enabled']));
        $payload = json_decode($result['stdout'], true, 16, JSON_THROW_ON_ERROR);
        $assert($result['exit'] === 0, 'engine_readiness_exit_invalid:' . $readiness);
        $assert(($payload['status'] ?? null) === 'legacy_engine_activation_retired', 'engine_readiness_not_retired:' . $readiness);
        $assert(!is_file($sentinel), 'engine_readiness_reached_bootstrap:' . $readiness);
    }

    foreach (['enable', 'enable-global', 'import', 'close', 'unknown-mutating-action'] as $action) {
        $result = $run([
            PHP_BINARY,
            $jobs . '/queue_core_historical.php',
            '--action=' . $action,
            '--confirm-enable',
            '--source=orders',
            '--company=1',
            '--account=1',
        ], $temporary, array_merge($_ENV, $profiles['v4_all_enabled']));
        $payload = json_decode($result['stdout'], true, 16, JSON_THROW_ON_ERROR);
        $assert($result['exit'] === 0, 'historical_mutation_exit_invalid:' . $action);
        $assert(($payload['status'] ?? null) === 'LEGACY_MUTATION_RETIRED', 'historical_mutation_not_retired:' . $action);
        $assert(($payload['action'] ?? null) === $action, 'historical_mutation_action_invalid:' . $action);
        $assert(($payload['remote'] ?? null) === false && ($payload['http'] ?? null) === 0, 'historical_remote_authority_invalid:' . $action);
        $assert(!is_file($sentinel), 'historical_mutation_reached_bootstrap:' . $action);
    }

    $engineJob = (string) file_get_contents($root . '/jobs/queue_engine_control.php');
    $engineCli = (string) file_get_contents($root . '/app/QueueCore/QueueEngineControlCli.php');
    $historical = (string) file_get_contents($root . '/jobs/queue_core_historical.php');
    $rollback = (string) file_get_contents($root . '/jobs/queue_core_rollback.php');
    $rollbackService = (string) file_get_contents($root . '/app/Services/QueueCoreRollbackService.php');
    $assert(strpos($engineJob, 'legacy_engine_activation_retired') < strpos($engineJob, "require dirname(__DIR__) . '/bootstrap.php'"), 'engine_retirement_after_bootstrap');
    $assert(str_contains($engineCli, "\$desired !== 'disabled'") && str_contains($engineCli, "compareAndSwap('disabled'"), 'engine_disable_only_contract_missing');
    $assert(str_contains($historical, "['status', 'sources']") && strpos($historical, 'LEGACY_MUTATION_RETIRED') < strpos($historical, "require dirname(__DIR__) . '/bootstrap.php'"), 'historical_read_only_gate_invalid');
    $assert(
        str_contains($rollback, "if (!in_array('--prepare'")
            && str_contains($rollback, "PHP_SAPI !== 'cli'")
            && str_contains($rollbackService, "compareAndSwap('disabled'")
            && !str_contains($rollbackService, "compareAndSwap('v3'")
            && !str_contains($rollbackService, "compareAndSwap('v4'"),
        'rollback_offline_disable_contract_missing',
    );

    require_once $root . '/vendor/autoload.php';
    $controller = (new ReflectionClass(\App\Controllers\SettingsController::class))->newInstanceWithoutConstructor();
    $guard = new ReflectionMethod(\App\Controllers\SettingsController::class, 'assertLegacyCronMutationDisabled');
    try {
        $guard->invoke($controller);
        throw new RuntimeException('web_legacy_guard_did_not_throw');
    } catch (HttpException $error) {
        $assert($error->status === 410, 'web_legacy_guard_status_invalid');
        $assert($error->getMessage() === 'Cron V3 fue retirado. Queue V4 es la única automatización disponible.', 'web_legacy_guard_message_invalid');
    }

    $controllerSource = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
    foreach ($webMutations as $method => $route) {
        $start = strpos($controllerSource, 'public function ' . $method . '(');
        $next = $start === false ? false : strpos($controllerSource, 'public function ', $start + 16);
        $body = $start === false ? '' : substr($controllerSource, $start, $next === false ? null : $next - $start);
        $guardAt = strpos($body, '$this->assertLegacyCronMutationDisabled();');
        $authAt = strpos($body, '$this->requireAdminPermanent();');
        $assert($guardAt !== false && $authAt !== false && $guardAt < $authAt, 'web_guard_order_invalid:' . $method);
        try {
            $controller->{$method}();
            throw new RuntimeException('web_mutation_not_blocked:' . $route);
        } catch (HttpException $error) {
            $assert($error->status === 410, 'web_mutation_status_invalid:' . $route);
            $assert(
                $error->getMessage() === 'Cron V3 fue retirado. Queue V4 es la única automatización disponible.',
                'web_mutation_message_invalid:' . $route,
            );
        }
    }

    $assert(!is_file($sentinel), 'blocked_matrix_reached_poison_bootstrap');

    echo 'LEGACY_REACTIVATION_FAIL_CLOSED_2391=PASS checks=' . $checks
        . ' routes=' . count($webMutations)
        . ' bootstrap=0 http=0 business_dml=0' . PHP_EOL;
} finally {
    $remove($temporary);
}
