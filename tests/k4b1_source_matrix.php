<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
use App\Services\MeliTransportSourcePolicy as P;
use App\Services\ApiExecutionMetadataContext as M;
use App\QueueV4Clean\QueueV4CleanCycleBudget as B;
B::start(1,'manual',microtime(true)+45);
try {
    foreach([
        ['queue_v4_clean','GET','/orders/1'],
        ['queue_v4_clean_sales_audit','GET','/orders/search'],
        ['queue_v4_clean_sales_repair','GET','/orders/1'],
        ['queue_v4_clean_domain_exact','GET','/billing/integration/group/ML/order/details'],
        ['queue_v4_clean_oauth','POST','/oauth/token'],
        ['queue_core','GET','/orders/1'],
    ] as $case) P::assertAllowed(...$case);
    foreach([
        ['readiness','queue_v4_clean_readiness','GET','/users/me'],
        ['emergency_canary','manual_emergency_canary','GET','/users/me'],
        ['emergency_oauth','manual_emergency_oauth_refresh','POST','/oauth/token'],
        ['initial_oauth','web','POST','/oauth/token'],
        ['oauth_profile','web','GET','/users/me'],
    ] as [$op,$source,$method,$path]){
        M::run(['company_id'=>9001,'account_id'=>9011],static fn()=>M::withTechnicalOperation($op,static fn()=>P::assertAllowed($source,$method,$path)));
    }
    $denied=[
        ['cron_v3_remote','GET','/orders/1'],['cron_v3','GET','/orders/1'],
        ['queue_core_webhook','GET','/orders/1'],['manual_campaign','GET','/orders/1'],
        ['manual_exact','GET','/orders/1'],['webhook_worker','GET','/orders/1'],
        ['cron','GET','/orders/1'],['module:orders','GET','/orders/1'],
        ['unknown','GET','/orders/1'],['web','GET','/orders/1'],
        ['queue_v4_clean_oauth','GET','/oauth/token'],
        ['queue_v4_clean_sales_audit','GET','/orders/1'],
        ['queue_v4_clean_sales_repair','POST','/orders/1'],
        ['queue_v4_clean','GET','/items/MLA1'],
    ];
    foreach($denied as $case){$blocked=false;try{P::assertAllowed(...$case);}catch(RuntimeException){$blocked=true;}k1b_assert($blocked,'source_should_be_denied:'.implode(':',$case));}
    echo "K4B1_SOURCE_MATRIX_PASS allowed_sources=10 allowed_cases=11 denied_cases=14\n";
}finally{B::clear();}
