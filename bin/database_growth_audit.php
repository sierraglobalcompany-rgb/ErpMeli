<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

\App\Core\Database::useProfile('diagnostic');
$persist = in_array('--persist', $_SERVER['argv'] ?? [], true);
$inspector = new \App\Services\StorageGrowthInspector();
$snapshot = $inspector->snapshot($persist, 'manual_cli');
$snapshot['producers'] = $inspector->producers();

echo json_encode(
    $snapshot,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . PHP_EOL;
