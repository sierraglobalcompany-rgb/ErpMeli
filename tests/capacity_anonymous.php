<?php
declare(strict_types=1);
namespace App\Services {
    final class CapacityPolicyService {
        public function __construct() { throw new \RuntimeException('Anonymous request reached policy'); }
    }
}
namespace {
require __DIR__.'/k1b_bootstrap.php';
if(!isset($argv[1])) {
    foreach(['saveCronCallBudget','saveManualCallBudget'] as $handler){
        $p=proc_open([PHP_BINARY,__FILE__,$handler],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
        k1b_assert($exit===0 && str_contains($out,'AUTH_REAL_ANONYMOUS_PASS'),$handler.':'.$out.$err);
    }
    echo "STATUS=PASS CAPACITY_ANONYMOUS REAL_AUTH=YES\n";exit;
}
$_SESSION=[];
register_shutdown_function(static function():void { if(error_get_last()===null)echo "AUTH_REAL_ANONYMOUS_PASS\n"; });
(new \App\Controllers\SettingsController())->{$argv[1]}();
throw new \RuntimeException('Anonymous handler returned without login redirect');
}
