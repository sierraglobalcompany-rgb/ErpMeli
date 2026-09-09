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
    $billing=$entry['path']==='/billing/integration/group/ML/order/details';
    if(($source['direct_type']??'')==='fresh_orders_discovery'){
        true_seed_assert($entry['method']==='GET'&&$entry['path']==='/orders/search'&&$entry['company_id']===9001&&$entry['account_id']===9011,'WIRE_EXACT_DISCOVERY_SCOPE',['entry'=>$entry]);
        true_seed_assert(($entry['safe_query']['seller']??'')==='99011'&&($entry['safe_query']['order_date_created_from']??'')===$source['discovery_payload']['from']&&($entry['safe_query']['order_date_created_to']??'')===$source['discovery_payload']['to'],'WIRE_EXACT_DISCOVERY_WINDOW');
        return ['status'=>(int)$outcome,'body'=>$outcome==='200'?['results'=>[],'paging'=>['total'=>0,'offset'=>0,'limit'=>50]]:['message'=>'Synthetic failure','error'=>'fixture_error']];
    }
    $order=$billing?$entry['order_ids']:basename($entry['path']);
    true_seed_assert($entry['method']==='GET'&&$entry['company_id']===9001&&$entry['account_id']===9011,'WIRE_METHOD_AND_TENANT',['entry'=>$entry]);
    true_seed_assert($billing?isset($source['saleKey']):(!isset($source['saleKey'])&&$entry['path']==='/orders/'.$order),'WIRE_EXACT_DOMAIN_PATH',['entry'=>$entry]);
    true_seed_assert(is_string($order)&&in_array($order,$source['orders'],true),'WIRE_ONLY_EXPECTED_ORDER',['entry'=>$entry]);
    if(in_array($outcome,['timeout','connect','partial'],true))return ['status'=>$outcome==='partial'?200:0,'raw'=>false,'error'=>$outcome==='timeout'?'Operation timed out':($outcome==='connect'?'Could not connect':'transfer closed with outstanding data'),'errno'=>$outcome==='timeout'?28:($outcome==='connect'?7:18),'wire_bytes'=>$outcome==='partial'?12:0];
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
