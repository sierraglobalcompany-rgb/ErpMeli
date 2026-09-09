<?php
declare(strict_types=1);

require __DIR__.'/cap2_manual_fixture.php';

use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\CapacityPolicyService;
use App\Services\ManualCampaignPreviewService;

$harness=cap2_manual_database();
try {
    $pdo=$harness->pdo();
    $source=cap2_manual_notification($pdo,'990001');
    $repository=new QueueV4CleanRepository($pdo);
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,state,available_at,payload_json)
         VALUES(9001,9011,'domain_exact',?,'manual-source-snapshot-fixture','ready','2000-01-01',?)"
    )->execute([
        (string)$source['source_id'],
        json_encode(['capability'=>'notification_work_item','source_id'=>(int)$source['source_id']]),
    ]);
    $jobId=(int)$pdo->lastInsertId();
    $policy=(new CapacityPolicyService())->snapshot('manual');
    $preview=(new ManualCampaignPreviewService())->create(9007,[
        'scope'=>'available_queue',
        'account_id'=>9011,
        'physical_api_call_budget'=>1,
        'capacity_revision'=>$policy['revision'],
    ]);
    $rows=array_values(array_filter(
        (array)$preview['rows'],
        static fn(array $row):bool=>(int)($row['queue_job_id']??0)===$jobId
    ));
    k1b_assert(count($rows)===1,'selected_pointer_present');
    k1b_assert(preg_match('/^[a-f0-9]{64}$/D',(string)($rows[0]['source_selection_version']??''))===1,'durable_source_version_persisted');

    $pdo->prepare(
        'UPDATE meli_notification_work_items SET remote_resource_id=?
         WHERE id=? AND meli_account_id=9011'
    )->execute(['990002',(int)$source['source_id']]);
    App\Services\Cap2DomainsWire::$responses['/questions/990002']=[200,[
        'id'=>990002,'text'=>'changed after preview','status'=>'UNANSWERED','seller_id'=>99011,
    ]];

    QueueV4CleanCycleBudget::start(1,'manual',microtime(true)+40);
    try {
        $result=(new QueueV4CleanWorker($pdo,$repository))->run('manual',1,30,[9011],9011,$rows);
    } finally {
        QueueV4CleanCycleBudget::clear();
    }
    k1b_assert((int)$result['claimed']===0 && (int)$result['stale_or_busy_skipped']===1,'changed_source_skipped_before_claim');
    k1b_assert((int)$result['physical_http_calls']===0 && App\Services\Cap2DomainsWire::$calls===[],'changed_source_zero_http');
    $pointerStatement=$pdo->prepare('SELECT state,attempt_count FROM queue_v4_clean_jobs WHERE id=?');
    $pointerStatement->execute([$jobId]);
    $pointer=$pointerStatement->fetch(PDO::FETCH_ASSOC)?:[];
    k1b_assert(($pointer['state']??null)==='ready' && (int)($pointer['attempt_count']??-1)===0,'changed_source_pointer_left_for_automatic_reconciliation');
    echo "STATUS=PASS CALLS_MANUAL_AVAILABLE_SOURCE_SNAPSHOT REAL_MELI_HTTP=0\n";
} finally {
    $harness->cleanup();
}
