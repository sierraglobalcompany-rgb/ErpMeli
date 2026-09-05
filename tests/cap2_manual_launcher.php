<?php
declare(strict_types=1);
require __DIR__.'/cap2_manual_fixture.php';
use App\QueueCore\ManualQueueLauncher;
use App\Services\ManualCampaignPreviewService;
use App\Services\Cap2DomainsWire;
$h=cap2_manual_database();
try {
    $pdo=$h->pdo(); $previews=new ManualCampaignPreviewService();
    $row=cap2_manual_notification($pdo,'8220');
    $state=(new App\Services\ManualCampaignSourceInspector())->inspect('notification_fallback',$row['source_id'],9011,9001);
    $authority=(new App\QueueCore\ManualSourceAuthorityService())->inspect('notification_fallback',$row['source_id'],9011,9001,$state);
    $item=['company_id'=>9001,'account_id'=>9011,'queue_key'=>'notification_fallback','source_id'=>$row['source_id'],'uses_api'=>true,'operation_key'=>$authority->operationKey,'input_version'=>'v1','source_authority_version'=>$authority->durableInputVersion,'explicit_attempt_key'=>hash('sha256','callback'),'remote_contract'=>$authority->remoteContract];
    Cap2DomainsWire::$responses['/questions/8220']=[200,['id'=>8220,'text'=>'admission','status'=>'UNANSWERED']];
    $token=cap2_manual_preview($pdo,[$row]); $invoked=0;
    // Break caught: launcher enqueues/recovers before invoking admission, or never invokes it.
    try {(new ManualQueueLauncher())->runExactBatch([$item],3,null,static function() use($pdo,$previews,$token,&$invoked): void {
        $invoked++;
        k1b_assert((int)$pdo->query("SELECT COUNT(*) FROM queue_core_jobs WHERE queue_domain='manual'")->fetchColumn()===0,'admission_before_enqueue');
        $previews->consume($token,9007);
        throw new RuntimeException('simulated loss immediately after admission');
    });} catch(RuntimeException) {}
    k1b_assert($invoked===1 && cap2_manual_state($pdo,$token)==='consumed','launcher_admission_exactly_once_before_effect');
    k1b_assert((int)$pdo->query("SELECT COUNT(*) FROM queue_core_jobs WHERE queue_domain='manual'")->fetchColumn()===0 && Cap2DomainsWire::$calls===[],'post_admission_failure_no_enqueue_no_http');
    $token=cap2_manual_preview($pdo,[$row]);$invoked=0;
    $bad=array_replace($item,['company_id'=>0]);
    k1b_assert(cap2_manual_rejected(fn()=>(new ManualQueueLauncher())->runExactBatch([$item,$bad],3,null,static function() use(&$invoked): void {$invoked++;})),'whole_selection_validated_before_admission');
    k1b_assert($invoked===0 && Cap2DomainsWire::$calls===[],'invalid_second_row_no_first_effect');
    // Expiry changes after the caller loads its preview, at the admission boundary.
    $previews->load($token,9007);$other=cap2_manual_connection();
    $other->prepare('UPDATE manual_campaign_previews SET expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE preview_token=?')->execute([$token]);
    k1b_assert(cap2_manual_rejected(fn()=>(new ManualQueueLauncher())->runExactBatch([$item],3,null,fn()=>$previews->consume($token,9007))),'expired_after_load_prevents_enqueue');
    k1b_assert(Cap2DomainsWire::$calls===[] && cap2_manual_state($pdo,$token)==='ready','expired_no_effect');
    echo "STATUS=PASS CAP2_MANUAL_LAUNCHER\nREAL_MELI_HTTP=0\n";
} finally {$h->cleanup();}
