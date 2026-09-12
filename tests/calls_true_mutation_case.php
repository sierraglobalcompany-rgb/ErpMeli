<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
require __DIR__.'/K1dSafeTestDatabase.php';
require __DIR__.'/calls_true_wire_fixture.php';
require __DIR__.'/calls_true_seed_fixture.php';

use App\QueueV4Clean\QueueV4CleanCycleBudget as Budget;
use App\QueueV4Clean\QueueV4CleanRepository as Repo;
use App\QueueV4Clean\QueueV4CleanWorker as Worker;
use App\Services\ApiExecutionMetadataContext as Meta;
use App\Services\CallsTrueWire as Wire;

final class MutationOracleViolation extends RuntimeException {}
final class MutationKnownResultFault extends PDOStatement {
    public static bool $armed=false;
    public static bool $triggered=false;
    protected function __construct(){}
    public function execute(?array $params=null):bool {
        if(self::$armed&&!self::$triggered&&str_contains($this->queryString,"UPDATE queue_core_jobs SET dispatch_state='DISPATCHED_RESULT_KNOWN'")){
            self::$triggered=true;throw new PDOException('SYNTHETIC_KNOWN_RESULT_WRITE_FAULT');
        }
        return parent::execute($params);
    }
}
function mutantCheck(bool $pass,string $label):void {if(!$pass)throw new MutationOracleViolation($label);}
function mutantJson(string $path,array $data):void {if(file_put_contents($path,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('artifact_write_failed');}
function mutantClaim(PDO $pdo,Repo $repo,int $run,string $kind='queue_v4_clean'):array {
    $job=$repo->claim($run,'synthetic-mutant-owner',120,[9011],9011);
    true_seed_assert(is_array($job),'MUTANT_ACTUAL_QUEUE_CLAIM');
    return ['source'=>$kind,'company_id'=>9001,'account_id'=>9011,'queue_v4_job_id'=>(int)$job['id'],
        'queue_v4_attempt_id'=>(int)$job['attempt_id'],'queue_v4_lease_owner'=>$job['lease_owner'],
        'queue_v4_lease_generation'=>(int)$job['lease_generation'],'transport_request_id'=>bin2hex(random_bytes(20)),
        'transport_meli_account_id'=>9011,'transport_operation_key'=>$kind==='queue_v4_clean'?'order_exact':'billing_orders'];
}
function mutantPreview(int $budget,int $account=9011):array {
    $capacity=(new App\Services\CapacityPolicyService())->snapshot('manual');
    return (new App\Services\ManualCampaignPreviewService())->create(9007,['scope'=>'available_queue','account_id'=>$account,
        'physical_api_call_budget'=>$budget,'capacity_revision'=>$capacity['revision']]);
}

$o=getopt('',['case:','root:','artifact:','template:','template-sha256:','contender:']);$case=(string)($o['case']??'');
// Only M8 uses a fresh second process. It borrows the already-owned DB and
// invokes the actual opposite launcher; it never creates/drops a database.
if(isset($o['contender'])){
    $path=realpath((string)$o['contender']);
    true_seed_assert($path!==false&&(str_starts_with(str_replace('\\','/',$path),'D:/Codex/') || str_starts_with(str_replace('\\','/',$path),'C:/codex/capacity-save-kiss/')),'M8_OWNED_CONTEXT_PATH');
    $context=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    K1dSafeTestDatabase::assertGuard((string)getenv('APP_ENV'),(string)getenv('ML_WRITE_ENABLED'),(string)getenv('DB_HOST'),(string)getenv('DB_NAME'));
    true_seed_assert(getenv('DB_NAME')===$context['db_name'],'M8_PARENT_OWNED_DATABASE');
    define('ERP_INSTALLATION_ROOT',$context['installation_root']);$pdo=App\Core\Database::connection();
    App\Core\Session::put('user',['id'=>9007,'role'=>'admin','session_generation'=>(new App\Services\SessionGenerationService())->current()]);
    Wire::$ledger=dirname($path).'/contender-wire.jsonl';
    Wire::$respond=static function(array $e):array{true_seed_assert($e['company_id']===9001&&$e['account_id']===9012&&preg_match('#^/orders/[0-9]+$#D',$e['path'])===1,'M8_DISTINCT_CONTENDER_WIRE');return ['status'=>200,'body'=>true_seed_order_body(basename($e['path']))];};
    $sourceSnapshot=static function()use($pdo):array{
        return $pdo->query('SELECT id,resource_id,state,attempt_count,lease_generation,available_at,completed_at
            FROM queue_v4_clean_jobs WHERE company_id=9001 AND meli_account_id=9012 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    };
    $sourcesBefore=$sourceSnapshot();$error=null;$result=null;
    try{
        if($context['launcher']==='manual'){$preview=mutantPreview(1,9012);$result=(new App\Services\ManualSingleStepService())->executePreview($preview['preview_token'],9007,1);}
        else{$result=(new App\QueueV4Clean\QueueV4CleanScheduler($pdo))->run(1,30);}
    }catch(Throwable $e){$error=get_class($e);}
    $sourcesAfter=$sourceSnapshot();
    $claimsDelta=array_sum(array_column($sourcesAfter,'attempt_count'))-array_sum(array_column($sourcesBefore,'attempt_count'));
    $globalDenied=$context['launcher']==='manual'
        ? ($result['status']??'')==='waiting'&&($result['processed_count']??null)===0
            &&($result['manual_auto_shared_global_authority']??false)===true&&($result['second_global_lease_acquire']??null)===0
            &&Wire::$entries===[]&&$sourcesAfter===$sourcesBefore
        : ($result['status']??'')==='busy_drainer'&&Wire::$entries===[]&&$sourcesAfter===$sourcesBefore;
    mutantJson(dirname($path).'/contender-result.json',['pid'=>getmypid(),'launcher'=>$context['launcher'],'global_admission_denied'=>$globalDenied,
        'source_rows_before'=>$sourcesBefore,'source_rows_after'=>$sourcesAfter,'source_claim_attempt_delta'=>$claimsDelta,
        'result'=>$result===null?null:array_intersect_key($result,array_flip(['status','stop_reason','completed_count','processed_count','processed','claimed_total','api_calls_used'])),
        'error_type'=>$error,'wire'=>Wire::$entries,'violations'=>Wire::$violations]);
    exit(0);
}
$root=rtrim(str_replace('\\','/',(string)($o['root']??'')),'/');$artifact=str_replace('\\','/',(string)($o['artifact']??''));
if(PHP_SAPI!=='cli'||!(str_starts_with($root,'D:/Codex/') || str_starts_with($root,'C:/codex/capacity-save-kiss/'))||in_array('..',explode('/',$root),true)
    ||!(str_starts_with($artifact,'D:/Codex/') || str_starts_with($artifact,'C:/codex/capacity-save-kiss/'))||in_array('..',explode('/',$artifact),true))throw new RuntimeException('explicit_owned_artifact_paths_required');
if(!mkdir($root,0770,true))throw new RuntimeException('fresh_case_directory_required');
$r=['case'=>$case,'state'=>'SETUP_FAILURE','reached'=>false,'witness'=>false,'cleanup'=>false,
    'oracle_hash'=>hash_file('sha256',__FILE__),'pid'=>getmypid(),'wire_count'=>0,'real_remote_http'=>0];
$h=null;$exit=1;
try {
    if($case!=='M6'){
        $db='erp_meli_k1d_test_mutant_'.strtolower($case).'_'.bin2hex(random_bytes(8));
        foreach(['APP_ENV'=>'test','ML_WRITE_ENABLED'=>'false','DB_NAME'=>$db,'APP_KEY'=>'synthetic-mutant-key',
            'ERP_PRIVATE_PATH'=>$root.'/private','PRIVATE_STORAGE_PATH'=>$root.'/private','MELI_API_BASE'=>'https://calls-wire.invalid']as $key=>$value)putenv($key.'='.$value);
        K1dSafeTestDatabase::assertGuard('test','false',(string)getenv('DB_HOST'),$db);
        define('ERP_INSTALLATION_ROOT',$root.'/public_html/erp-meli');mkdir(ERP_INSTALLATION_ROOT,0770,true);
        $r['ownership']=['owner'=>'calls_true_mutation_case.php','db_name'=>$db,'db_host'=>(string)getenv('DB_HOST'),
            'db_port'=>(string)(getenv('DB_PORT')?:3306),'pid'=>getmypid(),'schema'=>301,'state'=>'RECORDED_BEFORE_CREATE'];
        mutantJson($root.'/ownership.json',$r['ownership']);
        $h=K1dSafeTestDatabase::createFromEnvironment();$pdo=$h->pdo();
        if(isset($o['template'])){
            $template=realpath((string)$o['template']);
            true_seed_assert($template!==false&&(str_starts_with(str_replace('\\','/',$template),'D:/Codex/') || str_starts_with(str_replace('\\','/',$template),'C:/codex/capacity-save-kiss/')),'MUTANT_LOCAL_TEMPLATE');
            // Hash and decode the same read: no template TOCTOU between hash and SQL.
            $templateBytes=file_get_contents($template);
            true_seed_assert(is_string($templateBytes),'MUTANT_TEMPLATE_READ');
            $r['template_sha256']=hash('sha256',$templateBytes);
            true_seed_assert(isset($o['template-sha256'])&&hash_equals((string)$o['template-sha256'],$r['template_sha256']),'MUTANT_SEALED_TEMPLATE_HASH');
            foreach(json_decode($templateBytes,true,512,JSON_THROW_ON_ERROR)as $sql)$pdo->exec($sql);
        }else(new App\Services\Migrator($pdo,__DIR__.'/../database/migrations'))->run(301);
        true_seed_scope($pdo,9);$r['database_version']=$pdo->query('SELECT VERSION()')->fetchColumn();
        Wire::$ledger=$root.'/wire.jsonl';
        Wire::$respond=static function(array $e):array{throw new RuntimeException('UNEXPECTED_MUTANT_WIRE');};
    }
    switch($case){
        case 'M1':
            $source=true_seed_source($pdo,401,true,1);$second=true_seed_source($pdo,402,true,1);$repo=new Repo($pdo);$run=$repo->beginRun('test','synthetic-cardinality');
            Wire::$respond=static function(array $e):array{
                true_seed_assert($e['method']==='GET'&&$e['path']==='/billing/integration/group/ML/order/details'
                    &&$e['company_id']===9001&&$e['account_id']===9011,'M1_EXACT_BILLING_AUTHORITY');
                return ['status'=>200,'body'=>[]]; // CSV is observed, not rejected by the fixture.
            };
            Budget::start(2,'automatic',microtime(true)+45);$meta=mutantClaim($pdo,$repo,$run,'queue_v4_clean_domain_exact');
            // Negative first: prior successful Billing traffic can independently defer
            // the CSV request and hide removal of the cardinality guard.
            $error=null;$csv='771101,771102';
            try{Meta::run($meta,static fn()=>(new App\Services\MeliApiClient(9011))->get('/billing/integration/group/ML/order/details',['order_ids'=>$csv]));}catch(Throwable $e){$error=$e;}
            $r['reached']=true;$r['evidence']=['csv_wire_count'=>count(Wire::$entries),'csv_order_ids'=>Wire::$entries[0]['order_ids']??null,'error_type'=>$error===null?null:get_class($error)];
            $r['witness']=count(Wire::$entries)===1&&Wire::$entries[0]['order_ids']===$csv&&$error===null;
            mutantCheck(Wire::$entries===[]&&$error instanceof RuntimeException&&$error->getMessage()==='Billing order_ids debe contener un único ID válido.','M1_CSV_REACHED_PHYSICAL_WIRE');
            $meta=mutantClaim($pdo,$repo,$run,'queue_v4_clean_domain_exact');
            Meta::run($meta,static fn()=>(new App\Services\MeliApiClient(9011))->get('/billing/integration/group/ML/order/details',['order_ids'=>$second['orders'][0]]));
            true_seed_assert(count(Wire::$entries)===1&&Wire::$entries[0]['order_ids']===$second['orders'][0],'M1_SINGLETON_REAL_WIRE_CONTROL');
            $r['evidence']['singleton_control_wire_count']=count(Wire::$entries);break;
        case 'M2':
            $source=true_seed_source($pdo,39,true,1);$preview=mutantPreview(9);
            true_seed_assert(array_column($preview['rows'],'queue_job_id')==$source['queue']&&Wire::$entries===[],'M2_REAL_PREVIEW_EXACT_ZERO_WIRE');
            $pdo->prepare("UPDATE sale_financial_state SET official_status='complete',official_net_amount=90 WHERE company_id=9001 AND meli_account_id=9011 AND sale_key=?")->execute([$source['saleKey']]);
            $before=true_seed_local_resolution_snapshot($pdo,$source);
            $result=(new App\Services\ManualSingleStepService())->executePreview($preview['preview_token'],9007,9);
            $after=true_seed_local_resolution_snapshot($pdo,$source);
            $r['evidence']=['receipt'=>array_intersect_key($result,array_flip(['completed_count','api_calls_used','physical_http_calls','physical_http_calls_certainty'])),
                'source'=>$after['sources'],'pointers'=>$after['pointers']];
            $r['reached']=count($after['sources'])===1&&$after['sources'][0]['status']==='complete'&&$after['pointers'][0]['state']==='completed'&&Wire::$entries===[];
            $r['witness']=$r['reached']&&(($result['api_calls_used']??0)>0||($result['physical_http_calls_certainty']??'')!=='CERTIFIED');
            try{true_seed_assert_local_resolution($pdo,$source,$before,$result,Wire::$entries,9);}
            catch(RuntimeException $e){if($r['witness']&&str_starts_with($e->getMessage(),'INVARIANT:'))throw new MutationOracleViolation('M2_LOCAL_COMPLETION_NOT_ZERO_DEBIT');throw $e;}
            break;
        case 'M3':
            $profile=true_seed_profile(86,'fresh_orders_discovery');$source=true_seed_source($pdo,86,false,3,'fresh_orders_discovery');$source['page_size']=3;
            $before=true_seed_business_snapshot($pdo,$source);Wire::$respond=static fn(array $e):array=>true_seed_response($e,$source,'200');
            $preview=mutantPreview(2);$result=(new App\Services\ManualSingleStepService())->executePreview($preview['preview_token'],9007,2);
            $after=true_seed_business_snapshot($pdo,$source);$r['evidence']=['receipt'=>array_intersect_key($result,array_flip(['api_calls_used','physical_http_calls','physical_http_calls_certainty','completed_count'])),
                'persisted_orders'=>array_column($after['orders'],'external_order_id'),'requested_orders'=>$source['orders']];
            $r['reached']=count(Wire::$entries)===1&&Wire::$entries[0]['path']==='/orders/search'&&count($after['orders'])>=1;
            $r['witness']=$r['reached']&&($result['api_calls_used']??0)>1;
            try{true_seed_receipt($result,Wire::$entries,2);true_seed_assert_business($pdo,$source,$profile,Wire::$entries,$before,'200');
                mutantCheck(($result['api_calls_used']??null)===1,'M3_RESOURCES_NOT_PHYSICAL_UNIT');}
            catch(RuntimeException $e){if($r['witness']&&str_starts_with($e->getMessage(),'INVARIANT:'))throw new MutationOracleViolation('M3_RESOURCE_DEBITS_WITH_ONE_WIRE');throw $e;}
            break;
        case 'M4':
            $source=true_seed_source($pdo,404,false,4);$repo=new Repo($pdo);$run=$repo->beginRun('test','synthetic-admission');
            Wire::$respond=static fn(array $e):array=>true_seed_response($e,$source,'200');
            Budget::start(3,'automatic',microtime(true)+45);$lastError=null;
            for($i=0;$i<4;$i++){
                $meta=mutantClaim($pdo,$repo,$run);$order=$source['orders'][$i];
                try{Meta::run($meta,static fn()=>(new App\Services\CurlMeliHttpTransport())->request('GET','https://calls-wire.invalid/orders/'.$order,[],[],false,['timeout'=>20,'connect_timeout'=>3]));}
                catch(Throwable $e){if($i<3)throw $e;$lastError=$e;}
                if($i===2)true_seed_assert(count(Wire::$entries)===3&&Budget::snapshot()['used']===3,'M4_THREE_ACTUAL_WIRES_BEFORE_BOUNDARY');
            }
            $r['reached']=true;$r['evidence']=['wire'=>count(Wire::$entries),'used'=>Budget::snapshot()['used'],'fourth_error'=>$lastError===null?null:get_class($lastError)];
            $r['witness']=count(Wire::$entries)===4&&Budget::snapshot()['used']===4&&$lastError===null;
            mutantCheck(count(Wire::$entries)===3&&Budget::snapshot()['used']===3&&$lastError instanceof App\Services\ApiBudgetExhaustedException,'M4_N_PLUS_ONE_ADMISSION');
            break;
        case 'M5':
            $source=true_seed_source($pdo,405,false,2);$repo=new Repo($pdo);$run=$repo->beginRun('test','synthetic-after429');
            $statuses=[];Wire::$respond=static function(array $e)use($source,&$statuses):array{
                $status=count(Wire::$entries)===1?'429':'200';$statuses[]=(int)$status;return true_seed_response($e,$source,$status);
            };
            Budget::start(3,'automatic',microtime(true)+45);$meta=mutantClaim($pdo,$repo,$run);$firstError=null;
            try{Meta::run($meta,static fn()=>(new App\Services\MeliApiClient(9011))->get('/orders/'.$source['orders'][0]));}catch(Throwable $e){$firstError=$e;}
            true_seed_assert($firstError instanceof App\Services\ApiRhythmDeferredException&&$statuses===[429]
                &&Budget::snapshot()['stopped_reason']==='remote_429_global_pause','M5_REAL_KNOWN429_STOPPED_OUTER_BUDGET');
            $meta=mutantClaim($pdo,$repo,$run);$error=null;
            try{Meta::run($meta,static fn()=>(new App\Services\CurlMeliHttpTransport())->request('GET','https://calls-wire.invalid/orders/'.$source['orders'][1],[],[],false,['timeout'=>20,'connect_timeout'=>3]));}catch(Throwable $e){$error=$e;}
            $r['reached']=true;$r['evidence']=['kind'=>'real_known429_then_physical_boundary_fuse_NOT_automatic_worker_continuation','wire_statuses'=>$statuses,'used'=>Budget::snapshot()['used'],'error_type'=>$error===null?null:get_class($error)];
            $r['witness']=$statuses===[429,200]&&$error===null&&Budget::snapshot()['used']===2;
            mutantCheck($statuses===[429]&&$error instanceof RuntimeException&&Budget::snapshot()['used']===1,'M5_PHYSICAL_BOUNDARY_CONTINUES_AFTER_KNOWN429');break;
        case 'M6':
            $parser=new App\Services\AutomationCliCapacityArgumentParser();true_seed_assert($parser->parse(['max-calls'=>'3'])===['max_calls'=>3],'M6_VALID_CALLS_CONTROL');
            $r['evidence']=[];$r['reached']=true;$allRejected=true;
            foreach([['max-jobs'=>'3'],['max-jobs'=>'3','max-calls'=>'2']]as $args){$answer=null;$error=null;
                try{$answer=$parser->parse($args);}catch(InvalidArgumentException $e){$error=$e;}
                $rejected=$error!==null&&$error->getMessage()==='legacy_capacity_argument_removed';$allRejected=$allRejected&&$rejected;
                $r['evidence'][]=['returned'=>$answer,'rejected'=>$rejected];if($answer===['max_calls'=>3])$r['witness']=true;
            }
            mutantCheck($allRejected,'M6_MAX_JOBS_ACCEPTED');break;
        case 'M7':
            $policy=new App\Services\CapacityPolicyService($pdo);$other=$policy->snapshot('manual');$before=$policy->snapshot('automation');
            $q=$pdo->query("SELECT setting_key,setting_value,is_encrypted,setting_group FROM app_settings WHERE setting_key IN ('manual.api_calls_per_step','manual.api_calls_ceiling') ORDER BY setting_key");$rowsBefore=$q->fetchAll(PDO::FETCH_ASSOC);
            $saved=$policy->save('automation',3,17,$before['revision']);
            $rowsAfter=$pdo->query("SELECT setting_key,setting_value,is_encrypted,setting_group FROM app_settings WHERE setting_key IN ('manual.api_calls_per_step','manual.api_calls_ceiling') ORDER BY setting_key")->fetchAll(PDO::FETCH_ASSOC);
            App\Services\AppSettingsService::clearCache();$fresh=(new App\Services\CapacityPolicyService($pdo))->snapshot('manual');
            $r['reached']=$saved['current']===3&&$saved['ceiling']===17;$r['evidence']=['manual_rows_before'=>$rowsBefore,'manual_rows_after'=>$rowsAfter,'saved_current'=>$saved['current'],'saved_ceiling'=>$saved['ceiling']];
            $r['witness']=$r['reached']&&$rowsBefore!==$rowsAfter&&$fresh['current']===3&&$fresh['ceiling']===17;
            mutantCheck($r['reached']&&$rowsBefore===$rowsAfter&&$fresh===$other,'M7_OTHER_MODULE_PERSISTENCE_CHANGED');break;
        case 'M9':
            $core=new App\QueueCore\QueueCoreRepository($pdo);
            $job=$core->enqueue(new App\QueueCore\QueueJob(9001,9011,'manual_exact','remote','8101','normal',0,bin2hex(random_bytes(20)),'1','test',null,[],[],1));
            $pdo->prepare("UPDATE queue_core_jobs SET state='running',lease_owner='synthetic-known',lease_generation=1,lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE) WHERE id=? AND company_id=9001 AND meli_account_id=9011")->execute([$job]);
            $pdo->prepare("INSERT INTO queue_core_attempts(job_id,company_id,meli_account_id,lease_owner,lease_generation,launcher) VALUES(?,9001,9011,'synthetic-known',1,'manual')")->execute([$job]);$attempt=(int)$pdo->lastInsertId();
            $meta=['source'=>'queue_core','company_id'=>9001,'account_id'=>9011,'queue_core_launcher'=>'manual','queue_core_capability_launcher'=>'manual','queue_core_domain'=>'manual',
                'queue_core_uses_api'=>1,'queue_core_max_remote_calls'=>1,'queue_core_expected_method'=>'GET','queue_core_expected_endpoint_pattern'=>'#^/orders/8101$#D',
                'queue_core_expected_operation'=>'order_exact','queue_core_job_id'=>$job,'queue_core_attempt_id'=>$attempt,'queue_core_lease_owner'=>'synthetic-known','queue_core_lease_generation'=>1,'queue_core_work_type'=>'manual_exact'];
            Wire::$respond=static function(array $e):array{true_seed_assert($e['path']==='/orders/8101'&&$e['company_id']===9001&&$e['account_id']===9011,'M9_EXPECTED_WIRE');return ['status'=>200,'body'=>['id'=>8101]];};
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[MutationKnownResultFault::class]);MutationKnownResultFault::$armed=true;
            Budget::start(1,'automatic',microtime(true)+45);$error=null;
            try{Meta::run($meta,static fn()=>(new App\Services\MeliApiClient(9011))->get('/orders/8101'));}catch(Throwable $e){$error=$e;}
            MutationKnownResultFault::$armed=false;
            $permit=$pdo->query('SELECT status,http_status FROM api_remote_permits ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            $r['reached']=MutationKnownResultFault::$triggered&&count(Wire::$entries)===1&&is_array($permit);
            $r['evidence']=['fault_triggered'=>MutationKnownResultFault::$triggered,'permit'=>$permit,'error_type'=>$error===null?null:get_class($error),'budget'=>Budget::snapshot()];
            $r['witness']=$r['reached']&&$permit['http_status']===null&&$error instanceof App\Services\RemoteResultUncertainException;
            mutantCheck($r['reached']&&(int)$permit['http_status']===200&&in_array($permit['status'],['completed','expired'],true)
                &&$error instanceof App\Services\RemoteResultUncertainException,'M9_KNOWN200_LOST_AFTER_TARGETED_WRITE_FAULT');break;
        case 'M8':
            $r['evidence']=[];$bothReached=true;$bypassed=false;
            // Baseline and mutant each run both real launcher directions.
            foreach(['automatic','manual']as $round=>$parentLauncher){
                if($round>0)usleep(1100000);
                $source=true_seed_source($pdo,480+$round,false,1);$repo=new Repo($pdo);
                $otherOrder=(string)(990480+$round);$repo->enqueue(9001,9012,'order_exact',$otherOrder,'mutation-overlap-'.$round,[]);
                $roundDir=$root.'/overlap-'.$parentLauncher;mkdir($roundDir,0770,true);
                Wire::$entries=[];Wire::$violations=[];Wire::$ledger=$roundDir.'/holder-wire.jsonl';$observation=null;
                Wire::$respond=static function(array $entry)use($pdo,$source,$parentLauncher,$roundDir,&$observation):array{
                    true_seed_assert(count(Wire::$entries)===1,'M8_HOLDER_SINGLE_PHYSICAL_BOUNDARY');
                    $before=$pdo->query("SELECT * FROM queue_core_execution_leases WHERE lease_key='global'")->fetch(PDO::FETCH_ASSOC);
                    true_seed_assert(is_array($before)&&$before['owner_token']!==null&&strtotime($before['expires_at'].' UTC')>time(),'M8_ACTUAL_HOLDER_GLOBAL_LEASE_LIVE');
                    $context=['db_name'=>(string)getenv('DB_NAME'),'installation_root'=>ERP_INSTALLATION_ROOT,'launcher'=>$parentLauncher==='automatic'?'manual':'automatic'];
                    mutantJson($roundDir.'/context.json',$context);
                    $p=proc_open([PHP_BINARY,__FILE__,'--contender='.$roundDir.'/context.json'],[0=>['pipe','r'],1=>['file',$roundDir.'/child.out.log','w'],2=>['file',$roundDir.'/child.err.log','w']],$pipes,dirname(__DIR__));
                    true_seed_assert(is_resource($p),'M8_CONTENDER_PROCESS_STARTED');fclose($pipes[0]);$start=microtime(true);$s=proc_get_status($p);
                    mutantJson($roundDir.'/child-process.json',['pid'=>$s['pid'],'parent_pid'=>getmypid(),'launcher'=>$context['launcher']]);
                    while($s['running']){if(microtime(true)-$start>20){proc_terminate($p);proc_close($p);throw new RuntimeException('M8_CONTENDER_TIMEOUT');}usleep(50000);$s=proc_get_status($p);}
                    $closed=proc_close($p);$childExit=$s['exitcode']>=0?$s['exitcode']:$closed;
                    true_seed_assert($childExit===0&&is_file($roundDir.'/contender-result.json'),'M8_CONTENDER_PROCESS_RETURNED');
                    $child=json_decode((string)file_get_contents($roundDir.'/contender-result.json'),true,512,JSON_THROW_ON_ERROR);
                    $after=$pdo->query("SELECT * FROM queue_core_execution_leases WHERE lease_key='global'")->fetch(PDO::FETCH_ASSOC);
                    $observation=['holder_launcher'=>$parentLauncher,'contender'=>$child,'holder_was_live'=>true,
                        'owner_and_lease_unchanged'=>$before===$after,'before_generation'=>(int)$before['generation'],'after_generation'=>(int)$after['generation'],
                        'same_process'=>$child['pid']===getmypid()];
                    return true_seed_response($entry,$source,'200');
                };
                $parentError=null;
                try{if($parentLauncher==='automatic')(new App\QueueV4Clean\QueueV4CleanScheduler($pdo))->run(1,40);
                    else{$preview=mutantPreview(1);(new App\Services\ManualSingleStepService())->executePreview($preview['preview_token'],9007,1);}}
                catch(Throwable $e){$parentError=get_class($e);}
                $reached=$observation!==null&&!$observation['same_process']&&$observation['contender']['violations']===[]&&Wire::$violations===[];
                $bothReached=$bothReached&&$reached;
                if($reached&&$observation['after_generation']>$observation['before_generation'])$bypassed=true;
                $r['evidence'][]=['overlap'=>$observation,'holder_error'=>$parentError,'holder_wire_count'=>count(Wire::$entries),'holder_wire_violations'=>Wire::$violations];
                // Any reached physical observation remains in its direction-specific ledger.
            }
            $r['reached']=$bothReached;$r['witness']=$bothReached&&$bypassed;
            $r['wire_count_across_processes_and_directions']=array_sum(array_map(static fn(array $round):int=>
                $round['holder_wire_count']+count($round['overlap']['contender']['wire']??[]),$r['evidence']));
            $r['overlapping_transport_observed']=count(array_filter($r['evidence'],static fn(array $round):bool=>
                ($round['overlap']['contender']['wire']??[])!==[]))>0;
            $unchanged=$bothReached;
            foreach($r['evidence']as $round)$unchanged=$unchanged&&$round['overlap']['owner_and_lease_unchanged']
                &&$round['overlap']['contender']['global_admission_denied']&&$round['overlap']['contender']['wire']===[]
                &&$round['holder_wire_count']===1&&$round['holder_error']===null;
            mutantCheck($unchanged,'M8_REAL_OPPOSITE_LAUNCHER_OVERWROTE_LIVE_GLOBAL_AUTHORITY');break;
        case 'M10':
            true_seed_source($pdo,410,false,1);$repo=new Repo($pdo);$run=$repo->beginRun('test','synthetic-metric');$meta=mutantClaim($pdo,$repo,$run);
            foreach([['queue',$meta['queue_v4_job_id']],['sales_repair',$meta['queue_v4_job_id']],['queue',$meta['queue_v4_job_id']+100]]as[$kind,$work]){
                $pdo->prepare("INSERT INTO queue_v4_clean_transport_events(company_id,meli_account_id,source_kind,work_id,attempt_id,lease_generation,request_id,method,endpoint_key,physical_started_at) VALUES(9001,9011,?,?,?,?,?,'GET','order_exact',UTC_TIMESTAMP(3))")
                    ->execute([$kind,$work,$meta['queue_v4_attempt_id'],$meta['queue_v4_lease_generation'],bin2hex(random_bytes(20))]);
            }
            $receipt=(new Worker($pdo,$repo))->receiptForRun($run);$r['evidence']=$receipt;
            $r['reached']=$receipt['known_physical_calls']===0&&$receipt['unresolved_dispatches']===1&&$receipt['possible_physical_calls_max']===1&&Wire::$entries===[];
            $r['witness']=$r['reached']&&$receipt['physical_http_calls']===1&&$receipt['physical_http_calls_certainty']==='CERTIFIED';
            mutantCheck($r['reached']&&$receipt['physical_http_calls']===null&&$receipt['physical_http_calls_certainty']==='UNKNOWN','M10_UNRESOLVED_MARKER_CERTIFIED_EXACT');break;
        default:throw new RuntimeException('unsupported_case');
    }
    true_seed_assert(Wire::$violations===[],'MUTANT_NO_SWALLOWED_WIRE_VIOLATION');
    $r['state']='PASS';$exit=0;
}catch(MutationOracleViolation $e){
    $r['state']='ORACLE_VIOLATION';$r['invariant']=$e->getMessage();$exit=42;
}catch(Throwable $e){
    $r['state']='SETUP_OR_OTHER_GUARD';$r['failure']=['type'=>get_class($e),'file'=>basename($e->getFile()),'line'=>$e->getLine()];
}finally{
    Budget::clear();$r['wire_count']=count(Wire::$entries);$r['wire_violations']=Wire::$violations;
    $r['all_wire_violations']=Wire::$violations;
    if($case==='M8'){
        $r['all_wire_violations']=[];
        foreach($r['evidence']??[]as $round){
            foreach($round['holder_wire_violations']??[]as $violation)$r['all_wire_violations'][]=['role'=>'holder','violation'=>$violation];
            foreach($round['overlap']['contender']['violations']??[]as $violation)$r['all_wire_violations'][]=['role'=>'contender','violation'=>$violation];
        }
    }
    // This final guard also runs after an explicit oracle failure; fixture
    // rejection must never be upgraded to a behavioral kill.
    if($r['wire_violations']!==[]||$r['all_wire_violations']!==[]){
        $r['state']='WIRE_FIXTURE_FAILURE_NOT_KILL';$r['reached']=false;$r['witness']=false;$exit=1;
    }
    try{if($h!==null){$h->cleanup();$r['database_absent_after_cleanup']=true;}$r['cleanup']=true;}
    catch(Throwable $e){$r['cleanup']=false;$r['state']='CLEANUP_FAILURE_NOT_KILL';$exit=1;}
    mutantJson($artifact,$r);
}
exit($exit);
