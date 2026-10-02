<?php
declare(strict_types=1);
require __DIR__.'/k3_safe_pack_reentry_fixture.inc.php';

$cases=['deadline_pending','deadline_retry','rhythm_retry','queue_future','source_future','queue_lease','source_lease','source_orphan_token','manual_reservation','wrong_capability','wrong_tenant','wrong_source_account','source_mismatch','noncanonical_id','invalid_json','wrong_resource_type','pack_mismatch','nested_pack_mismatch','source_pair','rhythm_pair','budget','api','rate_limited','unknown','wrong_generation','newer_attempt','historical_running','historical_physical','historical_uncertain','null_calls','physical_calls','started','http','response','journal','orphan_journal','wrong_journal_generation','response_known_200','response_known_429','response_known_503','exhausted_attempts','missing_finish','missing_source_close','foreign_scope','history_identity','protected_h3_unit01_unit02'];
$cases=array_merge($cases,['cause_case','cause_suffix','source_case','capability_case','payload_fraction','pending_remote','pending_failure_space','historical_mislinked_journal','historical_duplicate_journal','historical_known_exact']);
if(isset($argv[1])) $cases=explode(',',$argv[1]);
$results=[];$failed=0;
foreach($cases as $case){
    try{
        $variant=in_array($case,['deadline_retry','rhythm_retry'],true)?$case:'deadline_pending';
        if($case==='rhythm_pair')$variant='rhythm_retry';
        $f=sprFixture($pdo,$variant);$q=$f['job_id'];$s=$f['source_id'];$a=$f['attempt_id'];
        switch($case){
            case 'pending_failure_space':$pdo->exec("UPDATE order_resource_enrichment_jobs SET failure_class=' ' WHERE id=$s");break;
            case 'historical_mislinked_journal':case 'historical_duplicate_journal':case 'historical_known_exact':
                $copy=sprRow($pdo,'queue_v4_clean_attempts',$a);unset($copy['id']);$copy['lease_owner']='new-generation';$copy['lease_generation']=2;
                $new=r0h3_insert($pdo,'queue_v4_clean_attempts',$copy);
                $pdo->exec("UPDATE queue_v4_clean_jobs SET lease_generation=2 WHERE id=$q");
                if($case==='historical_mislinked_journal')sprEvent($pdo,$f,['work_id'=>999999]);
                else{
                    $pdo->exec("UPDATE queue_v4_clean_attempts SET dispatch_state='RESPONSE_KNOWN',physical_http_calls=1,transport_method='GET',endpoint_key='pack_exact',http_status=200,physical_started_at=started_at,response_known_at=finished_at WHERE id=$a");
                    $old=sprRow($pdo,'queue_v4_clean_attempts',$a);
                    $event=['dispatch_state'=>'RESPONSE_KNOWN','http_status'=>200,'physical_started_at'=>$old['physical_started_at'],'response_known_at'=>$old['response_known_at']];
                    sprEvent($pdo,$f,$event);if($case==='historical_duplicate_journal')sprEvent($pdo,$f,$event);
                }
                $f['attempt_id']=$new;break;
            case 'cause_case':case 'cause_suffix':
                $bad=$case==='cause_case'?strtoupper($f['cause']):$f['cause'].':extra';
                $pdo->prepare('UPDATE queue_v4_clean_jobs SET last_error_class=? WHERE id=?')->execute([$bad,$q]);
                $pdo->prepare('UPDATE queue_v4_clean_attempts SET error_class=? WHERE id=?')->execute([$bad,$a]);break;
            case 'source_case':$pdo->exec("UPDATE order_resource_enrichment_jobs SET status='retry',failure_class='WAITING_DEADLINE',reached_remote=0 WHERE id=$s");break;
            case 'capability_case':$pdo->exec("UPDATE queue_v4_clean_jobs SET payload_json=JSON_SET(payload_json,'$.capability','ORDER_ENRICHMENT_PACK') WHERE id=$q");break;
            case 'payload_fraction':$pdo->exec("UPDATE queue_v4_clean_jobs SET payload_json=JSON_SET(payload_json,'$.source_id',1.5) WHERE id=$q");break;
            case 'pending_remote':$pdo->exec("UPDATE order_resource_enrichment_jobs SET reached_remote=1 WHERE id=$s");break;
            case 'queue_future':$pdo->exec("UPDATE queue_v4_clean_jobs SET available_at='2099-01-01' WHERE id=$q");break;
            case 'source_future':$pdo->exec("UPDATE order_resource_enrichment_jobs SET next_run_at='2099-01-01' WHERE id=$s");break;
            case 'queue_lease':$pdo->exec("UPDATE queue_v4_clean_jobs SET lease_owner='other',lease_expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR) WHERE id=$q");break;
            case 'source_lease':$pdo->exec("UPDATE order_resource_enrichment_jobs SET lock_token='other',locked_at=UTC_TIMESTAMP() WHERE id=$s");break;
            case 'source_orphan_token':$pdo->exec("UPDATE order_resource_enrichment_jobs SET lock_token='other',locked_at=NULL WHERE id=$s");break;
            case 'manual_reservation':
                $pdo->exec("DELETE FROM manual_campaigns WHERE campaign_token='".str_repeat('a',40)."'");
                $pdo->exec("INSERT INTO users(id,name,email,password_hash,role,status,is_temporary) VALUES(5001,'Synthetic owner','reentry@example.invalid','unused','admin',1,0) ON DUPLICATE KEY UPDATE status=1");
                k3u2_add_active_reservation($pdo,['company_id'=>7200,'meli_account_id'=>7201,'source_id'=>$s]);
                $pdo->exec("UPDATE manual_campaigns SET status='paused' WHERE campaign_token='".str_repeat('a',40)."'");break;
            case 'wrong_capability':$pdo->exec("UPDATE queue_v4_clean_jobs SET payload_json=JSON_SET(payload_json,'$.capability','financial_reconciliation') WHERE id=$q");break;
            case 'wrong_tenant':$pdo->exec("UPDATE queue_v4_clean_jobs SET company_id=7100 WHERE id=$q");break;
            case 'wrong_source_account':$pdo->exec("UPDATE order_resource_enrichment_jobs SET meli_account_id=7202 WHERE id=$s");break;
            case 'source_mismatch':$pdo->exec("UPDATE queue_v4_clean_jobs SET payload_json=JSON_SET(payload_json,'$.source_id',999999) WHERE id=$q");break;
            case 'noncanonical_id':$pdo->exec("UPDATE queue_v4_clean_jobs SET resource_id='0$s' WHERE id=$q");break;
            case 'invalid_json':$pdo->exec("UPDATE queue_v4_clean_jobs SET payload_json='{' WHERE id=$q");break;
            case 'wrong_resource_type':$pdo->exec("UPDATE order_resource_enrichment_jobs SET resource_type='shipment' WHERE id=$s");break;
            case 'pack_mismatch':$pdo->exec("UPDATE queue_v4_clean_jobs SET payload_json=JSON_SET(payload_json,'$.pack_id','other') WHERE id=$q");break;
            case 'nested_pack_mismatch':$pdo->exec("UPDATE queue_v4_clean_jobs SET payload_json=JSON_SET(payload_json,'$.payload.pack_id','other') WHERE id=$q");break;
            case 'source_pair':$pdo->exec("UPDATE order_resource_enrichment_jobs SET status='retry',failure_class='waiting_rhythm',reached_remote=0 WHERE id=$s");break;
            case 'rhythm_pair':$pdo->exec("UPDATE order_resource_enrichment_jobs SET failure_class='waiting_deadline' WHERE id=$s");break;
            case 'budget':case 'api':case 'rate_limited':case 'unknown':
                $class=in_array($case,['budget','api'],true)?'waiting_'.$case:$case;
                $pdo->prepare('UPDATE queue_v4_clean_jobs SET last_error_class=? WHERE id=?')->execute(['domain_source_waiting:order_enrichment_pack:'.$class,$q]);
                $pdo->prepare('UPDATE queue_v4_clean_attempts SET error_class=? WHERE id=?')->execute(['domain_source_waiting:order_enrichment_pack:'.$class,$a]);break;
            case 'wrong_generation':$pdo->exec("UPDATE queue_v4_clean_attempts SET lease_generation=2 WHERE id=$a");break;
            case 'newer_attempt':case 'historical_running':case 'historical_physical':case 'historical_uncertain':case 'history_identity':
                $copy=sprRow($pdo,'queue_v4_clean_attempts',$a);unset($copy['id']);$copy['lease_owner']='other-attempt';$copy['lease_generation']=$case==='newer_attempt'?2:0;
                if($case==='historical_running')$copy['outcome']='running';
                if($case==='historical_physical')$copy['dispatch_state']='PHYSICAL_STARTED';
                if($case==='historical_uncertain')$copy['error_class']='remote_result_uncertain_safe_get';
                if($case==='history_identity')$copy['company_id']=7100;
                r0h3_insert($pdo,'queue_v4_clean_attempts',$copy);break;
            case 'null_calls':$pdo->exec("UPDATE queue_v4_clean_attempts SET physical_http_calls=NULL WHERE id=$a");break;
            case 'physical_calls':$pdo->exec("UPDATE queue_v4_clean_attempts SET physical_http_calls=1 WHERE id=$a");break;
            case 'started':$pdo->exec("UPDATE queue_v4_clean_attempts SET physical_started_at=UTC_TIMESTAMP(3) WHERE id=$a");break;
            case 'http':$pdo->exec("UPDATE queue_v4_clean_attempts SET http_status=200 WHERE id=$a");break;
            case 'response':$pdo->exec("UPDATE queue_v4_clean_attempts SET response_known_at=UTC_TIMESTAMP(3) WHERE id=$a");break;
            case 'journal':sprEvent($pdo,$f);break;
            case 'orphan_journal':sprEvent($pdo,$f,['attempt_id'=>null,'lease_generation'=>0]);break;
            case 'wrong_journal_generation':sprEvent($pdo,$f,['lease_generation'=>9]);break;
            case 'response_known_200':case 'response_known_429':case 'response_known_503':
                $http=(int)substr($case,-3);$pdo->exec("UPDATE queue_v4_clean_attempts SET dispatch_state='RESPONSE_KNOWN',transport_method='GET',endpoint_key='pack_exact',physical_http_calls=1,physical_started_at=UTC_TIMESTAMP(3),response_known_at=UTC_TIMESTAMP(3),http_status=$http WHERE id=$a");
                sprEvent($pdo,$f,['dispatch_state'=>'RESPONSE_KNOWN','response_known_at'=>gmdate('Y-m-d H:i:s'),'http_status'=>$http]);break;
            case 'exhausted_attempts':$pdo->exec("UPDATE queue_v4_clean_jobs SET attempt_count=max_attempts WHERE id=$q");break;
            case 'missing_finish':$pdo->exec("UPDATE queue_v4_clean_attempts SET finished_at=NULL WHERE id=$a");break;
            case 'missing_source_close':$pdo->exec("UPDATE queue_v4_clean_attempts SET source_closed_at=NULL WHERE id=$a");break;
            case 'protected_h3_unit01_unit02':
                k3u2_create_unit($pdo,'UNIT-01',7201,false);k3u2_create_unit($pdo,'UNIT-02',7202,true);
                $pdo->exec("UPDATE queue_v4_clean_jobs SET state='dead' WHERE id=$q");break;
        }
        $before=sprRow($pdo,'queue_v4_clean_jobs',$q);$sourceBefore=sprRow($pdo,'order_resource_enrichment_jobs',$s);
        $hist=$pdo->query('SELECT id,state FROM queue_v4_clean_jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $attemptHash=hash('sha256',json_encode($pdo->query('SELECT * FROM queue_v4_clean_attempts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)));
        $positive=in_array($case,['deadline_pending','deadline_retry','rhythm_retry','historical_known_exact'],true);
        $n=$f['repo']->releaseDueRetryableDirectWaiting($case==='foreign_scope'?[7202]:[7101,7201,7202]);
        $after=sprRow($pdo,'queue_v4_clean_jobs',$q);
        if($positive){unset($before['state'],$after['state'],$before['updated_at'],$after['updated_at']);}
        r0h3_assert($n===($positive?1:0),'safe_reentry_count_'.$case,['released'=>$n]);
        r0h3_assert($before===$after && $sourceBefore===sprRow($pdo,'order_resource_enrichment_jobs',$s),'only_state_mutates_'.$case);
        r0h3_assert($attemptHash===hash('sha256',json_encode($pdo->query('SELECT * FROM queue_v4_clean_attempts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))),'history_preserved');
        if(!$positive)r0h3_assert($hist===$pdo->query('SELECT id,state FROM queue_v4_clean_jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'protected_states');
        r0h3_assert($f['repo']->releaseDueRetryableDirectWaiting($case==='foreign_scope'?[7202]:[7101,7201,7202])===0,'idempotent_'.$case);
        echo "PASS $case\n";
    }catch(Throwable $e){
        // Schema 301 itself refuses these two malformed states. Do not weaken it for a test.
        if($e instanceof PDOException && (($case==='null_calls' && ($e->errorInfo[1]??null)===1048)
            || ($case==='invalid_json' && ($e->errorInfo[1]??null)===4025))){
            r0h3_assert($f['repo']->releaseDueRetryableDirectWaiting([7202])===0,'schema_denied_no_transition');
            echo "PASS_SCHEMA_REJECTED $case\n";
        }else{echo "FAIL $case ".$e->getMessage()."\n";$failed++;}
    }
}
exit($failed?1:0);
