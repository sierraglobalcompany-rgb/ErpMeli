<?php
declare(strict_types=1);
require __DIR__.'/k3_safe_pack_reentry_fixture.inc.php';
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\Services\CronAdmissionService;
use App\Services\OrderEnrichmentService;
use App\Services\CronDeadlineContext;
use App\Services\CallsWireOptions;
use App\Services\Cap2DomainsWire;

foreach(['deadline_pending','deadline_retry','rhythm_retry'] as $case){
    $seed=r0h3_seed($pdo);r0h3_prepare_transport_fixture($pdo,1);$t=$seed['healthy'][0];
    $s=(new OrderEnrichmentService())->enqueue(7201,$t['order_id'],'pack',$t['external_pack_id'],10);
    $pdo->prepare('UPDATE order_resource_enrichment_jobs SET next_run_at=? WHERE id=?')->execute(['2000-01-01 00:00:00',$s]);
    $pdo->beginTransaction();$receipt=(new CronAdmissionService($pdo))->submit('order_enrichment_pack',7200,7201,$s,'source:'.$s,['pack_id'=>$t['external_pack_id']]);$pdo->commit();
    $repo=new QueueV4CleanRepository($pdo);$run=$repo->beginRun('test','real-deferral');$job=$repo->claim($run,'real-deferral',60,[7201]);
    CronDeadlineContext::start(45,40,8,3);QueueV4CleanCycleBudget::start(1,'automatic',microtime(true)+45);
    if($case==='deadline_pending')CallsWireOptions::$clockOffset=100.0;
    if($case==='deadline_retry')CallsWireOptions::$onFinalOptions=static function():void{CallsWireOptions::$clockOffset=100.0;};
    if($case==='rhythm_retry')$pdo->exec("UPDATE api_rhythm_states SET block_pause_until=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 1 HOUR) WHERE scope_key='global'");
    $worker=new QueueV4CleanWorker($pdo,$repo);
    try{$out=(new ReflectionMethod($worker,'handleDomainExact'))->invoke($worker,$job,7200,7201,json_decode($job['payload_json'],true));}
    finally{CallsWireOptions::$clockOffset=0.0;CallsWireOptions::$onFinalOptions=null;CronDeadlineContext::clear();QueueV4CleanCycleBudget::clear();}
    $expected='domain_source_waiting:order_enrichment_pack:waiting_'.($case==='rhythm_retry'?'rhythm':'deadline');
    r0h3_assert($out['state']==='waiting'&&$out['classification']===$expected,'real_deferral_emitted',['case'=>$case,'outcome'=>$out]);
    $repo->deferWithoutAttemptPenalty($job,$run,$expected,$out['next_safe_at']??null);$repo->finishRun($run,'completed');
    $prior=$pdo->query('SELECT * FROM queue_v4_clean_attempts WHERE job_id='.(int)$job['id'])->fetch(PDO::FETCH_ASSOC);
    r0h3_assert($prior['dispatch_state']==='NOT_DISPATCHED'&&(int)$prior['physical_http_calls']===0&&count(Cap2DomainsWire::$calls)===0,'real_zero_dispatch');
    $source=sprRow($pdo,'order_resource_enrichment_jobs',$s);
    r0h3_assert($source['status']===($case==='deadline_pending'?'pending':'retry'),'real_source_pair',$source);
    // Only time/pause advancement in the disposable test DB; no manual recovery.
    $pdo->exec('UPDATE queue_v4_clean_jobs SET available_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 MINUTE) WHERE id='.(int)$job['id']);
    $pdo->exec('UPDATE order_resource_enrichment_jobs SET next_run_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id='.$s);
    $pdo->exec("UPDATE api_rhythm_states SET next_allowed_at=NULL,block_pause_until=NULL,calls_in_block=0 WHERE scope_key='global'");
    Cap2DomainsWire::$responses=['/packs/'.$t['external_pack_id']=>[200,r0h3_wire_pack_response($t['external_pack_id'],'920001','920001')]];
    $next=r0h3_worker_run($pdo,1,[7201]);
    r0h3_assert($next['claimed']===1&&sprRow($pdo,'queue_v4_clean_jobs',(int)$job['id'])['state']==='completed','natural_cycle_reentry_and_closure',['case'=>$case,'next'=>$next]);
    r0h3_assert(count(Cap2DomainsWire::$calls)===1&&sprRow($pdo,'queue_v4_clean_attempts',(int)$prior['id'])===$prior,'only_new_generation_sends_and_old_attempt_unchanged');
    echo "PASS REAL_DEFERRAL $case NATURAL_REENTRY_AND_CLOSURE HTTP_SIMULATED=1 MANUAL_RECOVERY=0\n";
}
