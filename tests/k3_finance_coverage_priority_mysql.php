<?php
declare(strict_types=1);
require __DIR__ . '/r0_h3_bootstrap.php';

use App\QueueV4Clean\QueueV4CleanProducer;
use App\QueueV4Clean\QueueV4CleanRepository;

// Synthetic disposable MariaDB only; run through the same producer method.
function fpAssert(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
}
function fpFinance(PDO $db, array $pack, string $state = 'waiting', array $overrides = []): int
{
    $id = r0h3_insert($db, 'sale_financial_reconciliation_jobs', [
        'company_id' => $pack['company_id'], 'meli_account_id' => $pack['meli_account_id'],
        'sale_key' => 'P:' . $pack['external_pack_id'], 'external_sale_id' => $pack['external_pack_id'],
        'input_version' => hash('sha256', uniqid('synthetic', true)),
    ]);
    r0h3_insert($db, 'queue_v4_clean_jobs', array_replace([
        'company_id' => $pack['company_id'], 'meli_account_id' => $pack['meli_account_id'],
        'job_type' => 'domain_exact', 'resource_id' => (string) $id,
        'idempotency_key' => 'synthetic-finance:' . $id,
        'payload_json' => json_encode(['capability' => 'financial_reconciliation', 'source_id' => $id]),
        'state' => $state, 'available_at' => '2099-01-01 00:00:00',
    ], $overrides));
    return $id;
}
function fpCoverage(PDO $db): ?array
{
    $producer = new QueueV4CleanProducer($db, new QueueV4CleanRepository($db));
    return (new ReflectionMethod($producer, 'createPackExactDiscoverySourceCoverage'))->invoke(
        $producer, ['(a.company_id=? AND p.meli_account_id=?)', '(a.company_id=? AND p.meli_account_id=?)'],
        [7200, 7201, 7200, 7202]
    );
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
$cases = ['financial_first', 'financial_fifo', 'general_fifo', 'completed', 'review', 'dead',
    'foreign_company', 'foreign_account', 'homonym_account', 'payload_precedence', 'resource_fallback',
    'wrong_capability', 'wrong_job_type', 'protected_uncertain', 'protected_safe_get', 'duplicate_pointers', 'real_scheduler_admission'];
if (isset($argv[1])) { $cases = [$argv[1]]; }
$results = []; $failures = 0; $httpLogDelta = 0;
foreach ($cases as $case) {
    $pdo->exec('DELETE FROM sale_financial_reconciliation_jobs');
    $fixture = r0h3_seed($pdo); $h = $fixture['healthy']; $expected = $h[0];
    try {
        switch ($case) {
            case 'financial_first': fpFinance($pdo, $h[8]); $expected = $h[8]; break;
            case 'financial_fifo': fpFinance($pdo, $h[8]); fpFinance($pdo, $h[4]); $expected = $h[4]; break;
            case 'general_fifo': break;
            case 'completed': case 'review': case 'dead': fpFinance($pdo, $h[8], $case); break;
            case 'foreign_company': fpFinance($pdo, $h[8], 'waiting', ['company_id' => 7100]); break;
            case 'foreign_account': fpFinance($pdo, $h[8], 'waiting', ['meli_account_id' => 7202]); break;
            case 'homonym_account':
                $other = $h[8]; $other['meli_account_id'] = 7202; fpFinance($pdo, $other); break;
            case 'payload_precedence':
                $wrong = fpFinance($pdo, $h[0], 'completed');
                fpFinance($pdo, $h[8], 'waiting', ['resource_id' => (string) $wrong]); $expected = $h[8]; break;
            case 'resource_fallback':
                fpFinance($pdo, $h[8], 'waiting', ['payload_json' => json_encode(['capability' => 'financial_reconciliation', 'source_id' => 'invalid'])]);
                $expected = $h[8]; break;
            case 'wrong_capability': fpFinance($pdo, $h[8], 'waiting', ['payload_json' => '{"capability":"order_exact"}']); break;
            case 'wrong_job_type': fpFinance($pdo, $h[8], 'waiting', ['job_type' => 'order_exact']); break;
            case 'protected_uncertain': case 'protected_safe_get':
                fpFinance($pdo, $h[8]);
                r0h3_insert($pdo, 'order_resource_enrichment_jobs', [
                    'meli_account_id' => $h[8]['meli_account_id'], 'meli_order_id' => $h[8]['order_id'],
                    'resource_type' => 'pack', 'external_resource_id' => $h[8]['external_pack_id'], 'status' => 'error',
                    'failure_class' => $case === 'protected_uncertain' ? 'remote_result_uncertain' : 'remote_result_uncertain_safe_get',
                    'next_run_at' => gmdate('Y-m-d H:i:s'),
                ]); break;
            case 'duplicate_pointers':
                $id = fpFinance($pdo, $h[8]);
                r0h3_insert($pdo, 'queue_v4_clean_jobs', ['company_id'=>7200,'meli_account_id'=>7201,
                    'job_type'=>'domain_exact','resource_id'=>(string)$id,'idempotency_key'=>'synthetic-duplicate:'.$id,
                    'payload_json'=>json_encode(['capability'=>'financial_reconciliation','source_id'=>$id]),'state'=>'waiting']);
                $expected = $h[8]; break;
            case 'real_scheduler_admission': fpFinance($pdo, $h[8]); $expected = $h[8]; break;
            default: throw new RuntimeException('unknown_case');
        }
        $before = (int) $pdo->query('SELECT COUNT(*) FROM api_request_logs')->fetchColumn();
        if ($case === 'real_scheduler_admission') {
            $pdo->exec("INSERT INTO queue_v4_clean_checkpoints(producer_key,company_id,meli_account_id,next_due_at,last_job_id)
                VALUES('pack_discovery_fairness',0,0,UTC_TIMESTAMP(3),1) ON DUPLICATE KEY UPDATE last_job_id=1");
            $producer = new QueueV4CleanProducer($pdo, new QueueV4CleanRepository($pdo));
            $created = (new ReflectionMethod($producer, 'schedulePackExactDiscovery'))->invoke($producer,
                [['company_id'=>7100,'meli_account_id'=>7101],['company_id'=>7200,'meli_account_id'=>7201],['company_id'=>7200,'meli_account_id'=>7202]]);
            fpAssert($created === 2, 'real scheduler must respect two-slot bound');
            $pointer = $pdo->prepare("SELECT COUNT(*) FROM queue_v4_clean_jobs q JOIN order_resource_enrichment_jobs s
                ON s.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.source_id')) AS UNSIGNED)
                AND s.meli_account_id=q.meli_account_id
                WHERE q.company_id=? AND q.meli_account_id=? AND s.external_resource_id=?
                AND JSON_UNQUOTE(JSON_EXTRACT(q.payload_json,'$.capability'))='order_enrichment_pack'");
            $pointer->execute([$expected['company_id'],$expected['meli_account_id'],$expected['external_pack_id']]);
            fpAssert((int)$pointer->fetchColumn()===1, 'financial pack was not canonically admitted');
            $selected = ['external_resource_id'=>$expected['external_pack_id']];
        } else {
            $pdo->beginTransaction(); $selected = fpCoverage($pdo);
        }
        fpAssert($selected !== null && $selected['external_resource_id'] === $expected['external_pack_id'],
            $case . ': selected candidate does not match expected financial/FIFO order');
        $delta = (int)$pdo->query('SELECT COUNT(*) FROM api_request_logs')->fetchColumn() - $before;
        $httpLogDelta += $delta;
        fpAssert($delta === 0, 'producer recorded HTTP');
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $results[$case] = 'PASS';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $results[$case] = ['FAIL' => $e->getMessage()]; $failures++;
    }
}
echo json_encode(['cases'=>$results,'failed'=>$failures,'real_mariadb'=>true,'real_producer'=>true,
    'http_log_delta'=>$httpLogDelta], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures === 0 ? 0 : 1);
}
