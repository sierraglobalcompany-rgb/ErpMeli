<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\CampaignExecutionWindowPolicyService;
use App\Services\ExecutionJournalService;
use App\Services\ManualCampaignService;
use App\Services\ResumableCampaignWorkerService;

if (trim((string) getenv('ERP_RHYTHM_TEST_DSN')) === '') {
    fwrite(STDERR, "ERROR: ERP_RHYTHM_TEST_DSN es obligatorio.\n");
    exit(2);
}

putenv('DB_HOST=' . (string) getenv('ERP_RHYTHM_TEST_HOST'));
putenv('DB_PORT=' . (string) getenv('ERP_RHYTHM_TEST_PORT'));
putenv('DB_NAME=' . (string) getenv('ERP_RHYTHM_TEST_DB'));
putenv('DB_USER=' . (string) getenv('ERP_RHYTHM_TEST_USER'));
putenv('DB_PASS=' . (string) getenv('ERP_RHYTHM_TEST_PASS'));
putenv('APP_ENV=local');
putenv('ML_WRITE_ENABLED=false');

require dirname(__DIR__) . '/vendor/autoload.php';

function campaignAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = Database::connectionFresh();
$account = $pdo->query('SELECT id,company_id FROM meli_accounts ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
$userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$accountId = (int) ($account['id'] ?? 0);
$companyId = (int) ($account['company_id'] ?? 0);
campaignAssert($accountId > 0 && $companyId > 0 && $userId > 0, 'El clon debe contener usuario, empresa y cuenta.');

$suffix = bin2hex(random_bytes(5));
$token = hash('sha1', 'qa-22823-' . $suffix);
$slowOperation = 'qa_slow_' . $suffix;
$fastOperation = 'qa_fast_' . $suffix;
$campaignId = 0;
$journalRunId = 0;
$sourceJobIds = [];

try {
    $orderId = (int) $pdo->query(
        'SELECT id FROM meli_orders WHERE meli_account_id=' . $accountId . ' ORDER BY id LIMIT 1'
    )->fetchColumn();
    campaignAssert($orderId > 0, 'El clon debe contener una orden de la cuenta de prueba.');
    $sourceJob = $pdo->prepare(
        'INSERT INTO order_resource_enrichment_jobs
         (meli_account_id,meli_order_id,resource_type,external_resource_id,status,next_run_at)
         VALUES (?, ?, "shipment", ?, "pending", UTC_TIMESTAMP())'
    );
    $sourceJob->execute([$accountId, $orderId, 'QA-SLOW-' . $suffix]);
    $slowSourceId = (int) $pdo->lastInsertId();
    $sourceJobIds[] = $slowSourceId;
    $sourceJob->execute([$accountId, $orderId, 'QA-FAST-' . $suffix]);
    $fastSourceId = (int) $pdo->lastInsertId();
    $sourceJobIds[] = $fastSourceId;

    $insertCampaign = $pdo->prepare(
        'INSERT INTO manual_campaigns
         (campaign_token,created_by_user_id,company_scope_key,scope_key,execution_mode,status,
          configuration_json,total_items,total_units,observed_safe_window_ms,next_action_at)
         VALUES (?, ?, ?, "company", "directed_cli", "active", "{}", 2, 2, 20000, UTC_TIMESTAMP(3))'
    );
    $insertCampaign->execute([$token, $userId, $companyId]);
    $campaignId = (int) $pdo->lastInsertId();

    $insertOperation = $pdo->prepare(
        'INSERT INTO manual_campaign_operations
         (manual_campaign_id,queue_key,operation_key,meli_account_id,company_id,item_count,
          estimated_primary_calls,block_size,exact_adapter)
         VALUES (?, "order_enrichment", ?, ?, ?, 1, 1, 1, 1)'
    );
    $insertOperation->execute([$campaignId, $slowOperation, $accountId, $companyId]);
    $slowOperationId = (int) $pdo->lastInsertId();
    $insertOperation->execute([$campaignId, $fastOperation, $accountId, $companyId]);
    $fastOperationId = (int) $pdo->lastInsertId();

    $insertItem = $pdo->prepare(
        'INSERT INTO manual_campaign_items
         (manual_campaign_id,operation_id,queue_key,operation_key,source_id,meli_account_id,
          company_id,human_label,status,position_no,total_units)
         VALUES (?, ?, "order_enrichment", ?, ?, ?, ?, ?, "pending", ?, 1)'
    );
    $insertItem->execute([$campaignId, $slowOperationId, $slowOperation, (string) $slowSourceId, $accountId, $companyId, 'Lento QA', 1]);
    $slowItemId = (int) $pdo->lastInsertId();
    $insertItem->execute([$campaignId, $fastOperationId, $fastOperation, (string) $fastSourceId, $accountId, $companyId, 'Rápido QA', 2]);
    $fastItemId = (int) $pdo->lastInsertId();

    $metric = $pdo->prepare(
        'INSERT INTO api_operation_metric_samples
         (bucket_started_at,account_scope_key,meli_account_id,operation_key,load_class,duration_ms,
          reached_remote,successful,created_at)
         VALUES (UTC_TIMESTAMP(), ?, ?, ?, "normal", ?, 1, 1, UTC_TIMESTAMP())'
    );
    $metric->execute([$accountId, $accountId, $slowOperation, 13000]);
    $metric->execute([$accountId, $accountId, $fastOperation, 500]);

    $worker = new ResumableCampaignWorkerService();
    $method = new ReflectionMethod($worker, 'nextFittingCandidate');
    $candidate = $method->invoke(
        $worker,
        $campaignId,
        5000,
        new CampaignExecutionWindowPolicyService()
    );
    campaignAssert((int) ($candidate['id'] ?? 0) === $fastItemId,
        'El preview exacto debe saltar el primer ítem lento y elegir el rápido que sí cabe.');

    $claimed = (new ManualCampaignService())->claimExact('qa-worker-' . $suffix, $campaignId, $fastItemId);
    campaignAssert((int) ($claimed['id'] ?? 0) === $fastItemId, 'claimExact debe reclamar únicamente el candidato elegido.');
    $states = $pdo->query(
        'SELECT id,status,attempts FROM manual_campaign_items WHERE manual_campaign_id=' . $campaignId . ' ORDER BY position_no'
    )->fetchAll(PDO::FETCH_ASSOC);
    campaignAssert((int) ($states[0]['id'] ?? 0) === $slowItemId
        && (string) ($states[0]['status'] ?? '') === 'pending'
        && (int) ($states[0]['attempts'] ?? -1) === 0,
        'El ítem lento no debe adquirir lease, intento ni progreso falso.');
    campaignAssert((int) ($states[1]['id'] ?? 0) === $fastItemId
        && (string) ($states[1]['status'] ?? '') === 'running'
        && (int) ($states[1]['attempts'] ?? 0) === 1,
        'El ítem rápido exacto debe ser el único reclamado.');

    $journal = new ExecutionJournalService();
    $run = $journal->begin('manual_campaign', $campaignId, 5000);
    $journalRunId = (int) $run['id'];
    $attemptId = $journal->reserve($journalRunId, $claimed);
    $journal->localStarted($attemptId);
    $journal->budgetReserved($attemptId);
    $generation = (int) $claimed['lease_generation'];
    campaignAssert($journal->dispatchStarted($attemptId, $generation), 'El journal exacto debe cercar el dispatch por generación.');
    campaignAssert(!$journal->dispatchCancelledBeforeRemote($attemptId, $generation + 1),
        'Una generación ajena no puede revertir la frontera remota.');
    campaignAssert($journal->dispatchCancelledBeforeRemote($attemptId, $generation),
        'El mismo propietario lógico debe revertir el journal cuando HTTP no inició.');
    $attempt = $pdo->query('SELECT state,reached_remote,dispatched_at FROM system_execution_attempts WHERE id=' . $attemptId)
        ->fetch(PDO::FETCH_ASSOC) ?: [];
    campaignAssert((string) ($attempt['state'] ?? '') === 'budget_reserved'
        && (int) ($attempt['reached_remote'] ?? 1) === 0
        && ($attempt['dispatched_at'] ?? null) === null,
        'La reversión debe dejar el intento reanudable y auditar reached_remote=0.');

    echo "PASS campaign_exact_claim_mysql_integration_22823\n";
} finally {
    if ($journalRunId > 0) {
        $pdo->prepare('DELETE FROM system_execution_attempts WHERE system_execution_run_id=?')->execute([$journalRunId]);
        $pdo->prepare('DELETE FROM system_execution_runs WHERE id=?')->execute([$journalRunId]);
    }
    if ($campaignId > 0) {
        $pdo->prepare('DELETE FROM manual_campaigns WHERE id=?')->execute([$campaignId]);
    }
    if ($sourceJobIds !== []) {
        $pdo->prepare(
            'DELETE FROM order_resource_enrichment_jobs WHERE id IN ('
            . implode(',', array_fill(0, count($sourceJobIds), '?')) . ')'
        )->execute($sourceJobIds);
    }
    $pdo->prepare('DELETE FROM api_operation_metric_samples WHERE operation_key IN (?,?)')
        ->execute([$slowOperation, $fastOperation]);
}
