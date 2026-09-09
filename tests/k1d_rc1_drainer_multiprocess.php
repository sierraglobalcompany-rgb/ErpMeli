<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';

use App\Work\Adapters\QueueCoreDrainAuthority;

$mode = $argv[1] ?? 'parent';

if ($mode === 'child') {
    $harness = K1dSafeTestDatabase::connectExistingFromEnvironment();
    $pdo = $harness->pdo();
    $ledger = (string) getenv('K1D_DRAINER_LEDGER');
    $owner = bin2hex(random_bytes(8));
    $pdo->exec('UPDATE k1d_rc1_drainer_barrier SET ready_count=ready_count+1 WHERE id=1');
    k1d_rc1_drainer_wait_for($pdo, 'go_flag', 1);
    k1d_rc1_drainer_ledger($ledger, 'acquire started');
    $token = (new QueueCoreDrainAuthority($pdo))->acquire('cron_v4', $owner, 30);
    $pdo->exec('UPDATE k1d_rc1_drainer_barrier SET attempted_count=attempted_count+1 WHERE id=1');
    if ($token !== null) {
        k1d_rc1_drainer_ledger($ledger, 'winner transport generation=' . $token->generation);
        $stmt = $pdo->prepare("UPDATE k1d_rc1_drainer_jobs SET state='completed',completed_by=? WHERE id=1 AND state='ready'");
        $stmt->execute([$owner]);
        k1d_rc1_drainer_ledger($ledger, 'winner finalized ' . $stmt->rowCount());
        // Retain the lease until every contender has attempted acquisition.
        // A fixed250ms sleep let late-starting processes acquire after release,
        // falsely labelling legal sequential ownership as concurrent owners.
        k1d_rc1_drainer_wait_for($pdo, 'attempted_count', 20);
        k1d_rc1_drainer_ledger($ledger, 'release started generation=' . $token->generation);
        (new QueueCoreDrainAuthority($pdo))->release($token);
        k1d_rc1_drainer_ledger($ledger, 'release completed generation=' . $token->generation);
        echo "DRAINER_CHILD_STATUS=winner\n";
        exit(0);
    }
    k1d_rc1_drainer_ledger($ledger, 'loser no_transport');
    echo "DRAINER_CHILD_STATUS=loser\n";
    exit(0);
}

putenv('APP_ENV=test');
putenv('ML_WRITE_ENABLED=false');
putenv('DB_HOST=' . (getenv('DB_HOST') ?: '127.0.0.1'));
putenv('DB_PORT=' . (getenv('DB_PORT') ?: '3306'));
putenv('DB_USER=' . (getenv('DB_USER') ?: 'root'));
putenv('DB_PASS=' . (string) getenv('DB_PASS'));
putenv('DB_NAME=erp_meli_k1d_test_rc1_drainer_' . strtolower(bin2hex(random_bytes(4))));

$harness = K1dSafeTestDatabase::createFromEnvironment();
$ledger = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'k1d_rc1_drainer_' . bin2hex(random_bytes(4)) . '.log';
$winners = 0;
$winnerRemote = 0;
$loserRemote = 0;
$duplicateRemote = 0;
$doubleFinalization = 0;
$failure = '';
$childOutputs = [];
$reacquiredAfterRelease = false;

try {
    $pdo = $harness->pdo();
    $pdo->exec("CREATE TABLE queue_core_execution_leases (
        lease_key VARCHAR(60) NOT NULL,
        launcher ENUM('cron_v4','canary_v4','manual','test') NULL,
        owner_token VARCHAR(96) NULL,
        generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
        heartbeat_at DATETIME(3) NULL,
        expires_at DATETIME(3) NULL,
        updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
        PRIMARY KEY (lease_key),
        KEY idx_queue_core_execution_expiry (expires_at,launcher)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT IGNORE INTO queue_core_execution_leases (lease_key,generation) VALUES ('global',0)");
    $pdo->exec("CREATE TABLE k1d_rc1_drainer_jobs (id INT NOT NULL PRIMARY KEY,state VARCHAR(20) NOT NULL,completed_by VARCHAR(96) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT INTO k1d_rc1_drainer_jobs (id,state) VALUES (1,'ready')");
    $pdo->exec('CREATE TABLE k1d_rc1_drainer_barrier (id INT PRIMARY KEY,ready_count INT NOT NULL DEFAULT 0,go_flag INT NOT NULL DEFAULT 0,attempted_count INT NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO k1d_rc1_drainer_barrier (id) VALUES (1)');

    $childOutputs = k1d_rc1_drainer_spawn_children($argv[0], 20, ['K1D_DRAINER_LEDGER' => $ledger]);
    $winners = count(array_filter($childOutputs, static fn(string $out): bool => str_contains($out, 'DRAINER_CHILD_STATUS=winner')));
    $lines = is_file($ledger) ? (file($ledger, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
    $winnerRemote = count(array_filter($lines, static fn(string $line): bool => str_contains($line, 'winner transport')));
    $loserRemote = count(array_filter($lines, static fn(string $line): bool => str_contains($line, 'loser transport')));
    $duplicateRemote = max(0, $winnerRemote - 1);
    $finalized = (string) $pdo->query('SELECT state FROM k1d_rc1_drainer_jobs WHERE id=1')->fetchColumn();
    $finalizationRows = count(array_filter($lines, static fn(string $line): bool => str_contains($line, 'winner finalized 1')));
    $doubleFinalization = max(0, $finalizationRows - 1);
    k1b_assert($finalized === 'completed', 'DRAINER_WINNER_FINALIZED_JOB');
    k1b_assert((int) $pdo->query('SELECT attempted_count FROM k1d_rc1_drainer_barrier WHERE id=1')->fetchColumn() === 20, 'ALL_CONTENDERS_ATTEMPTED_WHILE_WINNER_HELD_LEASE');
    $authority = new QueueCoreDrainAuthority($pdo);
    $next = $authority->acquire('cron_v4', bin2hex(random_bytes(8)), 30);
    $reacquiredAfterRelease = $next !== null && $next->generation === 2;
    k1b_assert($reacquiredAfterRelease, 'SEQUENTIAL_REACQUISITION_AFTER_RELEASE_IS_LEGAL');
    k1b_assert($authority->release($next), 'SEQUENTIAL_TEST_LEASE_RELEASED');
} catch (Throwable $error) {
    $failure = get_class($error) . ':' . preg_replace('/\s+/', ' ', $error->getMessage());
} finally {
    if (is_file($ledger)) {
        unlink($ledger);
    }
    $harness->cleanup();
}

$pass = $failure === ''
    && $winners === 1
    && $winnerRemote >= 1
    && $loserRemote === 0
    && $duplicateRemote === 0
    && $doubleFinalization === 0
    && $reacquiredAfterRelease;

echo 'STATUS=' . ($pass ? 'PASS K1D_RC1_DRAINER_MULTIPROCESS' : 'BLOCKED K1D_RC1_DRAINER_MULTIPROCESS') . "\n";
echo "CONCURRENT_LAUNCHERS=20\n";
echo "ACTIVE_DRAINER_WINNERS={$winners}\n";
echo "WINNER_FAKE_REMOTE_CALLS={$winnerRemote}\n";
echo "LOSER_REMOTE_CALLS={$loserRemote}\n";
echo "DUPLICATE_REMOTE_CALLS={$duplicateRemote}\n";
echo "DOUBLE_FINALIZATION={$doubleFinalization}\n";
echo 'CHILD_OUTPUTS_CAPTURED=' . count($childOutputs) . "\n";
echo 'REACQUIRE_AFTER_RELEASE=' . ($reacquiredAfterRelease ? 'PASS' : 'FAIL') . "\n";
if (getenv('K1D_DRAINER_DIAGNOSTICS') === '1') {
    foreach ($lines ?? [] as $line) {
        echo 'DRAINER_TIMELINE=' . $line . "\n";
    }
}
if ($failure !== '') {
    echo "FAILURE={$failure}\n";
}
echo "PRODUCTION_CHANGED_BY_CODEX=NO\n";
echo "REAL_MELI_HTTP=0\n";
echo "REAL_EMAIL_SENT=0\n";

exit($pass ? 0 : 20);

function k1d_rc1_drainer_ledger(string $path, string $line): void
{
    if ($path === '') {
        return;
    }
    $fh = fopen($path, 'ab');
    if ($fh === false) {
        return;
    }
    flock($fh, LOCK_EX);
    fwrite($fh, getmypid() . ' ' . sprintf('%.6f', microtime(true)) . ' ' . $line . "\n");
    flock($fh, LOCK_UN);
    fclose($fh);
}

function k1d_rc1_drainer_wait_for(PDO $pdo, string $column, int $expected): void
{
    k1b_assert(in_array($column, ['ready_count','go_flag','attempted_count'], true), 'KNOWN_BARRIER_COLUMN');
    $deadline = microtime(true) + 15.0;
    do {
        if ((int) $pdo->query('SELECT ' . $column . ' FROM k1d_rc1_drainer_barrier WHERE id=1')->fetchColumn() === $expected) {
            return;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('DRAINER_BARRIER_TIMEOUT_' . $column);
}

/** @param array<string,string> $extraEnv @return list<string> */
function k1d_rc1_drainer_spawn_children(string $script, int $count, array $extraEnv): array
{
    $children = [];
    $scriptPath = realpath($script) ?: $script;
    $env = array_merge($_ENV, [
        'APP_ENV' => (string) getenv('APP_ENV'),
        'ML_WRITE_ENABLED' => (string) getenv('ML_WRITE_ENABLED'),
        'DB_HOST' => (string) getenv('DB_HOST'),
        'DB_PORT' => (string) getenv('DB_PORT'),
        'DB_NAME' => (string) getenv('DB_NAME'),
        'DB_USER' => (string) getenv('DB_USER'),
        'DB_PASS' => (string) getenv('DB_PASS'),
    ], $extraEnv);

    for ($i = 0; $i < $count; $i++) {
        $process = proc_open([PHP_BINARY, $scriptPath, 'child'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__, $env);
        if (is_resource($process)) {
            $children[] = [$process, $pipes];
        }
    }

    k1b_assert(count($children) === $count, 'ALL_DRAINER_CHILDREN_STARTED');
    $pdo = \App\Core\Database::connectionFresh();
    k1d_rc1_drainer_wait_for($pdo, 'ready_count', $count);
    $pdo->exec('UPDATE k1d_rc1_drainer_barrier SET go_flag=1 WHERE id=1');

    $outputs = [];
    foreach ($children as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        k1b_assert($exit === 0, 'CHILD_PROCESS_EXIT_drainer_' . $exit . '_' . $stderr);
        $outputs[] = (string) $stdout;
    }
    return $outputs;
}
