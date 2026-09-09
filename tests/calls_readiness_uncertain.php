<?php
declare(strict_types=1);
require __DIR__.'/calls_readiness_fixture.php';
$h=calls_readiness_database();$pdo=App\Core\Database::connection();
try {
    $service=new App\QueueV4Clean\QueueV4CleanReadinessService($pdo);
    $run=$service->prepare(9007);
    App\Services\Cap2DomainsWire::$onWire=static function():void {throw new RuntimeException('fixture uncertain physical result');};
    $unknown=calls_readiness_check($service,$run,1);App\Services\Cap2DomainsWire::$onWire=null;
    k1b_assert(!$unknown['ok'] && $unknown['failure_class']==='remote_result_uncertain','Uncertain response must remain distinct from HTTP429 and success');
    k1b_assert($unknown['physical_http_calls']===1 && $unknown['physical_http_calls_certainty']==='CERTIFIED','Known physical call count is not lost with unknown result');
    k1b_assert($unknown['state']==='FAILED','Unknown remote result must never certify readiness');
    k1b_assert(cap2_manual_rejected(fn()=>calls_readiness_check($service,$run,1)),'Unknown result replay cannot make another call');
    k1b_assert(count(App\Services\Cap2DomainsWire::$calls)===1,'Unknown result is not retried');
    echo "PASS readiness uncertain result distinct from known physical count/no retry\n";
} finally {App\Services\Cap2DomainsWire::$onWire=null;$h->cleanup();}
