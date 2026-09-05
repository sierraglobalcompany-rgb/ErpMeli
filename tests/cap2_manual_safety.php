<?php
declare(strict_types=1);
require __DIR__.'/cap2_manual_fixture.php';
use App\Services\Cap2DomainsWire;
use App\Services\ManualSingleStepService;
use App\Services\CampaignExecutionContext;
use App\Services\NotificationWorkItemService;
use App\Services\ApiExecutionMetadataContext;
$h=cap2_manual_database();
try {
    $pdo=$h->pdo();$other=cap2_manual_connection();
    $row=cap2_manual_notification($pdo,'8601');
    $token=cap2_manual_preview($pdo,[$row]);
    $other->exec("UPDATE app_settings SET setting_value='1' WHERE setting_key='manual.api_calls_per_step'");
    k1b_assert(cap2_manual_rejected(fn()=>(new ManualSingleStepService())->execute($token,9007)) && cap2_manual_state($pdo,$token)==='ready','reduction_invalidates_before_admission');
    $other->exec("UPDATE app_settings SET setting_value='3' WHERE setting_key='manual.api_calls_per_step'");
    $foreign=array_replace($row,['meli_account_id'=>9012]);$token=cap2_manual_preview($pdo,[$foreign]);
    k1b_assert(cap2_manual_rejected(fn()=>(new ManualSingleStepService())->execute($token,9007)) && cap2_manual_state($pdo,$token)==='ready','foreign_account_company_rejected_ready');
    $context=new CampaignExecutionContext(0,0,9001,'queue_core_manual',1,microtime(true)+25,1,str_repeat('a',64));
    foreach([[],['source'=>'queue_core'],['source'=>'queue_core','company_id'=>9001,'account_id'=>9011,'queue_core_launcher'=>'manual','queue_core_work_type'=>'manual_exact','queue_core_job_id'=>999,'queue_core_attempt_id'=>999,'queue_core_lease_owner'=>'forged','queue_core_execution_owner'=>'forged']] as $meta) {
        $r=ApiExecutionMetadataContext::run($meta,fn()=>(new NotificationWorkItemService())->processExact((int)$row['source_id'],9011,$context,false));
        k1b_assert($r['status']==='protected' && $r['stop_reason']==='manual_ownership_unproven','unproven_manual_owner_protected');
    }
    k1b_assert(Cap2DomainsWire::$calls===[] && $pdo->query('SELECT status FROM meli_notification_work_items WHERE id='.(int)$row['source_id'])->fetchColumn()==='pending','spoofed_metadata_no_http_or_source_effect');
    Cap2DomainsWire::$responses['/questions/8601']=[200,['id'=>8601,'text'=>'capture','status'=>'UNANSWERED','seller_id'=>99011]];
    $token=cap2_manual_preview($pdo,[$row]);$r=(new ManualSingleStepService())->execute($token,9007);
    k1b_assert($r['completed_count']===1 && count(Cap2DomainsWire::$calls)===1,'certified_manual_owner_uses_one_real_fenced_get');
    $meta=Cap2DomainsWire::$calls[0]['meta'];
    $pdo->exec('UPDATE meli_notification_work_items SET status="pending",next_run_at=UTC_TIMESTAMP() WHERE id='.(int)$row['source_id']);
    $job=$pdo->query('SELECT payload_json FROM queue_core_jobs WHERE id='.(int)$meta['queue_core_job_id'])->fetchColumn();
    $context=new CampaignExecutionContext(0,0,9001,'queue_core_manual',(int)$meta['queue_core_lease_generation'],microtime(true)+25,1,json_decode($job,true)['source_authority_version']);
    $r=ApiExecutionMetadataContext::run($meta,fn()=>(new NotificationWorkItemService())->processExact((int)$row['source_id'],9011,$context,false));
    k1b_assert($r['status']==='protected' && count(Cap2DomainsWire::$calls)===1,'stale_genuine_attempt_cannot_authorize_new_effect');

    // A source becoming nonterminal/ineligible after enqueue is not a completion.
    $changed=cap2_manual_notification($pdo,'8609');$untouched=cap2_manual_notification($pdo,'8608');
    $changedId=(int)$changed['source_id'];$token=cap2_manual_preview($pdo,[$changed,$untouched]);
    $pdo->exec("CREATE TRIGGER cap2_source_race AFTER INSERT ON queue_core_jobs FOR EACH ROW BEGIN IF NEW.queue_domain='manual' AND NEW.resource_id='{$changedId}' THEN UPDATE meli_notification_work_items SET next_run_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE id={$changedId}; END IF; END");
    try {$r=(new ManualSingleStepService())->executeMany($token,9007,2);}
    finally {$pdo->exec('DROP TRIGGER cap2_source_race');}
    k1b_assert($r['completed_count']===0 && $r['not_processed_count']===1 && count(Cap2DomainsWire::$calls)===1,'ineligible_nonterminal_source_after_admission_not_completed:'.json_encode($r));

    // Available FIFO uses the same admission boundary without an exact launcher.
    $available=(new App\Services\ManualCampaignPreviewService())->create(9007,['scope'=>'available_queue','account_id'=>9011,'physical_api_call_budget'=>1]);
    $token=$available['preview_token'];
    $leases=new App\QueueCore\QueueExecutionLeaseService($other);$lease=$leases->acquire('cron_v4','available-busy',60);
    try {$r=(new ManualSingleStepService())->execute($token,9007);}
    finally {$leases->release($lease);}
    k1b_assert($r['status']==='waiting' && cap2_manual_state($pdo,$token)==='ready','available_busy_retains_ready');
    // A real SQL event verifies the token before beginRun, then simulates failure.
    $quoted=$pdo->quote($token);
    $pdo->exec("CREATE TRIGGER cap2_available_crash BEFORE INSERT ON queue_v4_clean_runs FOR EACH ROW BEGIN IF (SELECT status FROM manual_campaign_previews WHERE preview_token={$quoted}) <> 'consumed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='token was not consumed before worker'; END IF; SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='simulated admitted worker failure'; END");
    try {k1b_assert(cap2_manual_rejected(fn()=>(new ManualSingleStepService())->execute($token,9007)),'available_worker_failure_reproduced');}
    finally {$pdo->exec('DROP TRIGGER cap2_available_crash');}
    k1b_assert(cap2_manual_state($pdo,$token)==='consumed' && count(Cap2DomainsWire::$calls)===1,'available_failure_terminal_and_zero_extra_http');
    k1b_assert(cap2_manual_rejected(fn()=>(new ManualSingleStepService())->execute($token,9007)),'available_replay_rejected');

    // Real pause authority at an admitted handler: no background continuation or next selection.
    $later=cap2_manual_notification($pdo,'8602');
    (new App\Services\WorkQueueProjectionService())->refreshQueue('notification_fallback');
    $token=cap2_manual_preview($pdo,[$row,$later]);
    (new App\Services\EmergencyControlService())->stopApi('cap2-test','disposable fixture');
    $r=(new ManualSingleStepService())->executeMany($token,9007,2);
    k1b_assert($r['status']==='review' && $r['completed_count']===0 && $r['not_processed_count']===1 && count(Cap2DomainsWire::$calls)===1,'pause_stops_selection_without_false_complete:'.json_encode($r));
    k1b_assert(cap2_manual_state($pdo,$token)==='consumed','admitted_pause_requires_new_preview');
    echo "STATUS=PASS CAP2_MANUAL_SAFETY\nREAL_MELI_HTTP=0\n";
} finally {$h->cleanup();}
