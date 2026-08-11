<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/DirectUpdateTransitionPolicy.php';

use App\Services\DirectUpdateTransitionPolicy;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$eligible = DirectUpdateTransitionPolicy::evaluate('2.36.3', '2.35.1', 0, true, true);
$assert($eligible['metadata_only_eligible'] === true, 'Schema293 metadata-only transition was not enabled.');
$assert($eligible['reason'] === 'metadata_only_required', 'Metadata-only reason is incorrect.');

$current = DirectUpdateTransitionPolicy::evaluate('2.36.3', '2.36.3', 0, true, true);
$assert($current['schema_matches'] === true && $current['metadata_only_eligible'] === false, 'Current version misclassified.');

$future = DirectUpdateTransitionPolicy::evaluate('2.36.3', '2.36.4', 0, true, true);
$assert($future['downgrade_blocked'] === true && $future['reason'] === 'downgrade_refused', 'Downgrade was not refused.');

$pending = DirectUpdateTransitionPolicy::evaluate('2.36.3', '2.35.1', 1, true, true);
$assert($pending['metadata_only_eligible'] === false && $pending['reason'] === 'migrations_pending', 'Pending migration was bypassed.');

$files = DirectUpdateTransitionPolicy::evaluate('2.36.3', '2.35.1', 0, false, true);
$assert($files['metadata_only_eligible'] === false && $files['reason'] === 'files_invalid', 'Invalid files were accepted.');

$migration = DirectUpdateTransitionPolicy::evaluate('2.36.3', '2.35.1', 0, true, false);
$assert($migration['metadata_only_eligible'] === false && $migration['reason'] === 'minimum_migration_missing', 'Missing migration was accepted.');

$invalidInstalled = DirectUpdateTransitionPolicy::evaluate('2.36.3', 'Por comprobar', 0, true, true);
$assert($invalidInstalled['reason'] === 'installed_version_invalid', 'Invalid installed version was accepted.');

$invalidFile = DirectUpdateTransitionPolicy::evaluate('desconocida', '2.35.1', 0, true, true);
$assert($invalidFile['reason'] === 'file_version_invalid', 'Invalid file version was accepted.');

fwrite(STDOUT, 'Direct update transition 2.36.3: PASS checks=' . $checks . PHP_EOL);
