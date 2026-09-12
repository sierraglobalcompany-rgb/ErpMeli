<?php
declare(strict_types=1);
require __DIR__.'/K1dSafeTestDatabase.php';
$options=getopt('',['context:']);$file=realpath((string)($options['context']??''));
if($file===false||!(str_starts_with(str_replace('\\','/',$file),'D:/Codex/') || str_starts_with(str_replace('\\','/',$file),'C:/codex/capacity-save-kiss/')))throw new RuntimeException('MPC1_OWNED_CONTEXT_REQUIRED');
$context=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$dir=dirname($file);
K1dSafeTestDatabase::assertGuard('test','false','127.0.0.1',$context['db_name']);
$pdo=new PDO('mysql:host=127.0.0.1;port=33079;dbname='.$context['db_name'].';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$result=['pid'=>getmypid(),'parent_wait_observed'=>false,'case'=>$context['case']];
try{
    $pdo->beginTransaction();$q=$pdo->prepare('SELECT id FROM queue_v4_clean_jobs WHERE id=? AND company_id=9001 AND meli_account_id=9011 FOR UPDATE');$q->execute([$context['queue_id']]);
    if($q->fetchColumn()===false)throw new RuntimeException('MPC1_OWNED_QUEUE_MISSING');
    file_put_contents($dir.'/lock-ready.json',json_encode(['pid'=>getmypid()],JSON_THROW_ON_ERROR));
    $query=$pdo->prepare('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID=? AND DB=DATABASE()');
    $start=microtime(true);
    do{
        $query->execute([$context['parent_connection_id']]);$sql=(string)$query->fetchColumn();
        if(str_contains($sql,'SELECT q.* FROM queue_v4_clean_jobs q WHERE')&&str_contains($sql,'FOR UPDATE')){$result['parent_wait_observed']=true;break;}
        if(microtime(true)-$start>15)throw new RuntimeException('MPC1_PARENT_DID_NOT_REACH_LOCK');
        usleep(50000);
    }while(true);
    if($context['case']==='lock_input_change'){
        $pdo->exec('UPDATE meli_order_items SET unit_price=101 WHERE meli_account_id=9011');
    }elseif($context['case']==='lock_deadline'){
        $pdo->query('SELECT SLEEP(45)')->fetchColumn();
    }else{throw new RuntimeException('MPC1_UNKNOWN_LOCK_CASE');}
    $pdo->commit();$result['state']='PASS';
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$result['state']='FAIL';$result['error']=$e->getMessage();fwrite(STDERR,$e->getMessage()."\n");}
file_put_contents($dir.'/lock-result.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
exit($result['state']==='PASS'?0:1);
