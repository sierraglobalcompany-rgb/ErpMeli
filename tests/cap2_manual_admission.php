<?php
declare(strict_types=1);
require __DIR__.'/cap2_manual_fixture.php';
use App\Services\ManualCampaignPreviewService;
use App\Services\ManualSingleStepService;
use App\Services\ManualPhysicalCallBudget;
use App\Services\CapacityPolicyService;
use App\Services\Cap2DomainsWire;

$h=cap2_manual_database();
try {
    $pdo=$h->pdo(); $previews=new ManualCampaignPreviewService();
    // Break caught: consume silently succeeds when another request already won.
    $token=cap2_manual_preview($pdo);
    $previews->load($token,9007); $previews->load($token,9007);
    $previews->consume($token,9007);
    $other=cap2_manual_connection();App\Core\Database::setConnection($other);
    k1b_assert(cap2_manual_rejected(fn()=>$previews->consume($token,9007)),'atomic_admission_requires_exactly_one_winner');
    App\Core\Database::setConnection($pdo);
    echo "PASS=atomic_admission_requires_exactly_one_winner\n";
    $token=cap2_manual_preview($pdo);
    k1b_assert(cap2_manual_rejected(fn()=>$previews->consume($token,9008)) && cap2_manual_state($pdo,$token)==='ready','wrong_user_cannot_consume');
    $previews->load($token,9007);
    $other->prepare('UPDATE manual_campaign_previews SET expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE preview_token=?')->execute([$token]);
    k1b_assert(cap2_manual_rejected(fn()=>$previews->consume($token,9007)) && cap2_manual_state($pdo,$token)==='ready','expiry_rechecked_at_atomic_admission');
    k1b_assert(cap2_manual_rejected(fn()=>ManualPhysicalCallBudget::resolve(['block_size'=>1],1,(new CapacityPolicyService())->snapshot('manual'))),'uncertified_legacy_requires_recalculation');

    $row=cap2_manual_notification($pdo,'8201');
    Cap2DomainsWire::$responses['/questions/8201']=[200,['id'=>8201,'text'=>'manual','status'=>'UNANSWERED','seller_id'=>99011]];
    $token=cap2_manual_preview($pdo,[$row]);
    $leases=new App\QueueCore\QueueExecutionLeaseService($other);$lease=$leases->acquire('cron_v4','cap2-busy',60);
    k1b_assert($lease!==null,'fixture_has_global_cron_lease');
    try { k1b_assert(cap2_manual_rejected(fn()=>(new ManualSingleStepService())->execute($token,9007)),'busy_is_rejected'); }
    finally {$leases->release($lease);}
    k1b_assert(cap2_manual_state($pdo,$token)==='ready' && Cap2DomainsWire::$calls===[],'busy_preserves_ready_zero_http');
    // Break caught: admission was after the physical effect, permitting replay after crash.
    Cap2DomainsWire::$onWire=static function() use($pdo,$token): void {
        k1b_assert(cap2_manual_state($pdo,$token)==='consumed','admission_precedes_first_physical_effect');
    };
    $result=(new ManualSingleStepService())->execute($token,9007);
    Cap2DomainsWire::$onWire=null;
    k1b_assert(count(Cap2DomainsWire::$calls)===1 && $result['completed_count']===1,'real_services_handler_fences_complete_once:'.json_encode($result));
    k1b_assert(cap2_manual_rejected(fn()=>(new ManualSingleStepService())->execute($token,9007)) && count(Cap2DomainsWire::$calls)===1,'lost_response_replay_zero_http');
    echo "PASS=real_manual_admission_replay_busy\nSTATUS=PASS CAP2_MANUAL_ADMISSION\nREAL_MELI_HTTP=0\n";
} finally {Cap2DomainsWire::$onWire=null;$h->cleanup();}
