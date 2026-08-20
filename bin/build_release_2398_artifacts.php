<?php

declare(strict_types=1);

putenv('ERP_RELEASE_BASE_REF=609fea570d9268e7aea94374e5bac7d4cd95e73d');
putenv('ERP_RELEASE_ID=erp-meli-2.39.8-h4-pack-integrity-release');
putenv('ERP_RELEASE_SEQUENCE=23908');
putenv('ERP_RELEASE_UPGRADE_FROM=2.39.7');
putenv('ERP_RELEASE_SCHEMA=299');
putenv('ERP_RELEASE_MIGRATION_NOTE=actualización code-only H4; conserva schema 299 y no aplica migraciones');
putenv('ERP_RELEASE_INSTRUCTION=Conservar la configuración física actual de Cron, max-jobs, Billing 900s, FIFO y backlog histórico; esta actualización no ejecuta backfill ni reparación automática. El tooling H4 de backfill requiere autorización separada y ejecución manual posterior.');

require __DIR__ . '/build_release_2384_artifacts.php';

$source = realpath((string) ($arguments['source'] ?? dirname(__DIR__)));
$output = realpath((string) ($arguments['output-dir'] ?? ''));
if ($source === false || $output === false) {
    throw new RuntimeException('h4_auxiliary_artifact_arguments_invalid');
}

$version = \App\Services\ManagedRuntimePublicationPolicy::VERSION;
$auxiliary = [
    'tools/ERP_MELI_2.39.8_PACK_INTEGRITY_BACKFILL.php' => 'ERP_MELI_2.39.8_PACK_INTEGRITY_BACKFILL.php',
    'tests/h4_pack_integrity_backfill_mysql.php' => 'tests_h4_pack_integrity_backfill_mysql.php',
    'docs/H4_BACKFILL_DESIGN.txt' => 'H4_BACKFILL_DESIGN.txt',
];

$authorityPath = $output . DIRECTORY_SEPARATOR . 'ERP_MELI_' . $version . '_ARTIFACT_MANIFEST.json';
$sumsPath = $output . DIRECTORY_SEPARATOR . 'ERP_MELI_' . $version . '_SHA256SUMS.txt';
$authority = json_decode((string) file_get_contents($authorityPath), true, 512, JSON_THROW_ON_ERROR);
$auxiliaryRows = [];
foreach ($auxiliary as $relative => $name) {
    $sourcePath = $source . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $targetPath = $output . DIRECTORY_SEPARATOR . $name;
    if (!is_file($sourcePath)) {
        throw new RuntimeException('h4_auxiliary_source_missing:' . $relative);
    }
    if (!copy($sourcePath, $targetPath)) {
        throw new RuntimeException('h4_auxiliary_copy_failed:' . $relative);
    }
    $row = [
        'source_path' => $relative,
        'artifact_name' => $name,
        'bytes' => filesize($targetPath),
        'sha256' => hash_file('sha256', $targetPath),
    ];
    $auxiliaryRows[] = $row;
    $authority['artifacts'][$name] = ['bytes' => $row['bytes'], 'sha256' => $row['sha256']];
}
$authority['auxiliary_artifacts'] = $auxiliaryRows;
file_put_contents($authorityPath, release2380Json($authority));
$authority['artifacts'][basename($authorityPath)] = [
    'bytes' => filesize($authorityPath),
    'sha256' => hash_file('sha256', $authorityPath),
];

$sumLines = [];
foreach ($authority['artifacts'] as $name => $definition) {
    if ($name === basename($sumsPath)) {
        continue;
    }
    $sumLines[] = $definition['sha256'] . '  ' . $name;
}
sort($sumLines, SORT_STRING);
file_put_contents($sumsPath, implode("\n", $sumLines) . "\n");
