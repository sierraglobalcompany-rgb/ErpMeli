<?php
declare(strict_types=1);
require __DIR__.'/cap2_manual_fixture.php';
use App\Services\ApiRhythmPolicyService as R;
use App\Services\ApiExecutionMetadataContext as M;
use App\Services\Cap2DomainsWire as W;
use App\QueueV4Clean\QueueV4CleanCycleBudget as B;

$h=cap2_manual_database(); $pdo=$h->pdo();
try {
    $r=new R();
    $permit=$r->reserve(9011,'GET','/users/me',['transport_request_id'=>bin2hex(random_bytes(20))]);
    k1b_assert($permit['enabled']===true && $r->isCurrent($permit),'real_enabled_permit');
    echo "RHYTHM_ENABLED=PASS\n";
    W::$responses['/users/me']=[200,['id'=>99011]];
    foreach(['busy','interval','pause','schema'] as $case) {
        if($case!=='busy') $pdo->exec('DELETE FROM api_remote_permits');
        $pdo->exec("UPDATE api_rhythm_states SET next_allowed_at=NULL,block_pause_until=NULL WHERE scope_key='global'");
        if($case==='interval')$pdo->exec("UPDATE api_rhythm_states SET next_allowed_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR) WHERE scope_key='global'");
        if($case==='pause')$pdo->exec("UPDATE api_rhythm_states SET block_pause_until=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR) WHERE scope_key='global'");
        if($case==='schema'){
            $pdo->exec('RENAME TABLE api_remote_permits TO k4b1_hidden_permits');
            App\Services\SchemaInspectorService::clearCache();
            (new ReflectionProperty(R::class,'schemaAvailable'))->setValue(null,null);
        }
        $before=count(W::$calls);$error=null;B::start(2,'manual',microtime(true)+45);
        try {
            M::run(['source'=>'web','company_id'=>9001,'account_id'=>9011],
                static fn()=>M::withTechnicalOperation('oauth_profile',
                    static fn()=>(new App\Services\MeliApiClient(9011))->get('/users/me')));
        }catch(Throwable $caught){$error=$caught;}
        if($case==='schema')$pdo->exec('RENAME TABLE k4b1_hidden_permits TO api_remote_permits');
        $expected=$case==='schema'?App\Services\ApiBudgetInfrastructureException::class:App\Services\ApiRhythmDeferredException::class;
        k1b_assert($error instanceof $expected,'rhythm_'.$case.':'.($error?->getMessage()??'no_exception'));
        k1b_assert(count(W::$calls)===$before && B::snapshot()['used']===0,'rhythm_'.$case.'_zero_physical');
        echo 'RHYTHM_CASE='.$case.' EXCEPTION='.$error::class." PHYSICAL=0\n";
        B::clear();
    }
    echo "K4B1_RHYTHM_AUTHORITY_MYSQL_PASS\n";
}finally{B::clear();$h->cleanup();}
