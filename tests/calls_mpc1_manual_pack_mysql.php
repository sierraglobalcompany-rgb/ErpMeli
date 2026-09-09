<?php
declare(strict_types=1);

require __DIR__.'/k1b_bootstrap.php';
require __DIR__.'/K1dSafeTestDatabase.php';
require __DIR__.'/calls_true_wire_fixture.php';
require __DIR__.'/calls_true_seed_fixture.php';

use App\Services\CallsTrueWire;
use App\Services\CapacityPolicyService;
use App\Services\ManualCampaignPreviewService;
use App\Services\ManualSingleStepService;
use App\Services\CronDeadlineContext;
use App\QueueV4Clean\QueueV4CleanScheduler;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueCore\QueueExecutionLeaseService;

/** Each invocation owns a new DB; only the final HTTP boundary is substituted. */
$options=getopt('',['case:','template:','template-sha256:','root:','artifact:','mutation-oracle']);
$case=(string)($options['case']??'continue');
$cases=['continue','preview_readonly','future_pointer','future_source','pointer_half_lease','source_half_lease',
    'attempts_exhausted','wrong_cause','wrong_source_state','payload_mismatch','checkpoint_invalid_json',
    'checkpoint_capture_mismatch','capture_scope_mismatch','live_input_changed','scope_changed',
    'source_generation_changed','queue_generation_changed','auto_aba','auto_wake_only','consume_rollback',
    'expired_token','global_busy','admitted_before_http_failure','unresolved','neighbor_untouched',
    'live_input_after_preview','checkpoint_after_preview','future_after_preview','tenant_after_preview',
    'consume_failure','capture_version_mismatch','capture_http_mismatch','capture_orders_mismatch','zero_checkpoints',
    'external_sale_changed','starvation','lock_input_change','lock_deadline',
    'all_checkpoints_complete','reservation_before_preview','reservation_after_preview','marker_malformed'];
true_seed_assert(in_array($case,$cases,true),'MPC1_KNOWN_CASE');
$root=rtrim((string)($options['root']??getenv('CALLS_TRUE_QA_ROOT')?:'D:/Codex/tmp/mpc1'),'/\\');
true_seed_assert(str_starts_with(str_replace('\\','/',$root),'D:/Codex/')&&!in_array('..',explode('/',str_replace('\\','/',$root)),true),'MPC1_EXTERNAL_EVIDENCE_ROOT');
$dir=$root.'/mpc1-'.$case.'-'.bin2hex(random_bytes(4));
true_seed_assert(mkdir($dir,0770,true),'MPC1_OWNED_DIRECTORY');
foreach(['APP_ENV'=>'test','ML_WRITE_ENABLED'=>'false','DB_HOST'=>'127.0.0.1','DB_PORT'=>'33079',
    'DB_USER'=>'root','DB_PASS'=>'','DB_NAME'=>'erp_meli_k1d_test_mpc1_'.bin2hex(random_bytes(5)),
    'APP_KEY'=>'synthetic-mpc1-only','ERP_PRIVATE_PATH'=>$dir.'/private','MELI_API_BASE'=>'https://calls-wire.invalid']as $key=>$value)putenv($key.'='.$value);
if(!defined('ERP_INSTALLATION_ROOT'))define('ERP_INSTALLATION_ROOT',$dir.'/public_html/erp-meli');
true_seed_assert(mkdir(ERP_INSTALLATION_ROOT,0770,true),'MPC1_SERVED_BOUNDARY');
$ledger=['case'=>$case,'pid'=>getmypid(),'db_name'=>getenv('DB_NAME'),'state'=>'OWNERSHIP_DECLARED_BEFORE_CREATE'];
file_put_contents($dir.'/started.json',json_encode($ledger,JSON_THROW_ON_ERROR));
$h=null;$exit=0;
$mutation=isset($options['mutation-oracle']);
true_seed_assert(!$mutation||$case==='auto_aba','MPC1_MUTATION_ORACLE_EXACT_CASE');
$ledger+=['oracle_hash'=>hash_file('sha256',__FILE__),'reached'=>false,'witness'=>false,'cleanup'=>false];

function mpc1_preview():array
{
    $capacity=(new CapacityPolicyService())->snapshot('manual');
    return (new ManualCampaignPreviewService())->create(9007,['scope'=>'available_queue','account_id'=>9011,
        'physical_api_call_budget'=>1,'capacity_revision'=>$capacity['revision']]);
}
function mpc1_snapshot(PDO $pdo):array
{
    $hashes=[];
    foreach(['queue_v4_clean_jobs','queue_v4_clean_attempts','sale_financial_reconciliation_jobs','sale_financial_evidence',
        'sale_financial_state','meli_orders','meli_order_items','meli_packs',
        'manual_campaigns','manual_campaign_operations','manual_campaign_items','manual_campaign_reservations',
        'meli_billing_capture_runs','queue_v4_clean_transport_events','meli_sale_financials',
        'meli_sale_financial_history','meli_sale_financial_lines','meli_sale_financial_allocations']as $table){
        $hashes[$table]=hash('sha256',json_encode($pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR));
    }
    return $hashes;
}
function mpc1_due(PDO $pdo,array $source):void
{
    $q=$pdo->prepare('SELECT q.available_at<=UTC_TIMESTAMP(3) AND s.next_run_at<=UTC_TIMESTAMP(3)
        FROM queue_v4_clean_jobs q JOIN sale_financial_reconciliation_jobs s
        ON s.id=? AND s.company_id=q.company_id AND s.meli_account_id=q.meli_account_id
        WHERE q.id=? AND q.company_id=9001 AND q.meli_account_id=9011');
    $start=microtime(true);
    do{$q->execute([$source['source'],$source['queue'][0]]);$due=(bool)$q->fetchColumn();
        true_seed_assert(microtime(true)-$start<20,'MPC1_REAL_WAIT_NOT_INFLATED');
        if(!$due)usleep(100000);
    }while(!$due);
}
function mpc1_execute(array $preview,int $execution):array
{
    CallsTrueWire::$execution=$execution;
    $start=count(CallsTrueWire::$entries);
    $result=(new ManualSingleStepService())->executePreview($preview['preview_token'],9007,1);
    $wire=array_slice(CallsTrueWire::$entries,$start);
    true_seed_receipt($result,$wire,1);
    return ['result'=>$result,'wire'=>$wire];
}

/** Complete synthetic evidence through the existing response/parser/recorder, without publishing. */
function mpc1_seed_remaining_checkpoints(PDO $pdo,array $source):void
{
    $read=$pdo->prepare('SELECT * FROM sale_financial_reconciliation_jobs WHERE id=? AND company_id=9001 AND meli_account_id=9011');
    $read->execute([$source['source']]);$job=$read->fetch(PDO::FETCH_ASSOC);
    true_seed_assert(is_array($job)&&$job['status']==='retry','MPC1_COMPLETE_CHECKPOINT_SOURCE_SEEDED');
    $pdo->beginTransaction();
    try{
        foreach(array_slice($source['orders'],1)as $order){
            $response=true_seed_response(['method'=>'GET','path'=>'/billing/integration/group/ML/order/details',
                'company_id'=>9001,'account_id'=>9011,'order_ids'=>$order],$source,'200')['body'];
            $hash=hash('sha256',json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $lines=(new App\Services\SaleBillingParser())->parse($response,[$order]);
            true_seed_assert(count($lines)===1&&(string)$lines[0]['external_order_id']===$order,'MPC1_COMPLETE_CHECKPOINT_LINE_IDENTITY');
            $pdo->prepare("INSERT INTO meli_billing_capture_runs
                (company_id,meli_account_id,sale_key,external_sale_id,input_version,source_mode,
                 requested_order_ids_json,http_status,response_class,response_hash,missing_fields_json,captured_at)
                VALUES(9001,9011,?,?,?,'exact_repair',?,200,'complete',?,'[]',UTC_TIMESTAMP())")
                ->execute([$job['sale_key'],$job['external_sale_id'],$job['input_version'],json_encode([$order],JSON_THROW_ON_ERROR),$hash]);
            (new App\Services\SaleFinancialStateService())->recordBillingOrderCheckpoint($pdo,$job,(int)$pdo->lastInsertId(),
                $order,$lines,'reconciled','Synthetic complete-checkpoint fixture',$hash,200,'complete',['response_item_count'=>1]);
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Same minimal FK fixture as calls_final_financial_neighbor_perimeter_mysql; no legacy engine starts. */
function mpc1_reserve_financial_source(PDO $pdo,int $sourceId):int
{
    $pdo->prepare("INSERT INTO manual_campaigns
        (campaign_token,created_by_user_id,company_scope_key,scope_key,preset,status,configuration_json,total_items)
        VALUES(?,9007,9001,'finance','safe','active','{}',1)")->execute([bin2hex(random_bytes(20))]);
    $campaign=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO manual_campaign_operations
        (manual_campaign_id,queue_key,operation_key,meli_account_id,company_id,item_count,exact_adapter)
        VALUES(?,'sale_financial_reconciliation','financial_reconciliation',9011,9001,1,1)")->execute([$campaign]);
    $operation=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO manual_campaign_items
        (manual_campaign_id,operation_id,queue_key,operation_key,source_id,meli_account_id,company_id,human_label,status,position_no)
        VALUES(?,?,'sale_financial_reconciliation','financial_reconciliation',?,9011,9001,'MPC1 reserved finance','pending',1)")
        ->execute([$campaign,$operation,(string)$sourceId]);
    $item=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO manual_campaign_reservations
        (manual_campaign_id,manual_campaign_item_id,queue_key,source_id,company_id,meli_account_id,status,expires_at)
        VALUES(?,?,'sale_financial_reconciliation',?,9001,9011,'active',DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 5 MINUTE))")
        ->execute([$campaign,$item,(string)$sourceId]);
    $reservation=(int)$pdo->lastInsertId();
    true_seed_assert($reservation>0,'MPC1_FINANCIAL_RESERVATION_SEEDED');
    return $reservation;
}

try{
    $h=K1dSafeTestDatabase::createFromEnvironment();$pdo=$h->pdo();
    $ledger['database_version']=$pdo->query('SELECT VERSION()')->fetchColumn();
    if(isset($options['template'])){
        $template=realpath($options['template']);
        true_seed_assert($template!==false&&str_starts_with(str_replace('\\','/',$template),'D:/Codex/'),'MPC1_PRIVATE_TEMPLATE_PATH');
        $templateBytes=file_get_contents($template);
        true_seed_assert(is_string($templateBytes),'MPC1_TEMPLATE_READ');
        $ledger['template_sha256']=hash('sha256',$templateBytes);
        true_seed_assert(!isset($options['template-sha256'])||hash_equals((string)$options['template-sha256'],$ledger['template_sha256']),'MPC1_SEALED_TEMPLATE_HASH');
        foreach(json_decode($templateBytes,true,512,JSON_THROW_ON_ERROR)as $sql)$pdo->exec($sql);
        unset($templateBytes);
    }else{(new App\Services\Migrator($pdo,__DIR__.'/../database/migrations'))->run(301);}
    true_seed_scope($pdo,1);$source=true_seed_source($pdo,26,true,3);
    CallsTrueWire::$ledger=$dir.'/wire.jsonl';
    CallsTrueWire::$respond=static function(array $entry)use($pdo,$source):array{
        true_seed_assert(!$pdo->inTransaction(),'MPC1_NO_TRANSACTION_DURING_HTTP');
        return true_seed_response($entry,$source,'200');
    };
    $initial=mpc1_preview();
    true_seed_assert(count(CallsTrueWire::$entries)===0,'MPC1_INITIAL_PREVIEW_ZERO_HTTP');
    $ledger['first']=mpc1_execute($initial,1);
    true_seed_assert(count($ledger['first']['wire'])===1,'MPC1_FIRST_SINGLETON_GET');
    $business=true_seed_business_snapshot($pdo,$source);
    true_seed_assert(count($business['billing_checkpoints'])===1&&$business['official_publications']===0,'MPC1_FIRST_CHECKPOINT_WITHOUT_PUBLICATION');
    $firstPointer=$pdo->query('SELECT * FROM queue_v4_clean_jobs WHERE id='.(int)$source['queue'][0])->fetch(PDO::FETCH_ASSOC);
    true_seed_assert($business['financial_source']['status']==='retry'&&$firstPointer['state']==='waiting'
        &&$firstPointer['last_error_class']==='domain_source_waiting:financial_reconciliation:billing_checkpoint_progress',
        'MPC1_REAL_CHECKPOINT_WAITING_CAUSE');
    mpc1_due($pdo,$source);
    $qid=(int)$source['queue'][0];$sid=(int)$source['source'];
    if($case==='starvation'){
        $pdo->exec("UPDATE sale_financial_reconciliation_jobs SET next_run_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE id=$sid");
        $columns=$pdo->query('SHOW COLUMNS FROM queue_v4_clean_jobs')->fetchAll(PDO::FETCH_ASSOC);
        $fields=array_values(array_filter(array_column($columns,'Field'),static fn(string $f):bool=>$f!=='id'));
        $quoted=implode(',',array_map(static fn(string $f):string=>'`'.$f.'`',$fields));
        $row=$pdo->query("SELECT $quoted FROM queue_v4_clean_jobs WHERE id=$qid")->fetch(PDO::FETCH_ASSOC);
        $insert=$pdo->prepare('INSERT INTO queue_v4_clean_jobs ('.$quoted.') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')');
        // Independent, locally seeded ineligible prefix; no runtime date or
        // rate authority is advanced to obtain another HTTP call in this case.
        $row['available_at']='2000-01-01 00:00:00.000';
        for($n=0;$n<65;$n++){$row['idempotency_key']='mpc1-poison-'.$n;$insert->execute(array_values($row));}
        $neighbor=true_seed_source($pdo,27,true,1);$expected=$neighbor['queue'];
        $prefix=$pdo->query('SELECT id FROM queue_v4_clean_jobs ORDER BY available_at,id LIMIT 60')->fetchAll(PDO::FETCH_COLUMN);
        true_seed_assert(count($prefix)===60&&!in_array($expected[0],array_map('intval',$prefix),true),'MPC1_INELIGIBLE_PREFIX_ORDERED_BEFORE_READY');
        $snapshot=mpc1_snapshot($pdo);$projection=mpc1_preview();
        true_seed_assert(array_column($projection['rows'],'queue_job_id')===$expected,'MPC1_INELIGIBLE_PREFIX_DOES_NOT_STARVE_READY',['rows'=>array_column($projection['rows'],'queue_job_id'),'expected'=>$expected]);
        true_seed_assert(mpc1_snapshot($pdo)===$snapshot&&count(CallsTrueWire::$entries)===1,'MPC1_STARVATION_PROBE_READONLY');
        $ledger['state']='PASS';
    }else{
    $negativeSql=match($case){
        'future_pointer'=>"UPDATE queue_v4_clean_jobs SET available_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR) WHERE id=$qid",
        'future_source'=>"UPDATE sale_financial_reconciliation_jobs SET next_run_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR) WHERE id=$sid",
        'pointer_half_lease'=>"UPDATE queue_v4_clean_jobs SET lease_owner='synthetic-incoherent',lease_expires_at=NULL WHERE id=$qid",
        'source_half_lease'=>"UPDATE sale_financial_reconciliation_jobs SET lock_owner='synthetic-incoherent',lease_expires_at=NULL WHERE id=$sid",
        'attempts_exhausted'=>"UPDATE queue_v4_clean_jobs SET attempt_count=max_attempts WHERE id=$qid",
        'wrong_cause'=>"UPDATE queue_v4_clean_jobs SET last_error_class='domain_source_waiting:financial_reconciliation:remote_429' WHERE id=$qid",
        'wrong_source_state'=>"UPDATE sale_financial_reconciliation_jobs SET status='awaiting_remote' WHERE id=$sid",
        'payload_mismatch'=>"UPDATE queue_v4_clean_jobs SET payload_json=JSON_SET(payload_json,'$.source_id',999999) WHERE id=$qid",
        'checkpoint_invalid_json'=>"UPDATE sale_financial_evidence SET evidence_json='not-json' WHERE evidence_type='billing_capture' AND company_id=9001 AND meli_account_id=9011",
        'checkpoint_capture_mismatch'=>"UPDATE sale_financial_evidence SET evidence_json=JSON_SET(evidence_json,'$.capture_id',999999) WHERE evidence_type='billing_capture' AND company_id=9001 AND meli_account_id=9011",
        'capture_scope_mismatch'=>"UPDATE meli_billing_capture_runs SET sale_key='P:other' WHERE company_id=9001 AND meli_account_id=9011",
        'capture_version_mismatch'=>"UPDATE meli_billing_capture_runs SET input_version=REPEAT('0',64) WHERE company_id=9001 AND meli_account_id=9011",
        'capture_http_mismatch'=>"UPDATE meli_billing_capture_runs SET http_status=429 WHERE company_id=9001 AND meli_account_id=9011",
        'capture_orders_mismatch'=>"UPDATE meli_billing_capture_runs SET requested_order_ids_json='[\"8002600\",\"8002601\"]' WHERE company_id=9001 AND meli_account_id=9011",
        'zero_checkpoints'=>"UPDATE sale_financial_evidence SET evidence_status='pending' WHERE evidence_type='billing_capture' AND company_id=9001 AND meli_account_id=9011",
        'live_input_changed'=>"UPDATE meli_order_items SET unit_price=101 WHERE meli_account_id=9011",
        'scope_changed'=>"UPDATE queue_v4_clean_jobs SET company_id=9002,meli_account_id=9021 WHERE id=$qid",
        default=>null,
    };
    if($negativeSql!==null)$pdo->exec($negativeSql);
    if($case==='unresolved'){
        $pdo->exec("UPDATE queue_v4_clean_transport_events SET response_known_at=NULL,dispatch_state='PHYSICAL_STARTED' WHERE source_kind='queue' AND work_id=$qid AND company_id=9001 AND meli_account_id=9011");
        true_seed_assert((int)$pdo->query("SELECT COUNT(*) FROM queue_v4_clean_transport_events WHERE dispatch_state='PHYSICAL_STARTED' AND response_known_at IS NULL")->fetchColumn()>0,'MPC1_UNRESOLVED_FIXTURE_REACHED');
    }
    if($case==='all_checkpoints_complete')mpc1_seed_remaining_checkpoints($pdo,$source);
    if($case==='reservation_before_preview')$reservation=mpc1_reserve_financial_source($pdo,$sid);
    $beforePreview=mpc1_snapshot($pdo);$preview=mpc1_preview();
    true_seed_assert(mpc1_snapshot($pdo)===$beforePreview&&count(CallsTrueWire::$entries)===1,'MPC1_PREVIEW_ZERO_OPERATIONAL_WRITES_OR_HTTP');
    if($negativeSql!==null||in_array($case,['unresolved','all_checkpoints_complete','reservation_before_preview'],true)){
        true_seed_assert($preview['rows']===[],'MPC1_UNSAFE_CONTINUATION_NOT_SHOWN',['case'=>$case]);
        if($case==='all_checkpoints_complete'){
            $complete=true_seed_business_snapshot($pdo,$source);
            $stateRead=$pdo->prepare('SELECT official_status,official_net_amount FROM sale_financial_state WHERE company_id=9001 AND meli_account_id=9011 AND sale_key=?');
            $stateRead->execute([$source['saleKey']]);$official=$stateRead->fetch(PDO::FETCH_ASSOC);
            true_seed_assert(count($complete['billing_checkpoints'])===3&&count(array_unique(array_column($complete['billing_checkpoints'],'order_id')))===3
                &&$complete['official_publications']===0&&$official['official_status']!=='complete'&&$official['official_net_amount']===null,
                'MPC1_ALL_CHECKPOINTS_UNPUBLISHED_PRECONDITION');
            $beforeSource=$pdo->query("SELECT * FROM sale_financial_reconciliation_jobs WHERE id=$sid")->fetch(PDO::FETCH_ASSOC);
            $beforePointer=$pdo->query("SELECT * FROM queue_v4_clean_jobs WHERE id=$qid")->fetch(PDO::FETCH_ASSOC);
            true_seed_assert($beforePointer['state']==='waiting'&&$beforeSource['status']==='retry','MPC1_ALL_CHECKPOINTS_WAITING_EXCLUDED');
            // Fixture selects the already-existing ready path; no source/date/rhythm rewrite or scheduler wake-up.
            $pdo->exec("UPDATE queue_v4_clean_jobs SET state='ready' WHERE id=$qid AND company_id=9001 AND meli_account_id=9011 AND state='waiting'");
            $ready=mpc1_preview();$captures=mpc1_snapshot($pdo)['meli_billing_capture_runs'];
            true_seed_assert(array_column($ready['rows'],'queue_job_id')===[$qid]
                &&!array_key_exists('manual_continuation_kind',$ready['rows'][0]),'MPC1_ALL_CHECKPOINTS_ORDINARY_READY_PATH');
            $ledger['local']=mpc1_execute($ready,2);$after=true_seed_business_snapshot($pdo,$source);
            $afterSource=$pdo->query("SELECT * FROM sale_financial_reconciliation_jobs WHERE id=$sid")->fetch(PDO::FETCH_ASSOC);
            $afterPointer=$pdo->query("SELECT * FROM queue_v4_clean_jobs WHERE id=$qid")->fetch(PDO::FETCH_ASSOC);
            true_seed_assert($ledger['local']['wire']===[]&&count(CallsTrueWire::$entries)===1
                &&$after['official_publications']===1&&$afterSource['status']==='complete'&&$afterPointer['state']==='completed'
                &&(int)$afterSource['lease_generation']===(int)$beforeSource['lease_generation']+1
                &&(int)$afterPointer['lease_generation']===(int)$beforePointer['lease_generation']+1
                &&mpc1_snapshot($pdo)['meli_billing_capture_runs']===$captures,'MPC1_ALL_CHECKPOINTS_READY_PUBLISHES_WITH_ZERO_HTTP');
            $stateRead->execute([$source['saleKey']]);$official=$stateRead->fetch(PDO::FETCH_ASSOC);
            true_seed_assert($official['official_status']==='complete'&&(float)$official['official_net_amount']===270.0,
                'MPC1_ALL_CHECKPOINTS_OFFICIAL_VALUES');
            $snapshot=mpc1_snapshot($pdo);$denied=false;
            try{mpc1_execute($ready,3);}catch(Throwable){$denied=true;}
            true_seed_assert($denied&&mpc1_snapshot($pdo)===$snapshot&&count(CallsTrueWire::$entries)===1,'MPC1_ALL_CHECKPOINTS_REPLAY_NO_DUPLICATE');
        }elseif($case==='reservation_before_preview'){
            $pdo->prepare("UPDATE manual_campaign_reservations SET status='released' WHERE id=?")->execute([$reservation]);
            $released=mpc1_preview();
            true_seed_assert(array_column($released['rows'],'queue_job_id')===[$qid]&&count(CallsTrueWire::$entries)===1,
                'MPC1_RELEASED_RESERVATION_NOT_A_BLANKET_BLOCK');
        }
    }else{
        true_seed_assert(array_map(static fn(array $row):int=>(int)$row['queue_job_id'],$preview['rows'])===[$qid],
            'MPC1_DUE_SAME_SOURCE_SHOWN');
        true_seed_assert(($preview['rows'][0]['manual_continuation_kind']??'')==='billing_checkpoint_progress','MPC1_MARKER_PERSISTED');
        $saved=$preview['rows'][0];
        $sourceIdentity=$pdo->query("SELECT lease_generation,input_version FROM sale_financial_reconciliation_jobs WHERE id=$sid")->fetch(PDO::FETCH_ASSOC);
        true_seed_assert(($saved['queue_lease_generation']??null)===(int)$firstPointer['lease_generation']
            &&($saved['financial_source_lease_generation']??null)===(int)$sourceIdentity['lease_generation']
            &&($saved['financial_input_version']??null)===$sourceIdentity['input_version'],'MPC1_DURABLE_GENERATIONS_AND_VERSION_IN_SNAPSHOT');
        $ledger['confirmed']=$preview['rows'];
        if($case==='continue'||$case==='preview_readonly'){
            for($step=2;$step<=3;$step++){
                if($step===3){mpc1_due($pdo,$source);$preview=mpc1_preview();}
                $ledger['steps'][]=mpc1_execute($preview,$step);
                $business=true_seed_business_snapshot($pdo,$source);
                true_seed_assert(count(CallsTrueWire::$entries)===$step&&count($business['billing_checkpoints'])===$step,'MPC1_ONE_NEW_CHECKPOINT_PER_EXPLICIT_STEP');
                true_seed_assert($business['official_publications']===($step===3?1:0),'MPC1_SINGLE_FINAL_PUBLICATION');
                $beforeReplay=mpc1_snapshot($pdo);$denied=false;
                try{mpc1_execute($preview,90+$step);}catch(Throwable){$denied=true;}
                true_seed_assert($denied&&mpc1_snapshot($pdo)===$beforeReplay&&count(CallsTrueWire::$entries)===$step,'MPC1_REPLAY_ZERO_EFFECT');
            }
            $ids=array_column(CallsTrueWire::$entries,'order_ids');
            true_seed_assert(count(array_unique($ids))===3,'MPC1_DISTINCT_ORDER_GETS');
        }elseif($case==='reservation_after_preview'){
            $reservation=mpc1_reserve_financial_source($pdo,$sid);$snapshot=mpc1_snapshot($pdo);
            $ledger['reserved']=mpc1_execute($preview,2);
            true_seed_assert(mpc1_snapshot($pdo)===$snapshot&&count(CallsTrueWire::$entries)===1,
                'MPC1_RESERVATION_AFTER_PREVIEW_ZERO_ADMISSION');
            $pdo->prepare("UPDATE manual_campaign_reservations SET status='released' WHERE id=?")->execute([$reservation]);
            true_seed_assert(array_column(mpc1_preview()['rows'],'queue_job_id')===[$qid],'MPC1_RESERVATION_RELEASE_NEW_CONFIRMATION_AVAILABLE');
        }elseif($case==='marker_malformed'){
            $repo=new QueueV4CleanRepository($pdo);
            $version=new ReflectionMethod(QueueV4CleanRepository::class,'queueSelectionVersion');
            // Recompute the real snapshot hash so an unrelated hash mismatch cannot mask missing type checks.
            foreach(['unknown_kind','missing_kind','missing_generation','string_generation','negative_generation','invalid_version']as $malformed){
                $current=mpc1_preview();$bad=$current['rows'][0];
                true_seed_assert($repo->manualContinuationSourceIdentityMatches($bad),'MPC1_MARKER_VALID_CONTROL');
                switch($malformed){
                    case 'unknown_kind':$bad['manual_continuation_kind']='other';break;
                    case 'missing_kind':unset($bad['manual_continuation_kind']);break;
                    case 'missing_generation':unset($bad['queue_lease_generation']);break;
                    case 'string_generation':$bad['queue_lease_generation']=(string)$bad['queue_lease_generation'];break;
                    case 'negative_generation':$bad['financial_source_lease_generation']=-1;break;
                    case 'invalid_version':$bad['financial_input_version']='not-a-version';break;
                }
                $bad['selection_version']=$version->invoke($repo,array_replace($bad,['idempotency_key'=>$bad['queue_idempotency_key']]));
                true_seed_assert(!$repo->manualContinuationSourceIdentityMatches($bad),'MPC1_MALFORMED_MARKER_REJECTED',['variant'=>$malformed]);
                $update=$pdo->prepare('UPDATE manual_campaign_preview_items SET item_payload_json=?
                    WHERE manual_campaign_preview_id=? AND queue_key="available_queue" AND source_id=? AND meli_account_id=9011');
                $update->execute([json_encode($bad,JSON_THROW_ON_ERROR),$current['preview_id'],(string)$qid]);
                true_seed_assert($update->rowCount()===1,'MPC1_MALFORMED_DURABLE_SNAPSHOT_REACHED');
                $loaded=(new ManualCampaignPreviewService())->load($current['preview_token'],9007);
                true_seed_assert($loaded['rows'][0]===$bad,'MPC1_MALFORMED_SNAPSHOT_LOADED_EXACTLY');
                $snapshot=mpc1_snapshot($pdo);$ledger['malformed'][$malformed]=mpc1_execute($current,2);
                true_seed_assert(mpc1_snapshot($pdo)===$snapshot&&count(CallsTrueWire::$entries)===1,
                    'MPC1_MALFORMED_MARKER_END_TO_END_NO_EFFECT',['variant'=>$malformed]);
            }
        }elseif($case==='auto_aba'){
            $beforeGenerations=$pdo->query("SELECT q.lease_generation AS qgen,s.lease_generation AS sgen FROM queue_v4_clean_jobs q JOIN sale_financial_reconciliation_jobs s ON s.id=$sid AND s.company_id=q.company_id AND s.meli_account_id=q.meli_account_id WHERE q.id=$qid")->fetch(PDO::FETCH_ASSOC);
            CallsTrueWire::$execution=2;CronDeadlineContext::start(45,43,8,3);
            try{$ledger['automatic']=(new QueueV4CleanScheduler($pdo))->run(1,45);}finally{CronDeadlineContext::clear();}
            true_seed_assert(count(CallsTrueWire::$entries)===2,'MPC1_ABA_REAL_AUTOMATIC_ADVANCE');
            $afterGenerations=$pdo->query("SELECT q.lease_generation AS qgen,s.lease_generation AS sgen,q.state,s.status FROM queue_v4_clean_jobs q JOIN sale_financial_reconciliation_jobs s ON s.id=$sid AND s.company_id=q.company_id AND s.meli_account_id=q.meli_account_id WHERE q.id=$qid")->fetch(PDO::FETCH_ASSOC);
            $afterAutomatic=true_seed_business_snapshot($pdo,$source);
            true_seed_assert((int)$afterGenerations['qgen']===(int)$beforeGenerations['qgen']+1
                &&(int)$afterGenerations['sgen']===(int)$beforeGenerations['sgen']+1
                &&$afterGenerations['state']==='waiting'&&$afterGenerations['status']==='retry'
                &&count($afterAutomatic['billing_checkpoints'])===2&&$afterAutomatic['official_publications']===0
                &&count(array_unique(array_column(CallsTrueWire::$entries,'order_ids')))===2,'MPC1_ABA_CHECKPOINT_AND_BOTH_GENERATIONS_ADVANCED');
            $ledger['reached']=true;
            mpc1_due($pdo,$source);$snapshot=mpc1_snapshot($pdo);
            $ledger['stale']=mpc1_execute($preview,3);
            $ledger['witness']=count(CallsTrueWire::$entries)===3
                &&count(array_unique(array_column(CallsTrueWire::$entries,'order_ids')))===3;
            true_seed_assert(count(CallsTrueWire::$entries)===2&&mpc1_snapshot($pdo)===$snapshot,'MPC1_ABA_OLD_PREVIEW_NO_EFFECT');
        }elseif(in_array($case,['source_generation_changed','queue_generation_changed'],true)){
            $table=$case==='source_generation_changed'?'sale_financial_reconciliation_jobs':'queue_v4_clean_jobs';
            $id=$case==='source_generation_changed'?$sid:$qid;
            $pdo->exec("UPDATE $table SET lease_generation=lease_generation+1 WHERE id=$id");$snapshot=mpc1_snapshot($pdo);
            $ledger['stale']=mpc1_execute($preview,2);
            true_seed_assert(count(CallsTrueWire::$entries)===1&&mpc1_snapshot($pdo)===$snapshot,'MPC1_GENERATION_CHANGE_NO_EFFECT');
        }elseif($case==='auto_wake_only'){
            $repo=new QueueV4CleanRepository($pdo);$repo->releaseDueWaiting();
            true_seed_assert($pdo->query("SELECT state FROM queue_v4_clean_jobs WHERE id=$qid")->fetchColumn()==='ready','MPC1_WAKE_WITHOUT_CLAIM');
            $ledger['second']=mpc1_execute($preview,2);
            true_seed_assert(count(CallsTrueWire::$entries)===2,'MPC1_WAKE_SAME_GENERATIONS_COMPATIBLE');
        }elseif($case==='global_busy'){
            $leases=new QueueExecutionLeaseService($pdo);$held=$leases->acquire('cron_v4','synthetic-mpc1-held',60);
            true_seed_assert($held!==null,'MPC1_BUSY_FIXTURE_LOCK');$snapshot=mpc1_snapshot($pdo);
            try{$ledger['busy']=(new ManualSingleStepService())->executePreview($preview['preview_token'],9007,1);}finally{$leases->release($held);}
            true_seed_assert(($ledger['busy']['status']??'')==='waiting'&&($ledger['busy']['processed_count']??null)===0,'MPC1_BUSY_NO_ADMISSION');
            true_seed_assert(mpc1_snapshot($pdo)===$snapshot&&count(CallsTrueWire::$entries)===1,'MPC1_BUSY_ZERO_EFFECT');
            $q=$pdo->prepare('SELECT status FROM manual_campaign_previews WHERE preview_token=?');$q->execute([$preview['preview_token']]);
            true_seed_assert($q->fetchColumn()==='ready','MPC1_BUSY_PREVIEW_NOT_CONSUMED');
        }elseif($case==='expired_token'){
            $q=$pdo->prepare('UPDATE manual_campaign_previews SET expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE preview_token=?');$q->execute([$preview['preview_token']]);
            $snapshot=mpc1_snapshot($pdo);$denied=false;
            try{mpc1_execute($preview,2);}catch(Throwable){$denied=true;}
            true_seed_assert($denied&&count(CallsTrueWire::$entries)===1&&mpc1_snapshot($pdo)===$snapshot,'MPC1_EXPIRED_NO_PROMOTION');
        }elseif(in_array($case,['live_input_after_preview','checkpoint_after_preview','future_after_preview','tenant_after_preview','external_sale_changed'],true)){
            $pdo->exec(match($case){
                'live_input_after_preview'=>"UPDATE meli_order_items SET unit_price=101 WHERE meli_account_id=9011",
                'checkpoint_after_preview'=>"UPDATE meli_billing_capture_runs SET input_version=REPEAT('0',64) WHERE company_id=9001 AND meli_account_id=9011",
                'future_after_preview'=>"UPDATE queue_v4_clean_jobs SET available_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR) WHERE id=$qid",
                'tenant_after_preview'=>"UPDATE queue_v4_clean_jobs SET company_id=9002,meli_account_id=9021 WHERE id=$qid",
                'external_sale_changed'=>"UPDATE sale_financial_reconciliation_jobs SET external_sale_id='changed-after-preview' WHERE id=$sid",
            });
            $snapshot=mpc1_snapshot($pdo);$ledger['stale']=mpc1_execute($preview,2);
            true_seed_assert(count(CallsTrueWire::$entries)===1&&mpc1_snapshot($pdo)===$snapshot,'MPC1_ADMISSION_REVALIDATES_CHANGED_SOURCE',['case'=>$case]);
        }elseif(in_array($case,['lock_input_change','lock_deadline'],true)){
            $context=['db_name'=>$h->dbName,'parent_connection_id'=>(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn(),
                'queue_id'=>$qid,'case'=>$case];
            $contextPath=$dir.'/lock-context.json';file_put_contents($contextPath,json_encode($context,JSON_THROW_ON_ERROR));
            $child=proc_open([PHP_BINARY,__DIR__.'/calls_mpc1_lock_contender.php','--context='.$contextPath],
                [0=>['pipe','r'],1=>['file',$dir.'/lock-child.out.log','w'],2=>['file',$dir.'/lock-child.err.log','w']],$pipes,dirname(__DIR__));
            true_seed_assert(is_resource($child),'MPC1_LOCK_CHILD_STARTED');fclose($pipes[0]);
            $wait=microtime(true);while(!is_file($dir.'/lock-ready.json')){true_seed_assert(microtime(true)-$wait<10,'MPC1_LOCK_CHILD_READY');usleep(50000);}
            $snapshot=mpc1_snapshot($pdo);$caught=null;
            try{$ledger['locked']=mpc1_execute($preview,2);}catch(Throwable $e){$caught=$e;}
            $wait=microtime(true);do{$childStatus=proc_get_status($child);if(!$childStatus['running'])break;true_seed_assert(microtime(true)-$wait<10,'MPC1_LOCK_CHILD_ENDED');usleep(50000);}while(true);
            proc_close($child);true_seed_assert($childStatus['exitcode']===0,'MPC1_LOCK_CHILD_EXIT');
            $ledger['lock_observer']=json_decode(file_get_contents($dir.'/lock-result.json'),true,512,JSON_THROW_ON_ERROR);
            true_seed_assert($ledger['lock_observer']['parent_wait_observed']===true,'MPC1_PARENT_WAIT_REACHED');
            $after=mpc1_snapshot($pdo);
            if($case==='lock_input_change'){unset($snapshot['meli_order_items'],$after['meli_order_items']);}
            true_seed_assert($after===$snapshot&&count(CallsTrueWire::$entries)===1,
                $case==='lock_input_change'?'MPC1_LOCK_WAIT_FRESH_INPUT_NO_PROMOTION':'MPC1_DEADLINE_ROLLS_BACK_PROMOTION');
            if($case==='lock_deadline'){
                $q=$pdo->prepare('SELECT status FROM manual_campaign_previews WHERE preview_token=?');$q->execute([$preview['preview_token']]);
                true_seed_assert($caught!==null&&$q->fetchColumn()==='ready','MPC1_DEADLINE_ADMISSION_NOT_CONSUMED');
            }else{true_seed_assert($caught===null,'MPC1_STALE_INPUT_OMITTED_NOT_INFRA_ERROR');}
        }elseif($case==='consume_failure'){
            $pdo->exec("CREATE TRIGGER mpc1_fail_consume BEFORE UPDATE ON manual_campaign_previews FOR EACH ROW BEGIN IF OLD.status='ready' AND NEW.status='consumed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MPC1_SYNTHETIC_CONSUME_FAILURE'; END IF; END");
            $snapshot=mpc1_snapshot($pdo);$caught=null;
            try{mpc1_execute($preview,2);}catch(Throwable $e){$caught=$e;}
            true_seed_assert($caught!==null&&str_contains($caught->getMessage(),'MPC1_SYNTHETIC_CONSUME_FAILURE'),'MPC1_CONSUME_FAULT_REACHED');
            $q=$pdo->prepare('SELECT status FROM manual_campaign_previews WHERE preview_token=?');$q->execute([$preview['preview_token']]);
            true_seed_assert($q->fetchColumn()==='ready'&&mpc1_snapshot($pdo)===$snapshot&&count(CallsTrueWire::$entries)===1,'MPC1_FAILED_CONSUME_ZERO_PROMOTION');
        }elseif($case==='consume_rollback'){
            $pdo->exec("CREATE TRIGGER mpc1_fail_promotion BEFORE UPDATE ON queue_v4_clean_jobs FOR EACH ROW BEGIN IF OLD.id=$qid AND OLD.state='waiting' AND NEW.state='ready' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MPC1_SYNTHETIC_PROMOTION_FAILURE'; END IF; END");
            $snapshot=mpc1_snapshot($pdo);$caught=null;
            try{mpc1_execute($preview,2);}catch(Throwable $e){$caught=$e;}
            true_seed_assert($caught!==null&&str_contains($caught->getMessage(),'MPC1_SYNTHETIC_PROMOTION_FAILURE'),'MPC1_CAS_FAULT_REACHED');
            $q=$pdo->prepare('SELECT status FROM manual_campaign_previews WHERE preview_token=?');$q->execute([$preview['preview_token']]);
            true_seed_assert($q->fetchColumn()==='ready'&&mpc1_snapshot($pdo)===$snapshot&&count(CallsTrueWire::$entries)===1,'MPC1_CONSUME_AND_PROMOTION_ROLLBACK');
        }elseif($case==='admitted_before_http_failure'){
            $pdo->exec("CREATE TRIGGER mpc1_fail_claim BEFORE UPDATE ON queue_v4_clean_jobs FOR EACH ROW BEGIN IF OLD.id=$qid AND OLD.state='ready' AND NEW.state='running' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='MPC1_SYNTHETIC_AFTER_ADMISSION'; END IF; END");
            $caught=null;try{mpc1_execute($preview,2);}catch(Throwable $e){$caught=$e;}
            true_seed_assert($caught!==null&&str_contains($caught->getMessage(),'MPC1_SYNTHETIC_AFTER_ADMISSION'),'MPC1_POST_ADMISSION_FAULT_REACHED');
            $q=$pdo->prepare('SELECT status FROM manual_campaign_previews WHERE preview_token=?');$q->execute([$preview['preview_token']]);
            true_seed_assert($q->fetchColumn()==='consumed'&&count(CallsTrueWire::$entries)===1,'MPC1_COMMITTED_ADMISSION_NOT_REHABILITATED');
            $pdo->exec('DROP TRIGGER mpc1_fail_claim');
            $beforeReplay=mpc1_snapshot($pdo);
            $denied=false;try{mpc1_execute($preview,3);}catch(Throwable){$denied=true;}
            true_seed_assert($denied&&count(CallsTrueWire::$entries)===1&&mpc1_snapshot($pdo)===$beforeReplay,'MPC1_FAILED_ADMISSION_REPLAY_ZERO_HTTP');
        }elseif($case==='neighbor_untouched'){
            $neighbor=true_seed_source($pdo,27,true,1);$nid=(int)$neighbor['queue'][0];
            $before=$pdo->query("SELECT * FROM queue_v4_clean_jobs WHERE id=$nid")->fetch(PDO::FETCH_ASSOC);
            $neighborBefore=true_seed_business_snapshot($pdo,$neighbor);
            $ledger['second']=mpc1_execute($preview,2);
            true_seed_assert($pdo->query("SELECT * FROM queue_v4_clean_jobs WHERE id=$nid")->fetch(PDO::FETCH_ASSOC)===$before&&count(CallsTrueWire::$entries)===2,'MPC1_NEW_NEIGHBOR_NOT_SUBSTITUTED');
            $neighborAfter=true_seed_business_snapshot($pdo,$neighbor);
            foreach(['financial_source','billing_checkpoints','official_publications','unauthorized_tenant']as $field){
                true_seed_assert($neighborAfter[$field]===$neighborBefore[$field],'MPC1_NEIGHBOR_DOMAIN_UNTOUCHED',['field'=>$field]);
            }
        }
    }
    }
    true_seed_assert(CallsTrueWire::$violations===[],'MPC1_NO_WIRE_CONTRACT_VIOLATIONS');
    $ledger['state']='PASS';
}catch(Throwable $e){
    $detected=$mutation&&$ledger['reached']&&$ledger['witness']&&str_starts_with($e->getMessage(),'INVARIANT:MPC1_ABA_OLD_PREVIEW_NO_EFFECT:');
    $exit=$detected?42:1;$ledger['state']=$detected?'ORACLE_VIOLATION':'FAIL';
    $ledger['failure']=['type'=>get_class($e),'message'=>$e->getMessage(),'file'=>$e->getFile(),'line'=>$e->getLine()];fwrite(STDERR,$e->getMessage()."\n");
}finally{
    $ledger['wire']=CallsTrueWire::$entries;
    $ledger['wire_violations']=CallsTrueWire::$violations;$ledger['all_wire_violations']=CallsTrueWire::$violations;
    if($h!==null){$h->cleanup();$ledger['cleanup']=true;}
    file_put_contents($dir.'/result.json',json_encode($ledger,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    if(isset($options['artifact'])){
        $artifact=str_replace('\\','/',(string)$options['artifact']);
        true_seed_assert(str_starts_with($artifact,'D:/Codex/')&&!in_array('..',explode('/',$artifact),true),'MPC1_ARTIFACT_OWNED_PATH');
        file_put_contents($artifact,json_encode($ledger,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }
    echo 'MPC1_RESULT='.$dir.'/result.json'."\n";
}
exit($exit);
