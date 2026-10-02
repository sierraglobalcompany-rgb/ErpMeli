<?php
declare(strict_types=1);
require __DIR__.'/k3_safe_pack_reentry_fixture.inc.php';
use App\QueueV4Clean\QueueV4CleanRepository;

if(($argv[1]??'')==='participant'){
    $path=$argv[2];file_put_contents($path.'.ready',json_encode(['connection'=>(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn()]));
    $n=(new QueueV4CleanRepository($pdo))->releaseDueRetryableDirectWaiting([7201]);
    file_put_contents($path.'.result',(string)$n);exit(0);
}
$root=getenv('CALLS_QA_STORAGE_ROOT')?:sys_get_temp_dir();
$results=[];
foreach(['competing','stale_evidence','stale_source','stale_attempt','late_journal'] as $case){
    $f=sprFixture($pdo);$before=sprRow($pdo,'queue_v4_clean_jobs',$f['job_id']);
    $source=sprRow($pdo,'order_resource_enrichment_jobs',$f['source_id']);
    $pdo->beginTransaction();$pdo->query('SELECT id FROM queue_v4_clean_jobs WHERE id='.$f['job_id'].' FOR UPDATE')->fetchColumn();
    $children=[];$ids=[];$deadline=microtime(true)+20;
    for($i=0;$i<2;$i++){
        $path=$root.'/safe-reentry-'.bin2hex(random_bytes(6));
        $p=proc_open([PHP_BINARY,'-d','disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client',__FILE__,'participant',$path],[0=>['pipe','r'],1=>['file',$path.'.stdout','w'],2=>['file',$path.'.stderr','w']],$pipes,dirname(__DIR__));
        r0h3_assert(is_resource($p),'participant_started');fclose($pipes[0]);$children[]=[$p,$path];
    }
    foreach($children as [$p,$path]){
        while(!is_file($path.'.ready')&&microtime(true)<$deadline)usleep(20000);
        r0h3_assert(is_file($path.'.ready'),'participant_ready');$ids[]=json_decode(file_get_contents($path.'.ready'),true)['connection'];
    }
    $observed=[];
    do{
        $observed=$pdo->query('SELECT ID,STATE,INFO FROM information_schema.PROCESSLIST WHERE ID IN ('.implode(',',$ids).')')->fetchAll(PDO::FETCH_ASSOC);
        $waiting=$pdo->query('SELECT t.trx_mysql_thread_id connection_id,w.requesting_trx_id,w.blocking_trx_id FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX t ON t.trx_id=w.requesting_trx_id WHERE t.trx_mysql_thread_id IN ('.implode(',',$ids).')')->fetchAll(PDO::FETCH_ASSOC);
        if(count($waiting)===2)break;usleep(20000);
    }while(microtime(true)<$deadline);
    r0h3_assert(count($waiting)===2,'real_competing_row_lock_observed',['processlist'=>$observed]);
    if($case==='stale_evidence')$pdo->exec("UPDATE queue_v4_clean_jobs SET last_error_class='domain_source_waiting:order_enrichment_pack:waiting_budget' WHERE id=".$f['job_id']);
    if($case==='stale_source')$pdo->exec("UPDATE order_resource_enrichment_jobs SET status='retry',failure_class='waiting_budget',reached_remote=0 WHERE id=".$f['source_id']);
    if($case==='stale_attempt')$pdo->exec('UPDATE queue_v4_clean_attempts SET physical_http_calls=1 WHERE id='.$f['attempt_id']);
    if($case==='late_journal')sprEvent($pdo,$f);
    $source=sprRow($pdo,'order_resource_enrichment_jobs',$f['source_id']);
    $pdo->commit();$counts=[];
    foreach($children as [$p,$path]){
        $code=proc_close($p);r0h3_assert($code===0&&is_file($path.'.result'),'participant_finished',['stderr'=>file_get_contents($path.'.stderr')]);$counts[]=(int)file_get_contents($path.'.result');
    }
    $after=sprRow($pdo,'queue_v4_clean_jobs',$f['job_id']);
    r0h3_assert(array_sum($counts)===($case==='competing'?1:0),'cas_transition_count',['case'=>$case,'counts'=>$counts]);
    r0h3_assert($before['lease_generation']===$after['lease_generation']&&$before['attempt_count']===$after['attempt_count'],'generation_attempt_count_unchanged');
    r0h3_assert($source===sprRow($pdo,'order_resource_enrichment_jobs',$f['source_id']),'source_unchanged');
    r0h3_assert((int)$pdo->query('SELECT COUNT(*) FROM queue_v4_clean_jobs WHERE id='.$f['job_id'])->fetchColumn()===1,'no_duplicate_pointer');
    $results[$case]=['counts'=>$counts,'transitions'=>array_sum($counts),'competing_connection_ids'=>$ids,'observed_waits'=>$waiting,'generation_unchanged'=>true,'attempt_count_unchanged'=>true];
}
echo json_encode(['CONCURRENCY'=>'PASS','cases'=>$results],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
