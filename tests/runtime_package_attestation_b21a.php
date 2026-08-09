<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/MeliNotificationTopicRegistry.php';
require dirname(__DIR__) . '/app/Services/RuntimePublicationPolicy.php';

use App\Services\RuntimePublicationPolicy;

$root = dirname(__DIR__);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$has = static fn (array $issues, string $prefix): bool => (bool) array_filter(
    $issues,
    static fn (string $issue): bool => str_starts_with($issue, $prefix),
);

$manifest = json_decode(
    RuntimePublicationPolicy::gitBlob($root, 'HEAD', 'resources/runtime-manifest.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
$files = [];
foreach (RuntimePublicationPolicy::packageEntries($root) as $entry) {
    $files[$entry['path']] = RuntimePublicationPolicy::gitBlob($root, 'HEAD', $entry['path']);
}
$assert(RuntimePublicationPolicy::packageIssues($root, $manifest, $files) === [], 'The Git-exact package was rejected.');

$topicsPath = 'resources/mercadolibre-api/generated/notification-topics.json';
$topicsKey = null;
foreach ($manifest['components'] as $key => $component) {
    if (is_array($component) && ($component['path'] ?? null) === $topicsPath) {
        $topicsKey = $key;
        break;
    }
}
$assert(is_string($topicsKey), 'The notification topic component is absent.');

$missing = $files;
unset($missing[$topicsPath]);
$issues = RuntimePublicationPolicy::packageIssues($root, $manifest, $missing);
$assert($has($issues, 'package_required_missing:' . $topicsPath), 'Missing topics did not fail.');

$stale = $files;
$stale[$topicsPath] = RuntimePublicationPolicy::gitBlob(
    $root,
    RuntimePublicationPolicy::BASE_COMMIT,
    $topicsPath,
);
$issues = RuntimePublicationPolicy::packageIssues($root, $manifest, $stale);
$assert($has($issues, 'package_git_blob_mismatch:' . $topicsPath), 'Stale topics did not fail raw identity.');
$assert($has($issues, 'package_notification_topics_semantic_invalid'), 'Stale topics did not fail pack semantics.');

$malformed = $files;
$malformed[$topicsPath] = "{\"rules\":[";
$issues = RuntimePublicationPolicy::packageIssues($root, $manifest, $malformed);
$assert($has($issues, 'package_git_blob_mismatch:' . $topicsPath), 'Malformed topics with stale manifest did not fail.');
$assert($has($issues, 'package_notification_topics_semantic_invalid'), 'Malformed topics did not fail semantic validation.');

$matchingMalformedManifest = $manifest;
$matchingMalformedManifest['components'][$topicsKey]['sha256'] = hash('sha256', $malformed[$topicsPath]);
$matchingMalformedManifest['components'][$topicsKey]['sha256_lf'] = hash('sha256', $malformed[$topicsPath]);
$issues = RuntimePublicationPolicy::packageIssues($root, $matchingMalformedManifest, $malformed);
$assert($has($issues, 'package_notification_topics_semantic_invalid'), 'A matching malformed manifest bypassed semantics.');

$caseChanged = $files;
unset($caseChanged[$topicsPath]);
$caseChanged['Resources/mercadolibre-api/generated/notification-topics.json'] = $files[$topicsPath];
$issues = RuntimePublicationPolicy::packageIssues($root, $manifest, $caseChanged);
$assert($has($issues, 'package_unsafe_extra:'), 'Case-changed path did not fail.');

$shadow = $files;
$shadow['Resources/mercadolibre-api/generated/notification-topics.json'] = $files[$topicsPath];
$issues = RuntimePublicationPolicy::packageIssues($root, $manifest, $shadow);
$assert($has($issues, 'package_path_case_collision:'), 'Case shadow did not fail.');

$missingComponent = $manifest;
$missingComponent['components'][$topicsKey]['path'] = 'resources/mercadolibre-api/generated/missing.json';
$issues = RuntimePublicationPolicy::packageIssues($root, $missingComponent, $files);
$assert($has($issues, 'manifest_path_not_in_git:'), 'Manifest missing-file pointer did not fail.');

$unmanifested = $manifest;
unset($unmanifested['components'][$topicsKey]);
$issues = RuntimePublicationPolicy::packageIssues($root, $unmanifested, $files);
$assert($has($issues, 'manifest_component_missing:' . $topicsPath), 'Required unmanifested runtime did not fail.');

$different = $files;
$different['app/Services/MeliNotificationTopicRegistry.php'] .= "\n";
$issues = RuntimePublicationPolicy::packageIssues($root, $manifest, $different);
$assert($has($issues, 'package_git_blob_mismatch:app/Services/MeliNotificationTopicRegistry.php'), 'Git blob mismatch did not fail.');

foreach (['../escape.json', 'C:\\escape.json', 'resources\\escape.json'] as $unsafePath) {
    $unsafe = $files;
    $unsafe[$unsafePath] = '{}';
    $assert($has(RuntimePublicationPolicy::packageIssues($root, $manifest, $unsafe), 'package_path_unsafe'), 'Unsafe path passed: ' . $unsafePath);
}

fwrite(STDOUT, 'Runtime package attestation: ' . $checks . " checks passed\n");
