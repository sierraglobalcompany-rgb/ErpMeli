<?php
declare(strict_types=1);

require __DIR__ . '/cap2_manual_fixture.php';

use App\QueueV4Clean\QueueV4CleanCycleBudget;
use App\QueueV4Clean\QueueV4CleanRepository;
use App\QueueV4Clean\QueueV4CleanWorker;
use App\Services\Cap2DomainsWire;
use App\Services\SaleFinancialStateService;

$assert = static function (bool $condition, string $label, array $context = []): void {
    if (!$condition) {
        throw new RuntimeException('FAIL:' . $label . ':' . json_encode($context, JSON_UNESCAPED_SLASHES));
    }
    echo 'PASS:' . $label . PHP_EOL;
};

$harness = cap2_manual_database();
try {
    $pdo = $harness->pdo();
    $repo = new QueueV4CleanRepository($pdo);
    $settings = new App\Services\AppSettingsService();
    $settings->set('automation.max_api_calls_per_cycle', '1', 'calls-final-fifo');
    $settings->set('api.rhythm.pause_ms', '0', 'calls-final-fifo');
    $settings->set('api.rhythm.burst_size', '100', 'calls-final-fifo');
    App\Services\AppSettingsService::clearCache();

    $seed = static function (int $externalOrderId) use ($pdo): array {
        $pdo->prepare(
            "INSERT INTO meli_orders
             (meli_account_id,external_order_id,status,paid_amount,currency_id,synced_at)
             VALUES (9011,?,'paid',100,'COP',UTC_TIMESTAMP())"
        )->execute([(string) $externalOrderId]);
        $state = (new SaleFinancialStateService())->projectSale(9001, 9011, 'O:' . $externalOrderId);
        $pdo->prepare(
            "INSERT INTO sale_financial_reconciliation_jobs
             (company_id,meli_account_id,sale_key,external_sale_id,input_version,status,next_run_at)
             VALUES (9001,9011,?,?,?,'pending','2000-01-01')"
        )->execute(['O:' . $externalOrderId, (string) $externalOrderId, $state['input_version']]);
        $sourceId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO queue_v4_clean_jobs
             (company_id,meli_account_id,job_type,resource_id,idempotency_key,state,available_at,payload_json)
             VALUES (9001,9011,'domain_exact',?,?,'ready','2000-01-01',?)"
        )->execute([
            (string) $sourceId,
            'calls-final-fifo-' . $sourceId,
            json_encode(['capability' => 'financial_reconciliation', 'source_id' => $sourceId], JSON_THROW_ON_ERROR),
        ]);

        return ['source' => $sourceId, 'queue' => (int) $pdo->lastInsertId(), 'external' => (string) $externalOrderId];
    };

    $rows = [];
    for ($i = 0; $i < 252; $i++) {
        $rows[] = $seed(940000 + $i);
    }
    $primary = $rows[0];
    $boundary = $rows[1];

    $resetWindow = static function (int $lastIndex, string $boundaryKind) use ($pdo, $rows, $boundary): void {
        $pdo->exec("UPDATE queue_v4_clean_jobs
            SET state='waiting',available_at='2099-01-01',lease_owner=NULL,lease_expires_at=NULL,lease_generation=0,attempt_count=0");
        $pdo->exec("UPDATE queue_v4_clean_jobs
            SET company_id=9001,meli_account_id=9011,job_type='domain_exact'");
        $ids = array_map(static fn (array $row): int => (int) $row['queue'], array_slice($rows, 0, $lastIndex + 1));
        $pdo->exec('UPDATE queue_v4_clean_jobs SET state=\'ready\',available_at=\'2000-01-01\' WHERE id IN (' . implode(',', $ids) . ')');
        if ($boundaryKind === 'nonfinance') {
            $pdo->prepare("UPDATE queue_v4_clean_jobs SET job_type='order_exact' WHERE id=?")->execute([$boundary['queue']]);
        } elseif ($boundaryKind === 'other_tenant') {
            $pdo->prepare("UPDATE queue_v4_clean_jobs SET company_id=9002,meli_account_id=9012 WHERE id=?")->execute([$boundary['queue']]);
        }
    };

    $claimPrimary = static function () use ($repo, $primary): array {
        $owner = 'calls-final-fifo-' . bin2hex(random_bytes(6));
        $runId = $repo->beginRun('test', $owner);
        $job = $repo->claim($runId, $owner);
        if ((int) ($job['id'] ?? 0) !== (int) $primary['queue']) {
            throw new RuntimeException('FAIL:primary_claim_order:' . json_encode(['expected' => $primary['queue'], 'actual' => $job['id'] ?? null]));
        }
        return $job;
    };

    foreach (['nonfinance', 'other_tenant'] as $kind) {
        foreach ([239, 240, 241] as $followers) {
            $resetWindow($followers, $kind);
            $actual = $repo->contiguousFinancialReconciliationSourceIds($claimPrimary(), 60);
            $assert($actual === [(int) $primary['source']], 'fifo_boundary_' . $kind . '_' . $followers, [
                'actual' => $actual,
                'expected' => [(int) $primary['source']],
            ]);
        }
    }

    $resetWindow(20, 'none');
    $pdo->prepare("UPDATE queue_v4_clean_jobs SET job_type='order_exact' WHERE id=?")->execute([$rows[11]['queue']]);
    $actual = $repo->contiguousFinancialReconciliationSourceIds($claimPrimary(), 60);
    $expected = array_map(static fn (array $row): int => (int) $row['source'], array_slice($rows, 0, 11));
    $assert($actual === $expected, 'fifo_valid_prefix_before_boundary_exact', [
        'actual' => $actual,
        'expected' => $expected,
    ]);

    $resetWindow(241, 'nonfinance');
    Cap2DomainsWire::$responses['/billing/integration/group/ML/order/details'] = [200, []];
    Cap2DomainsWire::$calls = [];
    QueueV4CleanCycleBudget::start(1, 'automatic', microtime(true) + 45);
    try {
        $result = (new QueueV4CleanWorker($pdo, $repo))->run('test', 1, 40);
    } finally {
        QueueV4CleanCycleBudget::clear();
    }
    $wireIds = [];
    if (isset(Cap2DomainsWire::$calls[0]['query']['order_ids'])) {
        $wireIds = explode(',', (string) Cap2DomainsWire::$calls[0]['query']['order_ids']);
    }
    $assert($wireIds === [(string) $primary['external']], 'fifo_worker_wire_ids_exact', [
        'wire_ids' => $wireIds,
        'result' => $result,
    ]);
    $boundaryState = (string) $pdo->query('SELECT state FROM queue_v4_clean_jobs WHERE id=' . (int) $boundary['queue'])->fetchColumn();
    $assert($boundaryState === 'ready', 'fifo_worker_boundary_intact', ['state' => $boundaryState]);

    echo "STATUS=PASS CALLS_FINAL_FIFO_BOUNDARY MYSQL=REAL REAL_MELI_HTTP=0\n";
} finally {
    QueueV4CleanCycleBudget::clear();
    $harness->cleanup();
}
