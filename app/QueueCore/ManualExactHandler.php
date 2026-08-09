<?php
declare(strict_types=1);
namespace App\QueueCore;
use App\Services\CampaignExecutionContext;
use App\Services\ManualCampaignAdapterRegistry;
final class ManualExactHandler implements QueueHandler
{
    public function handle(QueueClaim $job,QueueExecutionContext $context): QueueResult
    {$queue=(string)($job->payload['queue_key']??'');$source=(string)($job->payload['source_id']??'');$adapter=(new ManualCampaignAdapterRegistry())->forQueue($queue);if($adapter===null||!$adapter->supportsExact())return QueueResult::dead('unsupported_manual_work');$result=$adapter->processExact($source,$job->meliAccountId,new CampaignExecutionContext(0,0,$job->companyId,'queue_core_manual',$job->leaseGeneration,$context->deadline,1));return match($result->status){'completed'=>QueueResult::completed($result->processed,0),'deferred'=>QueueResult::retry($result->reason??'manual_deferred',$result->nextEligibleAt),'error'=>QueueResult::review($result->reason??'manual_error'),default=>QueueResult::review('manual_unknown_outcome')};}
}
