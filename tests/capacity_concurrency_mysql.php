<?php
declare(strict_types=1);
require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';

use App\Services\CapacityPolicyService;

if (($argv[1] ?? '') === 'child') {
    $harness = K1dSafeTestDatabase::connectExistingFromEnvironment();
    $policy = new CapacityPolicyService($harness->pdo());
    try {
        $policy->save('automation', 3, (int) $argv[3], $argv[2], static function (): array {
            usleep(400000);
            return ['allowed'=>true];
        });
        echo 'SAVE_OK';
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'La capacidad cambió. Recargue y confirme los valores actuales.') {
            throw $error;
        }
        echo 'STALE_REJECTED';
    }
    exit;
}

putenv('APP_ENV=test');
putenv('ML_WRITE_ENABLED=false');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=' . (getenv('DB_PORT') ?: '33079'));
putenv('DB_USER=root');
putenv('DB_PASS=');
putenv('DB_NAME=erp_meli_k1d_test_capacity_concurrency_' . bin2hex(random_bytes(4)));
$harness = K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo = $harness->pdo();
    $pdo->exec('CREATE TABLE app_settings (setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NULL,is_encrypted TINYINT NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL DEFAULT "general") ENGINE=InnoDB');
    $policy = new CapacityPolicyService($pdo);
    $revision = $policy->snapshot('automation')['revision'];
    $children = [];
    foreach ([55,100] as $ceiling) {
        $pipes = [];
        $process = proc_open([PHP_BINARY,__FILE__,'child',$revision,(string) $ceiling], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        k1b_assert(is_resource($process), 'capacity_child_started');
        fclose($pipes[0]);
        $children[] = [$process,$pipes];
    }
    $outputs = [];
    foreach ($children as [$process,$pipes]) {
        $outputs[] = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        k1b_assert(proc_close($process) === 0 && $error === '', 'capacity_child_clean_exit');
    }
    sort($outputs);
    k1b_assert($outputs === ['SAVE_OK','STALE_REJECTED'], 'first_save_single_winner_even_when_rows_absent');
    k1b_assert($policy->snapshot('automation')['current'] === 3, 'winner_pair_durable');
    k1b_assert((int) $pdo->query('SELECT COUNT(*) FROM app_settings')->fetchColumn() === 2, 'exactly_four_setting_design_one_pair_created');
    echo "STATUS=PASS CAPACITY_CONCURRENT_SAVE_MYSQL\nREAL_MELI_HTTP=0\nREAL_EMAIL_SENT=0\n";
} finally { $harness->cleanup(); }
