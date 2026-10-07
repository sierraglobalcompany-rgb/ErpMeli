<?php
declare(strict_types=1);

$path = __DIR__ . '/../resources/release/updater-authority-2.41.1.json';
$json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$contract = (string) ($json['contract'] ?? '');
$dependencies = array_column((array) ($json['new_runtime_dependencies'] ?? []), 'path');

if (!str_contains($contract, '2.41.0/schema302 to 2.41.1/schema303')) {
    throw new RuntimeException('FAIL:updater_authority_contract_must_name_2_41_1_schema303');
}
if (!hash_equals('2.41.1', (string) ($json['target_version'] ?? ''))) {
    throw new RuntimeException('FAIL:updater_authority_target_version_mismatch');
}
if (!in_array('database/migrations/303_outer_cron_http_receipt.sql', $dependencies, true)) {
    throw new RuntimeException('FAIL:updater_authority_must_include_migration303');
}

echo "STATUS=PASS CALLS_FINAL_UPDATER_AUTHORITY_SCHEMA\n";
