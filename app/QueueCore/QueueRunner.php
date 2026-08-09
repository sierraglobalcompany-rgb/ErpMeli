<?php
declare(strict_types=1);

namespace App\QueueCore;

use App\Services\ApiBudgetExhaustedException;
use App\Services\ApiRhythmDeferredException;
use App\Services\ApiExecutionMetadataContext;
use App\Services\ApiManualPauseException;
use App\Services\CronDeadlineDeferredException;
use App\Services\MeliApiException;
use App\Services\ManualRemoteCallLimitException;
use App\Services\OAuthRefreshBusyException;
use App\Services\RemoteResultUncertainException;
use App\Services\EmergencyControlService;
use App\Services\OAuthRefreshRequiredException;
use Throwable;

final class QueueRunner
{
    public function __construct(private readonly QueueCoreRepository $repository,private readonly QueueHandlerRegistry $handlers,
        private readonly ?QueueExecutionLeaseService $executionLeases=null,
        private readonly QueueCapabilityRegistry $capabilities=new QueueCapabilityRegistry()){}

    /** @return array{claimed:int,completed:int,retry_wait:int,review:int,dead:int,lease_lost:int,reason:string} */
    public function run(QueueRunRequest $request): array
    {
        $summary=['claimed'=>0,'completed'=>0,'retry_wait'=>0,'waiting_oauth'=>0,'review'=>0,'dead'=>0,'lease_lost'=>0,'reason'=>'drained'];
        while($summary['claimed']<$request->maxJobs){
            if($request->launcher==='cron_v4' && (new EmergencyControlService())->automationStopped()){$summary['reason']='automation_stopped';break;}
            if($request->executionLease!==null && ($this->executionLeases===null || !$this->executionLeases->heartbeat($request->executionLease))){$summary['reason']='execution_lease_lost';break;}
            if(microtime(true)>=($request->deadline-0.25)){$summary['reason']='deadline';break;}
            $claim=$this->repository->claimNext($request,$this->capabilities->certifiedTypes($this->handlers));
            if($claim===null)break;
            $summary['claimed']++;
            try{$attempt=$this->repository->beginAttempt($claim,$request->launcher);}catch(Throwable){$summary['lease_lost']++;continue;}
            $context=new QueueExecutionContext($attempt,$request->deadline,$request->launcher);
            $transportContract=$this->capabilities->transportContract($claim,$request->launcher);
            try{
                $result=ApiExecutionMetadataContext::run([
                    'source'=>'queue_core','company_id'=>$claim->companyId,'account_id'=>$claim->meliAccountId,
                    'queue_core_job_id'=>$claim->id,'queue_core_attempt_id'=>$attempt,'queue_core_work_type'=>$claim->workType,
                    'queue_core_lane'=>$claim->lane,'queue_core_attempt_count'=>$claim->attemptCount,'queue_core_max_attempts'=>$claim->maxAttempts,
                    'queue_core_lease_owner'=>$claim->leaseOwner,'queue_core_lease_generation'=>$claim->leaseGeneration,
                    'queue_core_lease_seconds'=>$request->leaseSeconds,'queue_core_launcher'=>$request->launcher,
                    'queue_core_execution_owner'=>$request->executionLease?->ownerToken,
                    'queue_core_execution_generation'=>$request->executionLease?->generation,
                    'queue_core_execution_lease_seconds'=>$request->executionLease?->leaseSeconds,
                    'queue_core_domain'=>$transportContract['domain']??($request->domain()??''),
                    'queue_core_capability_launcher'=>$transportContract['launcher']??'',
                    'queue_core_expected_method'=>$transportContract['method']??'',
                    'queue_core_expected_endpoint_pattern'=>$transportContract['endpoint_pattern']??'',
                    'queue_core_expected_operation'=>$transportContract['profile']??'',
                    'queue_core_max_remote_calls'=>$transportContract['max_remote_calls']??0,
                    'queue_core_uses_api'=>!empty($transportContract['uses_api'])?1:0,
                ],fn():QueueResult=>$this->handlers->get($claim->workType)->handle($claim,$context));
            }catch(RemoteResultUncertainException){$result=QueueResult::review('remote_result_uncertain');
            }catch(OAuthRefreshRequiredException){$result=QueueResult::waitingOAuth();
            }catch(OAuthRefreshBusyException){$result=QueueResult::automaticWait('oauth_refresh_busy',gmdate('Y-m-d H:i:s',time()+5));
            }catch(CronDeadlineDeferredException $e){$result=QueueResult::automaticWait('deadline',$e->nextSafeAt??gmdate('Y-m-d H:i:s',time()+60));
            }catch(ApiManualPauseException $e){$result=QueueResult::automaticWait('api_paused',$e->resumeAt??gmdate('Y-m-d H:i:s',time()+60));
            }catch(QueueCorePreRemoteBlockedException){$result=QueueResult::automaticWait('pre_remote_blocked',gmdate('Y-m-d H:i:s',time()+5));
            }catch(ApiRhythmDeferredException $e){$result=QueueResult::automaticWait('rhythm_deferred',$e->nextSafeAt);
            }catch(ApiBudgetExhaustedException $e){$result=QueueResult::automaticWait('policy_deferred',$e->nextSafeAt);
            }catch(ManualRemoteCallLimitException){$result=QueueResult::review('remote_call_limit_exceeded');
            }catch(MeliApiException $e){$result=QueueResult::retry('meli_http_error',null,$e->httpStatus);
            }catch(Throwable $e){$result=QueueResult::retry(self::safeError($e));}
            if(!$this->repository->finish($claim,$attempt,$result)){$summary['lease_lost']++;continue;}
            $state=(string)($this->repository->job($claim->id)['state']??'review');
            if(isset($summary[$state]))$summary[$state]++;
        }
        if($summary['claimed']>=$request->maxJobs)$summary['reason']='max_jobs';
        return $summary;
    }
    private static function safeError(Throwable $e): string {$name=strtolower((new \ReflectionClass($e))->getShortName());return substr((string)preg_replace('/[^a-z0-9_]+/','_',$name),0,100)?:'local_failure';}
}
