<?php
declare(strict_types=1);
require __DIR__.'/k3_safe_pack_reentry_fixture.inc.php';
use App\QueueV4Clean\QueueV4CleanProducer;
use App\QueueV4Clean\PackDiscoveryOccupancyPolicy;
use App\Services\Cap2DomainsWire;

$f=sprFixture($pdo);$repo=$f['repo'];$t=$f['target'];
// Keep only UNIT_B among synthetic healthy candidates, plus the protected H3.
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$pdo->exec('DELETE FROM meli_pack_orders WHERE meli_pack_id IN (SELECT id FROM meli_packs WHERE meli_account_id IN (7201,7202) AND id<>'.$t['pack_id'].')');
$pdo->exec('DELETE FROM meli_orders WHERE meli_account_id IN (7201,7202) AND id<>'.$t['order_id']);
$pdo->exec('DELETE FROM meli_packs WHERE meli_account_id IN (7201,7202) AND id<>'.$t['pack_id']);
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$held=k3u2_create_unit($pdo,'UNIT-01',7202,false);
$heldHashes=[r0h3_source_hash($pdo,$held['source_id']),r0h3_queue_hash($pdo,$held['queue_id']),r0h3_attempts_hash($pdo,7200,7202,$held['queue_id']),r0h3_transport_hash($pdo,7200,7202,$held['queue_id'])];
$h3=(string)$pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='queue_v4.pack_discovery_h3_authority'")->fetchColumn();
r0h3_defer_fresh_order_discovery($pdo);
$scope=k3u2_scope();
$measure=static function()use($pdo,$scope):int{$pdo->beginTransaction();$v=(new PackDiscoveryOccupancyPolicy($pdo))->outstandingForAccounts($scope);$pdo->commit();return $v;};
$observed=[$measure()];r0h3_assert($observed[0]===2,'two_real_occupants');
$n=$repo->releaseDueRetryableDirectWaiting([7201,7202]);r0h3_assert($n===1&&$measure()===2,'ready_is_not_vacancy');
r0h3_assert(count(Cap2DomainsWire::$calls)===0,'wakeup_zero_http');
Cap2DomainsWire::$responses=['/packs/'.$t['external_pack_id']=>[200,r0h3_wire_pack_response($t['external_pack_id'],'920001','920001')]];
$runB=r0h3_worker_run($pdo,1,[7201]);
$observed[]=$measure();r0h3_assert($observed[1]===1,'legitimate_worker_closure_releases_slot',['run'=>$runB,'occupancy'=>$observed]);
r0h3_assert(sprRow($pdo,'queue_v4_clean_jobs',$f['job_id'])['state']==='completed'&&sprRow($pdo,'order_resource_enrichment_jobs',$f['source_id'])['status']==='complete','unit_b_closed_by_services');
// General FIFO candidate predates financial candidate: PR21 must select finance.
r0h3_add_local_pack($pdo,7200,7201,'860099','960099');
r0h3_add_local_pack($pdo,7200,7201,'860100','960100');
$fin=r0h3_add_financial_waiting($pdo,7200,7201,'860100');
r0h3_assert($repo->releaseDueWaiting([7201])===0,'finance_blocked_before_integrity');
$pdo->exec("INSERT INTO queue_v4_clean_checkpoints(producer_key,company_id,meli_account_id,next_due_at,last_job_id) VALUES('pack_discovery_fairness',0,0,UTC_TIMESTAMP(3),1) ON DUPLICATE KEY UPDATE last_job_id=1");
$wire=count(Cap2DomainsWire::$calls);$producer=new QueueV4CleanProducer($pdo,$repo);$produced=$producer->produce(60);
$admitted=r0h3_pack_discovery_queue_for($pdo,7201,'860100');
r0h3_assert(is_array($admitted)&&(int)$produced['pack_discovery_created']===1,'pr21_admits_financial_in_real_producer',$produced);
r0h3_assert(r0h3_pack_discovery_queue_for($pdo,7201,'860099')===null,'general_fifo_waits');
r0h3_assert(count(Cap2DomainsWire::$calls)===$wire,'admission_zero_http');
$observed[]=$measure();r0h3_assert($observed[2]===2,'new_real_unit_uses_slot');
Cap2DomainsWire::$responses=[
    '/packs/860100'=>[200,r0h3_wire_pack_response('860100','960100','960101')],
    '/orders/960101'=>[200,r0h3_wire_order_response('960101','860100')],
];
r0h3_wait_for_global_rhythm($pdo);$packRun=r0h3_worker_run($pdo,1,[7201]);
r0h3_assert(r0h3_fetch_pack_state($pdo,7201,'860100')['integrity_status']!=='complete','pack_incomplete_before_child');
r0h3_assert($repo->releaseDueWaiting([7201])===0,'finance_blocked_until_legitimate_child');
r0h3_wait_for_global_rhythm($pdo);$childRun=r0h3_worker_run($pdo,1,[7201]);
$pack=r0h3_fetch_pack_state($pdo,7201,'860100');
r0h3_assert($pack['integrity_status']==='complete','integrity_by_real_persistence',$pack);
$released=$repo->releaseDueWaiting([7201]);r0h3_assert($released===1,'finance_release_positive_after_integrity');
r0h3_assert($heldHashes===[r0h3_source_hash($pdo,$held['source_id']),r0h3_queue_hash($pdo,$held['queue_id']),r0h3_attempts_hash($pdo,7200,7202,$held['queue_id']),r0h3_transport_hash($pdo,7200,7202,$held['queue_id'])],'unit_a_untouched');
r0h3_assert($h3===(string)$pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='queue_v4.pack_discovery_h3_authority'")->fetchColumn(),'h3_untouched');
echo json_encode(['K3_LOCAL_CONTINUITY'=>'PASS','occupancy'=>$observed,'UNIT_A_PRESERVED'=>true,'H3_PRESERVED'=>true,'FINANCE_RELEASE_BEFORE_INTEGRITY'=>0,'FINANCE_RELEASE_AFTER_INTEGRITY'=>$released,'pack'=>$pack,'unit_b_run'=>$runB,'financial_admission'=>$produced,'pack_run'=>$packRun,'child_run'=>$childRun,'simulated_wire'=>Cap2DomainsWire::$calls,'REAL_HTTP'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
