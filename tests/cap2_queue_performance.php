<?php
declare(strict_types=1);

// Bounded same-data comparison of the actual repository eligibility query.
$root = str_replace('\\', '/', realpath($argv[1] ?? dirname(__DIR__)) ?: '');
$allowed = [str_replace('\\', '/', realpath(dirname(__DIR__)) ?: ''), 'D:/Codex/tmp/erp-meli/cap2-20260905/qa/base'];
if (!in_array($root, $allowed, true)) throw new RuntimeException('explicit_local_source_required');
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) require_once $file;
    }
});
require __DIR__ . '/K1dSafeTestDatabase.php';
require __DIR__ . '/cap2_transport_legacy_fixture.php';
foreach (['APP_ENV'=>'test','ML_WRITE_ENABLED'=>'false','DB_HOST'=>'127.0.0.1','DB_PORT'=>'33079','DB_USER'=>'root','DB_PASS'=>'','DB_NAME'=>'erp_meli_k1d_test_cap2_queue_perf_' . bin2hex(random_bytes(4))] as $key=>$value) putenv($key . '=' . $value);
$db = K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo = $db->pdo();
    $pdo->exec('CREATE TABLE meli_accounts(company_id BIGINT UNSIGNED NOT NULL,id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(company_id,id)) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO meli_accounts VALUES(9001,9011)');
    cap2_transport_install_legacy_journal($pdo);
    $pdo->exec("CREATE TABLE queue_v4_clean_jobs(id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL,meli_account_id BIGINT UNSIGNED NOT NULL,state VARCHAR(20) NOT NULL,available_at DATETIME(3) NOT NULL,KEY ready_due(state,available_at,id)) ENGINE=InnoDB");
    $job = $pdo->prepare("INSERT INTO queue_v4_clean_jobs VALUES(?,9001,9011,'ready',UTC_TIMESTAMP(3))");
    $event = $pdo->prepare("INSERT INTO queue_v4_clean_transport_events(company_id,meli_account_id,source_kind,work_id,lease_generation,request_id,method,endpoint_key,dispatch_state,physical_started_at,response_known_at,http_status) VALUES(9001,9011,'queue',?,1,?,'GET','order_exact','RESPONSE_KNOWN',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),200)");
    $pdo->beginTransaction();
    for ($id=1; $id<=1000; $id++) { $job->execute([$id]); $event->execute([$id,'perf-' . $id]); }
    $pdo->commit();
    $repository = new App\QueueV4Clean\QueueV4CleanRepository($pdo);
    if ($repository->eligibleCount([9011],9011) !== 1000) throw new RuntimeException('known_response_eligibility_changed');
    $samples=[];
    for ($round=0; $round<5; $round++) {
        $before=(int)$pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_ASSOC)['Value'];
        $start=hrtime(true);
        for ($i=0; $i<50; $i++) if ($repository->eligibleCount([9011],9011)!==1000) throw new RuntimeException('eligibility_changed');
        $ms=(hrtime(true)-$start)/1_000_000;
        $after=(int)$pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_ASSOC)['Value'];
        if ($after-$before!==50) throw new RuntimeException('extra_eligibility_round_trips');
        $samples[]=['reads'=>50,'selects'=>$after-$before,'ms'=>round($ms,3)];
    }
    echo json_encode(['status'=>'PASS','source_root'=>$root,'repository_sha256'=>hash_file('sha256',$root.'/app/QueueV4Clean/QueueV4CleanRepository.php'),'jobs'=>1000,'known_events'=>1000,'samples'=>$samples,'scope'=>'eligibility_only_financial_table_absent_not_full_cycle','real_meli_http'=>0],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
} finally { $db->cleanup(); }
