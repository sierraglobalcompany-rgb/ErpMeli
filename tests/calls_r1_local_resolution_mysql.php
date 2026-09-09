<?php
declare(strict_types=1);

// Diagnostic only: actual manual preview/admission, real isolated SQL, intercepted cURL.
require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';
require __DIR__ . '/calls_true_wire_fixture.php';
require __DIR__ . '/calls_true_seed_fixture.php';

use App\Services\CallsTrueWire;
use App\Services\CapacityPolicyService;
use App\Services\ManualCampaignPreviewService;
use App\Services\ManualSingleStepService;

function r1LocalSnapshot(PDO $pdo, array $source): array
{
    $statement = $pdo->prepare('SELECT id,status,attempts,input_version,lease_generation,
        (lock_owner IS NOT NULL) AS owner_present,lease_expires_at,heartbeat_at,next_run_at,
        (lease_expires_at>UTC_TIMESTAMP()) AS lease_live
        FROM sale_financial_reconciliation_jobs
        WHERE id=? AND company_id=9001 AND meli_account_id=9011');
    $statement->execute([$source['source']]);
    $state = ['source' => $statement->fetch(PDO::FETCH_ASSOC)];
    $statement = $pdo->prepare('SELECT id,job_type,resource_id,state,attempt_count,lease_generation,
        (lease_owner IS NOT NULL) AS owner_present,lease_expires_at,available_at,last_error_class
        FROM queue_v4_clean_jobs WHERE company_id=9001 AND meli_account_id=9011 ORDER BY id');
    $statement->execute();
    $state['queue'] = $statement->fetchAll(PDO::FETCH_ASSOC);
    $statement = $pdo->prepare('SELECT id,job_id,lease_generation,outcome,error_class,dispatch_state,
        physical_http_calls,physical_started_at,http_status,response_known_at,source_closed_at,finished_at
        FROM queue_v4_clean_attempts WHERE company_id=9001 AND meli_account_id=9011 ORDER BY id');
    $statement->execute();
    $state['attempts'] = $statement->fetchAll(PDO::FETCH_ASSOC);
    $statement = $pdo->prepare('SELECT id,sale_key,status,input_version,lease_generation FROM sale_financial_reconciliation_jobs
        WHERE company_id=9001 AND meli_account_id=9011 ORDER BY id');
    $statement->execute();
    $state['all_sources'] = $statement->fetchAll(PDO::FETCH_ASSOC);
    $statement = $pdo->prepare('SELECT COUNT(*) FROM queue_v4_clean_transport_events WHERE company_id=9001 AND meli_account_id=9011');
    $statement->execute();
    $state['transport_events'] = (int) $statement->fetchColumn();
    return $state;
}

true_seed_assert(PHP_SAPI === 'cli', 'CLI_ONLY');
$options = getopt('', ['template:', 'scenario:']);
$scenario = (string) ($options['scenario'] ?? 'input_changed');
true_seed_assert(in_array($scenario, ['input_changed', 'official_complete'], true), 'KNOWN_LOCAL_SCENARIO');
$root = rtrim(str_replace('\\', '/', (string) (getenv('CALLS_R1_QA_ROOT') ?: '')), '/');
true_seed_assert(str_starts_with($root, 'D:/Codex/') && !in_array('..', explode('/', $root), true), 'EXPLICIT_LOCAL_QA_ROOT_REQUIRED');
$dir = $root . '/local-resolution-' . $scenario . '-' . bin2hex(random_bytes(5));
true_seed_assert(mkdir($dir, 0770, true), 'LOCAL_DIAGNOSTIC_DIRECTORY');
$database = 'erp_meli_k1d_test_r1_local_' . bin2hex(random_bytes(8));
foreach (['APP_ENV' => 'test', 'ML_WRITE_ENABLED' => 'false', 'DB_NAME' => $database,
    'APP_KEY' => 'synthetic-r1-local-only', 'PRIVATE_STORAGE_PATH' => $dir . '/private',
    'MELI_API_BASE' => 'https://calls-wire.invalid'] as $name => $value) {
    putenv($name . '=' . $value);
}
K1dSafeTestDatabase::assertGuard('test', 'false', (string) getenv('DB_HOST'), $database);
if (!defined('ERP_INSTALLATION_ROOT')) {
    define('ERP_INSTALLATION_ROOT', $dir . '/install');
}
true_seed_assert(mkdir(ERP_INSTALLATION_ROOT, 0770, true), 'LOCAL_INSTALL_DIRECTORY');
$ledger = ['owner' => 'calls_r1_local_resolution_mysql.php', 'scenario' => $scenario,
    'pid' => getmypid(), 'db_name' => $database, 'db_host' => (string) getenv('DB_HOST'),
    'db_port' => (string) (getenv('DB_PORT') ?: '3306'), 'schema_target' => 301,
    'state' => 'OWNERSHIP_RECORDED_BEFORE_CREATE', 'real_meli_http' => 0];
true_seed_assert(file_put_contents($dir . '/ownership.json', json_encode($ledger, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) !== false,
    'PRECREATE_OWNERSHIP_DURABLE');
echo 'OWNERSHIP=' . $dir . '/ownership.json' . PHP_EOL;
$harness = null;
$exit = 0;
try {
    $harness = K1dSafeTestDatabase::createFromEnvironment();
    $pdo = $harness->pdo();
    $ledger['database_version'] = $pdo->query('SELECT VERSION()')->fetchColumn();
    if (isset($options['template'])) {
        $template = realpath((string) $options['template']);
        true_seed_assert($template !== false && str_starts_with(str_replace('\\', '/', $template), 'D:/Codex/'), 'LOCAL_SCHEMA_TEMPLATE_REQUIRED');
        foreach (json_decode((string) file_get_contents($template), true, 512, JSON_THROW_ON_ERROR) as $sql) {
            $pdo->exec($sql);
        }
    } else {
        (new App\Services\Migrator($pdo, __DIR__ . '/../database/migrations'))->run(301);
    }
    true_seed_scope($pdo, 1);
    $source = true_seed_source($pdo, 901, true, 1);
    $ledger['confirmed_source'] = $source;
    $initial = r1LocalSnapshot($pdo, $source);
    true_seed_assert($initial['source']['status'] === 'pending' && (int) $initial['source']['owner_present'] === 0
        && count($initial['queue']) === 1 && $initial['queue'][0]['state'] === 'ready', 'PENDING_SOURCE_READY_POINTER');
    CallsTrueWire::$ledger = $dir . '/wire.jsonl';
    CallsTrueWire::$respond = static function (array $entry): array {
        throw new RuntimeException('LOCAL_RESOLUTION_MUST_NOT_REACH_WIRE');
    };
    $capacity = (new CapacityPolicyService())->snapshot('manual');
    $preview = (new ManualCampaignPreviewService())->create(9007, [
        'scope' => 'available_queue', 'account_id' => 9011, 'physical_api_call_budget' => 1,
        'capacity_revision' => $capacity['revision'],
    ]);
    $shown = array_map(static fn(array $row): int => (int) $row['queue_job_id'], $preview['rows']);
    true_seed_assert($shown === $source['queue'] && count(CallsTrueWire::$entries) === 0, 'REAL_MANUAL_PREVIEW_EXACT_SOURCE_ZERO_WIRE');
    $ledger['preview_queue_ids'] = $shown;
    $ledger['source_selection_version'] = $preview['rows'][0]['source_selection_version'] ?? null;
    if ($scenario === 'input_changed') {
        $statement = $pdo->prepare('UPDATE meli_orders o JOIN meli_accounts a ON a.id=o.meli_account_id
            SET o.paid_amount=101 WHERE a.company_id=9001 AND o.meli_account_id=9011 AND o.external_order_id=?');
        $statement->execute([$source['orders'][0]]);
    } else {
        // Alternative local-only branch: current input already has an official terminal result.
        $statement = $pdo->prepare("UPDATE sale_financial_state SET official_status='complete',official_net_amount=90
            WHERE company_id=9001 AND meli_account_id=9011 AND sale_key=?");
        $statement->execute([$source['saleKey']]);
    }
    true_seed_assert($statement->rowCount() === 1, 'LOCAL_INPUT_MUTATION_APPLIED');
    $ledger['before_execution'] = r1LocalSnapshot($pdo, $source);
    CallsTrueWire::$execution = 1;
    $result = null;
    $executionError = null;
    try {
        $result = (new ManualSingleStepService())->executePreview($preview['preview_token'], 9007, 1);
    } catch (Throwable $error) {
        $executionError = ['type' => get_class($error), 'line' => $error->getLine(), 'file' => basename($error->getFile())];
    }
    $after = r1LocalSnapshot($pdo, $source);
    $ledger['after_execution'] = $after;
    $ledger['execution_error'] = $executionError;
    // Deliberately omit preview tokens, owner strings, credentials and arbitrary exception messages.
    $ledger['receipt'] = $result === null ? null : array_intersect_key($result, array_flip([
        'status', 'stop_reason', 'selected_count', 'processed_count', 'completed_count', 'waiting_count',
        'review_error_count', 'not_processed_count', 'stale_or_busy_skipped', 'physical_http_calls',
        'physical_http_calls_certainty', 'known_physical_calls', 'api_calls_used', 'background_continuation',
    ]));
    $ledger['wire_count'] = count(CallsTrueWire::$entries);
    $ledger['wire_violations'] = CallsTrueWire::$violations;
    $before = $ledger['before_execution'];
    $omitted = $after['source'] === $before['source'] && $after['queue'] === $before['queue']
        && $after['attempts'] === $before['attempts'];
    $terminal = $after['source']['status'] === 'complete' && (int) $after['source']['owner_present'] === 0
        && $after['source']['lease_expires_at'] === null && count($after['queue']) === 1
        && $after['queue'][0]['state'] === 'completed' && (int) $after['queue'][0]['owner_present'] === 0
        && $after['queue'][0]['lease_expires_at'] === null;
    $live = $after['source']['status'] === 'running' && (int) $after['source']['owner_present'] === 1
        && (int) $after['source']['lease_live'] === 1;
    $ledger['classification'] = $omitted ? 'OMITTED_BEFORE_SOURCE_CLAIM'
        : ($terminal ? 'LOCAL_TERMINAL_NO_LEASE' : ($live ? 'SUSPECT_SOURCE_RUNNING_WITH_LIVE_LEASE' : 'UNEXPECTED_LOCAL_DISPOSITION'));
    echo 'LOCAL_CLASSIFICATION=' . $ledger['classification'] . PHP_EOL;
    true_seed_assert(count(CallsTrueWire::$entries) === 0 && CallsTrueWire::$violations === []
        && $after['transport_events'] === 0, 'LOCAL_EXECUTION_ZERO_WIRE');
    true_seed_assert(count($after['all_sources']) === 1 && count($after['queue']) === 1
        && $after['all_sources'][0]['id'] === $before['all_sources'][0]['id'], 'NO_SUCCESSOR_OR_UNCONFIRMED_SCOPE');
    true_seed_assert($executionError === null, 'MANUAL_ADMISSION_COMPLETED');
    true_seed_assert($omitted || $terminal, 'MANUAL_LOCAL_RESOLUTION_MUST_OMIT_OR_FINISH_WITHOUT_LIVE_LEASE');
    $ledger['state'] = 'PASS';
} catch (Throwable $error) {
    $exit = 1;
    $ledger['state'] = 'FAIL';
    $ledger['failure'] = ['type' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine()];
    // Only our fixed invariant labels can enter the public diagnostic.
    if (str_starts_with($error->getMessage(), 'INVARIANT:')) {
        $ledger['failure']['invariant'] = $error->getMessage();
    }
} finally {
    file_put_contents($dir . '/result.json', json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
    echo 'LOCAL_RESULT=' . $dir . '/result.json' . PHP_EOL;
    if ($harness !== null) {
        $harness->cleanup();
    }
}
exit($exit);
