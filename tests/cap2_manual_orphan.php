<?php
declare(strict_types=1);
require __DIR__.'/cap2_manual_fixture.php';

/** Real PDO; fault only at the first post-COMMIT/pre-claim statement. */
final class Cap2ManualPreclaimPdo extends PDO
{
    private bool $fired=false;
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        if (!$this->fired && str_starts_with($query,'SELECT COALESCE(MAX(id),0) FROM queue_core_attempts')) {
            $this->fired=true;
            $observer=cap2_manual_connection();
            $pending=(int)$observer->query("SELECT COUNT(*) FROM queue_core_jobs WHERE queue_domain='manual' AND state='pending' AND lease_owner IS NULL AND dispatch_state='NOT_DISPATCHED'")->fetchColumn();
            k1b_assert($pending===1,'fault_boundary_has_committed_pending_job');
            k1b_assert(cap2_manual_state($observer,(string)getenv('CAP2_PREVIEW'))==='consumed','fault_boundary_preview_already_consumed');
            echo "BOUNDARY=COMMITTED_ENQUEUE_BEFORE_CLAIM\n";
            if (getenv('CAP2_FAULT_MODE')==='death') exit(71); // No finally: real process death.
            throw new PDOException('simulated post-enqueue previous-attempt SELECT failure');
        }
        return parent::prepare($query,$options);
    }
}

if (($argv[1]??'')==='child') {
    K1dSafeTestDatabase::assertGuard((string)getenv('APP_ENV'),(string)getenv('ML_WRITE_ENABLED'),(string)getenv('DB_HOST'),(string)getenv('DB_NAME'));
    $pdo=new Cap2ManualPreclaimPdo('mysql:host=127.0.0.1;port=33079;dbname='.getenv('DB_NAME').';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec("SET time_zone='+00:00'");App\Core\Database::setConnection($pdo);
    App\Core\Session::put('user',['id'=>9007,'role'=>'admin','session_generation'=>(new App\Services\SessionGenerationService())->current()]);
    try {(new App\Services\ManualSingleStepService())->execute((string)getenv('CAP2_PREVIEW'),9007);}
    catch(PDOException) {echo "FAILURE=CAUGHT_POST_ENQUEUE\n";exit(72);}
    throw new RuntimeException('fault boundary was not reached');
}

$h=cap2_manual_database();
try {
    $pdo=$h->pdo();$row=cap2_manual_notification($pdo,'8801');
    $old=cap2_manual_preview($pdo,[$row]);
    cap2_manual_fault_child($old,'death',71);
    $orphan=$pdo->query("SELECT id,provenance_json FROM queue_core_jobs WHERE queue_domain='manual' AND state='pending'")->fetch();
    k1b_assert(is_array($orphan),'process_death_leaves_real_committed_orphan');
    k1b_assert(cap2_manual_rejected(fn()=>(new App\Services\ManualSingleStepService())->execute($old,9007)),'dead_process_preview_replay_rejected');
    // Live global owner must still block; no early recovery or consumption.
    $fresh=cap2_manual_preview($pdo,[$row]);
    k1b_assert(cap2_manual_rejected(fn()=>(new App\Services\ManualSingleStepService())->execute($fresh,9007)) && cap2_manual_state($pdo,$fresh)==='ready','live_old_global_lease_keeps_fresh_ready');
    $pdo->exec("UPDATE queue_core_execution_leases SET expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE lease_key='global'");
    $provenance=json_decode((string)$orphan['provenance_json'],true);
    foreach([['manual_user_id'=>9008],['execution_owner'=>'unknown'],['execution_generation'=>100000],['manual_preview_id'=>0]] as $mutation) {
        $pdo->prepare('UPDATE queue_core_jobs SET provenance_json=? WHERE id=?')->execute([json_encode(array_replace($provenance,$mutation)),(int)$orphan['id']]);
        k1b_assert(cap2_manual_rejected(fn()=>(new App\Services\ManualSingleStepService())->execute($fresh,9007)) && cap2_manual_state($pdo,$fresh)==='ready','unproven_orphan_authority_cannot_consume_or_recover');
    }
    $pdo->prepare('UPDATE queue_core_jobs SET provenance_json=? WHERE id=?')->execute([$orphan['provenance_json'],(int)$orphan['id']]);
    k1b_assert(App\Services\Cap2DomainsWire::$calls===[],'all_orphan_prechecks_and_old_replay_zero_http');
    // Expire the fresh preview after service load, during global lease admission.
    // The certified orphan must remain pending because consume then loses.
    $quoted=$pdo->quote($fresh);
    $pdo->exec("CREATE TRIGGER cap2_expire_orphan_preview AFTER UPDATE ON queue_core_execution_leases FOR EACH ROW BEGIN IF NEW.owner_token IS NOT NULL THEN UPDATE manual_campaign_previews SET expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE preview_token={$quoted}; END IF; END");
    try {k1b_assert(cap2_manual_rejected(fn()=>(new App\Services\ManualSingleStepService())->execute($fresh,9007)),'expired_fresh_admission_rejected');}
    finally {$pdo->exec('DROP TRIGGER cap2_expire_orphan_preview');}
    k1b_assert(cap2_manual_state($pdo,$fresh)==='ready' && $pdo->query('SELECT state FROM queue_core_jobs WHERE id='.(int)$orphan['id'])->fetchColumn()==='pending','failed_fresh_admission_does_not_recover_orphan');
    $fresh=cap2_manual_preview($pdo,[$row]);
    App\Services\Cap2DomainsWire::$responses['/questions/8801']=[200,['id'=>8801,'text'=>'after orphan','status'=>'UNANSWERED','seller_id'=>99011]];
    $r=null;
    try {$r=(new App\Services\ManualSingleStepService())->execute($fresh,9007);} catch(App\QueueCore\ManualFifoBusyException) {}
    k1b_assert(is_array($r) && $r['completed_count']===1,'fresh_preview_recovers_certified_committed_orphan_and_proceeds');
    k1b_assert($pdo->query('SELECT state FROM queue_core_jobs WHERE id='.(int)$orphan['id'])->fetchColumn()==='review','orphan_closed_locally_not_replayed');
    k1b_assert((int)$pdo->query('SELECT COUNT(*) FROM queue_core_attempts WHERE job_id='.(int)$orphan['id'])->fetchColumn()===0 && count(App\Services\Cap2DomainsWire::$calls)===1,'orphan_never_claimed_http_only_for_new_preview');
    echo "PASS=real_committed_orphan_recovery\n";

    $row=cap2_manual_notification($pdo,'8802');$token=cap2_manual_preview($pdo,[$row]);
    cap2_manual_fault_child($token,'exception',72);
    k1b_assert((int)$pdo->query("SELECT COUNT(*) FROM queue_core_jobs WHERE queue_domain='manual' AND state='pending'")->fetchColumn()===0,'catchable_post_enqueue_select_failure_is_closed');
    k1b_assert(cap2_manual_state($pdo,$token)==='consumed','catchable_post_enqueue_failure_terminal_preview');

    // Uncertified pending work is never guessed to belong to a crashed request.
    $repo=new App\QueueCore\QueueCoreRepository($pdo);
    $legacy=$repo->enqueue(new App\QueueCore\QueueJob(9001,9011,'manual_exact','notification_fallback',$row['source_id'],'normal',80,'uncertified-orphan','v1','manual_web',null,[],[],1,null,'manual'));
    $fresh=cap2_manual_preview($pdo,[$row]);
    k1b_assert(cap2_manual_rejected(fn()=>(new App\Services\ManualSingleStepService())->execute($fresh,9007)) && cap2_manual_state($pdo,$fresh)==='ready' && $repo->job($legacy)['state']==='pending','uncertified_legacy_pending_stays_busy_ready');
    echo "STATUS=PASS CAP2_MANUAL_ORPHAN\nREAL_MELI_HTTP=0\n";
} finally {$h->cleanup();}

function cap2_manual_fault_child(string $token,string $mode,int $expectedExit): void
{
    putenv('CAP2_PREVIEW='.$token);putenv('CAP2_FAULT_MODE='.$mode);
    $process=proc_open([PHP_BINARY,__FILE__,'child'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    k1b_assert(is_resource($process),'child_started');fclose($pipes[0]);
    $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    $exit=proc_close($process);putenv('CAP2_PREVIEW');putenv('CAP2_FAULT_MODE');
    k1b_assert($exit===$expectedExit && $stderr==='' && str_contains($stdout,'BOUNDARY=COMMITTED_ENQUEUE_BEFORE_CLAIM'),'real_preclaim_fault_child_exit_'.$exit.':'.$stderr);
    echo $stdout;
}
