<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
$projection=new ReflectionMethod(App\Services\QueueV4DiagnosticBundleService::class,'billingEvidenceProjection');
$service=(new ReflectionClass(App\Services\QueueV4DiagnosticBundleService::class))->newInstanceWithoutConstructor();
foreach(['UNKNOWN','ERROR'] as $status) {
    $data=$projection->invoke($service,['status'=>$status,'unique_physical_events'=>null,'known_physical_events'=>2,'unknown_rows'=>9]);
    k1b_assert($data['BILLING_429_UNIQUE_PHYSICAL_EVENTS']===null,'unknown_count_became_zero');
    k1b_assert($data['BILLING_429_EVIDENCE_STATE']===$status,'certainty_not_preserved');
    k1b_assert($data['BILLING_429_KNOWN_PHYSICAL_EVENTS']===2,'known_subset_lost');
    k1b_assert(array_key_exists('BILLING_429_STREAK',$data) && $data['BILLING_429_STREAK']===null,'unknown_streak_became_zero');
    k1b_assert(($data['BILLING_429_BACKOFF_ACTIVE']??null)==='UNKNOWN','missing_backoff_presented_safe');
}
$data=$projection->invoke($service,['status'=>'OK','unique_physical_events'=>0,'known_physical_events'=>0,'unknown_rows'=>0]);
k1b_assert($data['BILLING_429_UNIQUE_PHYSICAL_EVENTS']===0,'certified_zero_lost');
$callResult=['api_calls_used'=>null,'api_calls_remaining'=>null,'evidence_state'=>'UNKNOWN'];
ob_start();require __DIR__.'/../app/Views/settings/_calls_result.php';$html=ob_get_clean();
k1b_assert(!str_contains($html,'<strong>0</strong>'),'unknown_rendered_zero');
echo "PASS UNKNOWN diagnostic and physical summary preserve uncertainty\n";
