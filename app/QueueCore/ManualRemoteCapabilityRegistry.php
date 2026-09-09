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
            // SalesAuditRunService sends GET /orders/search. The transport
            // registry resolves that physical request as orders_search before
            // considering job_type, so the fence must bind that exact profile.
            'sales_audit'=>['GET','~^/orders/search$~','orders_search'],
            'order_exact'=>['GET','~^/orders/[0-9]+$~'],
            'shipment_exact'=>['GET','~^/shipments/[0-9]+$~'],
            'pack_exact'=>['GET','~^/packs/[0-9]+$~'],
            'question_exact'=>['GET','~^/questions/[0-9]+$~'],
            'questions_search'=>['GET','~^/questions/search$~'],
            'claim_exact'=>['GET','~^/post-purchase/v1/claims/[0-9]+$~'],
            'claims_search'=>['GET','~^/post-purchase/v1/claims/search$~'],
            'billing_orders'=>['GET','~^/billing/integration/group/ML/order/details$~'],
            'items_discovery'=>['GET','~^/users/[0-9]+/items/search$~'],
            'item_detail'=>['GET','~^/items/[A-Z]{2,4}[0-9]+$~i'],
            'item_exact'=>['GET','~^/items/[A-Z]{2,4}[0-9]+$~i','item_detail'],
            'item_description'=>['GET','~^/items/[A-Z]{2,4}[0-9]+/description$~i'],
        ];
        $definition=$contracts[$operationKey]??null;
        if(!is_array($definition))return null;
        return [
            'method'=>$definition[0],
            'endpoint_pattern'=>$definition[1],
            'operation_key'=>$definition[2]??$operationKey,
            'max_remote_calls'=>1,
        ];
    }

    /**
     * @param array<string,mixed> $sourceRow
     * @return array{method:string,endpoint_pattern:string,operation_key:string,max_remote_calls:int}|null
     */
    public function forSource(string $queueKey,string $operationKey,array $sourceRow): ?array
    {
        if($queueKey==='module_jobs')return null;
        if($queueKey==='order_enrichment'){
            $type=strtolower((string)($sourceRow['resource_type']??''));
            if(($type==='shipment'&&$operationKey!=='shipment_exact')
                ||($type==='pack'&&$operationKey!=='pack_exact')
                ||!in_array($type,['shipment','pack'],true))return null;
        }
        if($queueKey==='items_sync'){
            $expected=(string)($sourceRow['phase']??'')==='discovering'?'items_discovery':'item_detail';
            if($operationKey!==$expected)return null;
        }
        return $this->forOperation($operationKey);
    }
}
