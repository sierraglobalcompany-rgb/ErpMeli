<?php

declare(strict_types=1);

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use App\Services\ApiLogRiskPresenter;
use App\Services\ApiRhythmPolicyService;
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$assert(
    ApiRhythmPolicyService::normalizeBilling429BackoffMinutes([30, 120, 360, 720]) === [1 => 30, 2 => 120, 3 => 360, 4 => 720],
    'DEFAULT_B429_POLICY_NOT_PRESERVED',
);
$assert(
    ApiRhythmPolicyService::normalizeBilling429BackoffMinutes([720, 30, 5, 5]) === [1 => 720, 2 => 720, 3 => 720, 4 => 720],
    'DISORDERED_B429_POLICY_NOT_NORMALIZED_UPWARD',
);
$assert(
    ApiRhythmPolicyService::normalizeBilling429BackoffMinutes([1, 2, 3, 999]) === [1 => 5, 2 => 5, 3 => 5, 4 => 720],
    'B429_POLICY_BOUNDS_NOT_APPLIED',
);

$presenter = new ApiLogRiskPresenter();
$local = $presenter->present(['http_status' => 429, 'reached_remote' => 0, 'outcome_class' => 'policy_delay']);
$assert($local['title'] === 'Pausa preventiva local', 'LOCAL_DELAY_PRESENTED_AS_REMOTE_429');
$assert($local['reached_remote'] === false && $local['blocking'] === false, 'LOCAL_DELAY_REMOTE_FLAGS_INCORRECT');
$remote = $presenter->present(['http_status' => 429, 'reached_remote' => 1, 'outcome_class' => 'remote_error']);
$assert($remote['risk'] === 'Alto' && $remote['blocking'] === true, 'REMOTE_429_PRESENTATION_REGRESSION');

$controller = $read('app/Controllers/SettingsController.php');
$settingsSection = $read('app/Services/SettingsSectionService.php');
$health = $read('app/QueueV4Clean/QueueV4CleanHealthSnapshotService.php');
$logs = $read('app/Services/LogQueryService.php');
$workload = $read('app/Views/settings/api_workload.php');
$logsView = $read('app/Views/logs/index.php');

$assert(str_contains($controller, 'queueV4RhythmIncreaseGate'), 'RHYTHM_INCREASE_NOT_GUARDED_BY_QUEUE_V4');
$assert(!str_contains($controller, 'cronV3RhythmIncreaseGate'), 'RHYTHM_INCREASE_STILL_GUARDED_BY_CRON_V3');
$assert(str_contains($controller, 'QueueV4CleanHealthSnapshotService'), 'QUEUE_V4_SNAPSHOT_NOT_USED_FOR_RHYTHM');
$assert(str_contains($settingsSection, 'normalizeBilling429Backoff') && str_contains($settingsSection, 'beginTransaction'), 'GENERAL_SETTINGS_B429_NOT_ATOMIC');
$assert(str_contains($health, 'stale_running') && str_contains($health, "(int) (\$totals['dead'] ?? 0) > 0"), 'QUEUE_V4_HEALTH_OMITS_DEAD_OR_STALE_LEASE');
$assert(str_contains($logs, 'l.http_status=429 AND l.reached_remote=1'), 'HIGH_429_FILTER_INCLUDES_LOCAL_PAUSES');
$assert(str_contains($workload, 'Backlog operativo Queue V4') && !str_contains($workload, 'Fila remota lista'), 'RHYTHM_UI_STILL_MISLABELS_V4_BACKLOG');
$assert(str_contains($logsView, 'Sin HTTP remoto'), 'LOCAL_PAUSE_HTTP_LABEL_MISSING');

fwrite(STDOUT, 'QUEUE_V4_RHYTHM_AUTHORITY_23912=PASS checks=' . $checks . PHP_EOL);
