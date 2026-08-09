<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);require $root.'/bootstrap.php';
use App\QueueCore\QueueCoreRepository;
use App\QueueCore\QueueJob;
use App\QueueCore\QueueRunRequest;
$mode=(string)($argv[1]??'');$key=(string)($argv[2]??'worker');$startMs=(int)($argv[3]??0);$remaining=$startMs-(int)floor(microtime(true)*1000);if($remaining>0)usleep($remaining*1000);
$pdo=new PDO((string)getenv('QUEUE_CORE_TEST_DSN'),(string)getenv('QUEUE_CORE_TEST_USER'),(string)getenv('QUEUE_CORE_TEST_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$repository=new QueueCoreRepository($pdo);
if($mode==='enqueue'){$id=$repository->enqueue(new QueueJob(1,1,'test_work','test_resource','parallel','fresh_orders',0,'parallel-producer','v1','test',null,['key'=>'parallel'],[],5));echo json_encode(['id'=>$id]);exit;}
if($mode==='claim'){$claim=$repository->claimNext(new QueueRunRequest('test',$key,1,microtime(true)+15),['test_work']);echo json_encode(['id'=>$claim?->id]);exit;}
exit(2);
