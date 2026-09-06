<?php
declare(strict_types=1);
require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';

use App\QueueCore\ManualQueueLauncher;
use App\QueueCore\QueueCoreRepository;
use App\QueueCore\QueueExecutionLeaseService;
use App\QueueCore\QueueHandlerRegistry;
use App\QueueCore\QueueHandler;
use App\QueueCore\QueueClaim;
use App\QueueCore\QueueExecutionContext;
use App\QueueCore\QueueResult;
use App\QueueCore\QueueRunner;
use App\QueueCore\QueueJob;
use App\Services\CronDeadlineContext;
use App\Services\AppSettingsService;
use App\Services\CapacityPolicyService;
use App\Services\ManualCampaignPreviewService;
use App\Services\ManualSingleStepService;

k1b_assert((new ReflectionMethod(ManualQueueLauncher::class,'runExactBatch'))->getNumberOfParameters()>=3, 'Exact batch must accept preview capacity and original request deadline.');
putenv('APP_ENV=test');
putenv('ML_WRITE_ENABLED=false');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT='.(getenv('DB_PORT')?:'33079'));
putenv('DB_USER=root');
putenv('DB_NAME=erp_meli_k1d_test_manual_capacity_'.bin2hex(random_bytes(4)));
$harness=K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo=$harness->pdo();
    foreach ([280,281,282] as $migration) {
        $path=glob(__DIR__.'/../database/migrations/'.$migration.'_*.sql')[0];
        $pdo->exec(file_get_contents($path));
    }
    $pdo->exec('ALTER TABLE queue_core_attempts ADD run_id BIGINT UNSIGNED NULL');
    $pdo->exec('CREATE TABLE app_settings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,setting_key VARCHAR(190) UNIQUE,setting_value TEXT,is_encrypted TINYINT DEFAULT 0,setting_group VARCHAR(80) DEFAULT "general",updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec("INSERT INTO app_settings(setting_key,setting_value) VALUES ('manual.api_calls_per_step','100'),('manual.api_calls_ceiling','100')");
    $pdo->exec('CREATE TABLE meli_tokens(meli_account_id BIGINT PRIMARY KEY,refresh_version INT,expires_at DATETIME)');
    $pdo->exec("INSERT INTO meli_tokens VALUES (2,1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))");
    AppSettingsService::clearCache();
    $previewSchema=file_get_contents(__DIR__.'/../database/migrations/110_manual_campaign_preview_exact_notifications_2_20_6.sql');
    $pdo->exec(explode('SET @has_notification_manual_idx',$previewSchema)[0]);
    $normalize=new ReflectionMethod(ManualCampaignPreviewService::class,'normalize');
    $normalized=$normalize->invoke(new ManualCampaignPreviewService(),[]);
    k1b_assert($normalized['preview_format']===4 && !array_key_exists('block_size',$normalized) && $normalized['physical_api_call_budget']===100,'Production normalization persists only calls capacity, not a hidden resource cutoff.');
    k1b_assert($normalized['capacity_revision']===(new CapacityPolicyService())->snapshot('manual')['revision'],'Production preview stores authoritative revision.');
    $token=bin2hex(random_bytes(20));
    $pdo->prepare('INSERT INTO manual_campaign_previews (preview_token,created_by_user_id,scope_key,configuration_hash,configuration_json,summary_json,expires_at) VALUES (?,7,"available_queue",?,?,"{}",DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR))')->execute([$token,hash('sha256','preview'),json_encode($normalized)]);
    $pdo->exec("UPDATE app_settings SET setting_value='1' WHERE setting_key='manual.api_calls_per_step'");
    $rejected=false;
    try{(new ManualSingleStepService())->executePreview($token,7,100);}catch(RuntimeException $error){$rejected=str_contains($error->getMessage(),'capacidad manual');}
    k1b_assert($rejected,'Real executePreview rejects a stale over-budget preview before any business dispatch.');
    k1b_assert(!CronDeadlineContext::active(),'Rejected preview clears request deadline.');
    k1b_assert($pdo->query('SELECT status FROM manual_campaign_previews')->fetchColumn()==='ready','Rejected preview is not consumed.');
    $pdo->exec("UPDATE app_settings SET setting_value='100' WHERE setting_key='manual.api_calls_per_step'");
    $repository=new QueueCoreRepository($pdo);
    $leases=new QueueExecutionLeaseService($pdo);
    $handler=new class($pdo,$repository) implements QueueHandler {
        public int $calls=0;
        public string $mode='ok';
        public array $deadlines=[];
        public function __construct(private PDO $pdo,private QueueCoreRepository $repository) {}
        public function handle(QueueClaim $job,QueueExecutionContext $context): QueueResult {
            $this->deadlines[]=$context->deadline;
            if (!is_array($job->payload['remote_contract'] ?? null)) return QueueResult::completed(1,0);
            if ($this->mode==='preblocked') return QueueResult::automaticWait('policy_deferred',gmdate('Y-m-d H:i:s',time()+60));
            // Fake HTTP uses the same canonical physical marker as real transport.
            k1b_assert($this->repository->reserveTransport($job,$context->attemptId), 'Reserve exact transport.');
            k1b_assert($this->repository->physicalTransportStarted($job,$context->attemptId,'GET','/orders/123'), 'Record fake physical transport.');
            $this->calls++;
            if ($this->mode==='reduce') $this->pdo->exec("UPDATE app_settings SET setting_value='1' WHERE setting_key='manual.api_calls_per_step'");
            $status=match($this->mode){'429'=>429,'401'=>401,default=>200};
            k1b_assert($this->repository->responseKnown($job,$context->attemptId,$status), 'Record known fake response.');
            if ($this->mode==='expire') usleep(1000000);
            return match($status){429=>QueueResult::retry('remote_rate_limit',null,429),401=>QueueResult::review('oauth_unauthorized',401),default=>QueueResult::completed(1,1)};
        }
    };
    $registry=new QueueHandlerRegistry();
    $registry->register('manual_exact',$handler);
    $core=['repository'=>$repository,'execution_leases'=>$leases,'runner'=>new QueueRunner($repository,$registry,$leases)];
    $launcher=new ManualQueueLauncher(static fn()=>$core);
    $items=static function(int $count): array {
        $nonce=bin2hex(random_bytes(6));$items=[];
        for($i=0;$i<$count;$i++)$items[]=['company_id'=>1,'account_id'=>2,'queue_key'=>'orders','source_id'=>$nonce.'-'.$i,'uses_api'=>true,'operation_key'=>'order_exact','input_version'=>'v1','source_authority_version'=>'v1','explicit_attempt_key'=>hash('sha256',$nonce.'-'.$i),'remote_contract'=>['method'=>'GET','endpoint_pattern'=>'~^/orders/[0-9]+$~','operation_key'=>'order_exact','max_remote_calls'=>1]];
        return $items;
    };
    foreach([1,2,3,15,55,100] as $budget){
        $handler->calls=0;
        $result=$launcher->runExactBatch($items($budget+1),$budget,microtime(true)+45);
        k1b_assert($handler->calls===$budget && $result['remote_dispatches']===$budget,'Exact physical capacity '.$budget.' '.json_encode($result));
        k1b_assert($result['not_processed_count']===1 && $result['stop_reason']==='physical_call_budget','Unstarted selection is reported pending.');
    }
    $same=$items(1);$launcher->runExactBatch($same,1,microtime(true)+45);
    $handler->calls=0;$result=$launcher->runExactBatch($same,1,microtime(true)+45);
    k1b_assert($handler->calls===0 && $result['remote_dispatches']===0,'Idempotent replay does not count historic HTTP as new transport.');
    $localItems=$items(30);
    foreach($localItems as &$localItem){$localItem['uses_api']=false;$localItem['remote_contract']=null;$localItem['operation_key']='local_financial';}
    unset($localItem);
    $handler->calls=0;
    $result=$launcher->runExactBatch($localItems,1,microtime(true)+45);
    k1b_assert(count($result['results'])===30 && $result['remote_dispatches']===0 && $handler->calls===0,'One physical call of capacity does not truncate 30 selected local resources.');
    foreach(['429','401','preblocked'] as $mode){
        $handler->mode=$mode;$handler->calls=0;
        $result=$launcher->runExactBatch($items(3),3,microtime(true)+45);
        k1b_assert(count($result['results'])===1 && $result['not_processed_count']===2,'Stops remaining selection on '.$mode);
        k1b_assert($handler->calls===($mode==='preblocked'?0:1),'Actual physical count for '.$mode);
    }
    $handler->mode='reduce';$handler->calls=0;
    $result=$launcher->runExactBatch($items(3),3,microtime(true)+45);
    k1b_assert($handler->calls===1 && $result['not_processed_count']===2,'Saved reduction during a batch stops before the next exact item.');
    $pdo->exec("UPDATE app_settings SET setting_value='100' WHERE setting_key='manual.api_calls_per_step'");
    $otherLease=$leases->acquire('cron_v4','other-qa-launcher',60);
    k1b_assert($otherLease!==null,'Fixture owns global lease.');
    $excluded=false;$handler->calls=0;
    try{$launcher->runExactBatch($items(1),1,microtime(true)+45);}catch(RuntimeException){$excluded=true;}
    finally{$leases->release($otherLease);}
    k1b_assert($excluded && $handler->calls===0,'Automatic/manual global exclusion sends zero extra HTTP.');
    $unrelated=$repository->enqueue(new QueueJob(9,99,'financial_projection','order','999','local',0,'unrelated-fixture','v1','test',null,[],[],1,null,'operational'));
    $handler->mode='ok';$handler->calls=0;
    $launcher->runExactBatch($items(1),1,microtime(true)+45);
    k1b_assert($handler->calls===1 && $repository->job($unrelated)['state']==='pending','Exact manual selection never drains an unrelated tenant/FIFO job.');
    $handler->mode='ok';$handler->calls=0;
    $expiredRejected=false;$admitted=0;
    try {$launcher->runExactBatch($items(2),2,microtime(true)-1,static function() use(&$admitted): void {$admitted++;});}
    catch(RuntimeException) {$expiredRejected=true;}
    k1b_assert($expiredRejected && $handler->calls===0 && $admitted===0,'Prework exhausted deadline is rejected before admission and cannot be restarted.');
    $handler->mode='expire';$handler->calls=0;$handler->deadlines=[];$deadline=microtime(true)+0.8;
    $result=$launcher->runExactBatch($items(3),3,$deadline);
    k1b_assert($handler->calls<=1 && $result['not_processed_count']>=2,'Outer request deadline is never restarted per item.');
    k1b_assert(max($handler->deadlines)<=$deadline,'Each runner deadline is within the original request deadline.');
    k1b_assert((int)$pdo->query("SELECT COUNT(*) FROM queue_core_jobs WHERE queue_domain='manual' AND state IN ('pending','running','claimed','retry_wait','waiting_oauth')")->fetchColumn()===0,'No background continuation.');
    k1b_assert(!CronDeadlineContext::active(),'Request deadline context cleared.');
    echo "STATUS=PASS CAPACITY_MANUAL_RUNTIME REAL_HTTP=0 REAL_EMAIL=0\n";
} finally {$harness->cleanup();}
