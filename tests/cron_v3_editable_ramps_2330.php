<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "ASSERTION FAILED: {$message}\n");
        exit(1);
    }
};

$migration = (string) file_get_contents($root . '/database/migrations/267_cron_v3_editable_ramps_2_33_0.sql');
$rhythm = (string) file_get_contents($root . '/app/Services/ApiRhythmPolicyService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$view = (string) file_get_contents($root . '/app/Views/settings/api_workload.php');

foreach ([
    'cron_v3_ramp_profiles',
    'conservative',
    'balanced',
    'fast',
    'maximum',
    'custom',
    'api.rhythm.ramp_source',
] as $needle) {
    $assert(str_contains($migration, $needle), 'Migration 267 must define editable ramp profile contract: ' . $needle);
}

foreach ([
    'private function rampSteps',
    'api.rhythm.ramp_steps',
    'ramp_min_known_responses',
    'ramp_max_429',
    'ramp_require_drainage',
] as $needle) {
    $assert(str_contains($rhythm, $needle), 'ApiRhythmPolicyService must expose editable ramp setting: ' . $needle);
}

foreach ([
    'sanitizeRampSteps',
    'custom_target_http_per_minute',
    'api.rhythm.ramp_p95_http_ms',
    'api.rhythm.ramp_require_drainage',
] as $needle) {
    $assert(str_contains($controller, $needle), 'SettingsController must persist editable ramp setting safely: ' . $needle);
}

foreach ([
    'Editar rampa personalizada',
    'custom_ramp_steps',
    'ramp_evaluation_minutes',
    'ramp_require_drainage',
    'Trabajo parqueado',
] as $needle) {
    $assert(str_contains($view, $needle), 'Rhythm view must render human editable ramp control: ' . $needle);
}

echo "cron_v3_editable_ramps_2330: OK\n";
