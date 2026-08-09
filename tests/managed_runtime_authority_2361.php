<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Services/ManagedRuntimeAuthorityService.php';
require dirname(__DIR__) . '/app/Services/RuntimeDriftClassifier.php';

use App\Services\ManagedRuntimeAuthorityService as Authority;
use App\Services\RuntimeDriftClassifier as Drift;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$php = static fn (string $comment): string => "<?php // {$comment}\n";
$files = [
    '.htaccess' => "Deny from all\n",
    'VERSION' => "2.36.1\n",
    'composer.json' => "{}\n",
    'composer.lock' => "{}\n",
    'actualizar.php' => $php('updater entrypoint'),
    'asset.php' => $php('asset entrypoint'),
    'bootstrap.php' => $php('bootstrap'),
    'cron-status.php' => $php('managed root entrypoint'),
    'index.php' => $php('web entrypoint'),
    'login.php' => $php('login entrypoint'),
    'mantenimiento.php' => $php('maintenance entrypoint'),
    'recuperar.php' => $php('recovery entrypoint'),
    'stop.php' => $php('emergency entrypoint'),
    'public/index.php' => "<?php\nuse App\\Controllers\\DemoController;\n\$router->get('/demo', [DemoController::class, 'index']);\n",
    'public/assets/app.css' => "body{}\n",
    'public/assets/app.js' => "'use strict';\n",
    'app/Controllers/DemoController.php' => "<?php View::render('demo/index');\n",
    'app/Views/demo/index.php' => "<link href=\"<?= View::asset(\$base, 'app.css') ?>\">\n",
    'app/Views/layouts/app.php' => "<script src=\"<?= View::asset(\$base, 'app.js') ?>\"></script>\n",
    'jobs/process.php' => $php('cli'),
    'launcher/entrypoint.php' => $php('launcher'),
    'stop/README.php' => $php('stopped runtime'),
    'database/migrations/293_queue_core_runtime_profile_defaults_b2_1.sql' => "SELECT 1;\n",
    'resources/runtime-manifest.json' => "{}\n",
    'resources/release/queue-core-runtime-dependencies.json' => "{}\n",
    'resources/mercadolibre-api/generated/endpoints.json' => "{}\n",
    'resources/mercadolibre-api/source/mercadolibre-api-index.json' => "{}\n",
    'bin/migrate.php' => $php('operator migration entrypoint'),
    'bin/build_update_package.php' => $php('build only'),
    'tests/example.php' => $php('test only'),
    'docs/example.md' => "docs\n",
    'app/graphify-out/cache.json' => "{}\n",
];

$assert(Authority::coverageIssues($files) === [], 'A complete managed runtime fixture was rejected.');
$release = Authority::releasePaths(array_keys($files));
$assert(in_array('cron-status.php', $release, true), 'cron-status.php is not package-authoritative.');
$assert(in_array('bin/migrate.php', $release, true), 'The external migration operator entrypoint is absent.');
$assert(!in_array('bin/build_update_package.php', $release, true), 'A build-only tool entered runtime.');
$assert(!in_array('resources/mercadolibre-api/source/mercadolibre-api-index.json', $release, true),
    'A regeneration source entered runtime.');
$assert(Authority::classifyPath('config.env') === Authority::PROTECTED_EXTERNAL_STATE,
    'config.env is not protected external state.');
$assert(Authority::classifyPath('storage/logs/app.log') === Authority::PROTECTED_EXTERNAL_STATE,
    'Mutable storage is not protected external state.');
$assert(Authority::classifyPath('app/graphify-out/a.json') === Authority::NON_RUNTIME,
    'graphify-out is not explicitly non-runtime.');

$missingCron = $files;
unset($missingCron['cron-status.php']);
$assert(in_array('required_release_path_missing:cron-status.php', Authority::coverageIssues($missingCron), true),
    'Missing cron-status.php did not fail coverage.');
$missingView = $files;
unset($missingView['app/Views/demo/index.php']);
$assert((bool) array_filter(Authority::coverageIssues($missingView),
    static fn (string $issue): bool => str_starts_with($issue, 'routed_view_missing:')),
    'Missing routed view did not fail coverage.');
$missingAsset = $files;
unset($missingAsset['public/assets/app.css']);
$assert((bool) array_filter(Authority::coverageIssues($missingAsset),
    static fn (string $issue): bool => str_starts_with($issue, 'static_asset_missing:')),
    'Missing literal static asset did not fail coverage.');
$missingController = $files;
unset($missingController['app/Controllers/DemoController.php']);
$assert((bool) array_filter(Authority::coverageIssues($missingController),
    static fn (string $issue): bool => str_starts_with($issue, 'route_handler_missing:')),
    'Missing route handler did not fail coverage.');
$unclassified = $files;
$unclassified['unknown-runtime.bin'] = 'x';
$assert(in_array('unclassified_path:unknown-runtime.bin', Authority::coverageIssues($unclassified), true),
    'An unclassified tracked path was silently accepted.');

$hf12 = hash('sha256', 'hf1.2');
$target = hash('sha256', '2.36.1');
foreach ([
    'app/Services/CronV3Cli.php',
    'app/Services/EmergencyControlService.php',
    'app/Services/MeliApiClient.php',
] as $path) {
    $assert(Drift::classify($hf12, $hf12, $target) === Drift::EXPECTED_UPGRADE_DELTA,
        $path . ' was incorrectly blocked as drift.');
}
$assert(Drift::classify($target, $hf12, $target) === Drift::ALREADY_TARGET,
    'An already-target file was not recognized.');
$assert(Drift::classify(null, null, $target) === Drift::EXPECTED_UPGRADE_DELTA,
    'A target-authoritative new file was not treated as an expected addition.');
$assert(Drift::classify(hash('sha256', 'foreign'), $hf12, $target) === Drift::UNEXPECTED_DRIFT,
    'Unauthorized production drift was accepted.');
$assert(Drift::classify(hash('sha256', 'protected'), $hf12, $target, 'PROTECTED_STATE') === 'PROTECTED_STATE',
    'Explicit protected-state authority was ignored.');

fwrite(STDOUT, 'Managed runtime authority 2.36.1: ' . $checks . " checks passed\n");
