<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';

// Serial process runner; seed state, PHP caches and DBs never cross scenarios.
$options=getopt('',['seeds:','template:']);
$root=rtrim((string)(getenv('CALLS_TRUE_QA_ROOT')?:getenv('CALLS_VERIFY_RUN_OUTPUT')?:'D:/Codex/tmp/erp-meli/calls-20260906/true-final-seeds'),'/\\');
if(!is_dir($root))mkdir($root,0770,true);
$seeds=isset($options['seeds'])?array_map('intval',explode(',',$options['seeds'])):range(1,100);
k1b_assert(count($seeds)===count(array_unique($seeds))&&min($seeds)>=1&&max($seeds)<=100,'UNIQUE_SEED_SELECTION');
$run=static function(string $name,array $args)use($root):array{
    $out=$root.'/'.$name.'.out.log';$err=$root.'/'.$name.'.err.log';
    $cmd=array_merge([PHP_BINARY,__DIR__.'/calls_true_seed_case.php'],$args);
    $p=proc_open($cmd,[0=>['pipe','r'],1=>['file',$out,'w'],2=>['file',$err,'w']],$pipes,dirname(__DIR__));
    k1b_assert(is_resource($p),'SEED_PROCESS_START');fclose($pipes[0]);
    $start=microtime(true);$exit=null;
    do{$state=proc_get_status($p);if(!$state['running']){$exit=$state['exitcode'];break;}if(microtime(true)-$start>600){proc_terminate($p);$exit=124;break;}usleep(50000);}while(true);
    proc_close($p);
    $receipt=['command'=>$cmd,'exit'=>$exit,'seconds'=>microtime(true)-$start,'stdout_sha256'=>hash_file('sha256',$out),'stderr_sha256'=>hash_file('sha256',$err)];
    file_put_contents($root.'/'.$name.'.receipt.json',json_encode($receipt,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    if($exit!==0)throw new RuntimeException('SEED_CHILD_FAILED:'.$name.':'.trim(file_get_contents($err)));
    preg_match('/^SEED_RESULT=(.+)\r?$/m',file_get_contents($out),$m);
    k1b_assert(isset($m[1])&&is_file(trim($m[1])),'SEED_RESULT_PRESENT');
    return json_decode(file_get_contents(trim($m[1])),true,512,JSON_THROW_ON_ERROR);
};
$template=$options['template']??$root.'/private-schema-template.json';
if(!isset($options['template']))$run('schema',['--export-template='.$template]);
$results=[];
foreach($seeds as $seed){
    $row=$run('seed-'.$seed,['--seed='.$seed,'--template='.$template,'--retry-process']);
    k1b_assert($row['state']==='PASS','INTEGRATED_SEED_PASS');
    $results[]=$row;
    file_put_contents($root.'/integrated-results.json',json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    echo 'INTEGRATED_SEED='.$seed.' WIRE='.count($row['wire_entries'])."\n";flush();
}
if(count($seeds)!==100){echo 'FOCUSED_SEEDS='.count($seeds)." FINAL_CERTIFICATION=NO\n";exit(0);}
$wire=array_sum(array_map(static fn(array $r):int=>count($r['wire_entries']),$results));
$auto=count(array_filter($results,static fn(array $r):bool=>$r['owner']==='automatic'));
$manual=count($results)-$auto;
$billing=count(array_filter($results,static fn(array $r):bool=>$r['family']==='billing'));
k1b_assert($wire>0&&$auto===50&&$manual===50&&$billing===50,'INTEGRATED_COVERAGE_DISTRIBUTION');
// Additional continuation/retry requirements are material: never substitute a total-case banner.
$retry=count(array_filter($results,static fn(array $r):bool=>($r['retry_verified']??false)===true));
k1b_assert($retry>=10,'INTEGRATED_RETRY_COVERAGE_REQUIRED');
$coverage=['automatic'=>$auto,'manual'=>$manual,'billing'=>$billing,'other'=>100-$billing,'real_due_retry'=>$retry];
foreach(['pack_continuation_verified'=>'pack_continuations','oauth_verified'=>'oauth','resource_local_http_error_verified'=>'resource_local_http_error','business_verified'=>'business_assertions']as $key=>$label)$coverage[$label]=count(array_filter($results,static fn(array $r):bool=>($r[$key]??false)===true));
foreach(['prewire','remote_429','discovery','multiple_calls_one_execution','multiple_resources','local_resolution']as $key)$coverage[$key]=count(array_filter($results,static fn(array $r):bool=>($r['coverage'][$key]??false)===true));
$coverage['budgets']=array_values(array_unique(array_column($results,'budget')));sort($coverage['budgets']);
$coverage['fresh_process_retry']=count(array_filter($results,static fn(array $r):bool=>isset($r['retry_child_pid'])&&($r['retry_verified']??false)));
$coverage['unmet_categories']=$coverage['local_resolution']===0?['local_resolution_without_http']:[];
file_put_contents($root.'/integrated-coverage.json',json_encode($coverage,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
k1b_assert($coverage['pack_continuations']>=10&&$coverage['prewire']>=10&&$coverage['remote_429']>=10&&$coverage['fresh_process_retry']>=10,'INTEGRATED_REQUIRED_TEN_CASE_CATEGORIES');
k1b_assert($coverage['business_assertions']===100&&$coverage['oauth']>=1&&$coverage['discovery']>=1&&$coverage['multiple_calls_one_execution']>=1&&$coverage['multiple_resources']>=1,'INTEGRATED_SUPPORTED_ENTRYPOINT_BUSINESS_COVERAGE');
k1b_assert($coverage['budgets']===[1,2,3,5,9,15,50,55,100],'INTEGRATED_ALL_REQUIRED_BUDGETS');
echo "INTEGRATED_SEEDS=100\nAUTOMATIC_SEEDS={$auto}\nMANUAL_SEEDS={$manual}\nBILLING_SEEDS={$billing}\nTOTAL_WIRE_CALLS={$wire}\nINVARIANT_FAILURES=0\n";
echo 'COVERAGE='.json_encode($coverage,JSON_THROW_ON_ERROR)."\n";
echo 'FINAL_CERTIFICATION='.($coverage['unmet_categories']===[]?'YES':'NO_UNMET_CATEGORIES')."\n";
if ($coverage['unmet_categories'] !== []) { exit(2); }
