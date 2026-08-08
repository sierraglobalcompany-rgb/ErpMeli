<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$service = (string) file_get_contents($root . '/app/Services/CronV3OperationalReadService.php');
$assert(str_contains($service, 'THEN physical_http_calls ELSE 0 END'), 'Panel HTTP real must use physical dispatch evidence.');
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$view = (string) file_get_contents($root . '/app/Views/settings/cron_shell.php');
$javascript = (string) file_get_contents($root . '/public/assets/app.js');

foreach (['cron_v3_work', 'cron_v3_attempts', 'cron_v3_rate_buckets', 'cron_v3_circuit_states', 'cron_v3_snapshots'] as $table) {
    $assert(str_contains($service, "'" . $table . "'"), 'Cron V3 panel does not inspect ' . $table . '.');
}
foreach (['ready', 'leased', 'deferred', 'cooldown', 'review', 'dead', 'oldest_age_seconds', 'throughput_last_hour', 'http_last_hour'] as $metric) {
    $assert(str_contains($service, "'" . $metric . "'"), 'Cron V3 panel is missing metric ' . $metric . '.');
}
$assert(str_contains($service, "'state' => 'unavailable'"), 'Missing schema/snapshots must be unavailable.');
$assert(str_contains($service, "'state' => 'disabled'"), 'Disabled V3 must be explicit.');
$assert(str_contains($service, "!isset(\$snapshots['local'], \$snapshots['remote'])"), 'Both launcher snapshots must be required before health.');
$assert(!preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b\s+/i', $service), 'Cron V3 operational reader contains a mutation statement.');

$assert(str_contains($controller, "\$overview['cron_v3']"), 'V2 overview does not expose the independent V3 snapshot.');
$assert(str_contains($controller, 'CronOperationalReadService())->overview()'), 'V2 Cron overview compatibility was removed.');
foreach (['data-cron-v3', "['local' => 'Local', 'remote' => 'Remoto']", 'data-cron-v3-lane=', 'HTTP real/h'] as $needle) {
    $assert(str_contains($view, $needle), 'Cron V3 view contract is missing ' . $needle . '.');
}
$assert(str_contains($view, 'is-unavailable') && !str_contains($view, 'data-cron-v3-state>Saludable'), 'Initial V3 state may not claim health.');
$assert(str_contains($javascript, 'renderCronV3(lastOverview.cron_v3)'), 'Cron V3 snapshot is not rendered from overview polling.');
$assert(str_contains($javascript, "['healthy', 'attention', 'stale', 'disabled', 'unavailable']"), 'Frontend does not constrain V3 state values.');

echo "cron_v3_panel_2290_ok\n";
