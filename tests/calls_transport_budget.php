<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
use App\QueueV4Clean\QueueV4CleanCycleBudget as B;
function calls_throws(callable $fn,string $label):void {try{$fn();}catch(Throwable){return;}throw new RuntimeException($label);}
$id=str_repeat('a',40);$other=str_repeat('b',40);
B::clear();calls_throws(static fn()=>B::claim($id),'absent_context_must_deny');
B::start(100,'manual',microtime(true)+45);
k1b_assert((B::snapshot()['owner']??null)==='manual','manual_owner_retained');
calls_throws(static fn()=>B::start(1,'automatic'),'nested_context_cannot_reset');
$unusedPdo=new class extends PDO {public function __construct(){}};$nestedReason='';
try{(new App\QueueV4Clean\QueueV4CleanScheduler($unusedPdo))->run();}catch(Throwable $error){$nestedReason=$error->getMessage();}
k1b_assert($nestedReason==='physical_budget_already_owned'&&B::snapshot()['owner']==='manual','nested_scheduler_cannot_touch_or_clear_outer_owner');
B::reserve($id,'queue_core');B::reserve($id,'queue_core');
k1b_assert(B::snapshot()['used']===1,'same_attempt_reserves_once');
calls_throws(static fn()=>B::reserve($id,'queue_v4_clean'),'identity_cannot_change_source');
k1b_assert(!App\Services\ApiExecutionMetadataContext::run(['company_id'=>999],static fn()=>B::releaseBeforeTransport($id)),'foreign_context_cannot_refund_attempt');
k1b_assert(B::releaseBeforeTransport($id),'own_unsent_refunded');
k1b_assert(!B::releaseBeforeTransport($id)&&B::snapshot()['used']===0,'duplicate_refund_noop');
calls_throws(static fn()=>B::reserve($id,'queue_core'),'cancelled_id_cannot_be_reused');
B::reserve($other,'queue_core');B::enteringTransport($other);
k1b_assert(!B::releaseBeforeTransport($other)&&B::snapshot()['used']===1,'sent_never_refunded');
App\Services\ApiExecutionMetadataContext::run(['source'=>'queue_core','manual_physical_http_burst_limit'=>1],static function():void {
    App\Services\ApiExecutionMetadataContext::claimRemoteCall();
    App\Services\ApiExecutionMetadataContext::claimRemoteCall();
});
k1b_assert(B::snapshot()['used']===1,'metadata_does_not_create_parallel_physical_counter');
B::stop('remote_429_global_pause');
k1b_assert(B::snapshot()['remaining']===99&&B::exhausted(),'first429_stops_with99remaining');
calls_throws(static fn()=>B::reserve(str_repeat('c',40),'queue_core'),'protected_cycle_cannot_continue');
B::clear();B::start(1,'automatic',microtime(true)-1);
calls_throws(static fn()=>B::reserve($id,'queue_v4_clean'),'deadline_denies');
B::clear();B::start(1);
k1b_assert(is_float(B::snapshot()['deadline'])&&B::snapshot()['deadline']<=microtime(true)+45,'default_outer_deadline_is_finite');
B::clear();
$send=new ReflectionMethod(App\Services\MeliApiClient::class,'send');$reason='';
try{$send->invoke(new App\Services\MeliApiClient(1),'GET','https://calls-wire.invalid/orders/1',[],[],false,false,['source'=>'queue_v4_clean']);}catch(Throwable $error){$reason=$error->getMessage();}
k1b_assert($reason==='physical_budget_context_required','missing_outer_budget_denied_before_permit_authority_access');
B::start(2,'manual');B::reserve($id,'queue_core');B::stop('remote_result_uncertain');
$receipt=B::snapshot();
k1b_assert($receipt['used']===1&&($receipt['physical_http_calls']??null)===null&&($receipt['physical_http_calls_certainty']??'')==='UNKNOWN'&&($receipt['known_physical_calls']??null)===0,'unresolved_debit_is_not_certified_physical_http');
B::clear();B::start(2,'manual');B::reserve($id,'queue_core');B::enteringTransport($id);B::stop('remote_result_uncertain');
$receipt=B::snapshot();
k1b_assert(($receipt['physical_http_calls']??null)===1&&($receipt['physical_http_calls_certainty']??'')==='CERTIFIED','known_sent_uncertain_response_still_certifies_one_physical_http');
B::clear();B::start(2,'manual');B::reserve($id,'queue_core');B::releaseBeforeTransport($id);
k1b_assert((B::snapshot()['physical_http_calls']??null)===0&&(B::snapshot()['physical_http_calls_certainty']??'')==='CERTIFIED','certified_cancel_keeps_exact_zero_receipt');B::clear();
echo "CALLS_TRANSPORT_BUDGET_OK\n";
