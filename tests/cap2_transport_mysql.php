<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
require __DIR__.'/K1dSafeTestDatabase.php';
require __DIR__.'/cap2_transport_wire_fixture.php';
require __DIR__.'/cap2_transport_metric_fixture.php';
use App\Services\ApiExecutionMetadataContext as Meta;
use App\Services\Cap2TransportClock as Clock;
use App\Services\Cap2TransportOptions as Options;
use App\Services\Cap2DomainsWire as Wire;
use App\Services\CronDeadlineContext as Deadline;
use App\Services\CurlMeliHttpTransport as Transport;
use App\QueueV4Clean\QueueV4CleanCycleBudget as Budget;
use App\QueueV4Clean\QueueV4CleanRepository as Repo;
use App\QueueV4Clean\QueueV4CleanWorker as Worker;

foreach(['APP_ENV'=>'test','ML_WRITE_ENABLED'=>'false','DB_HOST'=>'127.0.0.1','DB_PORT'=>'33079','DB_USER'=>'root','DB_PASS'=>'','DB_NAME'=>'erp_meli_k1d_test_cap2_transport_'.bin2hex(random_bytes(4)),
    'APP_KEY'=>'cap2-disposable-test-only-not-a-real-secret','PRIVATE_STORAGE_PATH'=>'D:/Codex/tmp/erp-meli/cap2-20260905/qa/transport-private','MELI_API_BASE'=>'https://cap2-wire.invalid'] as $k=>$v) putenv($k.'='.$v);
if (!defined('ERP_INSTALLATION_ROOT')) define('ERP_INSTALLATION_ROOT','D:/Codex/tmp/erp-meli/cap2-20260905/qa/transport-install-'.bin2hex(random_bytes(4)));
if (!is_dir(ERP_INSTALLATION_ROOT)) mkdir(ERP_INSTALLATION_ROOT,0777,true);
$h=K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo=$h->pdo();
    (new App\Services\Migrator($pdo,__DIR__.'/../database/migrations'))->run(301);
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9001,'CAP2 transport',1)");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9011,9001,'CAP2 transport',99011,'conectado')");
    $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at) VALUES(9011,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))')->execute([App\Core\Crypto::encrypt('test-access'),App\Core\Crypto::encrypt('test-refresh')]);
    $pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE',readiness_state='CERTIFIED' WHERE control_key='primary'");
    $pdo->exec("UPDATE queue_engine_control SET active_engine='v4'");
    $repo=new Repo($pdo);
    // One counter across real physical fences, without restarting between stages.
    Clock::$now=1000.0; Deadline::start(45,43,20,3); Budget::start(3);
    $sharedBefore=count(Wire::$calls);
    foreach (['queue','oauth','sales_audit','sales_repair'] as $position=>$source) {
        [$meta,$method,$path]=cap2_transport_context($pdo,$source);
        Wire::$responses[$path]=[200,['id'=>8101]];
        $blocked=null;
        try { Meta::run($meta,static fn()=>(new Transport())->request($method,'https://cap2-wire.invalid'.$path,[],[],false,['timeout'=>20,'connect_timeout'=>3])); }
        catch(Throwable $error) { $blocked=$error; }
        k1b_assert($position<3 ? $blocked===null : $blocked instanceof App\Services\ApiBudgetExhaustedException,'shared_stage_admission_'.$source.':'.($blocked?->getMessage()??'none'));
        k1b_assert(count(Wire::$calls)===$sharedBefore+min($position+1,3) && Budget::snapshot()['used']===min($position+1,3),'shared_stage_exact_total_'.$source);
    }
    Budget::clear(); Deadline::clear();
    // Slow real SQL fence is simulated by advancing only the clock after its durable INSERT.
    foreach (['queue','oauth','sales_audit','sales_repair'] as $source) {
        foreach (['before','after','short','sent','uncertain','cancel_failure'] as $case) {
            [$meta,$method,$path]=cap2_transport_context($pdo,$source);
            Clock::$now=1000.0; Deadline::start(45,43,20,3); Budget::start(3); Options::$values=[];
            Wire::$responses[$path]=[200,['id'=>8101]];
            $before=count(Wire::$calls);
            $query=$pdo->prepare('SELECT COUNT(*) FROM queue_v4_clean_transport_events WHERE request_id=?');
            $changed=false;
            Clock::$tick=static function () use($query,$meta,$case,$pdo,&$changed): void {
                if($changed) return;
                $query->execute([$meta['transport_request_id']]);
                if((int)$query->fetchColumn()>0) {
                    $changed=true;
                    if(in_array($case,['after','cancel_failure'],true)) Clock::$now=1044.0;
                    if($case==='short') Clock::$now=1038.0;
                    if($case==='cancel_failure') $pdo->prepare("UPDATE queue_v4_clean_transport_events SET dispatch_state='RESPONSE_KNOWN',http_status=200 WHERE request_id=?")->execute([$meta['transport_request_id']]);
                }
            };
            if($case==='before') { Clock::$now=1044.0; Clock::$tick=null; }
            if($case==='uncertain') Wire::$onWire=static function():void {throw new RuntimeException('simulated_sent_without_response');};
            $error=null;
            try { Meta::run($meta,static fn()=>(new Transport())->request($method,'https://cap2-wire.invalid'.$path,[],[],false,['timeout'=>20,'connect_timeout'=>3])); }
            catch(Throwable $caught) {$error=$caught;}
            Clock::$tick=null; Wire::$onWire=null;
            $query->execute([$meta['transport_request_id']]);$events=(int)$query->fetchColumn();
            if(in_array($case,['before','after'],true)) {
                k1b_assert($error instanceof App\Services\CronDeadlineDeferredException,$source.'_'.$case.'_deadline_blocked:'.($error?->getMessage()??'no_error'));
                k1b_assert(count(Wire::$calls)===$before && $events===0 && Budget::snapshot()['used']===0,$source.'_'.$case.'_certified_zero');
            } elseif($case==='cancel_failure') {
                k1b_assert($error!==null && count(Wire::$calls)===$before && $events===1 && Budget::snapshot()['used']===1,$source.'_failed_compensation_retains_uncertainty');
            } else {
                k1b_assert(count(Wire::$calls)===$before+1 && $events===1 && Budget::snapshot()['used']===1,$source.'_'.$case.'_consumes_one');
                if($case==='short') k1b_assert(Options::$values[CURLOPT_TIMEOUT]<=4,$source.'_slow_fence_recalculates_timeout');
                if($case==='sent') {
                    Clock::$now=1046.0;
                    k1b_assert(isset(Options::$values[CURLOPT_XFERINFOFUNCTION]) && (Options::$values[CURLOPT_XFERINFOFUNCTION])()===1,$source.'_progress_aborts_at_deadline');
                }
            }
            Deadline::clear();Budget::clear();
            echo 'PASS='.$source.'_'.$case."\n";
        }
    }
    // Core keeps its real repository/heartbeat and must never cancel after curl_exec entry.
    foreach(['after','short','sent','tenant_mismatch'] as $case) {
        $core=new App\QueueCore\QueueCoreRepository($pdo);
        $job=$core->enqueue(new App\QueueCore\QueueJob(9001,9011,'manual_exact','remote','8101','normal',0,'core-'.$case,'1','test',null,[],[],1));
        $pdo->exec("UPDATE queue_core_jobs SET state='running',lease_owner='cap2',lease_generation=1,lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE) WHERE id={$job} AND company_id=9001 AND meli_account_id=9011");
        $pdo->exec("INSERT INTO queue_core_attempts(job_id,company_id,meli_account_id,lease_owner,lease_generation,launcher) VALUES({$job},9001,9011,'cap2',1,'manual')");
        $attempt=(int)$pdo->lastInsertId();
        $meta=['source'=>'queue_core','company_id'=>9001,'account_id'=>9011,'transport_request_id'=>bin2hex(random_bytes(20)),'transport_operation_key'=>'order_exact',
            'queue_core_launcher'=>'manual','queue_core_capability_launcher'=>'manual','queue_core_domain'=>'manual','queue_core_uses_api'=>1,'queue_core_max_remote_calls'=>1,
            'queue_core_expected_method'=>'GET','queue_core_expected_endpoint_pattern'=>'#^/orders/8101$#D','queue_core_expected_operation'=>'order_exact',
            'queue_core_job_id'=>$job,'queue_core_attempt_id'=>$attempt,'queue_core_lease_owner'=>'cap2','queue_core_lease_generation'=>1,'queue_core_work_type'=>'manual_exact'];
        Clock::$now=1000.0; Deadline::start(45,43,20,3);Budget::start(1,'manual');$changed=false;
        Clock::$tick=static function()use($pdo,$attempt,$case,&$changed):void {
            if(!$changed && (int)$pdo->query("SELECT physical_http_calls FROM queue_core_attempts WHERE id={$attempt}")->fetchColumn()===1) {
                $changed=true;if(in_array($case,['after','tenant_mismatch'],true))Clock::$now=1044.0;if($case==='short')Clock::$now=1038.0;
                if($case==='tenant_mismatch')$pdo->exec("UPDATE queue_core_dispatch_journal SET company_id=9002 WHERE attempt_id={$attempt}");
            }
        };
        Wire::$responses['/orders/8101']=[200,['id'=>8101]];$before=count(Wire::$calls);$error=null;
        try {Meta::run($meta,static fn()=>(new Transport())->request('GET','https://cap2-wire.invalid/orders/8101',[],[],false,['timeout'=>20,'connect_timeout'=>3]));}catch(Throwable $caught){$error=$caught;}
        Clock::$tick=null;
        if($case==='after') k1b_assert($error instanceof App\Services\CronDeadlineDeferredException && count(Wire::$calls)===$before && (int)$pdo->query("SELECT physical_http_calls FROM queue_core_attempts WHERE id={$attempt}")->fetchColumn()===0,'core_after_certified_zero:'.($error?->getMessage()??'no_error'));
        elseif($case==='tenant_mismatch') {
            k1b_assert($error instanceof App\Services\RemoteResultUncertainException && count(Wire::$calls)===$before && (int)$pdo->query("SELECT physical_http_calls FROM queue_core_attempts WHERE id={$attempt}")->fetchColumn()===1,'core_mismatched_journal_not_certified_or_refunded');
        } else {
            k1b_assert($error===null && count(Wire::$calls)===$before+1,'core_fenced_one_http:'.($error?->getMessage()??'no_error'));
            if($case==='short') k1b_assert(Options::$values[CURLOPT_TIMEOUT]<=4,'core_slow_fence_recalculates_timeout');
            k1b_assert(Meta::run($meta,static fn()=>App\QueueCore\QueueCoreDispatchFence::cancelBeforeCurl())===false,'core_sent_marker_never_refunded');
        }
        Budget::clear();Deadline::clear();echo 'PASS=core_'.$case."\n";
    }
    // Two NOT SENT requests for the same repair item each compensate their own marker exactly once.
    [$repair]=cap2_transport_context($pdo,'sales_repair');Budget::start(1);
    foreach([1,2] as $ordinal) {
        $repair['transport_request_id']=bin2hex(random_bytes(20));
        Meta::run($repair,static function():void {
            App\QueueV4Clean\QueueV4CleanDispatchFence::immediatelyBeforeCurl('GET','/orders/8101');
            k1b_assert(App\QueueV4Clean\QueueV4CleanTransportJournal::cancelBeforeCurl(App\Core\Database::connectionFresh(),Meta::current()),'same_repair_new_request_compensates');
            k1b_assert(!App\QueueV4Clean\QueueV4CleanTransportJournal::cancelBeforeCurl(App\Core\Database::connectionFresh(),Meta::current()),'compensation_is_exactly_once');
        });
    }
    Budget::clear();
    // A proven NOT SENT physical cancellation is insufficient when rhythm compensation fails.
    Clock::$now=\microtime(true);$start=Clock::$now;
    [$compensate]=cap2_transport_context($pdo,'queue');$compensateJob=$compensate['queue_v4_job_id'];
    $changed=false;Deadline::start(45,43,20,3);Budget::start(3);
    Clock::$tick=static function()use($pdo,$compensateJob,$start,&$changed):void {
        if(!$changed && (int)$pdo->query("SELECT COUNT(*) FROM queue_v4_clean_transport_events WHERE source_kind='queue' AND work_id={$compensateJob}")->fetchColumn()>0) {
            $changed=true;$pdo->exec("UPDATE api_rhythm_states SET generation=generation+1 WHERE scope_key='global'");Clock::$now=$start+44.0;
        }
    };
    $error=null;
    try {Meta::run($compensate,static fn()=>(new App\Services\MeliApiClient(9011))->get('/orders/8101'));}catch(Throwable $caught){$error=$caught;}
    finally {Clock::$tick=null;Deadline::clear();Budget::clear();}
    $compensationFailedCorrectly=$error instanceof App\Services\RemoteResultUncertainException;
    if(!$compensationFailedCorrectly)echo 'EXPECTED_RED=failed_rhythm_compensation_must_stop_uncertain:'.($error?->getMessage()??'no_error')."\n";
    $pdo->exec("UPDATE api_remote_permits SET expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE company_id=9001 AND meli_account_id=9011 AND status='dispatched'");
    usleep(1100000);
    // Unknown authority is not a certified zero and may not refund its dispatched permit.
    Clock::$now=\microtime(true);
    [$unknown]=cap2_transport_context($pdo,'queue');$unknown['queue_v4_lease_owner']='stale';
    Budget::start(3);$error=null;
    try {Meta::run($unknown,static fn()=>(new App\Services\MeliApiClient(9011))->get('/orders/8101'));}catch(Throwable $caught){$error=$caught;}
    finally {Budget::clear();}
    k1b_assert($error instanceof App\Services\RemoteResultUncertainException,'unknown_authority_is_uncertain_not_refunded:'.($error?->getMessage()??'no_error'));
    k1b_assert((int)$pdo->query("SELECT COUNT(*) FROM api_remote_permits WHERE company_id=9001 AND meli_account_id=9011 AND status='dispatched'")->fetchColumn()===1,'unknown_authority_keeps_dispatched_permit');
    $pdo->exec("UPDATE api_remote_permits SET expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE company_id=9001 AND meli_account_id=9011 AND status='dispatched'");
    usleep(1100000);
    // Real worker -> MeliApiClient -> real fence -> fake wire exceptions may not be retried automatically.
    Clock::$now=\microtime(true);
    [$clientContext]=cap2_transport_context($pdo,'queue');
    $fault=new Cap2TransportInsertPdo('mysql:host=127.0.0.1;port=33079;dbname='.getenv('DB_NAME').';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $fault->setAttribute(PDO::ATTR_STATEMENT_CLASS,[Cap2TransportInsertStatement::class]);$fault->rollbackBeforeFailure=true;
    $fault->exec("SET time_zone='+00:00'");App\Core\Database::setConnection($fault);Budget::start(3);$error=null;$before=count(Wire::$calls);
    try {Meta::run($clientContext,static fn()=>(new App\Services\MeliApiClient(9011))->get('/orders/8101'));}catch(Throwable $caught){$error=$caught;}
    finally {App\Core\Database::setConnection($pdo);}
    $uncertainClientProtected=$error instanceof App\Services\RemoteResultUncertainException
        && Budget::snapshot()['used']===1
        && Budget::snapshot()['physical_http_calls']===null
        && Budget::snapshot()['physical_http_calls_certainty']==='UNKNOWN'
        && Budget::snapshot()['known_physical_calls']===0
        && (int)$pdo->query("SELECT COUNT(*) FROM api_remote_permits WHERE company_id=9001 AND meli_account_id=9011 AND status='dispatched'")->fetchColumn()===1;
    Budget::clear();k1b_assert(count(Wire::$calls)===$before,'uncertain_client_test_never_enters_wire');
    $pdo->exec("UPDATE api_remote_permits SET expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE company_id=9001 AND meli_account_id=9011 AND status='dispatched'");
    usleep(1100000);
    Clock::$now=\microtime(true);
    foreach(['uncertain','429'] as $case) {
        $pdo->prepare("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json) VALUES(9001,9011,'order_exact','8199',?,'{}')")->execute(['worker-'.$case]);
        $job=(int)$pdo->lastInsertId();
        Wire::$responses['/orders/8199']=[$case==='429'?429:200,['message'=>'test','error'=>'too_many_requests']];
        Wire::$onWire=$case==='uncertain'?static function():void {throw new RuntimeException('simulated_sent_without_response');}:null;
        Budget::start(3);
        try {$result=(new Worker($pdo,$repo))->run(launcher:'test',maxCalls:3,runtimeSeconds:30,authorizedAccountIds:[9011],accountId:9011);}
        finally {Budget::clear();Wire::$onWire=null;}
        if($case==='uncertain') {
            k1b_assert($pdo->query("SELECT state FROM queue_v4_clean_jobs WHERE id={$job}")->fetchColumn()==='review','sent_without_response_requires_review_not_auto_retry:'.json_encode($result));
            k1b_assert($result['stop_reason']==='remote_result_uncertain','uncertain_stops_remaining_work');
        } else {
            k1b_assert($result['stop_reason']==='remote_429_global_pause','real_rhythm_429_preserves_stop_reason:'.json_encode($result));
            k1b_assert((int)$pdo->query("SELECT http_status FROM queue_v4_clean_attempts WHERE job_id={$job} ORDER BY id DESC LIMIT 1")->fetchColumn()===429,'real_rhythm_429_persisted');
        }
        // Model a later window; do not refund or delete the uncertain permit/evidence.
        $pdo->exec("UPDATE api_remote_permits SET expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE company_id=9001 AND meli_account_id=9011 AND status='dispatched'");
        usleep(1100000);Clock::$now=\microtime(true);
    }
    $newFailures=$compensationFailedCorrectly?[]:['failed_rhythm_compensation_must_stop_uncertain'];
    if(!$uncertainClientProtected)$newFailures[]='client_cannot_downgrade_transport_uncertainty_from_later_not_dispatched_read';
    foreach(['queue','oauth','sales_audit','sales_repair'] as $kind) {
        foreach([false,true] as $uncertifiable) {
            [$context,$method,$path]=cap2_transport_context($pdo,$kind);
            $fault=new Cap2TransportCommitPdo('mysql:host=127.0.0.1;port=33079;dbname='.getenv('DB_NAME').';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
            $fault->exec("SET time_zone='+00:00'");$fault->uncertifiable=$uncertifiable;
            App\Core\Database::setConnection($fault);Budget::start(1);$error=null;$before=count(Wire::$calls);
            try {Meta::run($context,static fn()=>(new Transport())->request($method,'https://cap2-wire.invalid'.$path,[],[],false,['timeout'=>20,'connect_timeout'=>3]));}catch(Throwable $caught){$error=$caught;}
            finally {App\Core\Database::setConnection($pdo);}
            $q=$pdo->prepare('SELECT COUNT(*) FROM queue_v4_clean_transport_events WHERE request_id=?');$q->execute([$context['transport_request_id']]);
            $events=(int)$q->fetchColumn();$used=Budget::snapshot()['used'];Budget::clear();
            k1b_assert(count(Wire::$calls)===$before,'commit_failure_never_enters_wire');
            if($uncertifiable) {
                if(!($error instanceof App\Services\RemoteResultUncertainException && $events===1 && $used===1))$newFailures[]=$kind.'_commit_uncertainty_never_refunded';
            } elseif(!($error!==null && $events===0 && $used===0))$newFailures[]=$kind.'_lost_commit_ack_can_compensate_own_unsent_marker';
        }
    }
    // Scheduler recovery is a real second attempt path: review must remain terminal until operator choice.
    foreach(['queue','oauth','sales_audit','sales_repair'] as $kind) {
        [$context,$method,$path]=cap2_transport_context($pdo,$kind);
        $fault=new Cap2TransportInsertPdo('mysql:host=127.0.0.1;port=33079;dbname='.getenv('DB_NAME').';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
        $fault->setAttribute(PDO::ATTR_STATEMENT_CLASS,[Cap2TransportInsertStatement::class]);
        $fault->exec("SET time_zone='+00:00'");
        App\Core\Database::setConnection($fault);Budget::start(1);$error=null;$before=count(Wire::$calls);
        try {Meta::run($context,static fn()=>(new Transport())->request($method,'https://cap2-wire.invalid'.$path,[],[],false,['timeout'=>20,'connect_timeout'=>3]));}catch(Throwable $caught){$error=$caught;}
        finally {App\Core\Database::setConnection($pdo);}
        $q=$fault->prepare('SELECT COUNT(*) FROM queue_v4_clean_transport_events WHERE request_id=?');$q->execute([$context['transport_request_id']]);
        $events=(int)$q->fetchColumn();$used=Budget::snapshot()['used'];Budget::clear();
        k1b_assert(count(Wire::$calls)===$before && $events===1,'insert_failure_exercised_real_journal_without_wire');
        if(!($error instanceof App\Services\RemoteResultUncertainException && $used===1))$newFailures[]=$kind.'_unknown_insert_and_rollback_never_refunded';
        $fault->failRollback=false;$fault->rollBack();
    }
    $recovered=(new App\QueueV4Clean\QueueV4CleanUncertainReadRecoveryService($pdo))->recoverOne();
    if($recovered['recovered']!==0)$newFailures[]='uncertain_review_is_not_automatically_recovered';
    $requeued=$repo->enqueue(9001,9011,'order_exact','8199','worker-uncertain',[]);
    if($pdo->query("SELECT state FROM queue_v4_clean_jobs WHERE id={$requeued}")->fetchColumn()!=='review')$newFailures[]='duplicate_enqueue_cannot_reset_uncertain_review';
    [$notSent]=cap2_transport_context($pdo,'queue');$notSentJob=$notSent['queue_v4_job_id'];
    $uncertainJob=(int)$pdo->query("SELECT j.id FROM queue_v4_clean_jobs j INNER JOIN queue_v4_clean_attempts a ON a.job_id=j.id AND a.company_id=j.company_id AND a.meli_account_id=j.meli_account_id WHERE j.company_id=9001 AND j.meli_account_id=9011 AND j.state='running' AND a.dispatch_state='PHYSICAL_STARTED' LIMIT 1")->fetchColumn();
    k1b_assert($uncertainJob>0,'expiry_fixture_uses_real_prior_physical_fence');
    $pdo->exec("UPDATE queue_v4_clean_jobs SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE company_id=9001 AND meli_account_id=9011 AND id IN ({$notSentJob},{$uncertainJob})");
    $repo->expireLeases([9011],9011);
    if($pdo->query("SELECT state FROM queue_v4_clean_jobs WHERE id={$uncertainJob}")->fetchColumn()!=='review')$newFailures[]='expired_physical_attempt_requires_review';
    k1b_assert($pdo->query("SELECT state FROM queue_v4_clean_jobs WHERE id={$notSentJob}")->fetchColumn()==='waiting','expired_not_sent_can_defer');
    // Historical recovery/wakeup may have already changed row state; the journal still wins.
    $pdo->exec("UPDATE queue_v4_clean_jobs SET available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE state='ready'");
    $historical=[];
    foreach(['uncertain','known','not_sent'] as $case) {
        [$context,$method,$path]=cap2_transport_context($pdo,'queue');$id=$context['queue_v4_job_id'];$historical[$case]=$id;
        if($case!=='not_sent') {
            Budget::start(1);Wire::$responses[$path]=[200,['id'=>8101]];
            Wire::$onWire=$case==='uncertain'?static function():void {throw new RuntimeException('historical_sent_without_response');}:null;
            try {Meta::run($context,static fn()=>(new Transport())->request($method,'https://cap2-wire.invalid'.$path,[],[],false,['timeout'=>20,'connect_timeout'=>3]));}catch(Throwable $caught){if($case!=='uncertain')throw $caught;}
            finally {Budget::clear();Wire::$onWire=null;}
        }
        $pdo->prepare("UPDATE queue_v4_clean_jobs SET state='ready',available_at='2000-01-01',lease_owner=NULL,lease_expires_at=NULL,last_error_class=? WHERE id=?")->execute([$case==='uncertain'?'remote_result_uncertain_safe_get_recovered':null,$id]);
    }
    $previewIds=array_column($repo->previewEligible(10,[9011],9011),'queue_job_id');
    if(in_array($historical['uncertain'],$previewIds))$newFailures[]='historical_uncertain_not_preview_eligible';
    if($repo->eligibleCount([9011],9011)!==2)$newFailures[]='historical_uncertain_not_counted_eligible';
    $run=$repo->beginRun('test','cap2-history');$claimed=[];
    for($i=0;$i<2;$i++) {$row=$repo->claim($run,'cap2-history',60,[9011],9011);if($row!==null)$claimed[]=(int)$row['id'];}
    if($claimed!==[$historical['known'],$historical['not_sent']])$newFailures[]='historical_uncertain_not_claimed_known200_and_not_sent_still_claimed';
    $repo->finishRun($run,'completed');
    foreach($historical as $case=>$id) {
        $pdo->prepare("INSERT INTO sale_financial_reconciliation_jobs(company_id,meli_account_id,sale_key,external_sale_id,input_version,status,next_run_at) VALUES(9001,9011,?,?,?,'retry','2000-01-01')")->execute(['O:'.(900000+$id),(string)(900000+$id),str_repeat('a',64)]);
        $source=(int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE queue_v4_clean_jobs SET state='waiting',job_type='domain_exact',resource_id=?,payload_json=?,lease_owner=NULL,lease_expires_at=NULL,available_at='2000-01-01',last_error_class=? WHERE id=?")->execute([(string)$source,json_encode(['capability'=>'financial_reconciliation','source_id'=>$source]),$case==='uncertain'?'remote_result_uncertain_safe_get':null,$id]);
    }
    if($repo->releaseDueWaiting([9011],9011)!==2)$newFailures[]='financial_wakeup_excludes_uncertain_preserves_known_and_not_sent';
    $q=$pdo->prepare('SELECT state,last_error_class FROM queue_v4_clean_jobs WHERE id=?');$q->execute([$historical['uncertain']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if($row['state']!=='waiting'||$row['last_error_class']!=='remote_result_uncertain_safe_get')$newFailures[]='financial_wakeup_preserves_uncertain_diagnostics';
    $worker=new Worker($pdo,$repo);
    [$metricMeta]=cap2_transport_context($pdo,'queue');
    $attempt=$metricMeta['queue_v4_attempt_id'];$job=$metricMeta['queue_v4_job_id'];
    $pdo->exec("UPDATE queue_v4_clean_attempts SET run_id=9999 WHERE id={$attempt}");
    foreach([['queue',$job],['sales_repair',$job],['queue',$job+100]] as [$kind,$work]) {
        $pdo->prepare("INSERT INTO queue_v4_clean_transport_events(company_id,meli_account_id,source_kind,work_id,attempt_id,lease_generation,request_id,method,endpoint_key,physical_started_at) VALUES(9001,9011,?,?,?,1,?,'GET','order_exact',UTC_TIMESTAMP(3))")->execute([$kind,$work,$attempt,'metric-'.bin2hex(random_bytes(8))]);
    }
    $metricReceipt=$worker->receiptForRun(9999);
    k1b_assert($metricReceipt['physical_http_calls']===null
        && $metricReceipt['physical_http_calls_certainty']==='UNKNOWN'
        && $metricReceipt['known_physical_calls']===0
        && $metricReceipt['unresolved_dispatches']===1
        && $metricReceipt['possible_physical_calls_max']===1,
        'metric_counts_only_queue_and_matching_work_id_without_exact_pre_curl_count');
    $fault=new Cap2TransportMetricPdo('mysql:host=127.0.0.1;port=33079;dbname='.getenv('DB_NAME').';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $faultReceipt=(new Worker($fault,new Repo($fault)))->receiptForRun(9997);
    if($faultReceipt['physical_http_calls']!==null
        || $faultReceipt['physical_http_calls_certainty']!=='UNKNOWN'
        || $faultReceipt['possible_physical_calls_max']!==null)$newFailures[]='metric_probe_failure_cannot_fall_back_to_zero';
    $pdo->exec('RENAME TABLE queue_v4_clean_attempts TO cap2_hidden_attempts');
    try {
        $hiddenReceipt=$worker->receiptForRun(9999);
        k1b_assert($hiddenReceipt['physical_http_calls']===null
            && $hiddenReceipt['physical_http_calls_certainty']==='UNKNOWN'
            && $hiddenReceipt['possible_physical_calls_max']===null,
            'metric_read_failure_is_not_certified_zero');
    } finally {$pdo->exec('RENAME TABLE cap2_hidden_attempts TO queue_v4_clean_attempts');}
    foreach($newFailures as $failure)echo 'EXPECTED_RED='.$failure."\n";
    k1b_assert($newFailures===[],'transport_recovery_metric_authority_regressions');
    echo "CAP2_TRANSPORT_MYSQL_OK\n";
} finally { Clock::$tick=null;Deadline::clear();Budget::clear();$h->cleanup(); }

function cap2_transport_context(PDO $pdo,string $source):array
{
    $request=bin2hex(random_bytes(20));
    $meta=['company_id'=>9001,'account_id'=>9011,'transport_request_id'=>$request];
    if($source==='queue') {
        $pdo->prepare("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,attempt_count,lease_owner,lease_generation,lease_expires_at) VALUES(9001,9011,'order_exact','8101',?,'{}','running',1,'cap2',1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))")->execute([$request]);
        $job=(int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO queue_v4_clean_attempts(job_id,run_id,company_id,meli_account_id,lease_owner,lease_generation) VALUES(?,9998,9001,9011,'cap2',1)")->execute([$job]);
        $meta+=['source'=>'queue_v4_clean','queue_v4_job_id'=>$job,'queue_v4_attempt_id'=>(int)$pdo->lastInsertId(),'queue_v4_lease_owner'=>'cap2','queue_v4_lease_generation'=>1];
        return [$meta,'GET','/orders/8101'];
    }
    if($source==='oauth') {
        $pdo->exec("DELETE FROM oauth_refresh_operations WHERE company_id=9001 AND meli_account_id=9011");
        $pdo->exec("INSERT INTO queue_v4_clean_leases(lease_key,owner_ref,expires_at) VALUES('scheduler','cap2',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)) ON DUPLICATE KEY UPDATE owner_ref='cap2',expires_at=VALUES(expires_at)");
        $pdo->exec("UPDATE meli_tokens SET refresh_version=1 WHERE meli_account_id=9011");
        $pdo->exec("INSERT INTO oauth_refresh_operations(company_id,meli_account_id,expected_meli_user_id,expected_refresh_version,state,lease_owner,lease_generation,lease_expires_at) VALUES(9001,9011,'99011',1,'RUNNING','cap2',1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))");
        $meta+=['source'=>'queue_v4_clean_oauth','oauth_operation_id'=>(int)$pdo->lastInsertId(),'expected_refresh_version'=>1,'oauth_lease_owner'=>'cap2','oauth_lease_generation'=>1,'scheduler_lease_owner'=>'cap2'];
        return [$meta,'POST','/oauth/token'];
    }
    if($source==='sales_audit') {
        $pdo->exec("INSERT INTO sync_sales_audit_runs(company_id,meli_account_id,period_year,period_month,timezone_used,normalizer_version,local_from,local_to,utc_from,utc_to) VALUES(9001,9011,2026,9,'UTC','test','2026-09-01','2026-09-30','2026-09-01','2026-09-30')");
        $run=(int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO sync_sales_audit_jobs(sync_sales_audit_run_id,company_id,meli_account_id,status,locked_by,lease_generation,lock_expires_at) VALUES({$run},9001,9011,'running','cap2',1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))");
        $meta+=['source'=>'queue_v4_clean_sales_audit','sales_audit_job_id'=>(int)$pdo->lastInsertId(),'sales_audit_lease_owner'=>'cap2','sales_audit_lease_generation'=>1];
        return [$meta,'GET','/orders/search'];
    }
    $pdo->exec("INSERT INTO sync_sales_repair_jobs(company_id,meli_account_id,period_year,period_month,status,source_kind,lock_owner,lease_generation,lock_expires_at) VALUES(9001,9011,2026,9,'running','exact','cap2',1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))");
    $job=(int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO sync_sales_repair_job_items(sync_sales_repair_job_id,external_order_id,status) VALUES({$job},'8101','running')");
    $meta+=['source'=>'queue_v4_clean_sales_repair','sales_repair_job_id'=>$job,'sales_repair_item_id'=>(int)$pdo->lastInsertId(),'sales_repair_lease_owner'=>'cap2','sales_repair_lease_generation'=>1];
    return [$meta,'GET','/orders/8101'];
}
