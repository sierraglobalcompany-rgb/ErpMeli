<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
$contract=(new App\QueueCore\ManualRemoteCapabilityRegistry())->forSource('notification_fallback','item_exact',['resource_type'=>'item','remote_resource_id'=>'MCO8105']);
k1b_assert(is_array($contract) && $contract['operation_key']==='item_detail' && $contract['max_remote_calls']===1,'uncached_manual_item_uses_real_transport_profile');
k1b_assert(preg_match($contract['endpoint_pattern'],'/items/MCO8105')===1 && preg_match($contract['endpoint_pattern'],'/items/MCO8105/description')===0,'manual_item_no_secondary_endpoint');
echo "STATUS=PASS CAP2_MANUAL_ITEM_CONTRACT\n";
