<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
$service=new App\Services\ApiRhythmPolicyService();$method=new ReflectionMethod($service,'dedupeBillingPhysicalEvents');
function calls_event(string $source,int $id,string $key,int $status=429,int $company=1,int $account=2):array {
    return ['source'=>$source,'id'=>$id,'physical_key'=>$key,'company_key'=>(string)$company,'account_key'=>(string)$account,
        'http_status'=>$status,'event_at'=>'2026-09-06 12:00:00','endpoint_key'=>'billing_orders','retry_after_seconds'=>0];
}
$events=[];for($i=1;$i<=9;$i++){ $key=str_pad(dechex($i),40,'0',STR_PAD_LEFT);$events[]=calls_event('api_request_logs',$i,$key);$events[]=calls_event('api_remote_permits',$i,$key); }
$result=$method->invoke($service,$events);
k1b_assert(count($result['events'])===9,'distinct_attempts_same_time_not_deduped');
k1b_assert(($result['status']??'')==='OK'&&$result['duplicate_rows_deduped']===9,'exact_pair_deduped_once');
foreach([
    [calls_event('api_request_logs',1,'legacy-work-42'),calls_event('api_remote_permits',2,'legacy-work-42')],
    [calls_event('api_request_logs',1,str_repeat('a',40))],
    [calls_event('api_request_logs',1,str_repeat('a',40)),calls_event('api_remote_permits',1,str_repeat('a',40),200)],
    [calls_event('api_request_logs',1,str_repeat('a',40)),calls_event('api_remote_permits',1,str_repeat('a',40),429,3,2)],
    [calls_event('api_request_logs',1,str_repeat('a',40)),calls_event('api_request_logs',2,str_repeat('a',40)),calls_event('api_remote_permits',1,str_repeat('a',40))],
] as $case) {
    $result=$method->invoke($service,$case);
    k1b_assert(($result['status']??'')==='UNKNOWN','partial_missing_contradictory_identity_unknown');
    k1b_assert(($result['unknown_rows']??0)>0,'unknown_has_explicit_unresolved_rows');
}
$schema=new ReflectionProperty($service,'schemaAvailable');$schema->setValue(null,false);$blocked=false;
try{$service->reserve(1,'GET','/orders/8101',['transport_request_id'=>str_repeat('a',40)]);}catch(Throwable){$blocked=true;}
finally{$schema->setValue(null,null);}
k1b_assert($blocked,'missing_rhythm_schema_never_enables_legacy_fallback');
echo "CALLS_BILLING_IDENTITY_OK\n";
