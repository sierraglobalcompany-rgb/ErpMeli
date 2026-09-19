<?php
declare(strict_types=1);

// Real schema 301, launchers, handlers, PDO guards and receipts. Only curl_exec is fake.
$callsQaRoot = rtrim(str_replace('\\', '/', (string) (getenv('CALLS_VERIFY_QA_ROOT') ?: 'D:/Codex/tmp/erp-meli/calls-20260906')), '/');
$projectQaRoot = str_replace('\\', '/', dirname(__DIR__) . '/storage/codex-');
if (!(str_starts_with($callsQaRoot, 'D:/Codex/')
        || str_starts_with($callsQaRoot, 'C:/codex/capacity-save-kiss/')
        || str_starts_with($callsQaRoot, $projectQaRoot))
    || in_array('..', explode('/', $callsQaRoot), true)) throw new RuntimeException('EXPLICIT_LOCAL_QA_ROOT_REQUIRED');
putenv('CAP2_MANUAL_QA_ROOT=' . $callsQaRoot . '/domains-regression');
putenv('CALLS_QA_STORAGE_ROOT=' . $callsQaRoot . '/domains-regression/storage');
require __DIR__.'/cap2_manual_fixture.php';

use App\QueueV4Clean\QueueV4CleanCycleBudget as Budget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\AppSettingsService;
use App\Services\Cap2DomainsWire as Wire;
use App\Services\ManualSingleStepService;

$h = cap2_manual_database();
$failures = [];
try {
    $pdo = $h->pdo();
    $settings = new AppSettingsService();
    foreach (['alerts.email.enabled'=>'0', 'items.search_mode'=>'offset',
        'items.hybrid_bulk_updates_enabled'=>'0', 'manual.api_calls_per_step'=>'1'] as $key=>$value) {
        $settings->set($key, $value, 'calls-domain-fixture');
    }
    AppSettingsService::clearCache();

    // A: genuine items_sync discovery and detail, not a notification item substitute.
    calls_domains_case('items_sync_manual_snapshot', static function () use ($pdo, $settings): void {
        $service = new App\Services\MeliItemSyncJobService();
        $job = $service->createOrResume(9011, false);
        $foreign = $service->createOrResume(9012, false);
        $foreignBefore = $pdo->query('SELECT * FROM meli_item_sync_jobs WHERE id='.$foreign)->fetch();
        $row = ['queue_key'=>'items_sync', 'source_id'=>(string)$job, 'meli_account_id'=>9011];
        (new App\Services\WorkQueueProjectionService())->refreshQueue('items_sync');
        $old = cap2_manual_preview($pdo, [$row]);
        $token = cap2_manual_preview($pdo, [$row]);
        // Increasing current policy must not increase the already reviewed physical snapshot.
        $settings->set('manual.api_calls_per_step', '3', 'calls-domain-fixture');
        AppSettingsService::clearCache();
        Wire::$responses['/users/99011/items/search'] = [200, ['results'=>['MCO9711','MCO9712'], 'paging'=>['total'=>2]]];
        $before = count(Wire::$calls);
        $result = (new ManualSingleStepService())->executeMany($token, 9007, 100);
        calls_domains_one_manual($result, $before, '/users/99011/items/search');
        $state = $pdo->query('SELECT phase,offset_value,discovered_count FROM meli_item_sync_jobs WHERE id='.$job)->fetch();
        k1b_assert($state['phase']==='details' && (int)$state['offset_value']===2 && (int)$state['discovered_count']===2,
            'items_discovery_checkpoint:'.json_encode([$state,$result]));
        k1b_assert((int)$pdo->query('SELECT COUNT(*) FROM meli_item_sync_job_items WHERE meli_item_sync_job_id='.$job.' AND status="pending"')->fetchColumn()===2,
            'discovery_does_not_fetch_details');
        k1b_assert(cap2_manual_state($pdo,$token)==='consumed' && cap2_manual_rejected(fn()=>(new ManualSingleStepService())->execute($token,9007)),
            'items_discovery_replay_rejected');
        k1b_assert(count(Wire::$calls)===$before+1, 'items_discovery_replay_zero_wire');

        $stale = (new ManualSingleStepService())->execute($old,9007);
        k1b_assert($stale['physical_http_calls']===0 && count(Wire::$calls)===$before+1,
            'old_discovery_authority_cannot_authorize_detail:'.json_encode($stale));
        $settings->set('manual.api_calls_per_step', '1', 'calls-domain-fixture');
        AppSettingsService::clearCache();
        (new App\Services\WorkQueueProjectionService())->refreshQueue('items_sync');
        $detailToken = cap2_manual_preview($pdo, [$row]);
        Wire::$responses['/items/MCO9711'] = [200, ['id'=>'MCO9711', 'title'=>'Bounded item detail', 'price'=>10,
            'currency_id'=>'COP', 'category_id'=>'MCO1', 'condition'=>'new', 'available_quantity'=>1,
            'sold_quantity'=>0, 'status'=>'active', 'listing_type_id'=>'gold_special', 'pictures'=>[], 'variations'=>[], 'attributes'=>[]]];
        usleep(2200000); // Keep real pacing; no policy bypass between the two requests.
        $before = count(Wire::$calls);
        $detail = (new ManualSingleStepService())->execute($detailToken,9007);
        calls_domains_one_manual($detail,$before,'/items/MCO9711');
        $items = $pdo->query('SELECT external_item_id,status FROM meli_item_sync_job_items WHERE meli_item_sync_job_id='.$job.' ORDER BY id')->fetchAll();
        k1b_assert($items===[['external_item_id'=>'MCO9711','status'=>'complete'],['external_item_id'=>'MCO9712','status'=>'pending']],
            'one_detail_preserves_remaining_source:'.json_encode([$items,$detail]));
        k1b_assert($pdo->query("SELECT title FROM meli_items WHERE meli_account_id=9011 AND external_item_id='MCO9711'")->fetchColumn()==='Bounded item detail',
            'detail_persisted_for_exact_account');
        k1b_assert($pdo->query('SELECT * FROM meli_item_sync_jobs WHERE id='.$foreign)->fetch()===$foreignBefore,
            'foreign_company_item_job_unchanged');
        k1b_assert(cap2_manual_rejected(fn()=>(new ManualSingleStepService())->execute($detailToken,9007)) && count(Wire::$calls)===$before+1,
            'items_detail_replay_zero_wire');
    }, $failures);

    // B: expected question absence is terminal neutral work, not a retry/protection error.
    foreach (['manual_core','automatic_v4'] as $owner) {
        calls_domains_case('question_404_'.$owner, static function () use ($pdo,$owner): void {
            $remote = $owner==='manual_core' ? '9741' : '9742';
            $row = cap2_manual_notification($pdo,$remote);
            $neighbor = cap2_manual_notification($pdo,$remote.'9');
            $source = (int)$row['source_id'];
            $neighborBefore = $pdo->query('SELECT * FROM meli_notification_work_items WHERE id='.(int)$neighbor['source_id'])->fetch();
            Wire::$responses['/questions/'.$remote] = [404,['error'=>'not_found','message'=>'Question not available','status'=>404]];
            usleep(2200000);
            $before = count(Wire::$calls);
            if ($owner==='manual_core') {
                $token = cap2_manual_preview($pdo,[$row]);
                $result = (new ManualSingleStepService())->execute($token,9007);
                calls_domains_one_manual($result,$before,'/questions/'.$remote);
                $meta = Wire::$calls[$before]['meta'];
                $attempt = $pdo->prepare('SELECT http_status FROM queue_core_attempts WHERE id=? AND job_id=? AND company_id=9001 AND meli_account_id=9011');
                $attempt->execute([(int)$meta['queue_core_attempt_id'],(int)$meta['queue_core_job_id']]);
                k1b_assert((int)$attempt->fetchColumn()===404,'expected_absence_core_preserves_known_http_evidence');
                k1b_assert(cap2_manual_rejected(fn()=>(new ManualSingleStepService())->execute($token,9007)) && count(Wire::$calls)===$before+1,
                    'expected_absence_manual_replay_zero');
            } else {
                $job = calls_domains_v4_pointer($pdo,$source);
                // Independent fixture cases must not select an earlier neighbor pointer.
                $pdo->exec('UPDATE queue_v4_clean_jobs SET available_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 DAY) WHERE id<>'.$job.' AND state="ready"');
                Budget::start(1,'automatic',microtime(true)+45);
                try {
                    $result = (new QueueV4CleanWorker($pdo,new QueueV4CleanRepository($pdo)))->run('test',1,45,[9011],9011);
                    k1b_assert(count(Wire::$calls)===$before+1 && $result['physical_http_calls']===1 && Budget::snapshot()['used']===1
                        && Budget::snapshot()['physical_http_calls_certainty']==='CERTIFIED'
                        && (int)Wire::$calls[$before]['meta']['queue_v4_job_id']===$job,
                        'expected_absence_v4_one_wire:'.json_encode($result));
                } finally { Budget::clear(); }
                k1b_assert((int)$pdo->query("SELECT COUNT(*) FROM queue_v4_clean_transport_events WHERE company_id=9001 AND meli_account_id=9011 AND work_id={$job} AND endpoint_key='question_exact' AND http_status=404 AND dispatch_state='RESPONSE_KNOWN'")->fetchColumn()===1,
                    'expected_absence_known_404_journal');
            }
            $state = $pdo->query('SELECT status,last_result,last_error_code,consecutive_failures,latest_event_id FROM meli_notification_work_items WHERE id='.$source)->fetch();
            k1b_assert($state['status']==='complete' && $state['last_result']==='question_not_available' && $state['last_error_code']===null && (int)$state['consecutive_failures']===0,
                'expected_absence_source_terminal_neutral:'.json_encode([$state,$result]));
            $event = $pdo->query('SELECT status,disposition FROM meli_notification_events WHERE id='.(int)$state['latest_event_id'])->fetch();
            k1b_assert($event===['status'=>'processed','disposition'=>'question_not_available'],'expected_absence_event_ack');
            k1b_assert($pdo->query('SELECT * FROM meli_notification_work_items WHERE id='.(int)$neighbor['source_id'])->fetch()===$neighborBefore,
                'expected_absence_does_not_touch_neighbor');
            echo 'EVIDENCE='.$owner.':'.json_encode(['source'=>$state,'receipt'=>$result])."\n";
            if ($owner==='manual_core') {
                k1b_assert((int)$result['completed_count']===1 && (int)$result['review_error_count']===0,
                    'expected_absence_manual_receipt_not_false_error:'.json_encode($result));
            } else {
                $pointer = $pdo->query('SELECT state,last_error_class FROM queue_v4_clean_jobs WHERE id='.$job)->fetch();
                k1b_assert((int)$result['completed']===1 && $pointer['state']==='completed' && $pointer['last_error_class']===null,
                    'expected_absence_v4_pointer_not_false_retry:'.json_encode([$pointer,$result]));
            }
        }, $failures);
    }
    // The narrow success signal must not hide another resource's404 or a failed lease CAS.
    foreach (['manual_core','automatic_v4'] as $owner) {
        foreach (['item_404','question_404_lost_lease'] as $scenario) {
            calls_domains_case($scenario.'_'.$owner, static function () use($pdo,$owner,$scenario): void {
                $lostLease = $scenario==='question_404_lost_lease';
                $remote = ($lostLease?'':'MCO').($owner==='manual_core'?'9761':'9762');
                $row = cap2_manual_notification($pdo,$remote,$lostLease?'question':'item');
                $source = (int)$row['source_id'];
                $path = ($lostLease?'/questions/':'/items/').$remote;
                Wire::$responses[$path] = [404,['error'=>'not_found','status'=>404,'message'=>'Not found']];
                if ($lostLease) {
                    Wire::$onWire = static function () use($pdo,$source): void {
                        $pdo->exec("UPDATE meli_notification_work_items SET locked_by='replacement-owner' WHERE id=".$source);
                    };
                }
                usleep(2200000);
                $before = count(Wire::$calls);
                try {
                    if ($owner==='manual_core') {
                        $result = (new ManualSingleStepService())->execute(cap2_manual_preview($pdo,[$row]),9007);
                        calls_domains_one_manual($result,$before,$path);
                        k1b_assert((int)$result['completed_count']===0 && (int)$result['review_error_count']===1,
                            'non_expected_or_uncommitted_absence_not_manual_success:'.json_encode($result));
                    } else {
                        $job = calls_domains_v4_pointer($pdo,$source);
                        $pdo->exec('UPDATE queue_v4_clean_jobs SET available_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 DAY) WHERE id<>'.$job.' AND state="ready"');
                        Budget::start(1,'automatic',microtime(true)+45);
                        try { $result = (new QueueV4CleanWorker($pdo,new QueueV4CleanRepository($pdo)))->run('test',1,45,[9011],9011); }
                        finally { Budget::clear(); }
                        k1b_assert(count(Wire::$calls)===$before+1 && $result['physical_http_calls']===1 && (int)$result['completed']===0,
                            'non_expected_or_uncommitted_absence_not_v4_success:'.json_encode($result));
                    }
                } finally { Wire::$onWire=null; }
                $state = $pdo->query('SELECT status,last_result,latest_event_id,locked_by FROM meli_notification_work_items WHERE id='.$source)->fetch();
                k1b_assert($state['status']!=='complete' && $state['last_result']!=='question_not_available',
                    'absence_success_requires_question_and_durable_close:'.json_encode($state));
                k1b_assert($pdo->query('SELECT status FROM meli_notification_events WHERE id='.(int)$state['latest_event_id'])->fetchColumn()!=='processed',
                    'failed_close_does_not_ack_event');
                if ($lostLease) k1b_assert($state['locked_by']==='replacement-owner','failed_close_preserves_replacement_lease');
            }, $failures);
        }
    }
    k1b_assert($failures===[], 'domain_regressions:'.json_encode($failures));
    echo "CALLS_DOMAINS_REGRESSION_MYSQL_OK\nREAL_MELI_HTTP=0\nREAL_EMAIL_SENT=0\n";
} finally {
    Wire::$onWire = null;
    Budget::clear();
    $h->cleanup();
}

function calls_domains_case(string $name, callable $case, array &$failures): void
{
    try { $case(); echo 'PASS='.$name."\n"; }
    catch (Throwable $error) { $failures[$name]=$error->getMessage(); echo 'FAIL='.$name.':'.$error->getMessage()."\n"; }
}

function calls_domains_one_manual(array $result,int $before,string $path): void
{
    k1b_assert(count(Wire::$calls)===$before+1 && Wire::$calls[$before]['path']===$path
        && $result['physical_http_calls']===1 && $result['physical_http_calls_certainty']==='CERTIFIED'
        && $result['effective_api_calls']===1, 'one_certified_manual_call_snapshot:'.json_encode($result));
    k1b_assert(Wire::$calls[$before]['meta']['source']==='queue_core'
        && (int)Wire::$calls[$before]['meta']['company_id']===9001 && (int)Wire::$calls[$before]['meta']['account_id']===9011,
        'real_manual_core_tenant_metadata');
}

function calls_domains_v4_pointer(PDO $pdo,int $source): int
{
    $s = $pdo->prepare("SELECT id FROM queue_v4_clean_jobs WHERE company_id=9001 AND meli_account_id=9011 AND job_type='domain_exact' AND resource_id=? AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.capability'))='notification_work_item' LIMIT 1");
    $s->execute([(string)$source]);
    if ($id=$s->fetchColumn()) return (int)$id;
    $pdo->prepare("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json) VALUES(9001,9011,'domain_exact',?,?,?)")
        ->execute([(string)$source,'calls-absence-'.$source,json_encode(['capability'=>'notification_work_item','source_id'=>$source])]);
    return (int)$pdo->lastInsertId();
}
