<?php
declare(strict_types=1);
require __DIR__.'/cap2_manual_fixture.php';
use App\Services\Cap2DomainsWire;
use App\Services\ManualSingleStepService;
$h=cap2_manual_database();
try {
    $pdo=$h->pdo();$row=cap2_manual_notification($pdo,'MCO8701','item');
    Cap2DomainsWire::$responses['/items/MCO8701']=[200,['id'=>'MCO8701','title'=>'QA item','price'=>10,'base_price'=>10,'currency_id'=>'COP','category_id'=>'MCO1','condition'=>'new','available_quantity'=>1,'sold_quantity'=>0,'status'=>'active','listing_type_id'=>'gold_special','pictures'=>[],'variations'=>[],'attributes'=>[]]];
    $token=cap2_manual_preview($pdo,[$row]);$r=(new ManualSingleStepService())->execute($token,9007);
    k1b_assert($r['completed_count']===1 && count(Cap2DomainsWire::$calls)===1,'real_manual_item_one_fenced_get:'.json_encode($r));
    $row=cap2_manual_notification($pdo,'MCO8701','item');
    $token=cap2_manual_preview($pdo,[$row]);$r=(new ManualSingleStepService())->execute($token,9007);
    k1b_assert($r['completed_count']===1 && $r['api_calls_used']===0 && count(Cap2DomainsWire::$calls)===1,'cached_manual_review_zero_physical');
    $row=cap2_manual_notification($pdo,'MCO8702','item');$later=cap2_manual_notification($pdo,'8703');
    Cap2DomainsWire::$responses['/items/MCO8702']=[429,['message'=>'rate limited','status'=>429,'error'=>'too_many_requests']];
    usleep(2200000);
    $token=cap2_manual_preview($pdo,[$row,$later]);$r=(new ManualSingleStepService())->executeMany($token,9007,2);
    k1b_assert($r['completed_count']===0 && $r['not_processed_count']===1 && $r['stop_reason']==='remote_429' && count(Cap2DomainsWire::$calls)===2,'strict_manual_item_429_stops_selection:'.json_encode($r));
    k1b_assert($pdo->query('SELECT status FROM meli_notification_work_items WHERE id='.(int)$row['source_id'])->fetchColumn()!=='complete','manual_item_error_not_swallowed');
    k1b_assert(cap2_manual_rejected(fn()=>(new ManualSingleStepService())->executeMany($token,9007,2)) && count(Cap2DomainsWire::$calls)===2,'protected_item_replay_zero_http');
    echo "STATUS=PASS CAP2_MANUAL_ITEMS\nREAL_MELI_HTTP=0\n";
} finally {$h->cleanup();}
