<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
$method=new ReflectionMethod(App\Services\CapacityChangeGuard::class,'billingIncreaseAllowed');
$guard=new App\Services\CapacityChangeGuard();
foreach ([[],['status'=>'ERROR','increase_evidence_status'=>'OK'],['status'=>'UNKNOWN'],['status'=>'UNKNOWN','increase_evidence_status'=>'UNKNOWN'],['status'=>'OK','backoff_active'=>true,'increase_evidence_status'=>'OK']] as $row) {
    k1b_assert($method->invoke($guard,$row)===false,'unknown_error_or_pause_allows_increase');
}
k1b_assert($method->invoke($guard,['status'=>'OK','backoff_active'=>false,'increase_evidence_status'=>'OK'])===true,'certified_new_history_blocked');
k1b_assert($method->invoke($guard,['status'=>'UNKNOWN','backoff_active'=>false,'increase_evidence_status'=>'OK'])===true,'new_evidence_cannot_recover_historical_unknown');
echo "PASS capacity increase uses fresh evidence, not expired uncertainty\n";
