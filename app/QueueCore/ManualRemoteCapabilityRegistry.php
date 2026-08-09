<?php
declare(strict_types=1);

namespace App\QueueCore;

final class ManualRemoteCapabilityRegistry
{
    /** @return array{method:string,endpoint_pattern:string,operation_key:string,max_remote_calls:int}|null */
    public function forOperation(string $operationKey): ?array
    {
        $contracts=[
            'orders_search'=>['GET','~^/orders/search$~'],
            'order_exact'=>['GET','~^/orders/[0-9]+$~'],
            'shipment_exact'=>['GET','~^/shipments/[0-9]+$~'],
            'pack_exact'=>['GET','~^/packs/[0-9]+$~'],
            'question_exact'=>['GET','~^/questions/[0-9]+$~'],
            'questions_search'=>['GET','~^/questions/search$~'],
            'claim_exact'=>['GET','~^/post-purchase/v1/claims/[0-9]+$~'],
            'claims_search'=>['GET','~^/post-purchase/v1/claims/search$~'],
            'billing_orders'=>['GET','~^/billing/integration/group/ML/order/details$~'],
            'item_detail'=>['GET','~^/items/[A-Z]{2,4}[0-9]+$~i'],
            'item_description'=>['GET','~^/items/[A-Z]{2,4}[0-9]+/description$~i'],
        ];
        $definition=$contracts[$operationKey]??null;
        if(!is_array($definition))return null;
        return [
            'method'=>$definition[0],
            'endpoint_pattern'=>$definition[1],
            'operation_key'=>$operationKey,
            'max_remote_calls'=>1,
        ];
    }
}
