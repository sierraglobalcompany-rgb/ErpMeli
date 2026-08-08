<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$coordinator = (string) file_get_contents($root . '/app/Services/CronV3LegacyImportCoordinator.php');
$cli = (string) file_get_contents($root . '/app/Services/CronV3Cli.php');
$adapter = (string) file_get_contents($root . '/app/Services/CronV3LegacyQueueAdapter.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(str_contains($coordinator, 'importOne(int $readLimit = 50, int $materializeLimit = 20)'), 'Import coordinator caps are missing.');
$assert(str_contains($coordinator, 'owner_engine="v3" AND enabled=1'), 'Import coordinator must require V3 ownership.');
$assert(!str_contains($coordinator, 'MeliApiClient'), 'Import coordinator must never use Mercado Libre transport.');
$assert(str_contains($cli, 'CronV3LegacyImportCoordinator') && str_contains($cli, '$lane === \'local\' && !$shadow'), 'Only active local launcher may import legacy rows.');
$assert(str_contains($adapter, 'min(50, $readLimit)') && str_contains($adapter, 'min(20, $materializeLimit)'), 'Adapter hard caps are missing.');

echo "PASS cron_v3_legacy_import_2290\n";
