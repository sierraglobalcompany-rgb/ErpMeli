<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';

use App\Services\CapacityPolicyService;

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

$service = new ReflectionClass(CapacityPolicyService::class);
$controller = new ReflectionClass(App\Controllers\SettingsController::class);
$assert(
    !$service->hasMethod('requiresManualAdoptionForRhythm'),
    'legacy_rhythm_manual_adoption_hook_removed'
);
$assert(
    !$controller->hasMethod('requiresManualAdoptionForRhythm'),
    'controller_does_not_own_legacy_rhythm_adoption_hook'
);

$controllerSource = (string) file_get_contents($controller->getFileName());
$assert(
    !str_contains($controllerSource, 'requiresManualAdoptionForRhythm'),
    'rhythm_save_no_longer_consults_manual_capacity_adoption'
);

if ($failures !== []) {
    fwrite(STDERR, "FAIL capacity_phase2_contract\n" . implode("\n", $failures) . "\n");
    exit(1);
}

echo "STATUS=PASS CAPACITY_PHASE2_CONTRACT\n";
