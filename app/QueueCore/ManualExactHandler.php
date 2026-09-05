<?php
declare(strict_types=1);

namespace App\QueueCore;

use App\Services\CampaignExecutionContext;
use App\Services\ManualCampaignAdapterRegistry;
use App\Services\ManualCampaignSourceInspector;
use App\Services\ManualStaleSourceException;
use Throwable;

final class ManualExactHandler implements QueueHandler
{
    /** @param (callable():void)|null $beforeAdapterEffect */
    public function __construct(private $beforeAdapterEffect=null){}

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
            $state=(new ManualCampaignSourceInspector())->inspect(
                $queue,$source,$job->meliAccountId,$job->companyId
            );
            if(!$state->exists||$state->terminal||!$state->eligible){
                return $state->exists && $state->terminal
                    ? QueueResult::completed(0,0)
                    : QueueResult::review(ManualStaleSourceException::REASON);
            }
            $authority=(new ManualSourceAuthorityService())->inspect(
                $queue,$source,$job->meliAccountId,$job->companyId,$state
            );
            if($authority->explicitlyUnsupported)return QueueResult::review('manual_capability_unavailable');
            if(!hash_equals($expected,$authority->durableInputVersion)
                || (string)($job->payload['operation_key']??'')!==$authority->operationKey
                || ($authority->usesApi && ($job->payload['remote_contract']??null)!=$authority->remoteContract)){
                return QueueResult::review(ManualStaleSourceException::REASON);
            }
            if($this->beforeAdapterEffect!==null)($this->beforeAdapterEffect)();
            // Última cerca justo antes de transferir control al adaptador. El
            // adaptador repite esta misma comprobación inmediatamente antes
            // del efecto; este punto evita que incluso su construcción use
            // una autoridad que cambió después del primer snapshot.
            $latestState=(new ManualCampaignSourceInspector())->inspect(
                $queue,$source,$job->meliAccountId,$job->companyId
            );
            if(!$latestState->exists||$latestState->terminal||!$latestState->eligible){
                return QueueResult::review(ManualStaleSourceException::REASON);
            }
            $latestAuthority=(new ManualSourceAuthorityService())->inspect(
                $queue,$source,$job->meliAccountId,$job->companyId,$latestState
            );
            if($latestAuthority->explicitlyUnsupported
                || !hash_equals($expected,$latestAuthority->durableInputVersion)){
                return QueueResult::review(ManualStaleSourceException::REASON);
            }
            $result=$adapter->processExact(
                $source,$job->meliAccountId,
                new CampaignExecutionContext(0,0,$job->companyId,'queue_core_manual',$job->leaseGeneration,$context->deadline,1,$expected)
            );
            // Physical evidence outranks an adapter that swallowed an HTTP
            // protection and reported complete/deferred. Never dispatch rest.
            $evidence=\App\Core\Database::connectionFresh()->prepare(
                'SELECT http_status FROM queue_core_attempts WHERE id=? AND job_id=? AND company_id=? AND meli_account_id=? AND lease_owner=? AND lease_generation=?'
            );
            $evidence->execute([$context->attemptId,$job->id,$job->companyId,$job->meliAccountId,$job->leaseOwner,$job->leaseGeneration]);
            $http=(int)$evidence->fetchColumn();
            if(in_array($http,[401,403,429],true))return QueueResult::review('remote_'.$http,$http);
            return match($result->status){
                'completed'=>QueueResult::completed($result->processed,0),
                // Un aplazamiento cierra esta petición. La fila fuente queda
                // intacta para que el administrador pueda pedir otro paso;
                // Cron nunca reclama el dominio manual.
                'deferred'=>QueueResult::manualDeferred($result->processed),
                'protected'=>QueueResult::review($result->reason??'manual_protection',$result->httpStatus),
                'error'=>QueueResult::review($result->reason??'manual_error'),
                default=>QueueResult::review('manual_unknown_outcome'),
            };
        } catch(ManualStaleSourceException) {
            return QueueResult::review(ManualStaleSourceException::REASON);
        } catch(Throwable) {
            return QueueResult::review('manual_step_failed');
        }
    }
}
