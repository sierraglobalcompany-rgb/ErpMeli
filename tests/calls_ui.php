<?php
declare(strict_types=1);
require __DIR__ . '/k1b_bootstrap.php';
// Real template rendering; no transport or database is involved at this layer.
$_SESSION = ['_csrf' => 'local-calls-fixture'];
$_ENV['APP_URL'] = 'https://local.invalid';
$capacity = ['current'=>1,'ceiling'=>55,'revision'=>'revision1','legacy_derived'=>false];
$scope = 'available_queue'; $campaignReady = true; $emergencyStop = false;
$preview = ['configuration'=>['preview_format'=>4,'scope'=>$scope,'physical_api_call_budget'=>1,'capacity_revision'=>'revision1'],
    'preview_token'=>str_repeat('a',40),'has_more'=>true,'eligible_jobs'=>30,'rows'=>[]];
for ($i=1; $i<=30; $i++) $preview['rows'][] = ['selection_id'=>'confirmed-'.$i,'resource_label'=>'RESOURCE-'.$i.'-END','meli_account_id'=>9011];
ob_start(); require __DIR__ . '/../app/Views/settings/manual_processing.php'; $html = ob_get_clean();
k1b_assert(substr_count($html,'RESOURCE-') === 30, 'budget_one_must_display_all_30_confirmed_rows');
foreach (['block_size','process_limit','interval_seconds','block_pause_seconds','max_blocks','max_duration_minutes'] as $retired) {
    k1b_assert(!str_contains($html, 'name="'.$retired.'"'), 'retired_input:'.$retired);
}
k1b_assert(str_contains($html,'name="physical_api_call_budget"'), 'start_and_preview_use_calls');
k1b_assert(str_contains($html,'confirmed-30'), 'display_stable_selection_identity');
k1b_assert(str_contains($html,'Hay más pendientes'), 'snapshot_truncation_disclosed');
k1b_assert(!str_contains($html,'name="scope" value="modules"'), 'unsupported_module_not_offered');
k1b_assert(str_contains($html,'Disponibilidad no comprobada'), 'unknown_availability_became_zero');
echo "PASS calls UI: full snapshot, calls-only inputs, stable selection, truncation\n";
