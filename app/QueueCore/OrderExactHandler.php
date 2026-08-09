<?php
declare(strict_types=1);
namespace App\QueueCore;
use App\Services\OrderSyncService;
final class OrderExactHandler implements QueueHandler
{
    /** @var \Closure(int):OrderSyncService */
    private \Closure $syncFactory;

    /** @param null|callable(int):OrderSyncService $syncFactory */
    public function __construct(
        ?callable $syncFactory=null,
        private readonly ?SalePipelineCapabilityRepository $salePipeline=null,
        private readonly ?WebhookTriggerService $webhookTriggers=null,
    )
    {
        $this->syncFactory=$syncFactory!==null?\Closure::fromCallable($syncFactory):static fn(int $accountId):OrderSyncService=>new OrderSyncService($accountId);
    }

    public function handle(QueueClaim $job,QueueExecutionContext $context): QueueResult
    {
        if(!$context->hasTime(1.0))return QueueResult::automaticWait('deadline',gmdate('Y-m-d H:i:s',time()+5));
        $id=(string)($job->payload['order_id']??$job->resourceId??'');
        if($id===''||!ctype_digit($id))return QueueResult::dead('invalid_order_identity');
        $sync=($this->syncFactory)($job->meliAccountId);
        $localOrderId=$sync->syncOrderByIdForQueueCore($id,[
            'job_type'=>'order_exact','source'=>'queue_core','queue_core_job_id'=>$job->id,
        ]);
        // Materialization is local and idempotent. If it fails after the order
        // commit, the exact job retries from its known response and the durable
        // pending capability remains the recovery authority.
        if($this->salePipeline!==null){
            $this->salePipeline->materializePending(4,$localOrderId);
        }
        $this->completeWebhookTrigger($job);
        return QueueResult::completed(1,1);
    }

    private function completeWebhookTrigger(QueueClaim $job): void
    {
        $triggerId=(int)($job->payload['trigger_id']??0);
        $watermark=(int)($job->payload['scheduled_watermark']??0);
        if($this->webhookTriggers!==null && $triggerId>0 && $watermark>0){
            $this->webhookTriggers->complete(
                $triggerId,$job->companyId,$job->meliAccountId,$job->id,$watermark
            );
        }
    }
}
