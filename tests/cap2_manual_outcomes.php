<?php
declare(strict_types=1);
require __DIR__.'/cap2_manual_fixture.php';
use App\Services\Cap2DomainsWire;
use App\Services\ManualSingleStepService;
$h=cap2_manual_database();
try {
    $pdo=$h->pdo();
    $one=cap2_manual_orders($pdo,1);$two=cap2_manual_notification($pdo,'8302');
    // A literal one-row search page of two total is a successful durable checkpoint, not an error.
    $order=['id'=>8301,'status'=>'paid','date_created'=>'2026-08-01T01:00:00Z','last_updated'=>'2026-08-01T01:00:00Z','total_amount'=>10,'paid_amount'=>10,'currency_id'=>'COP','buyer'=>['id'=>500],'seller'=>['id'=>99011],'shipping'=>null,'pack_id'=>null,'tags'=>[],'order_items'=>[],'payments'=>[]];
    Cap2DomainsWire::$responses['/orders/search']=[200,['paging'=>['total'=>2,'offset'=>0,'limit'=>50],'results'=>[$order]]];
    Cap2DomainsWire::$responses['/questions/8302']=[200,['id'=>8302,'text'=>'after checkpoint','status'=>'UNANSWERED','seller_id'=>99011]];
    Cap2DomainsWire::$onWire=static function(): void {usleep(2200000);}; // retain real rhythm between separate exact resources
    $token=cap2_manual_preview($pdo,[$one,$two]);
    $r=(new ManualSingleStepService())->executeMany($token,9007,2);
    k1b_assert(count(Cap2DomainsWire::$calls)===2,'normal_200_checkpoint_allows_second_selection:'.json_encode($r));
    k1b_assert($r['status']==='deferred' && $r['completed_count']===1 && $r['deferred_count']===1 && $r['not_processed_count']===0,'normal_200_checkpoint_truthful_deferred:'.json_encode($r));
    k1b_assert((int)$pdo->query('SELECT cursor_offset FROM sync_batch_chunks WHERE id='.(int)$one['source_id'])->fetchColumn()===1,'checkpoint_persisted');
    echo "PASS=normal_200_checkpoint_not_completed_not_error\n";

    // Break caught: post-checkpoint enqueue crash leaves old preview replayable.
    $three=cap2_manual_orders($pdo,3);$four=cap2_manual_orders($pdo,4);
    $token=cap2_manual_preview($pdo,[$three,$four]);$before=count(Cap2DomainsWire::$calls);
    $failId=(int)$four['source_id'];
    $pdo->exec("CREATE TRIGGER cap2_manual_crash BEFORE INSERT ON queue_core_jobs FOR EACH ROW BEGIN IF NEW.queue_domain='manual' AND NEW.resource_type='orders_sync' AND NEW.resource_id='{$failId}' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='simulated failure after first durable checkpoint'; END IF; END");
    try {k1b_assert(cap2_manual_rejected(fn()=>(new ManualSingleStepService())->executeMany($token,9007,2)),'second_enqueue_crash_reproduced');}
    finally {$pdo->exec('DROP TRIGGER cap2_manual_crash');}
    k1b_assert(cap2_manual_state($pdo,$token)==='consumed' && count(Cap2DomainsWire::$calls)===$before+1,'post_checkpoint_failure_preview_terminal');
    k1b_assert(cap2_manual_rejected(fn()=>(new ManualSingleStepService())->executeMany($token,9007,2)) && count(Cap2DomainsWire::$calls)===$before+1,'checkpoint_failure_replay_zero_http');
    $pdo->exec('UPDATE sync_batch_chunks SET next_run_at=UTC_TIMESTAMP() WHERE id='.(int)$three['source_id']);
    (new App\Services\WorkQueueProjectionService())->refreshQueue('orders_sync');
    $new=cap2_manual_preview($pdo,[$three]);
    usleep(2200000);
    $r=(new ManualSingleStepService())->execute($new,9007);
    k1b_assert($r['completed_count']===1 && (int)$pdo->query('SELECT cursor_offset FROM sync_batch_chunks WHERE id='.(int)$three['source_id'])->fetchColumn()===2,'new_preview_continues_saved_checkpoint:'.json_encode($r));
    echo "PASS=post_checkpoint_failure_replay_and_new_preview\n";

    // Persisted HTTP evidence must outrank any swallowed/complete domain status.
    foreach([401,403,429] as $http) {
        $row=cap2_manual_notification($pdo,(string)(8400+$http));
        $later=cap2_manual_notification($pdo,(string)(8500+$http));
        Cap2DomainsWire::$responses['/questions/'.(8400+$http)]=[$http,['message'=>'protected','error'=>'protected','status'=>$http]];
        $token=cap2_manual_preview($pdo,[$row,$later]);$before=count(Cap2DomainsWire::$calls);
        usleep(2200000);
        $r=(new ManualSingleStepService())->executeMany($token,9007,2);
        k1b_assert(count(Cap2DomainsWire::$calls)===$before+1 && $r['completed_count']===0 && $r['not_processed_count']===1 && $r['stop_reason']==='remote_'.$http,'protected_http_stops_rest_'.$http.':'.json_encode($r));
        k1b_assert($pdo->query('SELECT status FROM meli_notification_work_items WHERE id='.(int)$row['source_id'])->fetchColumn()!=='complete','protected_source_not_complete_'.$http);
        // Fixture resets prevention rows only between independent cases; every tested dispatch uses real guards.
        $pdo->exec('DELETE FROM api_rhythm_penalties');
    }
    Cap2DomainsWire::$onWire=null;
    echo "STATUS=PASS CAP2_MANUAL_OUTCOMES\nREAL_MELI_HTTP=0\n";
} finally {Cap2DomainsWire::$onWire=null;$h->cleanup();}
