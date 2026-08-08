<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\CronV3;
use App\Services\CronV3ExecutionContext;
use App\Services\CronV3HandlerRegistry;
use App\Services\CronV3RateGate;
use App\Services\CronV3WorkRepository;
use App\Services\CronV3WorkTypeRegistry;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;

$root = dirname(__DIR__);
$workerDsn = (string) getenv('CRON_V3_WORKER_DSN');
$workerArgument = (string) ($_SERVER['argv'][1] ?? '');
if ($workerDsn !== '' && str_starts_with($workerArgument, '--rate-worker=')) {
    $workerIndex = (int) substr($workerArgument, strlen('--rate-worker='));
    $workerPdo = new PDO($workerDsn, (string) getenv('CRON_V3_WORKER_USER'), (string) getenv('CRON_V3_WORKER_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $workerWork = $workerIndex % 2 === 0
        ? WorkEnvelope::create(1, 1, 'oauth_refresh', 'remote', 'account:1', 'generation:1')
        : WorkEnvelope::create(1, 2, 'order_exact', 'remote', 'order:2', 'generation:1');
    $reservation = (new CronV3RateGate($workerPdo))->reserve(
        $workerWork,
        hash('sha256', 'concurrent-owner-' . $workerIndex),
        10,
        60,
    );
    echo $reservation['allowed'] ? '1' : $reservation['reason'];
    exit(0);
}
if ($workerDsn !== '' && $workerArgument === '--claim-worker') {
    $workerPdo = new PDO($workerDsn, (string) getenv('CRON_V3_WORKER_USER'), (string) getenv('CRON_V3_WORKER_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $claim = (new CronV3WorkRepository($workerPdo))->claimOne('local', ['operational_maintenance']);
    echo $claim instanceof WorkEnvelope ? '1' : '0';
    exit(0);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$types = new CronV3WorkTypeRegistry();
$assert($types->admits('oauth_refresh', 'remote'), 'oauth_refresh must be a registered remote work type.');
$assert(!$types->admits('oauth_refresh', 'local'), 'oauth_refresh cannot run in the local lane.');

$work = WorkEnvelope::create(7, 19, 'oauth_refresh', 'remote', 'account:19', 'token-generation:4');
$assert(strlen($work->dedupeKey) === 64 && strlen($work->inputVersion) === 64, 'Work digests must be SHA-256.');

$handlers = new CronV3HandlerRegistry($types);
$handlers->register(
    'oauth_refresh',
    'remote',
    static function (WorkEnvelope $envelope, CronV3ExecutionContext $context): WorkResult {
        $context->logicalRemoteCall(static fn (): string => 'refreshed');
        return WorkResult::completed();
    },
);
$remoteContext = CronV3ExecutionContext::remote(
    static fn (): array => ['allowed' => true, 'retry_at' => null, 'reason' => 'test'],
);
$assert($handlers->execute($work, $remoteContext)->status === 'completed', 'Registered handler did not execute.');
$assert($remoteContext->logicalCallCount() === 1, 'Remote attempt must count one logical call.');
try {
    $remoteContext->logicalRemoteCall(static fn (): string => 'forbidden');
    throw new RuntimeException('A second logical remote call was accepted.');
} catch (LogicException) {
}
try {
    CronV3ExecutionContext::local()->logicalRemoteCall(static fn (): string => 'forbidden');
    throw new RuntimeException('Local lane accepted remote transport.');
} catch (LogicException) {
}

$migration = (string) file_get_contents($root . '/database/migrations/241_cron_v3_engine_2_29_0.sql');
$rateMigration = (string) file_get_contents($root . '/database/migrations/244_cron_v3_rate_scope_authority_2_29_1.sql');
foreach (['cron_v3_work', 'cron_v3_attempts', 'cron_v3_queue_ownership', 'cron_v3_rate_buckets',
          'cron_v3_circuit_states', 'cron_v3_snapshots', "'ready','leased','completed','deferred','review','dead'",
          "('oauth_refresh','remote','disabled',0"] as $needle) {
    $assert(str_contains($migration, $needle), 'Migration is missing Cron V3 contract: ' . $needle);
}
foreach (['jobs/cron_v3_local.php', 'jobs/cron_v3_remote.php'] as $relative) {
    $source = (string) file_get_contents($root . '/' . $relative);
    $assert(str_contains($source, 'CronV3Cli::run'), $relative . ' does not use the safe Cron V3 CLI.');
}
$cli = (string) file_get_contents($root . '/app/Services/CronV3Cli.php');
$assert(str_contains($cli, "'CRON_V3_ENABLED', false"), 'Cron V3 active CLI must be disabled by default.');
$assert(str_contains($cli, "'CRON_V3_SHADOW_ENABLED', false"), 'Cron V3 shadow CLI must be disabled by default.');
$assert(str_contains($cli, '$lane === \'local\' ? 50 : 30'), 'Cron V3 local CLI cap must be 50.');
$runner = (string) file_get_contents($root . '/app/Services/CronV3Runner.php');
$assert(str_contains($runner, 'ApiExecutionMetadataContext::run'), 'Remote runner must enter API execution metadata context.');
$assert(str_contains($runner, "'source' => 'cron_v3_remote'"), 'Remote runner source must be exactly cron_v3_remote.');
$assert(str_contains($runner, "'http_429'") && str_contains($runner, "'http_5xx'"), 'Known 429 and 5xx responses need distinct retry policies.');
$assert(str_contains($runner, 'remoteDispatchCount() - $dispatchesBefore'), 'Real HTTP must use physical dispatch counters.');
$assert(str_contains($migration, 'physical_http_calls') && str_contains($migration, 'known_response_count'), 'Attempt evidence must separate logical and physical HTTP.');
$repositorySource = (string) file_get_contents($root . '/app/Services/CronV3WorkRepository.php');
$assert(str_contains($repositorySource, 'family_consecutive'), 'Cron V3 fairness must persist family debt.');
$assert(str_contains($repositorySource, '? >= 3 AND w.work_family=?'), 'Cron V3 fairness must cap family bursts at three when alternatives exist.');
$enqueuerSource = (string) file_get_contents($root . '/app/Services/CronV3Enqueuer.php');
$assert(str_contains($enqueuerSource, 'FROM meli_accounts WHERE id=? AND company_id=?'), 'Enqueue must verify account/company ownership.');
$rateSource = (string) file_get_contents($root . '/app/Services/CronV3RateGate.php');
$assert(str_contains($rateSource, "hash('sha256', 'cron_v3|global')"), 'Cron V3 must reserve a global rate bucket.');

$mysql = 'skipped';
$dsn = trim((string) getenv('ERP_MIGRATOR_TEST_DSN'));
if ($dsn !== '') {
    $user = (string) getenv('ERP_MIGRATOR_TEST_USER');
    $pass = (string) getenv('ERP_MIGRATOR_TEST_PASS');
    $server = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $database = 'erp_cron_v3_2290_' . bin2hex(random_bytes(5));
    try {
        $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo = new PDO($dsn . ';dbname=' . $database, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('CREATE TABLE app_settings (
            setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NULL,
            is_encrypted TINYINT(1) NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL
        ) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE meli_accounts (
            id BIGINT UNSIGNED PRIMARY KEY,company_id BIGINT UNSIGNED NOT NULL
        ) ENGINE=InnoDB');
        $pdo->exec('INSERT INTO meli_accounts (id,company_id) VALUES (1,1),(2,1),(3,1)');
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $migration) ?: [] as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }

        putenv('MELI_CLIENT_ID=test-application-2291');
        $legacySchemaGate = new CronV3RateGate($pdo);
        $legacySchemaResult = $legacySchemaGate->reserve(
            WorkEnvelope::create(1, 1, 'order_exact', 'remote', 'legacy-schema', 'v1'),
            hash('sha256', 'legacy-schema-owner'),
            10,
            60,
        );
        $assert(!$legacySchemaResult['allowed'] && $legacySchemaResult['reason'] === 'rate_authority_unavailable',
            'Schema 241 without migration 244 did not fail closed.');
        $assert((int) $pdo->query('SELECT COUNT(*) FROM cron_v3_rate_buckets')->fetchColumn() === 0,
            'Fail-closed schema 241 created a legacy global reservation.');

        foreach (preg_split('/;\s*(?:\r?\n|$)/', $rateMigration) ?: [] as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }

        CronV3::resetForTests();
        CronV3::boot($pdo);
        $pdo->exec("UPDATE cron_v3_queue_ownership SET owner_engine='v3',enabled=1
                    WHERE queue_key IN ('financial_recalc','operational_maintenance','oauth_refresh','order_exact')");

        try {
            CronV3::enqueue(WorkEnvelope::create(2, 1, 'financial_recalc', 'local', 'wrong-company', 'v1'));
            throw new RuntimeException('Cron V3 accepted an account from another company.');
        } catch (RuntimeException $error) {
            $assert(str_contains($error->getMessage(), 'does not belong'), 'Unexpected account scope error.');
        }

        $local = WorkEnvelope::create(1, 1, 'financial_recalc', 'local', 'sale:100', 'v1');
        $first = CronV3::enqueue($local);
        $second = CronV3::enqueue($local);
        $assert($first['id'] === $second['id'], 'Cron V3 dedupe created two work ids.');
        $assert((int) $pdo->query('SELECT COUNT(*) FROM cron_v3_work')->fetchColumn() === 1, 'Cron V3 dedupe created two rows.');

        $repository = new CronV3WorkRepository($pdo);
        $oldClaim = $repository->claimOne('local', ['financial_recalc']);
        $assert($oldClaim instanceof WorkEnvelope, 'Cron V3 did not claim ready local work.');
        $repository->beginAttempt($oldClaim);
        $pdo->exec("UPDATE cron_v3_work SET lease_until=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND) WHERE id={$oldClaim->id}");
        $newClaim = $repository->claimOne('local', ['financial_recalc']);
        $assert($newClaim instanceof WorkEnvelope, 'Cron V3 did not recover an expired lease.');
        $assert($newClaim->leaseGeneration === $oldClaim->leaseGeneration + 1, 'Lease generation did not advance.');
        $assert(!$repository->finalize($oldClaim, WorkResult::completed(), 0), 'Expired owner crossed the completion fence.');
        $repository->beginAttempt($newClaim);
        $assert($repository->finalize($newClaim, WorkResult::completed(), 0), 'Current owner could not finalize.');

        CronV3::enqueue(WorkEnvelope::create(1, 1, 'financial_recalc', 'local', 'atomic-fence', 'v1'));
        $atomicClaim = $repository->claimOne('local', ['financial_recalc']);
        $assert($atomicClaim instanceof WorkEnvelope, 'Atomic side-effect test did not claim work.');
        $repository->beginAttempt($atomicClaim);
        try {
            $repository->finalize(
                $atomicClaim,
                WorkResult::completed(),
                0,
                0,
                0,
                static function (): bool {
                    throw new RuntimeException('simulated side-effect failure');
                },
            );
            throw new RuntimeException('Fenced side-effect failure was silently accepted.');
        } catch (RuntimeException $error) {
            $assert(str_contains($error->getMessage(), 'simulated side-effect failure'),
                'Unexpected atomic side-effect error.');
        }
        $atomicStatus = (string) $pdo->query(
            'SELECT status FROM cron_v3_work WHERE id=' . (int) $atomicClaim->id
        )->fetchColumn();
        $assert($atomicStatus === 'leased', 'Side-effect failure did not roll back work finalization.');
        $assert($repository->finalize($atomicClaim, WorkResult::completed(), 0),
            'Atomic side-effect work could not be finalized after rollback.');

        $pdo->exec("UPDATE cron_v3_snapshots SET payload_json='{}' WHERE snapshot_key='fairness:local'");
        for ($index = 1; $index <= 4; $index++) {
            CronV3::enqueue(WorkEnvelope::create(1, 1, 'financial_recalc', 'local', 'fair-finance-' . $index, 'v1'));
        }
        CronV3::enqueue(WorkEnvelope::create(1, 1, 'operational_maintenance', 'local', 'fair-infra-1', 'v1'));
        $families = [];
        for ($index = 0; $index < 5; $index++) {
            $claim = $repository->claimOne('local', ['financial_recalc', 'operational_maintenance']);
            $assert($claim instanceof WorkEnvelope, 'Fairness test did not claim expected work.');
            $families[] = $types->familyFor($claim->workType);
            $repository->beginAttempt($claim);
            $assert($repository->finalize($claim, WorkResult::completed(), 0), 'Fairness claim did not finalize.');
        }
        $longestFamilyRun = 0;
        $currentFamilyRun = 0;
        $previousFamily = null;
        foreach ($families as $family) {
            $currentFamilyRun = $family === $previousFamily ? $currentFamilyRun + 1 : 1;
            $longestFamilyRun = max($longestFamilyRun, $currentFamilyRun);
            $previousFamily = $family;
        }
        $assert($longestFamilyRun <= 3, 'Cron V3 served more than three family items while an alternative existed.');

        $rateWorks = [
            WorkEnvelope::create(1, 1, 'oauth_refresh', 'remote', 'account:1', 'generation:1'),
            WorkEnvelope::create(1, 2, 'order_exact', 'remote', 'order:2', 'generation:1'),
        ];
        $gate = new CronV3RateGate($pdo);
        $allowed = 0;
        for ($index = 0; $index < 100; $index++) {
            if ($gate->reserve($rateWorks[$index % 2], hash('sha256', 'owner-' . $index), 10, 60)['allowed']) {
                $allowed++;
            }
        }
        $assert($allowed === 10, 'One hundred reservations with limit ten did not yield exactly ten permits.');

        $workerDsn = $dsn . ';dbname=' . $database;
        putenv('CRON_V3_WORKER_DSN=' . $workerDsn);
        putenv('CRON_V3_WORKER_USER=' . $user);
        putenv('CRON_V3_WORKER_PASS=' . $pass);
        $runConcurrent = static function (array $arguments): array {
            $processes = [];
            foreach ($arguments as $argument) {
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, __FILE__, $argument],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    dirname(__DIR__),
                );
                if (!is_resource($process)) {
                    throw new RuntimeException('Could not start Cron V3 concurrent test worker.');
                }
                fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            $outputs = [];
            foreach ($processes as [$process, $pipes]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCode = proc_close($process);
                if ($exitCode !== 0) {
                    throw new RuntimeException('Cron V3 concurrent worker failed: ' . trim((string) $stderr));
                }
                $outputs[] = trim((string) $stdout);
            }
            return $outputs;
        };

        $pdo->exec('DELETE FROM cron_v3_rate_buckets');
        $pdo->exec('DELETE FROM cron_v3_circuit_states');
        $rateArguments = [];
        for ($index = 0; $index < 100; $index++) {
            $rateArguments[] = '--rate-worker=' . $index;
        }
        $concurrentPermits = $runConcurrent($rateArguments);
        $concurrentAllowed = count(array_filter($concurrentPermits, static fn (string $value): bool => $value === '1'));
        $assert($concurrentAllowed === 10,
            'Concurrent global rate reservations yielded ' . $concurrentAllowed . ' permits: '
            . json_encode($concurrentPermits, JSON_UNESCAPED_SLASHES));
        $scopeRows = $pdo->query(
            'SELECT scope_level,COUNT(*) bucket_count,SUM(used_count) used_count
             FROM cron_v3_rate_buckets GROUP BY scope_level ORDER BY scope_level'
        )->fetchAll(PDO::FETCH_ASSOC);
        $scopeUsage = [];
        foreach ($scopeRows as $scopeRow) {
            $scopeUsage[(string) $scopeRow['scope_level']] = [
                'buckets' => (int) $scopeRow['bucket_count'],
                'used' => (int) $scopeRow['used_count'],
            ];
        }
        foreach (['global', 'application', 'account', 'endpoint', 'operation'] as $scopeLevel) {
            $assert(isset($scopeUsage[$scopeLevel]), 'Concurrent test did not create scope ' . $scopeLevel . '.');
        }
        $assert(($scopeUsage['global']['used'] ?? -1) === 10, 'Global scope did not record exactly ten permits.');
        $assert(($scopeUsage['application']['used'] ?? -1) === 10, 'Application scope did not record exactly ten permits.');

        $pdo->exec('DELETE FROM cron_v3_rate_buckets');
        $pdo->exec('DELETE FROM cron_v3_circuit_states');
        $circuitWork = WorkEnvelope::create(1, 1, 'order_exact', 'remote', 'circuit-a', 'v1');
        $firstOwner = hash('sha256', 'circuit-owner-a');
        $secondOwner = hash('sha256', 'circuit-owner-b');
        $assert($gate->reserve($circuitWork, $firstOwner, 10, 60)['allowed'], 'First circuit reservation failed.');
        $assert($gate->reserve($circuitWork, $secondOwner, 10, 60)['allowed'], 'Second circuit reservation failed.');
        $assert($gate->recordResult($circuitWork, WorkResult::review('remote_result_uncertain'), $secondOwner),
            'Uncertain result did not open the circuit.');
        $assert($gate->recordResult($circuitWork, WorkResult::completed(), $firstOwner),
            'Concurrent earlier success could not be recorded safely.');
        $circuitState = (string) $pdo->query('SELECT state FROM cron_v3_circuit_states LIMIT 1')->fetchColumn();
        $assert($circuitState === 'open', 'An earlier concurrent success closed an open circuit.');
        $probeOwner = hash('sha256', 'circuit-probe-owner');
        $blocked = $gate->reserve($circuitWork, $probeOwner, 10, 60);
        $assert(!$blocked['allowed'] && $blocked['reason'] === 'circuit_open', 'Open circuit admitted work before cooldown.');
        $pdo->exec('UPDATE cron_v3_circuit_states SET open_until=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 1 SECOND)');
        $assert($gate->reserve($circuitWork, $probeOwner, 10, 60)['allowed'], 'Half-open probe was not admitted.');
        $assert($gate->recordResult($circuitWork, WorkResult::completed(), $probeOwner),
            'Half-open probe success was not recorded.');
        $circuitState = (string) $pdo->query('SELECT state FROM cron_v3_circuit_states LIMIT 1')->fetchColumn();
        $assert($circuitState === 'closed', 'The matching half-open probe did not close the circuit.');

        CronV3::enqueue(WorkEnvelope::create(1, 1, 'operational_maintenance', 'local', 'concurrent-claim', 'v1'));
        $concurrentClaims = $runConcurrent(['--claim-worker', '--claim-worker']);
        $assert(count(array_filter($concurrentClaims, static fn (string $value): bool => $value === '1')) === 1,
            'Two workers claimed the same Cron V3 work item.');
        putenv('CRON_V3_WORKER_DSN');
        putenv('CRON_V3_WORKER_USER');
        putenv('CRON_V3_WORKER_PASS');
        putenv('MELI_CLIENT_ID');
        $mysql = 'passed';
    } finally {
        putenv('CRON_V3_WORKER_DSN');
        putenv('CRON_V3_WORKER_USER');
        putenv('CRON_V3_WORKER_PASS');
        putenv('MELI_CLIENT_ID');
        CronV3::resetForTests();
        try {
            $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
        } catch (Throwable) {
        }
    }
}

echo 'PASS cron_v3_engine_2290 mysql=' . $mysql . PHP_EOL;
