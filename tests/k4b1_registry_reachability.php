<?php
declare(strict_types=1);
require __DIR__ . '/k1b_bootstrap.php';
$registry = new App\Services\MeliOperationProfileRegistry();
$cases = [
    ['oauth','/oauth/token',''], ['order_exact','/orders/1',''],
    ['orders_search','/orders/search',''], ['shipment_exact','/shipments/1',''],
    ['pack_exact','/packs/1',''], ['question_exact','/questions/1',''],
    ['questions_search','/questions/search',''], ['claim_exact','/post-purchase/v1/claims/1',''],
    ['claims_search','/post-purchase/v1/claims/search',''],
    ['billing_orders','/billing/integration/group/ML/order/details',''],
    ['item_description','/items/MLA1/description',''], ['item_detail','/items/MLA1',''],
    ['items_discovery','/users/1/items/search',''], ['item_stock','/user-products/X/stock',''],
    ['sales_audit','/unknown','sales_audit'], ['insights','/unknown','insight'],
    ['growth','/unknown','growth'], ['ads','/unknown','ads'], ['unknown_read','/unknown',''],
];
foreach ($cases as [$expected,$path,$job]) {
    foreach (['GET','POST','PUT','DELETE','HEAD'] as $method) {
        $profile=$registry->resolve($method,$path,['job_type'=>$job,'operation_key'=>'local_financial']);
        k1b_assert($profile['key']===$expected && $profile['uses_api']===true, 'resolve_api:'.$expected.':'.$method);
    }
}
foreach (['local_financial','local_maintenance'] as $local) {
    k1b_assert($registry->all()[$local]['uses_api']===false && $registry->get($local)['uses_api']===false, 'local_profile_exists');
    $p=$registry->resolve('GET','/unknown',['job_type'=>$local,'operation_key'=>$local]);
    k1b_assert($p['key']==='unknown_read' && $p['uses_api']===true, 'local_metadata_cannot_disable_rhythm');
}
echo "K4B1_REGISTRY_REACHABILITY_PASS profiles=19 cases=95 local_controls=2\n";
