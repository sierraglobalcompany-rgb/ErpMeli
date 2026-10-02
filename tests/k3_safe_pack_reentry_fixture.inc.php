<?php
declare(strict_types=1);
require_once __DIR__ . '/k3_unit02_disposition_fixture.inc.php';

use App\QueueV4Clean\QueueV4CleanRepository;
use App\Services\CronAdmissionService;
use App\Services\OrderEnrichmentService;

/** Closed synthetic zero-send attempt created through the real queue lifecycle. */
function sprFixture(PDO $pdo, string $variant = 'deadline_pending'): array
{
    $seed = r0h3_seed($pdo);
    r0h3_prepare_transport_fixture($pdo, 10);
    $pdo->exec('DELETE FROM manual_campaign_reservations');
    $pdo->exec('DELETE FROM sale_financial_reconciliation_jobs');
    $t = $seed['healthy'][0];
    $s = (new OrderEnrichmentService())->enqueue(7201, $t['order_id'], 'pack', $t['external_pack_id'], 10);
    $pdo->beginTransaction();
    $receipt = (new CronAdmissionService($pdo))->submit('order_enrichment_pack', 7200, 7201, $s, 'source:'.$s, ['pack_id'=>$t['external_pack_id']]);
    $pdo->commit();
    $repo = new QueueV4CleanRepository($pdo);
    $run = $repo->beginRun('test', 'safe-pack-fixture');
    $job = $repo->claim($run, 'safe-pack-fixture', 60, [7201]);
    r0h3_assert(is_array($job) && $job['id']===$receipt['job_id'], 'fixture_claim');
    $cause = 'domain_source_waiting:order_enrichment_pack:waiting_'.($variant==='rhythm_retry'?'rhythm':'deadline');
    $repo->deferWithoutAttemptPenalty($job, $run, $cause, '2000-01-01 00:00:00');
    $repo->finishRun($run, 'completed');
    $pdo->prepare('UPDATE queue_v4_clean_jobs SET available_at=? WHERE id=?')->execute(['2000-01-01 00:00:00',$job['id']]);
    $pdo->prepare('UPDATE order_resource_enrichment_jobs SET status=?,failure_class=?,reached_remote=?,next_run_at=? WHERE id=?')
        ->execute([$variant==='deadline_pending'?'pending':'retry', $variant==='deadline_pending'?null:($variant==='rhythm_retry'?'waiting_rhythm':'waiting_deadline'), $variant==='deadline_pending'?null:0,'2000-01-01 00:00:00',$s]);
    $a = (int)$pdo->query('SELECT id FROM queue_v4_clean_attempts WHERE job_id='.(int)$job['id'])->fetchColumn();
    return ['seed'=>$seed,'target'=>$t,'source_id'=>$s,'job_id'=>(int)$job['id'],'attempt_id'=>$a,'cause'=>$cause,'repo'=>$repo];
}

function sprRow(PDO $pdo, string $table, int $id): array
{
    $s=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC);
}

function sprEvent(PDO $pdo, array $f, array $extra=[]): int
{
    return r0h3_insert($pdo,'queue_v4_clean_transport_events',array_replace([
        'company_id'=>7200,'meli_account_id'=>7201,'source_kind'=>'queue','work_id'=>$f['job_id'],
        'attempt_id'=>$f['attempt_id'],'lease_generation'=>1,'request_id'=>bin2hex(random_bytes(20)),
        'method'=>'GET','endpoint_key'=>'pack_exact','dispatch_state'=>'PHYSICAL_STARTED',
        'physical_started_at'=>'2000-01-01 00:00:00',
    ],$extra));
}
