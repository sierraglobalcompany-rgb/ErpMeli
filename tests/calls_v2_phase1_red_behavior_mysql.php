<?php
declare(strict_types=1);

require __DIR__ . '/cap2_manual_fixture.php';

use App\Core\Database;
use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\ApiExecutionMetadataContext;
use App\Services\Cap2DomainsWire;
use App\Services\CronDeadlineContext;
use App\Services\CurlMeliHttpTransport;
use App\Services\SaleFinancialStateService;

final class CallsV2KnownStatusFaultStatement extends PDOStatement {
    protected function __construct() {}
    public function execute(?array $params = null): bool {
        if (str_contains($this->queryString, "UPDATE queue_v4_clean_transport_events\n             SET dispatch_state='RESPONSE_KNOWN'")) {
            throw new PDOException('phase1_known_status_journal_failure');
        }
        return parent::execute($params);
    }
}
final class CallsV2KnownStatusFaultPdo extends PDO {}

$red = static function (bool $condition, string $label): void {
    if ($condition) { echo "PASS={$label}\n"; return; }
    echo "RED={$label}\n";
};
$h = cap2_manual_database();
try {
    $pdo = $h->pdo();
    (new App\Services\AppSettingsService())->set('api.rhythm.pause_ms', '0', 'phase1-red');
    App\Services\AppSettingsService::clearCache();

    // Pack route: an actual P: sale with two member orders reaches the worker
    // and the test-only wire. The frozen target is one ID in that GET.
    $pdo->exec("INSERT INTO meli_packs(meli_account_id,external_pack_id,status,integrity_status,synced_at) VALUES(9011,771001,'paid','complete',UTC_TIMESTAMP())");
    foreach ([771101, 771102] as $id) {
        $pdo->prepare("INSERT INTO meli_orders(meli_account_id,external_order_id,external_pack_id,status,paid_amount,currency_id,synced_at) VALUES(9011,?,771001,'paid',100,'COP',UTC_TIMESTAMP())")->execute([(string)$id]);
    }
    $state = (new SaleFinancialStateService())->projectSale(9001, 9011, 'P:771001');
    $pdo->prepare("INSERT INTO sale_financial_reconciliation_jobs(company_id,meli_account_id,sale_key,external_sale_id,input_version,status,next_run_at) VALUES(9001,9011,'P:771001','771001',?,'pending','2000-01-01')")->execute([$state['input_version']]);
    $source = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,available_at) VALUES(9001,9011,'domain_exact',?,?,'{}','ready','2000-01-01')")->execute([(string)$source, 'phase1-pack']);
    $packQueueId = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE queue_v4_clean_jobs SET payload_json=? WHERE id=?')->execute([json_encode(['capability'=>'financial_reconciliation','source_id'=>$source], JSON_THROW_ON_ERROR), $packQueueId]);
    Cap2DomainsWire::$responses['/billing/integration/group/ML/order/details'] = [200, []];
    QueueV4CleanCycleBudget::start(1, 'automatic', microtime(true) + 45);
    try { (new QueueV4CleanWorker($pdo, new QueueV4CleanRepository($pdo)))->run('test', 1, 40); } finally { QueueV4CleanCycleBudget::clear(); }
    $ids = explode(',', (string)(Cap2DomainsWire::$calls[0]['query']['order_ids'] ?? ''));
    $red(count(Cap2DomainsWire::$calls) === 1 && count($ids) === 1, 'pack_billing_get_has_exactly_one_order_id');

    // F1: create only the durable pre-curl marker, then ask the real receipt.
    $run = (new QueueV4CleanRepository($pdo))->beginRun('test', 'phase1-f1');
    $pdo->prepare("INSERT INTO queue_v4_clean_jobs(company_id,meli_account_id,job_type,resource_id,idempotency_key,payload_json,state,lease_owner,lease_generation,lease_expires_at) VALUES(9001,9011,'order_exact','880001','phase1-f1','{}','running','phase1',1,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))")->execute();
    $job = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO queue_v4_clean_attempts(job_id,run_id,company_id,meli_account_id,lease_owner,lease_generation) VALUES(?,?,9001,9011,\'phase1\',1)')->execute([$job,$run]);
    $attempt = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO queue_v4_clean_transport_events(company_id,meli_account_id,source_kind,work_id,attempt_id,lease_generation,request_id,method,endpoint_key,physical_started_at) VALUES(9001,9011,'queue',?,?,1,'phase1-f1','GET','order_exact',UTC_TIMESTAMP(3))")->execute([$job,$attempt]);
    $receipt = (new QueueV4CleanWorker($pdo, new QueueV4CleanRepository($pdo)))->receiptForRun($run);
    $red(($receipt['physical_http_calls'] ?? null) === null, 'f1_pre_curl_marker_is_unknown_not_exact_physical_call');

    // F2: actual wire returns HTTP 200, then the journal write faults. A
    // preserved known result must remain observable after that local failure.
    $fault = new CallsV2KnownStatusFaultPdo('mysql:host=127.0.0.1;port=33079;dbname='.getenv('DB_NAME').';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $fault->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CallsV2KnownStatusFaultStatement::class]);
    $fault->exec("SET time_zone='+00:00'");
    $meta = ['source'=>'queue_v4_clean','company_id'=>9001,'account_id'=>9011,'transport_request_id'=>'phase1-f2','queue_v4_job_id'=>$job,'queue_v4_attempt_id'=>$attempt,'queue_v4_lease_owner'=>'phase1','queue_v4_lease_generation'=>1];
    Cap2DomainsWire::$responses['/orders/880001'] = [200, ['id'=>880001]];
    Database::setConnection($fault); QueueV4CleanCycleBudget::start(1, 'automatic', microtime(true) + 45); CronDeadlineContext::start(45,40,8,3);
    try { ApiExecutionMetadataContext::run($meta, static fn() => (new CurlMeliHttpTransport())->request('GET','https://cap2-wire.invalid/orders/880001',[],[],false,['timeout'=>5,'connect_timeout'=>2])); } catch (Throwable) {} finally { Database::setConnection($pdo); QueueV4CleanCycleBudget::clear(); CronDeadlineContext::clear(); }
    $known = $pdo->query("SELECT http_status FROM queue_v4_clean_transport_events WHERE request_id='phase1-f2' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $red((int)$known === 200, 'f2_known_http_status_survives_local_journal_failure');

    // Prove viability against the migrated schema, not SQL text. An attempted
    // immutable billing_order_v2 record must be accepted to certify schema 301.
    $type = (string)$pdo->query("SHOW COLUMNS FROM sale_financial_evidence LIKE 'evidence_type'")->fetch(PDO::FETCH_ASSOC)['Type'];
    $red(str_contains($type, "'billing_order_v2'"), 'schema_301_persists_one_order_billing_order_v2_checkpoint_evidence');
} finally { $h->cleanup(); }
