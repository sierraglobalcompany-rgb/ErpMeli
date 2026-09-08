<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
$source=file_get_contents(__DIR__.'/calls_verify.ps1');
k1b_assert(str_contains($source,'-- app jobs public resources tests database bootstrap.php'), 'verification_must_seal_tests_and_schema_with_product');
k1b_assert(str_contains($source,"'calls_readiness_contract'"), 'readiness_real_contract_not_registered');
k1b_assert(str_contains($source,"'calls_readiness_transport --mysql'"), 'readiness_wire_contract_not_registered');
k1b_assert(str_contains($source,"'calls_readiness_ui.js'"), 'readiness_ui_contract_not_registered');
k1b_assert(str_contains($source,"'calls_final_billing_checkpoint_continuation_mysql --orders=50 --interval=1 --budget=1'"), 'billing_50_window_gate_not_registered');
k1b_assert(str_contains($source,"'calls_final_concurrency_physical_mysql'"), 'concurrency_physical_gate_not_registered');
k1b_assert(str_contains($source,"'calls_final_seed_matrix_mysql'"), 'seed_matrix_gate_not_registered');
k1b_assert(str_contains($source,"'calls_final_mutation_matrix'"), 'mutation_matrix_gate_not_registered');
k1b_assert(str_contains($source,"'calls_final_updater_authority_hash'"), 'updater_authority_hash_gate_not_registered');
echo "PASS verification input coverage\n";
