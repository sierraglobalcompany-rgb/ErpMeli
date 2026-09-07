<?php
declare(strict_types=1);

$path = __DIR__ . '/../resources/release/updater-authority-2.40.1.json';
$json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$contract = (string) ($json['contract'] ?? '');
$dependencies = array_column((array) ($json['new_runtime_dependencies'] ?? []), 'path');

if (!str_contains($contract, 'schema301')) {
    throw new RuntimeException('FAIL:updater_authority_contract_must_name_schema301');
}
if (str_contains($contract, 'to 2.40.1/schema300')) {
    throw new RuntimeException('FAIL:updater_authority_contract_must_not_target_schema300');
}
if (!in_array('database/migrations/301_k1d_api_safety_2_40_1.sql', $dependencies, true)) {
    throw new RuntimeException('FAIL:updater_authority_must_include_migration301');
}

echo "STATUS=PASS CALLS_FINAL_UPDATER_AUTHORITY_SCHEMA\n";
