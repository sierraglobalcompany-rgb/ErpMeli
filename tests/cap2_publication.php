<?php
declare(strict_types=1);
require __DIR__ . '/k1b_bootstrap.php';

use App\Services\ManagedRuntimePublicationPolicy;

foreach (['.superpowers/sdd/2026-09-05-cap2/task-5-report.md', '.superpowers/sdd/2026-09-05-cap2/review.diff'] as $path) {
    k1b_assert(ManagedRuntimePublicationPolicy::trackedPathClassification($path) === 'NON_RUNTIME', 'workflow_evidence_is_non_runtime:' . $path);
}
k1b_assert(ManagedRuntimePublicationPolicy::trackedPathClassification('qa/calls-final/call-path-inventory.csv') === 'NON_RUNTIME', 'qa_evidence_is_non_runtime');
k1b_assert(ManagedRuntimePublicationPolicy::trackedPathClassification('.unknown/file.php') === 'UNCLASSIFIED', 'unknown_hidden_paths_stay_closed');
k1b_assert(ManagedRuntimePublicationPolicy::trackedPathClassification('.superpowers/.env') === 'PROTECTED_EXTERNAL_STATE', 'secret_rule_retains_priority');
$entries = ManagedRuntimePublicationPolicy::packageEntries(dirname(__DIR__));
foreach ($entries as $entry) {
    $path = is_array($entry) ? (string) ($entry['path'] ?? '') : (string) $entry;
    k1b_assert(!str_starts_with($path, '.superpowers/'), 'workflow_evidence_never_in_product');
}
$registry = json_decode((string) file_get_contents(__DIR__ . '/../resources/release/managed-runtime-dependencies-2.41.1.json'), true, 64, JSON_THROW_ON_ERROR);
$dependencies = array_column($registry['runtime_dependencies'], null, 'path');
foreach (['app/Services/CapacityChangeGuard.php', 'app/Services/CapacityPolicyService.php', 'app/Services/ManualPhysicalCallBudget.php', 'app/Views/settings/capacity_confirmation.php'] as $path) {
    k1b_assert(isset($dependencies[$path]) && $dependencies[$path]['required_in_runtime_manifest'] === true, 'capacity_dependency_registered:' . $path);
}
echo "STATUS=PASS CAP2_PUBLICATION\n";
