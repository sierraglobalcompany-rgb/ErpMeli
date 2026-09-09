<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
use App\Services\ApiExecutionMetadataContext as M;
use App\Services\MeliTransportSourcePolicy as P;
use App\QueueV4Clean\QueueV4CleanCycleBudget as B;
function calls_source_denied(callable $fn):void {try{$fn();}catch(Throwable){return;}throw new RuntimeException('unauthorized_technical_source');}
B::start(1,'manual',microtime(true)+45);
P::assertAllowed('queue_core','GET','/orders/8101');
foreach([
    ['readiness','queue_v4_clean_readiness','GET','/users/me'],
    ['emergency_canary','manual_emergency_canary','GET','/users/me'],
    ['emergency_oauth','manual_emergency_oauth_refresh','POST','/oauth/token'],
    ['initial_oauth','web','POST','/oauth/token'],
    ['oauth_profile','web','GET','/users/me'],
] as [$operation,$source,$method,$path]) {
    calls_source_denied(static fn()=>M::run(['calls_technical_operation'=>$operation],static fn()=>P::assertAllowed($source,$method,$path)));
    M::run(['company_id'=>1,'account_id'=>2],static fn()=>M::withTechnicalOperation($operation,static function()use($source,$method,$path):void {
        P::assertAllowed($source,$method,$path);
        calls_source_denied(static fn()=>P::assertAllowed($source,$method,'/orders/8101'));
    }));
    calls_source_denied(static fn()=>P::assertAllowed($source,$method,$path));
}
calls_source_denied(static fn()=>P::assertAllowed('cron','GET','/orders/8101'));
B::clear();echo "CALLS_TRANSPORT_SOURCES_OK\n";
