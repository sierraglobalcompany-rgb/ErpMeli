<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
require __DIR__.'/K1dSafeTestDatabase.php';
require __DIR__.'/calls_true_wire_fixture.php';
require __DIR__.'/calls_true_seed_fixture.php';

use App\Services\CallsTrueWire;
use App\Services\CapacityPolicyService;
use App\Services\CronDeadlineContext;
use App\Services\ManualCampaignPreviewService;
use App\Services\ManualSingleStepService;
use App\QueueV4Clean\QueueV4CleanScheduler;

$options=getopt('',['seed:','template:','export-template:','retry-process','direct-type:']);
$seed=(int)($options['seed']??1);
true_seed_assert($seed>=1&&$seed<=100,'SEED_RANGE');
$root=rtrim((string)(getenv('CALLS_TRUE_QA_ROOT')?:'D:/Codex/tmp/erp-meli/calls-20260906/true-seeds'),'/\\');
$dir=$root.'/case-'.$seed.'-'.bin2hex(random_bytes(4));
if(!is_dir($dir))mkdir($dir,0770,true);
foreach(['APP_ENV'=>'test','ML_WRITE_ENABLED'=>'false','DB_HOST'=>'127.0.0.1','DB_PORT'=>'33079','DB_USER'=>'root','DB_PASS'=>'','DB_NAME'=>'erp_meli_k1d_test_true_seed_'.bin2hex(random_bytes(5)),'APP_KEY'=>'synthetic-true-final-only','PRIVATE_STORAGE_PATH'=>$dir.'/private','MELI_API_BASE'=>'https://calls-wire.invalid']as $k=>$v)putenv($k.'='.$v);
if(!defined('ERP_INSTALLATION_ROOT'))define('ERP_INSTALLATION_ROOT',$dir.'/install');
mkdir(ERP_INSTALLATION_ROOT,0770,true);
$h=null;
$ledger=['seed'=>$seed,'db_name'=>(string)getenv('DB_NAME'),'db_host'=>(string)getenv('DB_HOST'),'db_port'=>(string)getenv('DB_PORT'),'pid'=>getmypid(),'database_version'=>null,'state'=>'OWNERSHIP_DECLARED_BEFORE_CREATE','wire_entries'=>[]];
true_seed_assert(file_put_contents($dir.'/started.json',json_encode($ledger,JSON_THROW_ON_ERROR),LOCK_EX)!==false,'PRECREATE_OWNERSHIP_LEDGER_DURABLE');
$exit=0;
try{
    $h=K1dSafeTestDatabase::createFromEnvironment();
    $ledger['state']='RUNNING';
    $pdo=$h->pdo();
    $ledger['database_version']=$pdo->query('SELECT VERSION()')->fetchColumn();
    if(isset($options['template'])){
        $template=realpath($options['template']);
        true_seed_assert($template!==false&&str_starts_with(str_replace('\\','/',$template),'D:/Codex/'),'TEMPLATE_LOCAL_QA_PATH');
        foreach(json_decode(file_get_contents($template),true,512,JSON_THROW_ON_ERROR)as $sql)$pdo->exec($sql);
    }else{
        (new App\Services\Migrator($pdo,__DIR__.'/../database/migrations'))->run(301);
    }
    if(isset($options['export-template'])){
        true_seed_export_schema($pdo,$options['export-template']);
        $ledger['state']='SCHEMA_TEMPLATE_EXPORTED';
    }else{
        $profile=true_seed_profile($seed,isset($options['direct-type'])?(string)$options['direct-type']:null);
        $manual=$profile['manual'];$billing=$profile['billing'];$budget=$profile['budget'];$outcome=$profile['outcome'];$count=$profile['count'];$retry=$profile['retry'];
        $ledger['expected_profile']=$profile;
        true_seed_scope($pdo,$budget);
        $source=true_seed_source($pdo,$seed,$billing,$count,$profile['direct_type']);
        $source+=['oauth'=>$profile['oauth'],'resource_local'=>$profile['resource_local'],'page_size'=>$profile['page_size']];
        if($profile['oauth']){
            putenv('MELI_CLIENT_ID=synthetic-client');putenv('MELI_CLIENT_SECRET=synthetic-client-secret');
            $pdo->exec("UPDATE meli_tokens SET expires_at='2000-01-01' WHERE meli_account_id=9011");
            $ledger['oauth_refresh_version_before']=(int)$pdo->query('SELECT refresh_version FROM meli_tokens WHERE meli_account_id=9011')->fetchColumn();
        }
        $ledger+=['owner'=>$manual?'manual':'automatic','family'=>$billing?'billing':'order','budget'=>$budget,'outcome'=>$outcome,'source'=>$source];
        $before=true_seed_business_snapshot($pdo,$source);
        $ledger['business_before']=$before;
        CallsTrueWire::$ledger=$dir.'/wire.jsonl';
        CallsTrueWire::$respond=static fn(array $e):array=>true_seed_response($e,$source,$outcome==='stale'?'200':$outcome);
        $preview=null;
        if($manual){
            $capacity=(new CapacityPolicyService())->snapshot('manual');
            $preview=(new ManualCampaignPreviewService())->create(9007,['scope'=>'available_queue','account_id'=>9011,'physical_api_call_budget'=>$budget,'capacity_revision'=>$capacity['revision']]);
            true_seed_assert(count(CallsTrueWire::$entries)===0,'PREVIEW_ZERO_HTTP');
            $shown=array_map(static fn(array $r):int=>(int)$r['queue_job_id'],$preview['rows']);
            true_seed_assert($shown===$source['queue'],'PREVIEW_SHOWN_EQUALS_SOURCE_IDS',['shown'=>$shown,'expected'=>$source['queue']]);
            $ledger['confirmed_queue_ids']=$shown;
        }
        if($outcome==='prewire')$pdo->exec("UPDATE queue_v4_clean_control SET engine_state='STOPPED' WHERE control_key='primary'");
        if($outcome==='stale'){
            foreach($source['queue']as $id)$pdo->prepare("UPDATE queue_v4_clean_jobs SET state='completed' WHERE id=? AND company_id=9001 AND meli_account_id=9011")->execute([$id]);
        }
        CallsTrueWire::$execution=1;
        $result=null;$error=null;
        try{
            if($manual)$result=(new ManualSingleStepService())->executePreview($preview['preview_token'],9007,$budget);
            else{
                CronDeadlineContext::start(45,43,8,3);
                try{$result=(new QueueV4CleanScheduler($pdo))->run($budget,45);}finally{CronDeadlineContext::clear();}
            }
        }catch(Throwable $e){$error=['type'=>get_class($e),'message'=>$e->getMessage()];}
        $wire=CallsTrueWire::$entries;
        $ledger['wire_entries']=$wire;$ledger['result']=$result;$ledger['error']=$error;
        true_seed_assert(count($wire)<=$budget,'PHYSICAL_ATTEMPTS_WITHIN_EFFECTIVE_BUDGET',['wire'=>count($wire),'budget'=>$budget]);
        if(in_array($outcome,['prewire','stale'],true)){
            true_seed_assert(count($wire)===0,'PREWIRE_BLOCK_ZERO_HTTP');
            true_seed_assert($error!==null||in_array($result['status']??'',['stopped','waiting','completed'],true),'PREWIRE_REASON_EXPLICIT');
        }else{
            true_seed_assert($error===null,'SUPPORTED_LAUNCH_COMPLETES_WITH_RECEIPT',['error'=>$error]);
            true_seed_assert(count($wire)>0,'VALID_SCENARIO_REACHES_PHYSICAL_BOUNDARY',['result'=>$result]);
            true_seed_receipt($result,$wire,$budget);
            $certainty=$result['physical_http_calls_certainty']??$result['http_budget']['physical_http_calls_certainty']??null;
            $physical=$result['physical_http_calls']??$result['http_budget']['physical_http_calls']??null;
            $known=$result['known_physical_calls']??$result['http_budget']['known_physical_calls']??null;
            true_seed_assert(in_array($certainty,['CERTIFIED','UNKNOWN'],true),'RECEIPT_CERTAINTY_EXPLICIT',['result'=>$result]);
            if($certainty==='CERTIFIED')true_seed_assert($physical===count($wire)&&$known===count($wire),'CERTIFIED_RECEIPT_EQUALS_INDEPENDENT_WIRE',['physical'=>$physical,'known'=>$known,'wire'=>count($wire)]);
            else true_seed_assert($physical===null,'UNKNOWN_NOT_EXACT_ZERO');
            $charged=$result['charged_calls']??$result['api_calls_used']??$result['http_budget']['used']??null;
            true_seed_assert(is_int($charged)&&$charged>=count($wire),'SENT_ATTEMPTS_NOT_REFUNDED',['charged'=>$charged,'wire'=>count($wire)]);
            if($outcome==='429')true_seed_assert(count($wire)===1,'FIRST_429_STOPS_CURRENT_EXECUTION');
            if(in_array($outcome,['timeout','connect','partial'],true))true_seed_assert(count($wire)===1,'UNCERTAIN_TRANSPORT_STOPS_EXECUTION');
            $requestIds=array_column($wire,'transport_request_id');
            true_seed_assert(count(array_unique($requestIds))===count($wire)&&count(array_filter($requestIds,static fn(string $id):bool=>preg_match('/^[a-f0-9]{40}$/D',$id)===1))===count($wire),'PHYSICAL_IDENTITIES_UNIQUE');
        }
        true_seed_assert(CallsTrueWire::$violations===[],'NO_SWALLOWED_FIXTURE_VIOLATIONS',['violations'=>CallsTrueWire::$violations]);
        $ledger['business_after_first']=true_seed_assert_business($pdo,$source,$profile,$wire,$before,$outcome);
        if($profile['oauth']){
            $oauthWire=array_values(array_filter($wire,static fn(array $e):bool=>$e['path']==='/oauth/token'));
            true_seed_assert(count($oauthWire)===1&&$wire[0]['path']==='/oauth/token','OAUTH_IS_ONE_REAL_PHYSICAL_POST_BEFORE_BUSINESS');
            $version=(int)$pdo->query('SELECT refresh_version FROM meli_tokens WHERE meli_account_id=9011')->fetchColumn();
            true_seed_assert($version===$ledger['oauth_refresh_version_before']+1,'OAUTH_ROTATION_DURABLE_ONCE');
            $ledger['oauth_verified']=true;
        }
        if($profile['resource_local']){
            true_seed_assert(count($ledger['business_after_first']['orders'])>=1&&$ledger['business_after_first']['queue'][0]['state']==='waiting','RESOURCE_403_DOES_NOT_BLOCK_OTHER_RESOURCE');
            $ledger['resource_local_http_error_verified']=true;
        }
        $ledger['executions']=[['execution'=>1,'budget'=>$budget,'result'=>$result,'wire'=>$wire]];
        if($retry){
            $queueId=$source['queue'][0];
            $read=$pdo->prepare('SELECT state,available_at,last_error_class,attempt_count,available_at<=UTC_TIMESTAMP(3) AS due FROM queue_v4_clean_jobs WHERE id=? AND company_id=9001 AND meli_account_id=9011');
            $read->execute([$queueId]);$waiting=$read->fetch(PDO::FETCH_ASSOC);
            $ledger['retry_waiting_before']=$waiting;
            true_seed_assert(count($wire)===1&&$waiting['state']==='waiting','RETRY_FIRST_ATTEMPT_WAITING',['queue'=>$waiting]);
            $waitStart=microtime(true);
            while(!(bool)$waiting['due']){
                true_seed_assert(microtime(true)-$waitStart<65,'RETRY_WAIT_BOUNDED',['queue'=>$waiting]);
                usleep(100000);$read->execute([$queueId]);$waiting=$read->fetch(PDO::FETCH_ASSOC);
            }
            $ledger['retry_wait_seconds']=microtime(true)-$waitStart;
            CallsTrueWire::$execution=2;
            CallsTrueWire::$respond=static fn(array $e):array=>true_seed_response($e,$source,'200');
            if(isset($options['retry-process'])){
                file_put_contents($dir.'/retry-context.json',json_encode(['db_name'=>$h->dbName,'installation_root'=>ERP_INSTALLATION_ROOT,'source'=>$source,'budget'=>$budget],JSON_THROW_ON_ERROR));
                $child=proc_open([PHP_BINARY,__DIR__.'/calls_true_retry_process.php','--context='.$dir.'/retry-context.json'],[0=>['pipe','r'],1=>['file',$dir.'/retry-child.out.log','w'],2=>['file',$dir.'/retry-child.err.log','w']],$pipes,dirname(__DIR__));
                true_seed_assert(is_resource($child),'RETRY_CHILD_START');fclose($pipes[0]);
                $childStart=microtime(true);
                do{$state=proc_get_status($child);if(!$state['running'])break;true_seed_assert(microtime(true)-$childStart<60,'RETRY_CHILD_TIMEOUT');usleep(50000);}while(true);
                proc_close($child);true_seed_assert($state['exitcode']===0,'RETRY_CHILD_EXIT',['exit'=>$state['exitcode']]);
                $childResult=json_decode(file_get_contents($dir.'/retry-child-result.json'),true,512,JSON_THROW_ON_ERROR);
                true_seed_assert($childResult['pid']!==getmypid()&&$childResult['violations']===[],'FRESH_RETRY_PROCESS_VALID');
                $second=$childResult['result'];$secondWire=$childResult['wire'];
                CallsTrueWire::$entries=array_merge(CallsTrueWire::$entries,$secondWire);
                $ledger['retry_child_pid']=$childResult['pid'];
            }else{
                CronDeadlineContext::start(45,43,8,3);
                try{$second=(new QueueV4CleanScheduler($pdo))->run($budget,45);}finally{CronDeadlineContext::clear();}
                $secondWire=array_values(array_filter(CallsTrueWire::$entries,static fn(array $e):bool=>$e['execution']===2));
            }
            $ledger['executions'][]=['execution'=>2,'budget'=>$budget,'result'=>$second,'wire'=>$secondWire];
            $read->execute([$queueId]);$ledger['retry_queue_after']=$read->fetch(PDO::FETCH_ASSOC);
            true_seed_assert(count($secondWire)===1,'RETRY_DUE_SOURCE_REACHES_HTTP',['result'=>$second,'queue'=>$ledger['retry_queue_after']]);
            true_seed_receipt($second,$secondWire,$budget);
            true_seed_assert($secondWire[0]['transport_request_id']!==$wire[0]['transport_request_id'],'RETRY_HAS_OWN_PHYSICAL_ID');
            true_seed_assert($ledger['retry_queue_after']['state']==='completed','RETRY_COMPLETES_SOURCE');
            $ledger['business_after_retry']=true_seed_assert_business($pdo,$source,$profile,$secondWire,$before,'200');
            $ledger['retry_verified']=true;
        }
        if($billing&&$count>1&&$outcome==='200'){
            $allWire=$wire;
            for($execution=2;count($allWire)<$count;$execution++){
                true_seed_assert($execution<=$count,'PACK_CONTINUATION_EXECUTIONS_BOUNDED');
                $due=$pdo->prepare('SELECT q.available_at<=UTC_TIMESTAMP(3) AND s.next_run_at<=UTC_TIMESTAMP(3) AS due FROM queue_v4_clean_jobs q JOIN sale_financial_reconciliation_jobs s ON s.id=? AND s.company_id=q.company_id AND s.meli_account_id=q.meli_account_id WHERE q.id=? AND q.company_id=9001 AND q.meli_account_id=9011');
                $start=microtime(true);
                do{$due->execute([$source['source'],$source['queue'][0]]);$ready=(bool)$due->fetchColumn();true_seed_assert(microtime(true)-$start<15,'PACK_CONTINUATION_REAL_DUE_BOUNDED');if(!$ready)usleep(100000);}while(!$ready);
                CallsTrueWire::$execution=$execution;
                if($manual){
                    $capacity=(new CapacityPolicyService())->snapshot('manual');
                    $nextPreview=(new ManualCampaignPreviewService())->create(9007,['scope'=>'available_queue','account_id'=>9011,'physical_api_call_budget'=>$budget,'capacity_revision'=>$capacity['revision']]);
                    true_seed_assert(array_map(static fn(array $r):int=>(int)$r['queue_job_id'],$nextPreview['rows'])===$source['queue'],'PACK_CONTINUATION_RECONFIRMS_SAME_SOURCE');
                    $next=(new ManualSingleStepService())->executePreview($nextPreview['preview_token'],9007,$budget);
                }else{
                    CronDeadlineContext::start(45,43,8,3);
                    try{$next=(new QueueV4CleanScheduler($pdo))->run($budget,45);}finally{CronDeadlineContext::clear();}
                }
                $nextWire=array_values(array_filter(CallsTrueWire::$entries,static fn(array $e):bool=>$e['execution']===$execution));
                true_seed_assert(count($nextWire)===1,'PACK_ONE_NEW_ORDER_PER_EXECUTION');
                true_seed_receipt($next,$nextWire,$budget);
                $allWire=array_merge($allWire,$nextWire);
                $business=true_seed_assert_business($pdo,$source,$profile,$allWire,$before,'200');
                $ledger['executions'][]=['execution'=>$execution,'budget'=>$budget,'result'=>$next,'wire'=>$nextWire,'business'=>$business,'real_due_wait_seconds'=>microtime(true)-$start];
            }
            true_seed_assert(count($allWire)===$count,'PACK_ALL_ORDERS_CHECKPOINTED');
            $ledger['pack_continuation_verified']=true;
        }
        if($manual&&$outcome!=='prewire'){
            $beforeReplay=count(CallsTrueWire::$entries);$replayRejected=false;
            try{(new ManualSingleStepService())->executePreview($preview['preview_token'],9007,$budget);}catch(Throwable){$replayRejected=true;}
            true_seed_assert($replayRejected&&count(CallsTrueWire::$entries)===$beforeReplay,'MANUAL_REPLAY_ZERO_NEW_CALLS');
        }
        $ledger['business_verified']=true;
        $ledger['coverage']=['prewire'=>$outcome==='prewire'||$outcome==='stale','remote_429'=>$outcome==='429'&&count($wire)===1,'discovery'=>$profile['direct_type']==='fresh_orders_discovery'&&!$billing&&$outcome==='200','multiple_calls_one_execution'=>count($wire)>1,'multiple_resources'=>count($ledger['business_after_first']['orders'])>1&&!$billing,'local_resolution'=>false];
        $ledger['state']='PASS';
        $ledger['wire_entries']=CallsTrueWire::$entries;
    }
}catch(Throwable $e){
    $exit=1;$ledger['state']='FAIL';$ledger['failure']=['type'=>get_class($e),'message'=>$e->getMessage(),'file'=>str_replace('\\','/',$e->getFile()),'line'=>$e->getLine()];
    $ledger['wire_entries']=CallsTrueWire::$entries;
    fwrite(STDERR,$e->getMessage()."\n");
}finally{
    file_put_contents($dir.'/result.json',json_encode($ledger,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    echo 'SEED_RESULT='.$dir.'/result.json'."\n";
    if($h!==null)$h->cleanup();
}
exit($exit);
