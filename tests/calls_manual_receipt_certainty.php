<?php
declare(strict_types=1);

require __DIR__.'/k1b_bootstrap.php';

use App\QueueV4Clean\QueueV4CleanCycleBudget as Budget;
use App\Services\ApiExecutionMetadataContext as Metadata;
use App\Services\ManualSingleStepService;

$receipt=new ReflectionMethod(ManualSingleStepService::class,'capacityReceipt');
$configuration=['physical_api_call_budget'=>3];
$id=str_repeat('a',40);

Budget::start(3,'manual',microtime(true)+40);
Metadata::run(['source'=>'queue_core','transport_request_id'=>$id],static fn()=>Budget::reserve($id,'queue_core'));
$unknown=$receipt->invoke(new ManualSingleStepService(),[],$configuration,3,3);
k1b_assert($unknown['api_calls_used']===1 && $unknown['api_calls_remaining']===2,'reserved_call_remains_charged_for_enforcement');
k1b_assert($unknown['physical_http_calls']===null && $unknown['physical_http_calls_certainty']==='UNKNOWN','reserved_call_not_presented_as_known_http');
k1b_assert($unknown['known_physical_calls']===0 && $unknown['unresolved_reservations']===1 && $unknown['evidence_state']==='UNKNOWN','unresolved_reservation_is_explicit');
Budget::clear();

$sentId=str_repeat('b',40);
Budget::start(3,'manual',microtime(true)+40);
Metadata::run(['source'=>'queue_core','transport_request_id'=>$sentId],static function()use($sentId):void {
    Budget::reserve($sentId,'queue_core');
    Budget::enteringTransport($sentId);
});
$known=$receipt->invoke(new ManualSingleStepService(),[],$configuration,3,3);
k1b_assert($known['physical_http_calls']===1 && $known['physical_http_calls_certainty']==='CERTIFIED','sent_call_remains_certified_even_if_result_later_unknown');
k1b_assert($known['api_calls_used']===1 && $known['known_physical_calls']===1 && $known['unresolved_reservations']===0,'known_sent_call_matches_enforcement_debit');
Budget::clear();

echo "STATUS=PASS CALLS_MANUAL_RECEIPT_CERTAINTY REAL_HTTP=0\n";
