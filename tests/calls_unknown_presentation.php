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
    if ($status==='ERROR') {
        k1b_assert(array_key_exists('BILLING_429_RETRY_AFTER_SECONDS',$data) && $data['BILLING_429_RETRY_AFTER_SECONDS']===null,'error_retry_after_became_zero');
        k1b_assert(($data['API_REQUEST_LOGS_HAS_RETRY_AFTER_SECONDS']??null)==='UNKNOWN','schema_error_became_absent_column');
    }
}
$data=$projection->invoke($service,['status'=>'OK','unique_physical_events'=>0,'known_physical_events'=>0,'unknown_rows'=>0]);
k1b_assert($data['BILLING_429_UNIQUE_PHYSICAL_EVENTS']===0,'certified_zero_lost');
$callResult=['api_calls_used'=>null,'api_calls_remaining'=>null,'evidence_state'=>'UNKNOWN'];
ob_start();require __DIR__.'/../app/Views/settings/_calls_result.php';$html=ob_get_clean();
k1b_assert(!str_contains($html,'<strong>0</strong>'),'unknown_rendered_zero');
echo "PASS UNKNOWN diagnostic and physical summary preserve uncertainty\n";

$callResult=['api_calls_used'=>1,'physical_http_calls'=>null,'known_physical_calls'=>0,
    'unresolved_reservations'=>1,'api_calls_remaining'=>2,'evidence_state'=>'UNKNOWN'];
ob_start();require __DIR__.'/../app/Views/settings/_calls_result.php';$html=ob_get_clean();
k1b_assert(preg_match('/Llamadas físicas usadas<\/span>\s*<strong>UNKNOWN<\/strong>/u',$html)===1,'reserved_debit_falsely_rendered_as_sent');
k1b_assert(str_contains($html,'Capacidad consumida sin devolución'),'uncertain_charge_not_explained');
echo "PASS reserved capacity is not falsely labeled physical send\n";
