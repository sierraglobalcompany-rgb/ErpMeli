<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
require __DIR__.'/K1dSafeTestDatabase.php';
require __DIR__.'/cap2_health_fixture.php';
if (isset($argv[1])) {
    $path=str_replace('\\','/',realpath($argv[1]) ?: '');
    k1b_assert(str_starts_with($path,'C:/codex/capacity-save-kiss/') && str_ends_with($path,'/baseline/CapacityPolicyService.php'),'owned_baseline_service');
    require $path;
}
putenv('APP_ENV=test');putenv('ML_WRITE_ENABLED=false');putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT='.(getenv('DB_PORT') ?: '33079'));putenv('DB_USER=root');putenv('DB_PASS=');
putenv('DB_NAME=erp_meli_k1d_test_capacity_bench_'.bin2hex(random_bytes(4)));
$db=K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo=$db->pdo();cap2_health_create_schema($pdo);cap2_health_seed($pdo);cap2_health_authenticate();
    $policy=new App\Services\CapacityPolicyService($pdo);
    $guard=new App\Services\CapacityChangeGuard($pdo);
    $samples=[];
    for($i=0;$i<25;$i++){
        // Independent identical trials; no ongoing transport/progress is cleared.
        $pdo->exec("DELETE FROM app_settings WHERE setting_key IN ('automation.max_api_calls_per_cycle','automation.api_calls_ceiling')");
        App\Services\AppSettingsService::clearCache();
        $before=$policy->snapshot('automation');
        $selects=(int)$pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_ASSOC)['Value'];
        $start=hrtime(true);
        $guard->assertGlobalAuthorization();
        $args=['automation',10,55,$before['revision']];
        if(isset($argv[1]))$args[]=fn():array=>$guard->increaseGate();
        $saved=$policy->save(...$args);
        $ms=(hrtime(true)-$start)/1e6;
        $count=(int)$pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_ASSOC)['Value']-$selects;
        k1b_assert($saved['current']===10 && $saved['ceiling']===55,'exact_saved_pair');
        if($i>=5)$samples[]=['sample'=>$i-4,'selects'=>$count,'ms'=>round($ms,3)];
    }
    echo json_encode(['status'=>'PASS','variant'=>isset($argv[1])?'original_service_with_real_health_gate':'new_service_without_health_gate','warmups'=>5,'samples'=>$samples,'scope'=>'same_healthy_fixture_authorization_plus_policy_save_only_not_browser_latency','database'=>$pdo->query('SELECT VERSION()')->fetchColumn(),'physical_http'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
} finally {$db->cleanup();}
