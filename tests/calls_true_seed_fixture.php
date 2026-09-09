<?php
declare(strict_types=1);

use App\Core\Crypto;
use App\Core\Session;
use App\Services\AppSettingsService;
use App\Services\SaleFinancialStateService;

function true_seed_assert(bool $value,string $code,array $context=[]):void
{
    if(!$value)throw new RuntimeException('INVARIANT:'.$code.':'.json_encode($context,JSON_THROW_ON_ERROR));
}

/** The profile is fixed before execution; observed output never defines success. */
function true_seed_profile(int $seed,?string $directOverride=null):array
{
    $family=intdiv($seed-1,25);$index=($seed-1)%25;
    $manual=($family%2)===1;$billing=$family<2;
    $budgets=[1,2,3,5,9,15,50,55,100];
    $outcomes=['200','prewire','429','401','403','500','503','timeout','connect','partial','200','stale','200','200','200','200','200','200','200','200','200','prewire','429','200','429'];
    $retry=$family===2&&$index>=12&&$index<=21;
    $count=$billing&&in_array($index,[0,10,12,18,23],true)?3:($billing?1:3);
    $directType=$directOverride??(in_array($seed,[51,74,76,99],true)?'fresh_orders_discovery':'order_exact');
    true_seed_assert(in_array($directType,['order_exact','fresh_orders_discovery'],true),'DIRECT_TYPE_ALLOWLIST');
    return ['manual'=>$manual,'billing'=>$billing,'budget'=>$budgets[$index%count($budgets)],
        'outcome'=>$retry?'500':$outcomes[$index],'count'=>$retry?1:$count,'retry'=>$retry,
        'direct_type'=>$directType,'oauth'=>$seed===61,'resource_local'=>in_array($seed,[55,80],true),'local_resolution'=>$seed===39,
        'page_size'=>in_array($seed,[51,74,76,99],true)?1:($retry?1:$count),
        'expected_business'=>$seed===39?'claimed_local_completion_without_http_or_new_publication':($billing?($count>1?'durable_per_order_checkpoints_then_one_official_publication':'official_publication_or_explicit_deferral'):($directType==='fresh_orders_discovery'?'persist_page_orders_and_continuation_or_watermark':'persist_only_successful_exact_orders')),
        'expected_certainty'=>'CERTIFIED','expected_429_calls'=>1,'expected_retry_executions'=>$retry?2:1];
}

/** Snapshot for the one explicit local-only matrix seed; no lease owner values. */
function true_seed_local_resolution_snapshot(PDO $pdo,array $source):array
{
    $snapshot=['business'=>true_seed_business_snapshot($pdo,$source)];
    $snapshot['sources']=$pdo->query('SELECT id,status,input_version,attempts,lease_generation,
        (lock_owner IS NOT NULL) AS owner_present,lease_expires_at,heartbeat_at,next_run_at,completed_at
        FROM sale_financial_reconciliation_jobs WHERE company_id=9001 AND meli_account_id=9011 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $snapshot['pointers']=$pdo->query('SELECT id,resource_id,state,attempt_count,lease_generation,
        (lease_owner IS NOT NULL) AS owner_present,lease_expires_at,available_at,completed_at
        FROM queue_v4_clean_jobs WHERE company_id=9001 AND meli_account_id=9011 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $snapshot['attempts']=$pdo->query('SELECT id,job_id,outcome,lease_generation,dispatch_state,physical_http_calls,
        physical_started_at,http_status,response_known_at,source_closed_at,finished_at
        FROM queue_v4_clean_attempts WHERE company_id=9001 AND meli_account_id=9011 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $snapshot['transport_events']=(int)$pdo->query('SELECT COUNT(*) FROM queue_v4_clean_transport_events
        WHERE company_id=9001 AND meli_account_id=9011')->fetchColumn();
    foreach(['sale_financial_state','meli_sale_financials','meli_billing_capture_runs']as $table){
        $snapshot[$table]=$pdo->query('SELECT * FROM '.$table.' WHERE company_id=9001 AND meli_account_id=9011 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach(['meli_sale_financial_history','meli_sale_financial_lines','meli_sale_financial_allocations']as $table){
        $snapshot[$table]=$pdo->query('SELECT h.* FROM '.$table.' h JOIN meli_sale_financials f ON f.id=h.meli_sale_financial_id
            WHERE f.company_id=9001 AND f.meli_account_id=9011 ORDER BY h.id')->fetchAll(PDO::FETCH_ASSOC);
    }
    $snapshot['official_evidence']=$pdo->query("SELECT * FROM sale_financial_evidence
        WHERE company_id=9001 AND meli_account_id=9011 AND evidence_type<>'local_projection' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $snapshot['local_projection_evidence']=$pdo->query("SELECT * FROM sale_financial_evidence
        WHERE company_id=9001 AND meli_account_id=9011 AND evidence_type='local_projection' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    return $snapshot;
}

/** Positive local closure oracle, separate from the 99 ordinary wire scenarios. */
function true_seed_assert_local_resolution(PDO $pdo,array $source,array $before,array $result,array $wire,int $budget):array
{
    true_seed_assert($wire===[]&&App\Services\CallsTrueWire::$violations===[],'LOCAL_MATRIX_INDEPENDENT_WIRE_ZERO');
    true_seed_receipt($result,$wire,$budget);
    true_seed_assert(($result['api_calls_used']??null)===0&&($result['completed_count']??null)===1
        &&($result['waiting_count']??null)===0,'LOCAL_MATRIX_COMPLETED_RECEIPT_ZERO_CONSUMED');
    $after=true_seed_local_resolution_snapshot($pdo,$source);
    true_seed_assert(count($before['sources'])===1&&count($after['sources'])===1
        &&count($before['pointers'])===1&&count($after['pointers'])===1,'LOCAL_MATRIX_NO_NEW_NEIGHBORS');
    $s=$after['sources'][0];$q=$after['pointers'][0];
    true_seed_assert((int)$s['id']===$source['source']&&(int)$q['id']===$source['queue'][0]
        &&$s['status']==='complete'&&$q['state']==='completed'
        &&(int)$s['lease_generation']===(int)$before['sources'][0]['lease_generation']+1
        &&(int)$q['lease_generation']===(int)$before['pointers'][0]['lease_generation']+1
        &&$s['input_version']===$before['sources'][0]['input_version'],'LOCAL_MATRIX_ACTUAL_CLAIM_EXACT_SOURCE_COMPLETED');
    true_seed_assert((int)$s['owner_present']===0&&$s['lease_expires_at']===null&&$s['heartbeat_at']===null
        &&(int)$q['owner_present']===0&&$q['lease_expires_at']===null
        &&$s['completed_at']!==null&&$q['completed_at']!==null,'LOCAL_MATRIX_SOURCE_POINTER_LEASES_CLEARED');
    true_seed_assert($before['attempts']===[]&&count($after['attempts'])===1,'LOCAL_MATRIX_ONE_ATTEMPT');
    $a=$after['attempts'][0];
    true_seed_assert((int)$a['job_id']===(int)$q['id']&&$a['outcome']==='completed'
        &&$a['dispatch_state']==='NOT_DISPATCHED'&&(int)$a['physical_http_calls']===0
        &&$a['physical_started_at']===null&&$a['http_status']===null&&$a['response_known_at']===null
        &&$a['finished_at']!==null&&$after['transport_events']===0,'LOCAL_MATRIX_ATTEMPT_CLOSED_WITHOUT_DISPATCH');
    $progress=['queue'=>true,'financial_source'=>true];
    true_seed_assert(array_diff_key($after['business'],$progress)===array_diff_key($before['business'],$progress),
        'LOCAL_MATRIX_NO_CHECKPOINT_PUBLICATION_OR_UNSELECTED_EFFECT');
    $allowed=['business'=>true,'sources'=>true,'pointers'=>true,'attempts'=>true,'sale_financial_state'=>true,'local_projection_evidence'=>true];
    true_seed_assert(array_diff_key($after,$allowed)===array_diff_key($before,$allowed),'LOCAL_MATRIX_NO_CAPTURE_OR_OFFICIAL_EVIDENCE_WRITES');
    true_seed_assert(count($after['sale_financial_state'])===1&&count($before['sale_financial_state'])===1,'LOCAL_MATRIX_ONE_OFFICIAL_STATE');
    $projectionTimes=['projected_at'=>true,'updated_at'=>true];
    true_seed_assert(array_diff_key($after['sale_financial_state'][0],$projectionTimes)
        ===array_diff_key($before['sale_financial_state'][0],$projectionTimes)
        &&$after['sale_financial_state'][0]['official_status']==='complete'
        &&(float)$after['sale_financial_state'][0]['official_net_amount']===90.0,'LOCAL_MATRIX_OFFICIAL_INPUT_AND_VALUES_UNCHANGED');
    return $after;
}

function true_seed_business_snapshot(PDO $pdo,array $source):array
{
    $q=$pdo->prepare('SELECT id,resource_id,job_type,state,attempt_count,last_error_class,payload_json FROM queue_v4_clean_jobs WHERE company_id=9001 AND meli_account_id=9011 ORDER BY id');$q->execute();
    $snapshot=['queue'=>$q->fetchAll(PDO::FETCH_ASSOC)];
    $q=$pdo->prepare('SELECT o.external_order_id,o.status,o.total_amount,o.paid_amount,COUNT(i.id) AS item_count,COALESCE(SUM(i.quantity*i.unit_price),0) AS item_total FROM meli_orders o JOIN meli_accounts a ON a.id=o.meli_account_id LEFT JOIN meli_order_items i ON i.meli_order_id=o.id AND i.meli_account_id=o.meli_account_id WHERE a.company_id=9001 AND o.meli_account_id=9011 GROUP BY o.id ORDER BY o.external_order_id');$q->execute();
    $snapshot['orders']=$q->fetchAll(PDO::FETCH_ASSOC);
    $snapshot['checkpoints']=$pdo->query('SELECT producer_key,meli_account_id,watermark_at,next_due_at FROM queue_v4_clean_checkpoints WHERE company_id=9001 AND meli_account_id IN (9011,9012,9013) ORDER BY meli_account_id,producer_key')->fetchAll(PDO::FETCH_ASSOC);
    // This tenant is outside both the certified automatic account set and the
    // manual user's company access. Its local rows are a distinct scope sentinel.
    $snapshot['unauthorized_tenant']=[
        'checkpoints'=>$pdo->query('SELECT producer_key,company_id,meli_account_id,watermark_at,next_due_at,last_job_id FROM queue_v4_clean_checkpoints WHERE company_id=9002 AND meli_account_id=9021 ORDER BY producer_key')->fetchAll(PDO::FETCH_ASSOC),
        'orders'=>$pdo->query('SELECT o.* FROM meli_orders o JOIN meli_accounts a ON a.id=o.meli_account_id WHERE a.company_id=9002 AND o.meli_account_id=9021 ORDER BY o.id')->fetchAll(PDO::FETCH_ASSOC),
        'queue'=>$pdo->query('SELECT * FROM queue_v4_clean_jobs WHERE company_id=9002 AND meli_account_id=9021 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    ];
    if(isset($source['saleKey'])){
        $q=$pdo->prepare('SELECT status,attempts FROM sale_financial_reconciliation_jobs WHERE id=? AND company_id=9001 AND meli_account_id=9011');$q->execute([$source['source']]);$snapshot['financial_source']=$q->fetch(PDO::FETCH_ASSOC);
        $q=$pdo->prepare('SELECT evidence_status,evidence_json FROM sale_financial_evidence WHERE company_id=9001 AND meli_account_id=9011 AND sale_key=? AND evidence_type="billing_capture" ORDER BY id');$q->execute([$source['saleKey']]);
        $snapshot['billing_checkpoints']=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC)as $row){$payload=json_decode($row['evidence_json'],true,512,JSON_THROW_ON_ERROR);if(($payload['format']??'')==='billing_order_v2')$snapshot['billing_checkpoints'][]=['order_id'=>(string)$payload['order_id'],'status'=>$row['evidence_status'],'lines'=>$payload['lines']??[]];}
        $q=$pdo->prepare('SELECT COUNT(*) FROM meli_sale_financial_history h JOIN meli_sale_financials f ON f.id=h.meli_sale_financial_id WHERE f.company_id=9001 AND f.meli_account_id=9011 AND f.sale_key=? AND h.source="billing_official"');$q->execute([$source['saleKey']]);$snapshot['official_publications']=(int)$q->fetchColumn();
    }
    return $snapshot;
}

function true_seed_assert_business(PDO $pdo,array $source,array $profile,array $wire,array $before,string $outcome):array
{
    $after=true_seed_business_snapshot($pdo,$source);
    $blocked=in_array($outcome,['prewire','stale'],true);
    $success=$outcome==='200';
    $original=array_values(array_filter($after['queue'],static fn(array $r):bool=>in_array((int)$r['id'],$source['queue'],true)));
    true_seed_assert(count($original)===count($source['queue']),'SOURCE_ROWS_DURABLE');
    foreach($after['orders']as $order)true_seed_assert(in_array((string)$order['external_order_id'],$source['orders'],true)&&$order['status']==='paid'&&(float)$order['total_amount']===100.0&&(float)$order['paid_amount']===100.0&&(int)$order['item_count']===1&&(float)$order['item_total']===100.0,'ORDER_BUSINESS_VALUES_DURABLE',['order'=>$order]);
    if($profile['billing']){
        $expected=$success?count($wire):0;
        true_seed_assert(count($after['billing_checkpoints'])===$expected,'BILLING_CHECKPOINT_COUNT',['expected'=>$expected,'after'=>$after]);
        if($success){
            $ids=array_column($after['billing_checkpoints'],'order_id');
            true_seed_assert($ids===array_slice($source['orders'],0,$expected)&&count(array_unique($ids))===count($ids),'BILLING_CHECKPOINT_FIFO_NO_DUPLICATES');
            foreach($after['billing_checkpoints']as $checkpoint)true_seed_assert($checkpoint['status']==='reconciled'&&count($checkpoint['lines'])===1,'BILLING_OFFICIAL_LINE_DURABLE');
            $complete=$expected===count($source['orders']);
            true_seed_assert($after['official_publications']===($complete?1:0),'BILLING_NO_EARLY_OR_DUPLICATE_PUBLICATION');
            true_seed_assert($after['financial_source']['status']===($complete?'complete':'retry')&&$original[0]['state']===($complete?'completed':'waiting'),'BILLING_SOURCE_AND_POINTER_AGREE',['after'=>$after]);
            if(!$complete)true_seed_assert((int)$after['financial_source']['attempts']===(int)$before['financial_source']['attempts']&&(int)$original[0]['attempt_count']===(int)$before['queue'][0]['attempt_count'],'CHECKPOINT_PROGRESS_IS_NOT_FAILED_ATTEMPT');
        }else true_seed_assert($after['official_publications']===0,'FAILED_BILLING_NO_OFFICIAL_PUBLICATION');
    }else{
        $businessWire=array_values(array_filter($wire,static fn(array $e):bool=>$e['path']!=='/oauth/token'));
        if(($source['direct_type']??'')==='fresh_orders_discovery'){
            $expected=$success?min(count($source['orders']),count($businessWire)*$profile['page_size']):0;
            true_seed_assert(count($after['orders'])===$expected,'DISCOVERY_SNAPSHOTS_DURABLE',['after'=>$after,'expected'=>$expected]);
            $cp=static fn(array $s):array=>array_values(array_filter($s['checkpoints'],static fn(array $r):bool=>$r['meli_account_id']==9011&&$r['producer_key']==='fresh_orders'))[0];
            if($success&&$expected===count($source['orders']))true_seed_assert($cp($after)['watermark_at']==='2026-09-01 00:05:00.000','DISCOVERY_COMPLETED_WINDOW_CHECKPOINT');
            else{
                true_seed_assert($cp($before)===$cp($after),'DISCOVERY_INCOMPLETE_WINDOW_NOT_ADVANCED');
                if($success){$pending=array_values(array_filter($after['queue'],static fn(array $r):bool=>$r['job_type']==='fresh_orders_discovery'&&$r['state']==='ready'));true_seed_assert(count($pending)===1&&(int)json_decode($pending[0]['payload_json'],true)['offset']===$expected,'DISCOVERY_NEXT_PAGE_DURABLE');}
            }
        }else{
            $expectedIds=[];
            if($success||$profile['resource_local'])foreach($businessWire as $entry){$id=basename($entry['path']);if($success||$id!==$source['orders'][0])$expectedIds[]=$id;}
            sort($expectedIds);$actual=array_map('strval',array_column($after['orders'],'external_order_id'));sort($actual);
            true_seed_assert($actual===$expectedIds,'EXACT_ORDER_RESULTS_MATCH_SUCCESSFUL_WIRE',['expected'=>$expectedIds,'actual'=>$actual]);
            foreach($original as $row)if(in_array((string)$row['resource_id'],$expectedIds,true))true_seed_assert($row['state']==='completed','SUCCESSFUL_ORDER_QUEUE_COMPLETED',['row'=>$row]);
        }
    }
    if($blocked){
        true_seed_assert($after['orders']===$before['orders'],'PREWIRE_BUSINESS_UNCHANGED');
        foreach($original as $row)true_seed_assert((int)$row['attempt_count']===0&&$row['state']===($outcome==='stale'?'completed':'ready'),'PREWIRE_NO_ATTEMPT_OR_RECLAIM',['row'=>$row]);
    }elseif(!$success&&!$profile['resource_local']){
        $expectedState=in_array($outcome,['timeout','connect','partial'],true)?'review':'waiting';
        true_seed_assert($original[0]['state']===$expectedState,'FAILURE_HAS_EXPLICIT_DURABLE_DISPOSITION',['expected'=>$expectedState,'after'=>$original]);
    }
    $other=static fn(array $s):array=>array_values(array_filter($s['checkpoints'],static fn(array $r):bool=>$r['meli_account_id']!=9011));
    // Automatic producer housekeeping is authorized for all three certified
    // accounts; only the confirmed manual selection is restricted to 9011.
    if($profile['manual'])true_seed_assert($other($before)===$other($after),'MANUAL_UNSELECTED_ACCOUNT_CHECKPOINTS_UNCHANGED',['before'=>$other($before),'after'=>$other($after)]);
    true_seed_assert($before['unauthorized_tenant']===$after['unauthorized_tenant'],'UNCERTIFIED_UNAUTHORIZED_TENANT_UNCHANGED',['before'=>$before['unauthorized_tenant'],'after'=>$after['unauthorized_tenant']]);
    return $after;
}

function true_seed_scope(PDO $pdo,int $budget):void
{
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9001,'True final synthetic QA',1)");
    $pdo->exec("INSERT INTO users(id,name,email,password_hash,role,status,is_temporary) VALUES(9007,'Synthetic QA','qa@example.invalid','unused','admin',1,0)");
    $pdo->exec('INSERT INTO user_company_access(user_id,company_id) VALUES(9007,9001)');
    $pdo->exec("INSERT INTO queue_v4_clean_readiness_runs(id,state,expected_accounts,passed_accounts,started_by,finished_at) VALUES(1,'CERTIFIED',3,3,9007,UTC_TIMESTAMP(3))");
    foreach([9011,9012,9013] as $account){
        $pdo->prepare("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(?,9001,'Synthetic QA',?,'conectado')")->execute([$account,(string)(90000+$account)]);
        $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at) VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))')->execute([$account,Crypto::encrypt('synthetic-access'),Crypto::encrypt('synthetic-refresh')]);
        $pdo->prepare("INSERT INTO queue_v4_clean_readiness_accounts(readiness_run_id,company_id,meli_account_id,outcome) VALUES(1,9001,?,'PASS')")->execute([$account]);
        foreach(['fresh_orders','inventory_order_refresh']as $producer){
            $pdo->prepare("INSERT INTO queue_v4_clean_checkpoints(producer_key,company_id,meli_account_id,watermark_at,next_due_at) VALUES(?,9001,?,UTC_TIMESTAMP(3),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 DAY))")->execute([$producer,$account]);
        }
    }
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9002,'Outside certified and manual scope',1)");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9021,9002,'Uncertified scope sentinel','99021','desconectado')");
    foreach(['fresh_orders','inventory_order_refresh']as $producer)$pdo->prepare("INSERT INTO queue_v4_clean_checkpoints(producer_key,company_id,meli_account_id,watermark_at,next_due_at) VALUES(?,9002,9021,'2000-01-01','2000-01-01')")->execute([$producer]);
    $pdo->exec("INSERT INTO meli_orders(meli_account_id,external_order_id,status,total_amount,paid_amount,currency_id,synced_at) VALUES(9021,'990000001','paid',777,777,'COP','2000-01-01')");
    $pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE',readiness_state='CERTIFIED',scheduler_enabled=1,readiness_passed_accounts=3 WHERE control_key='primary'");
    $pdo->exec("UPDATE queue_engine_control SET active_engine='v4'");
    $settings=new AppSettingsService();
    foreach([
        'automation.max_api_calls_per_cycle'=>(string)$budget,'automation.api_calls_ceiling'=>'100',
        'manual.api_calls_per_step'=>(string)$budget,'manual.api_calls_ceiling'=>'100',
        'api.rhythm.pause_ms'=>'0','api.rhythm.burst_size'=>'100','api.rhythm.minimum_interval_ms'=>'0',
        'api.rhythm.current_adaptive_limit'=>'100','api.rhythm.billing_min_interval_seconds'=>'1',
        'api.rhythm.shared_429_jitter_seconds'=>'0','sales_financial.commercial_pipeline_enabled'=>'1',
        'alerts.email.enabled'=>'0',
    ]as $key=>$value)$settings->set($key,$value,'true-final-fixture');
    AppSettingsService::clearCache();
    Session::put('user',['id'=>9007,'role'=>'admin','session_generation'=>(new App\Services\SessionGenerationService())->rotate()]);
}

function true_seed_source(PDO $pdo,int $seed,bool $billing,int $count=1,string $directType='order_exact'):array
{
    $orders=[];$pack=(string)(770000+$seed);$queue=[];
    for($i=0;$i<$count;$i++)$orders[]=(string)(8000000+$seed*100+$i);
    if(!$billing&&$directType==='fresh_orders_discovery'){
        $payload=['from'=>'2026-09-01T00:00:00Z','to'=>'2026-09-01T00:05:00Z','offset'=>0];
        $pdo->prepare("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at) VALUES(9001,9011,'fresh_orders_discovery',NULL,?,?,'ready','2000-01-01')")->execute(['true-discovery-'.$seed,json_encode($payload)]);
        $queue[]=(int)$pdo->lastInsertId();
        return ['orders'=>$orders,'queue'=>$queue,'direct_type'=>$directType,'discovery_payload'=>$payload];
    }
    if($billing){
        if($count>1)$pdo->prepare("INSERT INTO meli_packs(meli_account_id,external_pack_id,status,integrity_status,expected_orders_count,linked_orders_count,expected_orders_json,synced_at,verified_at) VALUES(9011,?,'paid','complete',?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$pack,$count,$count,json_encode($orders)]);
        foreach($orders as $order){
            $pdo->prepare("INSERT INTO meli_orders(meli_account_id,external_order_id,external_pack_id,status,total_amount,paid_amount,currency_id,synced_at) VALUES(9011,?,?,'paid',100,100,'COP',UTC_TIMESTAMP())")->execute([$order,$count>1?$pack:null]);
            $local=(int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO meli_order_items(meli_order_id,meli_account_id,external_item_id,title,seller_sku,quantity,unit_price,sale_fee) VALUES(?,9011,?,'Synthetic item','QA',1,100,10)")->execute([$local,'MCO'.$order]);
        }
        $saleKey=($count>1?'P:':'O:').($count>1?$pack:$orders[0]);
        $state=(new SaleFinancialStateService())->projectSale(9001,9011,$saleKey);
        $pdo->prepare("INSERT INTO sale_financial_reconciliation_jobs(company_id,meli_account_id,sale_key,external_sale_id,input_version,status,next_run_at) VALUES(9001,9011,?,?,?,'pending','2000-01-01')")->execute([$saleKey,$count>1?$pack:$orders[0],$state['input_version']]);
        $source=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at) VALUES(9001,9011,'domain_exact',?,?,?,'ready','2000-01-01')")->execute([(string)$source,'true-'.$seed,json_encode(['capability'=>'financial_reconciliation','source_id'=>$source])]);
        $queue[]=(int)$pdo->lastInsertId();
        return compact('orders','queue','source','saleKey');
    }
    foreach($orders as $order){
        $pdo->prepare("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at) VALUES(9001,9011,'order_exact',?,?,'{}','ready','2000-01-01')")->execute([$order,'true-'.$order]);
        $queue[]=(int)$pdo->lastInsertId();
    }
    return compact('orders','queue');
}

function true_seed_order_body(string $order):array
{
    return ['id'=>$order,'date_created'=>'2026-09-01T00:00:00.000Z','date_closed'=>'2026-09-01T00:01:00.000Z','last_updated'=>'2026-09-01T00:02:00.000Z','status'=>'paid','status_detail'=>null,'total_amount'=>100,'paid_amount'=>100,'currency_id'=>'COP','buyer'=>['id'=>99100,'nickname'=>'synthetic'],'shipping'=>['id'=>null],'tags'=>[],
        'order_items'=>[['item'=>['id'=>'MCO'.$order,'title'=>'Synthetic item','seller_sku'=>'QA','listing_type_id'=>'gold_special'],'quantity'=>1,'unit_price'=>100,'full_unit_price'=>100,'sale_fee'=>10]],'payments'=>[]];
}

function true_seed_response(array $entry,array $source,string $outcome):array
{
    if(($source['oauth']??false)&&$entry['path']==='/oauth/token'){
        true_seed_assert($entry['method']==='POST'&&$entry['company_id']===9001&&$entry['account_id']===9011,'WIRE_OAUTH_EXACT_TENANT');
        return ['status'=>200,'body'=>['access_token'=>'synthetic-rotated-access','refresh_token'=>'synthetic-rotated-refresh','expires_in'=>21600,'token_type'=>'Bearer','user_id'=>99011]];
    }
    $transport=in_array($outcome,['timeout','connect','partial'],true)?['status'=>$outcome==='partial'?200:0,'raw'=>false,'error'=>$outcome==='timeout'?'Operation timed out':($outcome==='connect'?'Could not connect':'transfer closed with outstanding data'),'errno'=>$outcome==='timeout'?28:($outcome==='connect'?7:18),'wire_bytes'=>$outcome==='partial'?12:0]:null;
    $billing=$entry['path']==='/billing/integration/group/ML/order/details';
    if(($source['direct_type']??'')==='fresh_orders_discovery'){
        true_seed_assert($entry['method']==='GET'&&$entry['path']==='/orders/search'&&$entry['company_id']===9001&&$entry['account_id']===9011,'WIRE_EXACT_DISCOVERY_SCOPE',['entry'=>$entry]);
        true_seed_assert(($entry['safe_query']['seller']??'')==='99011'&&($entry['safe_query']['order_date_created_from']??'')===$source['discovery_payload']['from']&&($entry['safe_query']['order_date_created_to']??'')===$source['discovery_payload']['to'],'WIRE_EXACT_DISCOVERY_WINDOW');
        $offset=(int)($entry['safe_query']['offset']??0);$size=(int)($source['page_size']??count($source['orders']));
        true_seed_assert($offset>=0&&$offset<count($source['orders']),'WIRE_DISCOVERY_OFFSET_IN_RANGE');
        return $transport??['status'=>(int)$outcome,'body'=>$outcome==='200'?['results'=>array_map('true_seed_order_body',array_slice($source['orders'],$offset,$size)),'paging'=>['total'=>count($source['orders']),'offset'=>$offset,'limit'=>50]]:['message'=>'Synthetic failure','error'=>'fixture_error'],'headers'=>$outcome==='429'?['retry-after'=>'1']:[]];
    }
    $order=$billing?$entry['order_ids']:basename($entry['path']);
    true_seed_assert($entry['method']==='GET'&&$entry['company_id']===9001&&$entry['account_id']===9011,'WIRE_METHOD_AND_TENANT',['entry'=>$entry]);
    true_seed_assert($billing?isset($source['saleKey']):(!isset($source['saleKey'])&&$entry['path']==='/orders/'.$order),'WIRE_EXACT_DOMAIN_PATH',['entry'=>$entry]);
    true_seed_assert(is_string($order)&&in_array($order,$source['orders'],true),'WIRE_ONLY_EXPECTED_ORDER',['entry'=>$entry]);
    if($transport!==null)return $transport;
    if(($source['resource_local']??false)&&$order!==$source['orders'][0])$outcome='200';
    $status=(int)$outcome;
    if($status!==200)return ['status'=>$status,'body'=>['message'=>'Synthetic failure','error'=>'fixture_error'],'headers'=>$status===429?['retry-after'=>'1']:[]];
    return ['status'=>200,'body'=>$billing?[['order_id'=>$order,'detail_id'=>'fee-'.$order,'detail_type'=>'SALE_FEE','description'=>'Synthetic fee','amount'=>10,'date_created'=>'2026-09-08T00:00:00Z']]:true_seed_order_body($order)];
}

function true_seed_receipt(array $result,array $wire,int $requested):void
{
    $receipt=$result['http_budget']??$result;
    $effective=$receipt['limit']??$result['effective_api_calls']??$result['effective_max_calls']??$result['max_calls']??null;
    $certainty=$result['physical_http_calls_certainty']??$receipt['physical_http_calls_certainty']??null;
    $physical=$result['physical_http_calls']??$receipt['physical_http_calls']??null;
    $known=$result['known_physical_calls']??$receipt['known_physical_calls']??null;
    $charged=$result['charged_calls']??$result['api_calls_used']??$receipt['used']??null;
    true_seed_assert(App\Services\CallsTrueWire::$violations===[],'NO_SWALLOWED_FIXTURE_VIOLATIONS',['violations'=>App\Services\CallsTrueWire::$violations]);
    true_seed_assert(is_int($effective)&&$effective>0&&$effective<=$requested,'EFFECTIVE_BUDGET_EXPLICIT',['effective'=>$effective,'requested'=>$requested]);
    // These cases do not inject a journal failure: a simulated transport result must be certifiable.
    true_seed_assert($certainty==='CERTIFIED'&&$physical===count($wire)&&$known===count($wire),'CERTIFIED_RECEIPT_EQUALS_INDEPENDENT_WIRE',['physical'=>$physical,'known'=>$known,'wire'=>count($wire),'certainty'=>$certainty]);
    true_seed_assert(is_int($charged)&&count($wire)<=$charged&&$charged<=$effective,'COMMITTED_CAPACITY_WITHIN_EFFECTIVE_BUDGET',['charged'=>$charged,'wire'=>count($wire),'effective'=>$effective]);
}

// The template contains only the unmodified, migrated synthetic schema and default rows.
function true_seed_export_schema(PDO $pdo,string $path):void
{
    $queries=['SET FOREIGN_KEY_CHECKS=0'];
    foreach($pdo->query('SHOW FULL TABLES WHERE Table_type="BASE TABLE"')->fetchAll(PDO::FETCH_NUM)as $table){
        $name=$table[0];
        true_seed_assert(preg_match('/^[a-zA-Z0-9_]+$/D',$name)===1,'TEMPLATE_TABLE_NAME');
        $queries[]=$pdo->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_NUM)[1];
        $columns=[];foreach($pdo->query('SHOW COLUMNS FROM `'.$name.'`')->fetchAll(PDO::FETCH_ASSOC)as $column)if(!str_contains($column['Extra'],'GENERATED'))$columns[]=$column['Field'];
        $columnSql=implode(',',array_map(static fn(string $s):string=>'`'.$s.'`',$columns));
        foreach($pdo->query('SELECT '.$columnSql.' FROM `'.$name.'`')->fetchAll(PDO::FETCH_NUM)as $row)$queries[]='INSERT INTO `'.$name.'` ('.$columnSql.') VALUES ('.implode(',',array_map(static fn($v):string=>$v===null?'NULL':$pdo->quote((string)$v),$row)).')';
    }
    true_seed_assert((int)$pdo->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn()===0,'TEMPLATE_HAS_NO_UNCOPIED_TRIGGERS');
    $queries[]='SET FOREIGN_KEY_CHECKS=1';
    file_put_contents($path,json_encode($queries,JSON_THROW_ON_ERROR),LOCK_EX);
}
