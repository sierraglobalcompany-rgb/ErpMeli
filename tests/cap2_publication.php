<?php
declare(strict_types=1);
require __DIR__ . '/k1b_bootstrap.php';

use App\Services\ManagedRuntimePublicationPolicy;

foreach (['.superpowers/sdd/2026-09-05-cap2/task-5-report.md', '.superpowers/sdd/2026-09-05-cap2/review.diff'] as $path) {
    k1b_assert(ManagedRuntimePublicationPolicy::trackedPathClassification($path) === 'NON_RUNTIME', 'workflow_evidence_is_non_runtime:' . $path);
}
k1b_assert(ManagedRuntimePublicationPolicy::trackedPathClassification('.unknown/file.php') === 'UNCLASSIFIED', 'unknown_hidden_paths_stay_closed');
k1b_assert(ManagedRuntimePublicationPolicy::trackedPathClassification('.superpowers/.env') === 'PROTECTED_EXTERNAL_STATE', 'secret_rule_retains_priority');
$entries = ManagedRuntimePublicationPolicy::packageEntries(dirname(__DIR__));
foreach ($entries as $entry) {
    $path = is_array($entry) ? (string) ($entry['path'] ?? '') : (string) $entry;
    k1b_assert(!str_starts_with($path, '.superpowers/'), 'workflow_evidence_never_in_product');
}
echo "STATUS=PASS CAP2_PUBLICATION\n";
