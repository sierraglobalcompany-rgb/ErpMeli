<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';
require __DIR__ . '/calls_true_wire_fixture.php';
require __DIR__ . '/calls_true_seed_fixture.php';

use App\Core\Crypto;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\QueueV4Clean\QueueV4CleanScheduler;
use App\Services\AppSettingsService;
use App\Services\CallsTrueWire;
use App\Services\CronDeadlineContext;

function calls_final_concurrency_assert(bool $condition, string $label, array $context = []): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL:' . $label . ':' . json_encode($context, JSON_UNESCAPED_SLASHES));
    }
    echo 'PASS:' . $label . PHP_EOL;
}

function calls_final_concurrency_wait(PDO $pdo, string $column, int $expected, float $seconds = 20.0): void
{
    calls_final_concurrency_assert(in_array($column, ['ready_count', 'go_flag', 'attempted_count', 'finished_count'], true), 'known_barrier_column');
    $deadline = microtime(true) + $seconds;
    do {
        if ((int) $pdo->query('SELECT ' . $column . ' FROM calls_final_concurrency_barrier WHERE id=1')->fetchColumn() >= $expected) {
            return;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('barrier_timeout_' . $column);
}

function calls_final_concurrency_order_payload(int $externalOrderId): array
{
    return [
        'id' => $externalOrderId,
        'status' => 'paid',
        'date_created' => '2026-09-08T00:00:00.000Z',
        'date_closed' => '2026-09-08T00:01:00.000Z',
        'last_updated' => '2026-09-08T00:02:00.000Z',
        'total_amount' => 100,
        'paid_amount' => 100,
        'currency_id' => 'COP',
        'buyer' => ['id' => 970001],
        'seller' => ['id' => 99011],
        'pack_id' => null,
        'order_items' => [[
            'item' => ['id' => 'MCO' . $externalOrderId, 'title' => 'Concurrency order ' . $externalOrderId, 'seller_sku' => 'CONC-' . $externalOrderId],
            'quantity' => 1,
            'unit_price' => 100,
            'sale_fee' => 10,
        ]],
    ];
}

function calls_final_concurrency_seed_scope(PDO $pdo): void
{
    // Reuse the actual three-account certified scope and independent budgets;
    // this does not replace any launcher, handler, authorization or transport.
    true_seed_scope($pdo, 1);
    $pdo->exec('CREATE TABLE calls_final_concurrency_barrier (id INT PRIMARY KEY, ready_count INT NOT NULL DEFAULT 0, go_flag INT NOT NULL DEFAULT 0, attempted_count INT NOT NULL DEFAULT 0, finished_count INT NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE calls_final_concurrency_observer (id INT AUTO_INCREMENT PRIMARY KEY, scenario VARCHAR(20) NOT NULL, pid INT NOT NULL, path VARCHAR(255) NOT NULL, resource_id VARCHAR(80) NULL, request_id VARCHAR(80) NULL, meta_json JSON NULL, created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB');
}

function calls_final_concurrency_seed_one_job(PDO $pdo, int $scenario, int $externalOrderId): int
{
    $pdo->prepare(
        "INSERT INTO queue_v4_clean_jobs
         (company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at)
         VALUES(9001,9011,'order_exact',?,?,'{}','ready','2000-01-01')"
    )->execute([(string) $externalOrderId, 'calls-concurrency-' . $scenario . '-' . $externalOrderId]);

    return (int) $pdo->lastInsertId();
}

function calls_final_concurrency_run_child(): void
{
    $harness = K1dSafeTestDatabase::connectExistingFromEnvironment();
    $pdo = $harness->pdo();
    $scenario = (string) getenv('CALLS_CONCURRENCY_SCENARIO');
    $orderId = (int) getenv('CALLS_CONCURRENCY_ORDER_ID');
    $pdo->exec('UPDATE calls_final_concurrency_barrier SET ready_count=ready_count+1 WHERE id=1');
    calls_final_concurrency_wait($pdo, 'go_flag', 1);

    CallsTrueWire::$ledger=(string)getenv('CALLS_CONCURRENCY_OUTPUT_DIR').'/wire-'.getmypid().'.jsonl';
    CallsTrueWire::$respond = static function (array $last) use ($pdo, $scenario, $orderId): array {
        $meta = \App\Services\ApiExecutionMetadataContext::current();
        $pdo->prepare(
            'INSERT INTO calls_final_concurrency_observer(scenario,pid,path,resource_id,request_id,meta_json)
             VALUES(?,?,?,?,?,?)'
        )->execute([
            $scenario,
            getmypid(),
            (string) ($last['path'] ?? ''),
            basename((string) ($last['path'] ?? '')),
            (string) ($meta['transport_request_id'] ?? ''),
            json_encode(['job_id'=>$meta['queue_v4_job_id']??null,'attempt_id'=>$meta['queue_v4_attempt_id']??null,
                'company_id'=>$meta['company_id']??null,'meli_account_id'=>$meta['account_id']??null], JSON_THROW_ON_ERROR),
        ]);
        calls_final_concurrency_assert(in_array($last['path'],['/orders/'.$orderId,'/orders/'.($orderId+1)],true),'observed_wire_path_supported',$last);
        // Keep the real scheduler/global drainer lease occupied until every
        // contender has returned. This proves overlap, not sequential winners.
        calls_final_concurrency_wait($pdo, 'finished_count', (int)$scenario - 1);
        calls_final_concurrency_assert((int)$pdo->query("SELECT COUNT(*) FROM queue_core_execution_leases
            WHERE lease_key='global' AND launcher='cron_v4' AND owner_token IS NOT NULL
            AND expires_at>UTC_TIMESTAMP(3)")->fetchColumn()===1,'winner_global_lease_live_through_overlap');
        return ['status'=>200,'body'=>calls_final_concurrency_order_payload((int)basename($last['path']))];
    };

    $pdo->exec('UPDATE calls_final_concurrency_barrier SET attempted_count=attempted_count+1 WHERE id=1');
    try {
        $result = (new QueueV4CleanScheduler($pdo))->run(1, 45);
        echo 'CHILD_RESULT=' . json_encode([
            'pid' => getmypid(),
            'status'=>$result['status']??null,
            'claimed' => (int) ($result['worker']['claimed'] ?? 0),
            'completed' => (int) ($result['worker']['completed'] ?? 0),
            'physical_http_calls' => $result['physical_http_calls'] ?? null,
            'physical_http_calls_certainty'=>$result['physical_http_calls_certainty']??null,
            'observed_calls'=>count(CallsTrueWire::$entries),
            'fixture_violations'=>CallsTrueWire::$violations,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } finally {
        $pdo->exec('UPDATE calls_final_concurrency_barrier SET finished_count=finished_count+1 WHERE id=1');
        CallsTrueWire::$respond = null;
        QueueV4CleanCycleBudget::clear();
        CronDeadlineContext::clear();
    }
}

/** @return list<string> */
function calls_final_concurrency_spawn(string $script, int $count, int $scenario, int $orderId): array
{
    $children = [];
    $outputRoot=rtrim((string)getenv('CALLS_CONCURRENCY_OUTPUT_DIR'),'/\\');
    calls_final_concurrency_assert(is_dir($outputRoot),'owned_child_output_directory');
    $env = array_merge($_ENV, [
        'APP_ENV' => 'test',
        'ML_WRITE_ENABLED' => 'false',
        'DB_HOST' => (string) getenv('DB_HOST'),
        'DB_PORT' => (string) getenv('DB_PORT'),
        'DB_NAME' => (string) getenv('DB_NAME'),
        'DB_USER' => (string) getenv('DB_USER'),
        'DB_PASS' => (string) getenv('DB_PASS'),
        'APP_KEY' => (string) getenv('APP_KEY'),
        'ERP_PRIVATE_PATH' => (string) getenv('ERP_PRIVATE_PATH'),
        'CALLS_VERIFY_QA_ROOT' => (string) getenv('CALLS_VERIFY_QA_ROOT'),
        'CALLS_CONCURRENCY_OUTPUT_DIR' => (string) getenv('CALLS_CONCURRENCY_OUTPUT_DIR'),
        'MELI_API_BASE' => (string) getenv('MELI_API_BASE'),
        'CALLS_CONCURRENCY_SCENARIO' => (string) $scenario,
        'CALLS_CONCURRENCY_ORDER_ID' => (string) $orderId,
    ]);
    $outputs = [];
    try {
    for ($i = 0; $i < $count; $i++) {
        $prefix=$outputRoot.'/scenario-'.$scenario.'-child-'.$i;
        $process = proc_open([PHP_BINARY, $script, 'child'], [1 => ['file', $prefix.'.stdout.log', 'w'], 2 => ['file', $prefix.'.stderr.log', 'w']], $pipes, __DIR__, $env);
        calls_final_concurrency_assert(is_resource($process), 'child_process_started');
        $children[] = [$process, $prefix, null];
        $status=proc_get_status($process);
        $children[array_key_last($children)][2]=$status['pid'];
        file_put_contents($prefix.'.process.json',json_encode(['pid'=>$status['pid'],'scenario'=>$scenario,
            'command'=>[PHP_BINARY,$script,'child'],'state'=>'STARTED'],JSON_THROW_ON_ERROR),LOCK_EX);
    }
        $pdo = \App\Core\Database::connectionFresh();
        calls_final_concurrency_wait($pdo, 'ready_count', $count);
        $pdo->exec('UPDATE calls_final_concurrency_barrier SET go_flag=1 WHERE id=1');
        $deadline=microtime(true)+75;
        foreach ($children as [$process, $prefix, $pid]) {
            do{
                $status=proc_get_status($process);
                if(!$status['running'])break;
                if(microtime(true)>$deadline)throw new RuntimeException('child_timeout_incomplete');
                usleep(20000);
            }while(true);
            $exit=$status['exitcode'];
            $closed=proc_close($process);
            if($exit<0)$exit=$closed;
            $stdout=file_get_contents($prefix.'.stdout.log');
            $stderr=file_get_contents($prefix.'.stderr.log');
            file_put_contents($prefix.'.process.json',json_encode(['pid'=>$pid,'scenario'=>$scenario,
                'state'=>'FINISHED','exit_code'=>$exit,'stdout_sha256'=>hash_file('sha256',$prefix.'.stdout.log'),
                'stderr_sha256'=>hash_file('sha256',$prefix.'.stderr.log')],JSON_THROW_ON_ERROR),LOCK_EX);
            calls_final_concurrency_assert($exit === 0 && trim((string) $stderr) === '', 'child_process_clean_exit', ['exit' => $exit, 'stderr' => $stderr]);
            $outputs[] = (string) $stdout;
        }
    }finally{
        foreach($children as [$process])if(is_resource($process)){
            if(proc_get_status($process)['running'])proc_terminate($process);
            proc_close($process);
        }
    }
    return $outputs;
}

if (($argv[1] ?? '') === 'child') {
    calls_final_concurrency_run_child();
    exit(0);
}

$root = rtrim((string)(getenv('CALLS_VERIFY_QA_ROOT')?:'D:/Codex/tmp/erp-meli/calls-20260906/concurrency-physical'),'/\\').'/concurrency-'.bin2hex(random_bytes(4));
if(!is_dir($root))mkdir($root,0770,true);
putenv('CALLS_CONCURRENCY_OUTPUT_DIR='.$root);
foreach ([
    'APP_ENV' => 'test',
    'ML_WRITE_ENABLED' => 'false',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '33079',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    'DB_NAME' => 'erp_meli_k1d_test_calls_concurrency_' . bin2hex(random_bytes(4)),
    'APP_KEY' => 'calls-disposable-test-only',
    'ERP_PRIVATE_PATH' => $root . '/private',
    'MELI_API_BASE' => 'https://calls-wire.invalid',
] as $key => $value) {
    putenv($key . '=' . $value);
}
if (!defined('ERP_INSTALLATION_ROOT')) {
    define('ERP_INSTALLATION_ROOT', $root . '/public_html/erp-meli');
}
if (!is_dir(ERP_INSTALLATION_ROOT)) {
    mkdir(ERP_INSTALLATION_ROOT, 0777, true);
}

$harness = K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo = $harness->pdo();
    $options=getopt('',['template:']);
    if(isset($options['template'])){
        $template=realpath($options['template']);
        calls_final_concurrency_assert($template!==false&&str_starts_with(str_replace('\\','/',$template),'D:/Codex/'),'local_schema_template');
        foreach(json_decode(file_get_contents($template),true,512,JSON_THROW_ON_ERROR)as $sql)$pdo->exec($sql);
    }else{
        (new App\Services\Migrator($pdo, __DIR__ . '/../database/migrations'))->run(301);
    }
    calls_final_concurrency_seed_scope($pdo);

    foreach ([2, 5, 20] as $scenario) {
        $pdo->exec('DELETE FROM calls_final_concurrency_barrier');
        $pdo->exec('INSERT INTO calls_final_concurrency_barrier(id) VALUES(1)');
        $orderId = 970000 + $scenario * 10;
        $queueId = calls_final_concurrency_seed_one_job($pdo, $scenario, $orderId);
        $neighborId = calls_final_concurrency_seed_one_job($pdo, $scenario, $orderId + 1);
        $outputs = calls_final_concurrency_spawn(__FILE__, $scenario, $scenario, $orderId);
        file_put_contents($root.'/scenario-'.$scenario.'.json',json_encode(['launchers'=>$scenario,'outputs'=>$outputs,
            'wire'=>$pdo->query("SELECT * FROM calls_final_concurrency_observer WHERE scenario='{$scenario}' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX);
        $wireRows = (int) $pdo->query("SELECT COUNT(*) FROM calls_final_concurrency_observer WHERE scenario='{$scenario}'")->fetchColumn();
        $distinctPids = (int) $pdo->query("SELECT COUNT(DISTINCT pid) FROM calls_final_concurrency_observer WHERE scenario='{$scenario}'")->fetchColumn();
        $job = $pdo->query('SELECT state,attempt_count FROM queue_v4_clean_jobs WHERE id=' . $queueId)->fetch(PDO::FETCH_ASSOC);
        calls_final_concurrency_assert($wireRows === 1, 'concurrency_' . $scenario . '_single_physical_wire', ['wire_rows' => $wireRows, 'outputs' => $outputs]);
        calls_final_concurrency_assert($distinctPids === 1, 'concurrency_' . $scenario . '_one_process_reached_wire', ['distinct_pids' => $distinctPids, 'outputs' => $outputs]);
        calls_final_concurrency_assert((string) ($job['state'] ?? '') === 'completed', 'concurrency_' . $scenario . '_job_completed_once', $job ?: []);
        calls_final_concurrency_assert((int) ($job['attempt_count'] ?? 0) === 1, 'concurrency_' . $scenario . '_one_claim_attempt', $job ?: []);
        $losers=0;$winners=0;
        foreach($outputs as $output){
            preg_match('/CHILD_RESULT=(.*)/',$output,$match);
            $receipt=json_decode($match[1]??'',true,512,JSON_THROW_ON_ERROR);
            calls_final_concurrency_assert($receipt['fixture_violations']===[],'concurrency_'.$scenario.'_no_swallowed_fixture_failure',$receipt);
            if($receipt['observed_calls']===0){
                calls_final_concurrency_assert(in_array($receipt['status'],['busy','busy_drainer'],true),
                    'concurrency_'.$scenario.'_loser_busy_not_empty_claim',$receipt);
                $losers++;
            }else{
                calls_final_concurrency_assert($receipt['status']==='completed'&&$receipt['claimed']===1
                    &&$receipt['completed']===1&&$receipt['observed_calls']===1&&$receipt['physical_http_calls']===1
                    &&$receipt['physical_http_calls_certainty']==='CERTIFIED','concurrency_'.$scenario.'_winner_exact_receipt',$receipt);
                $winners++;
            }
        }
        calls_final_concurrency_assert($winners===1,'concurrency_'.$scenario.'_one_winner_receipt');
        $observed=$pdo->query("SELECT * FROM calls_final_concurrency_observer WHERE scenario='{$scenario}'")->fetch(PDO::FETCH_ASSOC);
        $correlation=json_decode($observed['meta_json'],true,512,JSON_THROW_ON_ERROR);
        calls_final_concurrency_assert(preg_match('/^[a-f0-9]{40}$/D',$observed['request_id'])===1
            &&(int)$correlation['job_id']===$queueId&&(int)$correlation['attempt_id']>0
            &&(int)$correlation['company_id']===9001&&(int)$correlation['meli_account_id']===9011,
            'concurrency_'.$scenario.'_wire_identity_correlated',$correlation);
        calls_final_concurrency_assert($losers===$scenario-1,'concurrency_'.$scenario.'_all_losers_return_during_live_winner');
        $neighbor=$pdo->query('SELECT state,attempt_count FROM queue_v4_clean_jobs WHERE id='.$neighborId)->fetch(PDO::FETCH_ASSOC);
        calls_final_concurrency_assert($neighbor['state']==='ready'&&(int)$neighbor['attempt_count']===0,
            'concurrency_'.$scenario.'_distinct_eligible_neighbor_unclaimed',$neighbor);
        // End this independent scenario without pretending to process its
        // deliberately unclaimed neighbor; subsequent scenarios have new IDs.
        $pdo->prepare("UPDATE queue_v4_clean_jobs SET state='completed' WHERE id=? AND company_id=9001 AND meli_account_id=9011 AND state='ready'")->execute([$neighborId]);
        echo "CONCURRENCY_{$scenario}_WIRE_ROWS={$wireRows}\n";
    }

    echo "STATUS=PASS CALLS_FINAL_CONCURRENCY_PHYSICAL MYSQL=REAL REAL_MELI_HTTP=0\n";
} finally {
    QueueV4CleanCycleBudget::clear();
    CronDeadlineContext::clear();
    $harness->cleanup();
}
