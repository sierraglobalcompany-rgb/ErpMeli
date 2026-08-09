<?php
declare(strict_types=1);

namespace App\QueueCore;

use App\Services\CampaignExecutionContext;
use App\Services\ManualCampaignAdapterRegistry;
use Throwable;

final class ManualExactHandler implements QueueHandler
{
    public function handle(QueueClaim $job,QueueExecutionContext $context): QueueResult
    {
        $queue=trim((string)($job->payload['queue_key']??''));
        $source=trim((string)($job->payload['source_id']??''));
        $expected=trim((string)($job->payload['source_authority_version']??''));
        if($queue===''||$source===''||preg_match('/^[a-f0-9]{64}$/',$expected)!==1){
            return QueueResult::dead('invalid_manual_source_authority');
        }
        $adapter=(new ManualCampaignAdapterRegistry())->forQueue($queue);
        if($adapter===null||!$adapter->supportsExact())return QueueResult::dead('unsupported_manual_work');

        try {
            // La fuente se relee inmediatamente antes del handler. Un preview
            // viejo jamás autoriza transporte ni mutación local.
            $state=$adapter->inspect($source,$job->meliAccountId);
            if(!$state->exists||$state->terminal||!$state->eligible){
                return QueueResult::completed(0,0);
            }
            $authority=(new ManualSourceAuthorityService())->inspect(
                $queue,$source,$job->meliAccountId,$job->companyId,$state
            );
            if($authority->explicitlyUnsupported)return QueueResult::review('manual_capability_unavailable');
            if(!hash_equals($expected,$authority->durableInputVersion)
                || (string)($job->payload['operation_key']??'')!==$authority->operationKey
                || ($authority->usesApi && ($job->payload['remote_contract']??null)!=$authority->remoteContract)){
                return QueueResult::review('manual_source_changed');
            }
            $result=$adapter->processExact(
                $source,$job->meliAccountId,
                new CampaignExecutionContext(0,0,$job->companyId,'queue_core_manual',$job->leaseGeneration,$context->deadline,1)
            );
            return match($result->status){
                'completed'=>QueueResult::completed($result->processed,0),
                // Un aplazamiento cierra esta petición. La fila fuente queda
                // intacta para que el administrador pueda pedir otro paso;
                // Cron nunca reclama el dominio manual.
                'deferred'=>QueueResult::completed($result->processed,0),
                'error'=>QueueResult::review($result->reason??'manual_error'),
                default=>QueueResult::review('manual_unknown_outcome'),
            };
        } catch(Throwable) {
            return QueueResult::review('manual_step_failed');
        }
    }
}
