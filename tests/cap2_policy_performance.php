<?php
declare(strict_types=1);

// Same disposable SQL fixture and workload for immutable baseline and target.
$root = str_replace('\\', '/', realpath($argv[1] ?? dirname(__DIR__)) ?: '');
$allowed = [str_replace('\\', '/', realpath(dirname(__DIR__)) ?: ''), 'D:/Codex/tmp/erp-meli/cap2-20260905/qa/base'];
if (!in_array($root, $allowed, true) || !is_file($root . '/app/Services/CapacityPolicyService.php')) {
    throw new RuntimeException('explicit_local_source_required');
}
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $file = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) require_once $file;
    }
});
require __DIR__ . '/K1dSafeTestDatabase.php';
putenv('APP_ENV=test'); putenv('ML_WRITE_ENABLED=false');
putenv('DB_HOST=127.0.0.1'); putenv('DB_PORT=33079'); putenv('DB_USER=root'); putenv('DB_PASS=');
putenv('DB_NAME=erp_meli_k1d_test_cap2_perf_' . bin2hex(random_bytes(5)));
$db = K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo = $db->pdo();
    $pdo->exec('CREATE TABLE app_settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT,is_encrypted TINYINT NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL DEFAULT "general",updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)');
    $policy = new App\Services\CapacityPolicyService($pdo);
    $result = [];
    foreach (['absent', 'explicit'] as $fixture) {
        if ($fixture === 'explicit') {
            $pdo->exec("INSERT INTO app_settings(setting_key,setting_value) VALUES('automation.max_api_calls_per_cycle','1'),('automation.api_calls_ceiling','55'),('manual.api_calls_per_step','15'),('manual.api_calls_ceiling','55')");
        }
        $beforeRows = (int)$pdo->query('SELECT COUNT(*) FROM app_settings')->fetchColumn();
        $samples = [];
        for ($round = 0; $round < 5; $round++) {
            $before = (int)$pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_ASSOC)['Value'];
            $start = hrtime(true);
            for ($i = 0; $i < 100; $i++) {
                $auto = $policy->snapshot('automation');
                $manual = $policy->snapshot('manual');
                if ($auto['current'] !== 1 || $auto['ceiling'] !== 55 || $manual['current'] !== 15 || $manual['ceiling'] !== 55) {
                    throw new RuntimeException('baseline_target_semantics_differ');
                }
            }
            $ms = (hrtime(true) - $start) / 1_000_000;
            $after = (int)$pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_ASSOC)['Value'];
            if ($after - $before !== 200) throw new RuntimeException('unexpected_policy_select_count');
            $samples[] = ['reads'=>200, 'selects'=>$after - $before, 'ms'=>round($ms, 3)];
        }
        if ((int)$pdo->query('SELECT COUNT(*) FROM app_settings')->fetchColumn() !== $beforeRows) throw new RuntimeException('policy_read_mutated_settings');
        $result[$fixture] = $samples;
    }
    echo json_encode(['status'=>'PASS','source_root'=>$root,'policy_sha256'=>hash_file('sha256',$root.'/app/Services/CapacityPolicyService.php'),'fixtures'=>$result,'scope'=>'policy_reads_only_not_end_to_end_or_health_gate','real_meli_http'=>0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} finally {
    $db->cleanup();
}
