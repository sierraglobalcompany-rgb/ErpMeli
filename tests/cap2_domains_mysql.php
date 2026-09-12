<?php
declare(strict_types=1);
require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';
require __DIR__ . '/cap2_domains_wire_fixture.php';

use App\Core\Crypto;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\AppSettingsService;
use App\Services\Cap2DomainsWire;
use App\Services\Migrator;
use App\Services\NotificationWorkItemService;

putenv('APP_ENV=test');
putenv('ML_WRITE_ENABLED=false');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=33079');
putenv('DB_USER=root');
putenv('DB_PASS=');
putenv('DB_NAME=erp_meli_k1d_test_cap2_domains_' . bin2hex(random_bytes(4)));
putenv('APP_KEY=cap2-disposable-test-only-not-a-real-secret');
$fixtureRoot = rtrim(str_replace('\\', '/', (string) (getenv('CALLS_VERIFY_QA_ROOT') ?: 'D:/Codex/tmp/erp-meli/calls-20260906')), '/') . '/domains-' . bin2hex(random_bytes(8));
if (!(str_starts_with($fixtureRoot, 'D:/Codex/') || str_starts_with($fixtureRoot, 'C:/codex/capacity-save-kiss/')) || in_array('..', explode('/', $fixtureRoot), true)) throw new RuntimeException('EXPLICIT_LOCAL_QA_ROOT_REQUIRED');
define('ERP_INSTALLATION_ROOT', $fixtureRoot . '/install');
foreach ([ERP_INSTALLATION_ROOT, $fixtureRoot . '/private'] as $directory) {
    if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('cap2_domains_fixture_directory_unavailable');
    }
}
putenv('PRIVATE_STORAGE_PATH=' . $fixtureRoot . '/private');
putenv('MELI_API_BASE=https://cap2-wire.invalid');

$harness = K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo = $harness->pdo();
    (new Migrator($pdo, __DIR__ . '/../database/migrations'))->run(301);
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(9001,'CAP2 fixture',1)");
    $pdo->exec("INSERT INTO meli_accounts(id,company_id,account_name,meli_user_id,status) VALUES(9011,9001,'CAP2 account',99011,'conectado')");
    $pdo->prepare('INSERT INTO meli_tokens(meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at) VALUES(9011,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))')
        ->execute([Crypto::encrypt('test-access'), Crypto::encrypt('test-refresh')]);
    $pdo->exec("UPDATE queue_v4_clean_control SET engine_state='ACTIVE',readiness_state='CERTIFIED' WHERE control_key='primary'");
    $pdo->exec("UPDATE queue_engine_control SET active_engine='v4'");
    $settings = new AppSettingsService();
    $settings->set('notifications.debounce_seconds', '0', 'notifications');
    $settings->set('items.hybrid_notification_updates_enabled', '0', 'items');
    $settings->set('api.rhythm.burst_size', '100', 'api');
    AppSettingsService::clearCache();

    // Break caught: worker used legacy source so the real ownership guard blocks the one exact GET.
    $work = cap2_domains_notification($pdo, 'question', '8103', '/questions/8103');
    $job = cap2_domains_pointer($pdo, 'notification_work_item', $work);
    Cap2DomainsWire::$responses['/questions/8103'] = [200, ['id'=>8103,'item_id'=>'MCO8105','text'=>'Question','status'=>'UNANSWERED','date_created'=>'2026-09-05T01:00:00Z','from'=>['id'=>500],'seller_id'=>99011,'answer'=>null]];
    $result = cap2_domains_run($pdo, 1);
    k1b_assert(count(Cap2DomainsWire::$calls) === 1, 'question_exact_reaches_one_real_fenced_wire:' . json_encode($result));
    k1b_assert($result['completed'] === 1, 'question_exact_completed');
    k1b_assert((int)$pdo->query("SELECT COUNT(*) FROM queue_v4_clean_transport_events WHERE source_kind='queue' AND work_id={$job} AND endpoint_key='question_exact' AND dispatch_state='RESPONSE_KNOWN'")->fetchColumn() === 1, 'question_exact_journal');

    $order = ['id'=>8101,'status'=>'paid','status_detail'=>null,'date_created'=>'2026-09-05T01:00:00Z','date_closed'=>'2026-09-05T01:01:00Z','last_updated'=>'2026-09-05T01:02:00Z',
        'total_amount'=>10,'paid_amount'=>10,'currency_id'=>'COP','buyer'=>['id'=>500,'nickname'=>'QA'],'seller'=>['id'=>99011],
        'shipping'=>['id'=>8102],'pack_id'=>null,'tags'=>[],'order_items'=>[],'payments'=>[]];
    cap2_domains_case($pdo, 'order', '8101', '/orders/8101', $order, 'order_exact');
    $orderId = (int)$pdo->query("SELECT id FROM meli_orders WHERE meli_account_id=9011 AND external_order_id='8101'")->fetchColumn();
    $pdo->prepare("INSERT INTO meli_shipments(meli_account_id,external_shipment_id,meli_order_id,status,synced_at) VALUES(9011,'8102',?,'pending',UTC_TIMESTAMP())")->execute([$orderId]);
    cap2_domains_case($pdo, 'shipment', '8102', '/shipments/8102', ['id'=>8102,'order_id'=>8101,'status'=>'shipped','substatus'=>null,'logistic_type'=>'drop_off','mode'=>'me2','shipping_option'=>['cost'=>0,'list_cost'=>0],'tracking_number'=>'QA','tracking_method'=>'QA'], 'shipment_exact');
    // Automatic exact shipment reads do not need a prior local order and never fetch that order.
    cap2_domains_case($pdo, 'shipment', '8112', '/shipments/8112', ['id'=>8112,'order_id'=>999991,'status'=>'shipped','shipping_option'=>['cost'=>0,'list_cost'=>0]], 'shipment_exact');
    k1b_assert($pdo->query("SELECT meli_order_id FROM meli_shipments WHERE meli_account_id=9011 AND external_shipment_id='8112'")->fetchColumn()===null,'unknown_shipment_order_stays_unlinked_without_secondary_get');
    cap2_domains_case($pdo, 'claim', '8104', '/post-purchase/v1/claims/8104', ['id'=>8104,'resource'=>'/orders/8101','order_id'=>8101,'type'=>'mediations','stage'=>'claim','status'=>'opened','reason_id'=>'PDD','date_created'=>'2026-09-05T01:00:00Z','last_updated'=>'2026-09-05T01:01:00Z','players'=>[]], 'claim_exact');
    $item = ['id'=>'MCO8105','title'=>'QA item','price'=>10,'base_price'=>10,'original_price'=>null,'currency_id'=>'COP','category_id'=>'MCO1','condition'=>'new','available_quantity'=>1,'sold_quantity'=>0,'status'=>'active','listing_type_id'=>'gold_special','pictures'=>[],'variations'=>[],'attributes'=>[]];
    cap2_domains_case($pdo, 'item', 'MCO8105', '/items/MCO8105', $item, 'item_exact');

    $packSource = (new App\Services\OrderEnrichmentService())->enqueue(9011,$orderId,'pack','8100');
    $packJob = cap2_domains_pointer($pdo,'order_enrichment_pack',$packSource);
    Cap2DomainsWire::$responses['/packs/8100'] = [200,['id'=>8100,'status'=>'released','orders'=>[['id'=>8101]],'shipment'=>null]];
    cap2_domains_assert_run($pdo,$packJob,'/packs/8100','pack_exact');
    // Cached pack and existing item review must traverse their real local branch with zero dispatches.
    $pdo->exec("UPDATE order_resource_enrichment_jobs SET status='pending' WHERE id={$packSource}");
    $pdo->exec("UPDATE queue_v4_clean_jobs SET state='ready',available_at=UTC_TIMESTAMP(3) WHERE id={$packJob}");
    $before = count(Cap2DomainsWire::$calls);
    $cached = cap2_domains_run($pdo,1);
    k1b_assert($cached['completed']===1 && $cached['physical_http_calls']===0 && $cached['cycle_used']===0 && count(Cap2DomainsWire::$calls)===$before,'cached_pack_zero_physical');
    $itemWork = cap2_domains_notification($pdo,'item','MCO8105','/items/MCO8105');
    $itemJob = cap2_domains_pointer($pdo,'notification_work_item',$itemWork);
    $pdo->exec("UPDATE queue_v4_clean_jobs SET state='ready',available_at=UTC_TIMESTAMP(3) WHERE id={$itemJob}");
    $cached = cap2_domains_run($pdo,1);
    k1b_assert($cached['completed']===1 && $cached['physical_http_calls']===0 && $cached['cycle_used']===0 && count(Cap2DomainsWire::$calls)===$before,'cached_item_review_zero_physical');

    // The default hybrid branch must persist locally without a second remote read.
    $pdo->exec("DELETE FROM app_settings WHERE setting_key='items.hybrid_notification_updates_enabled'");
    AppSettingsService::clearCache();
    k1b_assert((new AppSettingsService())->bool('items.hybrid_notification_updates_enabled', true), 'hybrid_default_enabled');
    $hybridItem = array_replace($item, ['id'=>'MCO8190', 'title'=>'QA hybrid item']);
    cap2_domains_case($pdo, 'item', 'MCO8190', '/items/MCO8190', $hybridItem, 'item_exact');
    $persisted = $pdo->query("SELECT title FROM meli_items WHERE meli_account_id=9011 AND external_item_id='MCO8190'")->fetchColumn();
    k1b_assert($persisted === 'QA hybrid item', 'hybrid_default_persists_exact_snapshot_locally');
    k1b_assert((int)$pdo->query("SELECT COUNT(*) FROM meli_items WHERE meli_account_id<>9011 AND external_item_id='MCO8190'")->fetchColumn() === 0, 'hybrid_no_foreign_account_persistence');
    $settings->set('items.hybrid_notification_updates_enabled', '0', 'items');
    AppSettingsService::clearCache();

    cap2_domains_negative_fences($pdo);
    // An event arriving during the GET keeps its own ID unacknowledged and requests a rerun.
    $rerunWork=cap2_domains_notification($pdo,'question','8177','/questions/8177');
    $rerunJob=cap2_domains_pointer($pdo,'notification_work_item',$rerunWork);
    $firstEvent=(int)$pdo->query("SELECT latest_event_id FROM meli_notification_work_items WHERE id={$rerunWork}")->fetchColumn();
    Cap2DomainsWire::$responses['/questions/8177']=[200,['id'=>8177,'text'=>'rerun','status'=>'UNANSWERED','seller_id'=>99011]];
    Cap2DomainsWire::$onWire=static function () use($pdo): void { cap2_domains_notification($pdo,'question','8177','/questions/8177'); };
    try { cap2_domains_assert_run($pdo,$rerunJob,'/questions/8177','question_exact'); }
    finally { Cap2DomainsWire::$onWire=null; }
    $rerun=$pdo->query("SELECT status,latest_event_id,processing_event_id FROM meli_notification_work_items WHERE id={$rerunWork}")->fetch();
    k1b_assert($rerun['status']==='pending' && (int)$rerun['latest_event_id']>$firstEvent && $rerun['processing_event_id']===null,'rerun_source_preserved');
    k1b_assert($pdo->query("SELECT status FROM meli_notification_events WHERE id={$firstEvent}")->fetchColumn()==='processed','processing_event_acknowledged');
    k1b_assert($pdo->query('SELECT status FROM meli_notification_events WHERE id='.(int)$rerun['latest_event_id'])->fetchColumn()!=='processed','newer_event_not_acknowledged');

    // The independent review remains tolerant; strict exact review cannot reuse its failed snapshot.
    $before=count(Cap2DomainsWire::$calls);
    QueueV4CleanCycleBudget::start(1);
    try {
        App\Services\ApiExecutionMetadataContext::run([
            'source'=>App\Services\MeliTransportSourcePolicy::QUEUE_V4_DOMAIN_EXACT,'company_id'=>9001,'account_id'=>9011,
            'domain_resource_type'=>'item','domain_remote_resource_id'=>'MCO8888',
        ],static function () use($pdo): void {
            $review=new App\Services\MeliProductUpdateReviewService();
            $legacy=$review->createReviewForItem(9011,'MCO8181');
            k1b_assert($pdo->query("SELECT status FROM meli_product_update_reviews WHERE id={$legacy}")->fetchColumn()==='ready','independent_review_keeps_tolerant_scan');
            $error=null;
            try { $review->createReviewForItem(9011,'MCO8181',true); }
            catch(RuntimeException $caught) { $error=$caught->getMessage(); }
            k1b_assert($error==='queue_v4_clean_domain_transport_capability_denied','strict_review_propagates_and_does_not_reuse_failed_cache');
        });
        k1b_assert(count(Cap2DomainsWire::$calls)===$before && QueueV4CleanCycleBudget::snapshot()['used']===0,'review_errors_pretransport_zero');
    } finally { QueueV4CleanCycleBudget::clear(); }

    // Break caught: new item is misdeclared local, and scanOne swallows real 429 as success.
    $badItem = cap2_domains_notification($pdo,'item','MCO8199','/items/MCO8199');
    $badJob = cap2_domains_pointer($pdo,'notification_work_item',$badItem);
    $state = (new NotificationWorkItemService())->inspectExact($badItem,9011,9001);
    $itemCapable = $state->usesApi && $state->estimatedCalls===1 && $state->operationKey==='item_exact';
    echo 'ITEM_API_CAPABLE=' . ($itemCapable ? 'YES' : 'NO') . "\n";
    $laterWork = cap2_domains_notification($pdo,'question','8198','/questions/8198');
    $laterJob = cap2_domains_pointer($pdo,'notification_work_item',$laterWork);
    Cap2DomainsWire::$responses['/items/MCO8199'] = [429,['message'=>'rate limit','error'=>'too_many_requests','status'=>429]];
    Cap2DomainsWire::$responses['/questions/8198'] = [200,['id'=>8198,'text'=>'must not be called','status'=>'UNANSWERED']];
    $before=count(Cap2DomainsWire::$calls);
    $limited=cap2_domains_run($pdo,100);
    k1b_assert(count(Cap2DomainsWire::$calls)===$before+1 && $limited['cycle_used']===1 && $limited['physical_http_calls']===1,'real_item_429_stops_remaining_cycle');
    k1b_assert((int)$pdo->query("SELECT COUNT(*) FROM queue_v4_clean_transport_events WHERE company_id=9001 AND meli_account_id=9011 AND source_kind='queue' AND work_id={$badJob} AND endpoint_key='item_exact' AND http_status=429 AND dispatch_state='RESPONSE_KNOWN'")->fetchColumn()===1,'item_429_durable_journal');
    k1b_assert($pdo->query("SELECT status FROM meli_notification_work_items WHERE id={$badItem}")->fetchColumn()!=='complete','item_429_not_complete');
    k1b_assert($pdo->query("SELECT state FROM queue_v4_clean_jobs WHERE id={$laterJob}")->fetchColumn()==='ready','429_preserves_remaining_unclaimed');
    k1b_assert($itemCapable,'uncached_item_is_api_capable');
    k1b_assert(count(Cap2DomainsWire::$calls)===10,'matrix_exact_ten_wire_calls_including_default_hybrid');
    echo "STATUS=PASS CAP2_DOMAINS_MYSQL\nREAL_MELI_HTTP=0\nREAL_EMAIL_SENT=0\n";
} finally {
    QueueV4CleanCycleBudget::clear();
    $harness->cleanup();
}

function cap2_domains_case(PDO $pdo,string $type,string $id,string $path,array $body,string $endpoint): void
{
    $work=cap2_domains_notification($pdo,$type,$id,$path);
    $job=cap2_domains_pointer($pdo,'notification_work_item',$work);
    Cap2DomainsWire::$responses[$path]=[200,$body];
    cap2_domains_assert_run($pdo,$job,$path,$endpoint);
    $source=$pdo->query("SELECT status,processing_event_id,latest_event_id FROM meli_notification_work_items WHERE id={$work}")->fetch();
    k1b_assert($source['status']==='complete' && $source['processing_event_id']===null,$type.'_source_ack');
    k1b_assert($pdo->query('SELECT status FROM meli_notification_events WHERE id='.(int)$source['latest_event_id'])->fetchColumn()==='processed',$type.'_event_ack');
}

function cap2_domains_negative_fences(PDO $pdo): void
{
    $work=cap2_domains_notification($pdo,'question','8166','/questions/8166');
    $jobId=cap2_domains_pointer($pdo,'notification_work_item',$work);
    $repo=new QueueV4CleanRepository($pdo);
    $run=$repo->beginRun('test','negative-fixture-owner');
    $job=$repo->claim($run,'negative-fixture-owner',60,[9011],9011);
    k1b_assert((int)$job['id']===$jobId,'negative_fixture_claims_real_attempt');
    $meta=['source'=>App\Services\MeliTransportSourcePolicy::QUEUE_V4_DOMAIN_EXACT,'company_id'=>9001,'account_id'=>9011,
        'domain_resource_type'=>'question','domain_remote_resource_id'=>'8166','queue_v4_job_id'=>$jobId,
        'queue_v4_attempt_id'=>$job['attempt_id'],'queue_v4_lease_owner'=>$job['lease_owner'],'queue_v4_lease_generation'=>$job['lease_generation']];
    $before=count(Cap2DomainsWire::$calls);
    QueueV4CleanCycleBudget::start(3);
    try {
        foreach ([[$meta,'/questions/8167'],[$meta,'/questions/8166/answers'],
            [array_replace($meta,['source'=>'cron']),'/questions/8166'],
            [array_replace($meta,['queue_v4_lease_owner'=>'stale']),'/questions/8166'],
            [array_replace($meta,['queue_v4_lease_generation'=>99]),'/questions/8166'],
            [array_replace($meta,['account_id'=>9999]),'/questions/8166']] as [$context,$path]) {
            // Each direct transport attempt needs the same fresh identity
            // contract as MeliApiClient; otherwise an earlier guard masks the
            // path/tenant/lease fence this negative case is meant to exercise.
            $context['transport_request_id'] = bin2hex(random_bytes(20));
            $error=null;
            try {
                App\Services\ApiExecutionMetadataContext::run($context,static fn() => (new App\Services\CurlMeliHttpTransport())->request('GET','https://cap2-wire.invalid'.$path,[],[],false,['timeout'=>5,'connect_timeout'=>2]));
            } catch (RuntimeException $caught) { $error=$caught->getMessage(); }
            k1b_assert(in_array($error,['queue_v4_clean_domain_transport_capability_denied','queue_v4_clean_cycle_transport_source_denied','queue_v4_clean_dispatch_fence_lost'],true),'negative_rejected_by_real_policy_or_lease:'.$error);
            k1b_assert(count(Cap2DomainsWire::$calls)===$before && QueueV4CleanCycleBudget::snapshot()['used']===0,'negative_dispatch_zero');
        }
        k1b_assert((int)$pdo->query("SELECT COUNT(*) FROM queue_v4_clean_transport_events WHERE source_kind='queue' AND work_id={$jobId}")->fetchColumn()===0,'negative_ledger_zero');
        k1b_assert($pdo->query('SELECT dispatch_state FROM queue_v4_clean_attempts WHERE id='.(int)$job['attempt_id'])->fetchColumn()==='NOT_DISPATCHED','negative_attempt_unchanged');
    } finally { QueueV4CleanCycleBudget::clear(); }
    $repo->review($job,$run,'fixture_negative_completed');
    $repo->finishRun($run,'completed');
    echo "PASS=negative_source_path_id_tenant_owner_generation_zero\n";
}

function cap2_domains_assert_run(PDO $pdo,int $job,string $path,string $endpoint): void
{
    usleep(1100000); // Real pacing is retained; no bypass of rhythm or ownership checks.
    $before=count(Cap2DomainsWire::$calls);
    $result=cap2_domains_run($pdo,1);
    k1b_assert(count(Cap2DomainsWire::$calls)===$before+1 && $result['physical_http_calls']===1 && $result['cycle_used']===1,$endpoint.'_one_wire_ledger_budget:'.json_encode($result));
    k1b_assert($result['completed']===1,$endpoint.'_completed:'.json_encode($pdo->query("SELECT last_error_class FROM queue_v4_clean_jobs WHERE id={$job}")->fetch()));
    $call=Cap2DomainsWire::$calls[$before];
    k1b_assert($call['path']===$path && $call['meta']['source']===App\Services\MeliTransportSourcePolicy::QUEUE_V4_DOMAIN_EXACT,$endpoint.'_bound_path_source');
    k1b_assert((int)$call['meta']['company_id']===9001 && (int)$call['meta']['account_id']===9011 && (int)$call['meta']['queue_v4_job_id']===$job && (int)$call['meta']['queue_v4_attempt_id']>0 && (int)$call['meta']['queue_v4_lease_generation']>0 && $call['meta']['queue_v4_lease_owner']!=='',$endpoint.'_tenant_lease_metadata');
    k1b_assert((int)$pdo->query("SELECT COUNT(*) FROM queue_v4_clean_transport_events WHERE company_id=9001 AND meli_account_id=9011 AND source_kind='queue' AND work_id={$job} AND endpoint_key='{$endpoint}' AND dispatch_state='RESPONSE_KNOWN'")->fetchColumn()===1,$endpoint.'_journal');
    echo 'PASS='.$endpoint."\n";
}

function cap2_domains_notification(PDO $pdo, string $type, string $id, string $path): int
{
    $pdo->prepare('INSERT INTO meli_notification_events(meli_account_id,topic,resource,payload_json,payload_hash) VALUES(9011,?,?,?,?)')
        ->execute([$type, $path, '{}', hash('sha256', $path . random_bytes(8))]);
    $event = (int)$pdo->lastInsertId();
    return (int)(new NotificationWorkItemService())->enqueue($event,9011,99011,[
        'valid'=>true,'actionable'=>true,'canonical_topic'=>$type,'resource_type'=>$type,'resource_id'=>$id,'priority'=>10,
    ],null);
}

function cap2_domains_pointer(PDO $pdo, string $capability, int $source): int
{
    $existing = $pdo->prepare("SELECT id FROM queue_v4_clean_jobs WHERE company_id=9001 AND meli_account_id=9011 AND job_type='domain_exact' AND resource_id=? AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))=? LIMIT 1");
    $existing->execute([(string)$source, $capability]);
    if ($id = $existing->fetchColumn()) { return (int)$id; }
    $pdo->prepare("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json) VALUES(9001,9011,'domain_exact',?,?,?)")
        ->execute([(string)$source, 'cap2-' . $capability . '-' . $source . '-' . bin2hex(random_bytes(3)), json_encode(['capability'=>$capability,'source_id'=>$source])]);
    return (int)$pdo->lastInsertId();
}

function cap2_domains_run(PDO $pdo, int $budget): array
{
    QueueV4CleanCycleBudget::start($budget);
    try {
        $result = (new QueueV4CleanWorker($pdo,new QueueV4CleanRepository($pdo)))->run('test',$budget,45,[9011],9011);
        $result['cycle_used'] = QueueV4CleanCycleBudget::snapshot()['used'];
        return $result;
    } finally { QueueV4CleanCycleBudget::clear(); }
}
