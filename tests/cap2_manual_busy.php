<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';require __DIR__.'/K1dSafeTestDatabase.php';
putenv('APP_ENV=test');putenv('ML_WRITE_ENABLED=false');putenv('DB_HOST=127.0.0.1');putenv('DB_PORT=33079');putenv('DB_USER=root');putenv('DB_PASS=');
putenv('DB_NAME=erp_meli_k1d_test_cap2_manual_busy_'.bin2hex(random_bytes(4)));
$h=K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo=$h->pdo();foreach([280,281,282] as $n)$pdo->exec(file_get_contents(glob(__DIR__.'/../database/migrations/'.$n.'_*.sql')[0]));
    $pdo->exec('CREATE TABLE app_settings(id BIGINT AUTO_INCREMENT PRIMARY KEY,setting_key VARCHAR(190) UNIQUE,setting_value TEXT,is_encrypted TINYINT DEFAULT 0,setting_group VARCHAR(80),updated_at DATETIME)');
    $pdo->exec("INSERT INTO app_settings(setting_key,setting_value) VALUES('manual.api_calls_per_step','3'),('manual.api_calls_ceiling','55')");
    $repo=new App\QueueCore\QueueCoreRepository($pdo);
    $id=$repo->enqueue(new App\QueueCore\QueueJob(1,2,'manual_exact','orders_sync','1','normal',80,'old-busy','v1','manual_web',null,[],[],1,null,'manual'));
    $pdo->exec("UPDATE queue_core_jobs SET state='claimed',lease_expires_at=NULL WHERE id={$id}");
    $item=['company_id'=>1,'account_id'=>2,'queue_key'=>'orders_sync','source_id'=>'2','uses_api'=>false,'input_version'=>'v1','explicit_attempt_key'=>hash('sha256','new')];$admitted=0;
    $leases=new App\QueueCore\QueueExecutionLeaseService($pdo);$registry=new App\QueueCore\QueueHandlerRegistry();$registry->register('manual_exact',new App\QueueCore\ManualExactHandler());
    $core=['repository'=>$repo,'execution_leases'=>$leases,'runner'=>new App\QueueCore\QueueRunner($repo,$registry,$leases)];
    try {(new App\QueueCore\ManualQueueLauncher(static fn()=>$core))->runExactBatch([$item],1,null,static function() use(&$admitted): void {$admitted++;});} catch(App\QueueCore\ManualFifoBusyException) {}
    k1b_assert($admitted===0 && $repo->job($id)['state']==='claimed','unknown_busy_lease_must_not_admit_or_recover');
    $pdo->exec("UPDATE queue_core_jobs SET lease_expires_at=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE id={$id}");
    try {(new App\QueueCore\ManualQueueLauncher(static fn()=>$core))->runExactBatch([$item],1,null,static function(): void {throw new RuntimeException('preview admission rejected');});}
    catch(RuntimeException) {}
    k1b_assert($repo->job($id)['state']==='claimed','recovery_is_an_effect_and_follows_successful_admission');
    $pdo->exec("UPDATE queue_core_jobs SET state='review' WHERE id={$id}");
    // Slow enqueue exhausts an admitted window before the real runner claims.
    $pdo->exec('CREATE TRIGGER cap2_slow_enqueue BEFORE INSERT ON queue_core_jobs FOR EACH ROW DO SLEEP(1.1)');
    $r=(new App\QueueCore\ManualQueueLauncher(static fn()=>$core))->runExactBatch([$item],1,microtime(true)+1);
    k1b_assert($r['processed_count']===0 && $r['not_processed_count']===1 && $r['completed_count']===0,'unclaimed_after_admission_is_not_started:'.json_encode($r));
    echo "STATUS=PASS CAP2_MANUAL_BUSY\n";
} finally {$h->cleanup();}
