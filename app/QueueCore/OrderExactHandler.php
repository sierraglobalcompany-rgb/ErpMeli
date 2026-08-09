<?php
declare(strict_types=1);
namespace App\QueueCore;
use App\Services\OrderSyncService;
final class OrderExactHandler implements QueueHandler
{
    public function handle(QueueClaim $job,QueueExecutionContext $context): QueueResult
    {if(!$context->hasTime(1.0))return QueueResult::retry('deadline',gmdate('Y-m-d H:i:s',time()+5));$id=(string)($job->payload['order_id']??$job->resourceId??'');if($id===''||!ctype_digit($id))return QueueResult::dead('invalid_order_identity');(new OrderSyncService($job->meliAccountId))->syncOrderById($id,['job_type'=>'order_exact','source'=>'queue_core','queue_core_job_id'=>$job->id]);return QueueResult::completed(1,0);}
}
