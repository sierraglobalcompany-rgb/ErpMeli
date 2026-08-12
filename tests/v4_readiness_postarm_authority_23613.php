<?php

declare(strict_types=1);

$mode = (string) ($argv[1] ?? '');
if (!in_array($mode, ['classic', 'managed'], true)) {
    fwrite(STDERR, "usage: php tests/v4_readiness_postarm_authority_23613.php classic|managed\n");
    exit(2);
}

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/erp-meli-23613-config-' . $mode . '-' . bin2hex(random_bytes(5));
$release = $mode === 'managed' ? $fixture . '/releases/release-23613' : $fixture;
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path)) {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $remove($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    @rmdir($path);
};

try {
    if (!mkdir($release, 0700, true) && !is_dir($release)) {
        throw new RuntimeException('fixture_release_create_failed');
    }
    if ($mode === 'managed') {
        if (!mkdir($fixture . '/shared', 0700, true) && !is_dir($fixture . '/shared')) {
            throw new RuntimeException('fixture_shared_create_failed');
        }
        file_put_contents($fixture . '/shared/current-release.json', "{}\n");
    }
    $expected = $mode === 'managed' ? $fixture . '/shared/config.env' : $fixture . '/config.env';
    file_put_contents($expected, "CRON_V4_ENABLED=false\n");

    define('ERP_INSTALLATION_ROOT', $fixture);
    define('ERP_RELEASE_ROOT', $release);
    require $root . '/app/Core/AppPaths.php';
    require $root . '/app/Core/Env.php';
    require $root . '/app/Services/CronV3SetupAssistantService.php';
    require $root . '/app/Services/V4ReadinessBootstrapService.php';

    $v4 = new \App\Services\V4ReadinessBootstrapService();
    $cron = new \App\Services\CronV3SetupAssistantService();
    $v4PathMethod = new ReflectionMethod($v4, 'configPath');
    $cronPathMethod = new ReflectionMethod($cron, 'configPath');
    $appPath = \App\Core\AppPaths::configFile();
    $v4Path = (string) $v4PathMethod->invoke($v4);
    $cronPath = (string) $cronPathMethod->invoke($cron);
    foreach ([$appPath, $v4Path, $cronPath] as $path) {
        $assert(realpath($path) === realpath($expected), 'config_authority_mismatch:' . $mode);
    }
    $assert($appPath === $v4Path && $v4Path === $cronPath, 'config_path_bytes_mismatch:' . $mode);
    $other = $mode === 'managed' ? $fixture . '/config.env' : $fixture . '/shared/config.env';
    $assert(!file_exists($other), 'secondary_config_written:' . $mode);

    \App\Core\Env::load($expected);
    $assert(!\App\Core\Env::bool('CRON_V4_ENABLED', true), 'initial_env_not_false:' . $mode);
    file_put_contents($expected, "CRON_V4_ENABLED=true\n");
    \App\Core\Env::load($expected);
    $assert(\App\Core\Env::bool('CRON_V4_ENABLED', false), 'same_request_env_not_refreshed:' . $mode);
    $_ENV['CRON_V4_ENABLED'] = 'false';
    $assert(!\App\Core\Env::bool('CRON_V4_ENABLED', true), 'process_override_precedence_changed:' . $mode);
    unset($_ENV['CRON_V4_ENABLED']);

    $postcondition = new ReflectionMethod($v4, 'assertArmPostcondition');
    $postcondition->invoke(null, [
        'state' => 'ready_for_context',
        'reason' => 'stable_authorities_ready',
    ]);
    foreach ([
        'config_write_not_persisted' => ['state' => 'recovery_required', 'reason' => 'config_only_partial'],
        'api_enable_not_persisted' => ['state' => 'recovery_required', 'reason' => 'api_not_enabled'],
        'feature_cas_incomplete' => ['state' => 'blocked', 'reason' => 'feature_generation_profile_invalid'],
        'generation_mismatch' => ['state' => 'blocked', 'reason' => 'feature_generation_invalid:fresh_producer'],
    ] as $name => $postimage) {
        try {
            $postcondition->invoke(null, $postimage);
            $assert(false, 'postcondition_failure_accepted:' . $name);
        } catch (ReflectionException $error) {
            throw $error;
        } catch (Throwable $error) {
            $assert(
                str_starts_with($error->getMessage(), 'v4_bootstrap_arm_postcondition_failed:'),
                'postcondition_error_not_structured:' . $name,
            );
        }
    }

    $source = (string) file_get_contents($root . '/app/Services/V4ReadinessBootstrapService.php');
    $assert(str_contains($source, 'return $this->configPath ?? AppPaths::configFile();'), 'app_paths_fallback_missing');
    $assert(!str_contains($source, "dirname(__DIR__, 2) . '/shared/config.env'"), 'shared_config_fallback_remains');
    $assert(str_contains($source, 'Env::load($this->configPath());'), 'same_request_env_reload_missing');
    $assert(str_contains($source, 'self::assertArmPostcondition($postimage);'), 'postimage_gate_missing');

    echo 'V4 post-arm authority 2.36.13: PASS mode=' . $mode . ' checks=' . $checks
        . ' config=' . str_replace('\\', '/', $expected) . PHP_EOL;
} finally {
    $remove($fixture);
}
