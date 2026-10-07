<?php
declare(strict_types=1);

require __DIR__.'/k1b_bootstrap.php';
require __DIR__.'/K1dSafeTestDatabase.php';

use App\Core\Database;
use App\QueueV4Clean\OuterCronHttpReceipt;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\Services\ColdArchiveService;
use App\Services\Migrator;
use App\Services\RetentionPolicyService;

putenv('APP_ENV=test');
putenv('ML_WRITE_ENABLED=false');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=33338');
putenv('DB_USER=root');
putenv('DB_PASS=local-http-receipt-test-only');
putenv('DB_NAME=erp_meli_k1d_test_http_retention_'.bin2hex(random_bytes(5)));
putenv('CALLS_VERIFY_QA_ROOT='.dirname(__DIR__).'/storage/codex-http-phase1');
putenv('APP_KEY=synthetic-http-retention-only');
putenv('PRIVATE_STORAGE_PATH='.dirname(__DIR__).'/storage/codex-http-phase1/retention-private-'.getmypid());
$db=K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo=$db->pdo();
    Database::setConnection($pdo);
    $policy=new RetentionPolicyService();
    k1b_assert(in_array('outer_http_attempts',$policy->datasets(),true),'R1 RED: outer HTTP retention missing');
    k1b_assert(in_array('outer_http_runs',$policy->datasets(),true),'R1 parent registered');
    echo "R1 registration=PASS\n";
    $migrator=new Migrator($pdo,dirname(__DIR__).'/database/migrations');
    $migrator->run(303);
    $migrator->run(303);
    k1b_assert((int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version LIKE '303_%'")->fetchColumn()===1,'303 exact once');
    $sql=file_get_contents(dirname(__DIR__).'/database/migrations/303_outer_cron_http_receipt.sql');
    k1b_assert(!str_contains($sql,'IF NOT EXISTS'),'303 canonical DDL');
    echo "MIGRATION_303_CANONICAL_EXACT_ONCE=PASS\n";
    $seed=static function (int $requests,int $days,string $kind='known',string $component='queue_v4_outer_http') use($pdo): array {
        $result=OuterCronHttpReceipt::within($pdo,['max_calls'=>55,'ceiling'=>55],static function () use($requests,$kind): array {
            for($i=0;$i<$requests;$i++) {
                $id=bin2hex(random_bytes(20));
                OuterCronHttpReceipt::reserve($id,'GET','/orders/100',9001,9011,'queue_v4_clean');
                OuterCronHttpReceipt::boundary($id);
                OuterCronHttpReceipt::enteringWire($id);
                if($kind!=='unknown') { OuterCronHttpReceipt::result($id,$kind==='429'?429:200,''); }
            }
            return ['status'=>'completed'];
        });
        $pdo->prepare('UPDATE system_execution_runs SET component_key=?,finished_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ? DAY),started_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL ? DAY) WHERE run_token=?')->execute([$component,$days,$days,$result['cron_cycle_id']]);
        return ['cycle'=>$result['cron_cycle_id'],'id'=>(int)$pdo->query('SELECT MAX(id) FROM system_execution_runs')->fetchColumn()];
    };
    $old=$seed(4,180);
    $empty=$seed(0,180);
    $other=$seed(1,180,'known','manual_test');
    $recent=$seed(1,2);
    $unknown=$seed(1,2,'unknown');
    $pause=$seed(1,2,'429');
    $crash=$seed(1,180,'unknown');
    $pdo->prepare("UPDATE system_execution_runs SET status='running',finished_at=NULL WHERE id=?")->execute([$crash['id']]);
    $protectedSnapshots=[];
    foreach([$other,$recent,$unknown,$pause,$crash] as $protected) {
        $protectedSnapshots[$protected['id']]=[
            ColdArchiveService::rowHash($pdo->query('SELECT * FROM system_execution_runs WHERE id='.$protected['id'])->fetch(PDO::FETCH_ASSOC)),
            ColdArchiveService::rowHash($pdo->query('SELECT * FROM system_execution_attempts WHERE system_execution_run_id='.$protected['id'])->fetch(PDO::FETCH_ASSOC)),
        ];
    }
    QueueV4CleanCycleBudget::start(10);
    $permitsBefore=(int)$pdo->query('SELECT COUNT(*) FROM api_remote_permits')->fetchColumn();
    k1b_assert($policy->deleteEligible('outer_http_attempts',2)===0,'R4 no delete before archive');
    echo "R4 archive_first=PASS\n";
    $month=substr((string)$pdo->query('SELECT finished_at FROM system_execution_runs WHERE id='.$old['id'])->fetchColumn(),0,7);
    foreach(['outer_http_attempts','outer_http_runs'] as $dataset) {
        $done=false;
        for($i=0;$i<100;$i++) {
            $step=$policy->runDatasetStep($dataset,2);
            k1b_assert(($step['archive_rows']??0)<=2 && $step['deleted']<=2,'R10 bounded batch');
            if($step['stage']==='rollup') { $done=true; }
            if($dataset==='outer_http_attempts' && $done && $step['stage']==='delete') { break; }
            if($dataset==='outer_http_runs') {
                $a=(new ColdArchiveService())->find($dataset,$month);
                if($a!==null && $a['rollup_verified_at']!==null) { break; }
            }
        }
    }
    // The child dataset can remove at most two; the parent must still be blocked.
    k1b_assert((int)$pdo->query('SELECT COUNT(*) FROM system_execution_runs WHERE id='.$old['id'])->fetchColumn()===1,'R6 parent not cascaded');
    k1b_assert((int)$pdo->query('SELECT COUNT(*) FROM system_execution_attempts WHERE system_execution_run_id='.$old['id'])->fetchColumn()===2,'R10 actual rows removed bounded');
    k1b_assert(OuterCronHttpReceipt::read($pdo,$old['cycle'])['physical_http_total']===4,'R6 aggregate survives partial detail pruning');
    echo "R6 parent_child_order_and_receipt=PASS\n";
    $archive=(new ColdArchiveService())->find('outer_http_attempts',$month);
    k1b_assert($archive['status']==='ready' && $archive['verified_at']!==null && $archive['membership_verified_at']!==null && $archive['rollup_verified_at']!==null,'R5 archive verified');
    // Corrupt one membership; it must be retained, not silently cascaded away.
    $remaining=(int)$pdo->query('SELECT MIN(id) FROM system_execution_attempts WHERE system_execution_run_id='.$old['id'])->fetchColumn();
    $pdo->exec("UPDATE system_cold_archive_memberships SET row_sha256=REPEAT('0',64) WHERE source_table='system_execution_attempts' AND source_id=".$remaining);
    $policy->deleteEligible('outer_http_attempts',2);
    k1b_assert((int)$pdo->query('SELECT COUNT(*) FROM system_execution_attempts WHERE id='.$remaining)->fetchColumn()===1,'R5 checksum mismatch retained');
    k1b_assert((int)$pdo->query('SELECT COUNT(*) FROM system_execution_runs WHERE id='.$old['id'])->fetchColumn()===1,'R6 parent with changed child retained');
    echo "R5 checksum_and_membership=PASS\n";
    // Separate eligible parent for lease-abort and successful terminal cleanup.
    $calls=0;
    try { $policy->deleteEligible('outer_http_runs',2,static function () use(&$calls): void { if(++$calls===5) { throw new RuntimeException('synthetic_lease_lost'); } }); }
    catch(RuntimeException $e) { k1b_assert($e->getMessage()==='synthetic_lease_lost','R11 exact fence failure'); }
    k1b_assert((int)$pdo->query('SELECT COUNT(*) FROM system_execution_runs WHERE id='.$empty['id'])->fetchColumn()===1,'R11 lease abort retained');
    k1b_assert($calls===5,'R11 rollback after delete attempted');
    k1b_assert($policy->deleteEligible('outer_http_runs',2)===1,'R6 eligible empty parent removed');
    k1b_assert((int)$pdo->query('SELECT COUNT(*) FROM system_execution_runs WHERE id='.$empty['id'])->fetchColumn()===0,'R6 parent removal verified');
    // Restore only the synthetic archived child fingerprint; then exercise the
    // successful child -> parent path, preserving the source bytes themselves.
    $row=$pdo->query('SELECT * FROM system_execution_attempts WHERE id='.$remaining)->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare("UPDATE system_cold_archive_memberships SET row_sha256=?,stale_at=NULL,verification_error=NULL WHERE source_table='system_execution_attempts' AND source_id=?")->execute([ColdArchiveService::rowHash($row),$remaining]);
    k1b_assert($policy->deleteEligible('outer_http_attempts',2)===1,'R6 remaining verified child removed');
    k1b_assert($policy->deleteEligible('outer_http_runs',2)===1,'R6 fully archived parent removed');
    echo "R11 lease_fence=PASS\n";
    foreach([$other,$recent,$unknown,$pause,$crash] as $protected) {
        k1b_assert((int)$pdo->query('SELECT COUNT(*) FROM system_execution_runs WHERE id='.$protected['id'])->fetchColumn()===1,'protected parent');
        k1b_assert((int)$pdo->query('SELECT COUNT(*) FROM system_execution_attempts WHERE system_execution_run_id='.$protected['id'])->fetchColumn()===1,'protected child');
        k1b_assert($protectedSnapshots[$protected['id']] === [
            ColdArchiveService::rowHash($pdo->query('SELECT * FROM system_execution_runs WHERE id='.$protected['id'])->fetch(PDO::FETCH_ASSOC)),
            ColdArchiveService::rowHash($pdo->query('SELECT * FROM system_execution_attempts WHERE system_execution_run_id='.$protected['id'])->fetch(PDO::FETCH_ASSOC)),
        ],'protected rows byte fingerprints unchanged');
    }
    echo "R2 other_components=PASS\nR3 recent_rows=PASS\nR8 unknown_crash_evidence=PASS\nR9 recent_429=PASS\nR10 bounded_batch=PASS\n";
    k1b_assert((int)$pdo->query('SELECT COUNT(*) FROM system_execution_attempts a LEFT JOIN system_execution_runs r ON r.id=a.system_execution_run_id WHERE r.id IS NULL')->fetchColumn()===0,'R7 no orphans');
    k1b_assert(QueueV4CleanCycleBudget::remaining()===10,'R12 HTTP budget untouched');
    k1b_assert((int)$pdo->query('SELECT COUNT(*) FROM api_remote_permits')->fetchColumn()===$permitsBefore,'R12 no rhythm permits');
    QueueV4CleanCycleBudget::clear();
    echo "R7 no_orphans=PASS\nR12 budget_and_permits_unchanged REAL_MELI_HTTP=0 REAL_OAUTH=0\n";
} finally { $db->cleanup(); }
