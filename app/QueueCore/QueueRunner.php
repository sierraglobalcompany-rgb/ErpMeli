<?php
declare(strict_types=1);

namespace App\QueueCore;

use App\Services\ApiBudgetExhaustedException;
use App\Services\ApiExecutionMetadataContext;
use App\Services\MeliApiException;
use App\Services\RemoteResultUncertainException;
use App\Services\EmergencyControlService;
use App\Services\OAuthRefreshRequiredException;
use Throwable;

final class QueueRunner
{
    public function __construct(private readonly QueueCoreRepository $repository,private readonly QueueHandlerRegistry $handlers){}

    /** @return array{claimed:int,completed:int,retry_wait:int,review:int,dead:int,lease_lost:int,reason:string} */
    public function run(QueueRunRequest $request): array
    {
        $summary=['claimed'=>0,'completed'=>0,'retry_wait'=>0,'review'=>0,'dead'=>0,'lease_lost'=>0,'reason'=>'drained'];
        while($summary['claimed']<$request->maxJobs){
            if($request->launcher==='cron_v4' && (new EmergencyControlService())->automationStopped()){$summary['reason']='automation_stopped';break;}
            if(microtime(true)>=($request->deadline-0.25)){$summary['reason']='deadline';break;}
            $claim=$this->repository->claimNext($request,$this->handlers->workTypes());
            if($claim===null)break;
            $summary['claimed']++;
            try{$attempt=$this->repository->beginAttempt($claim,$request->launcher);}catch(Throwable){$summary['lease_lost']++;continue;}
            $context=new QueueExecutionContext($attempt,$request->deadline,$request->launcher);
            try{
                $result=ApiExecutionMetadataContext::run([
                    'source'=>'queue_core','company_id'=>$claim->companyId,'account_id'=>$claim->meliAccountId,
                    'queue_core_job_id'=>$claim->id,'queue_core_attempt_id'=>$attempt,'queue_core_work_type'=>$claim->workType,
                    'queue_core_lane'=>$claim->lane,'queue_core_attempt_count'=>$claim->attemptCount,'queue_core_max_attempts'=>$claim->maxAttempts,
                    'queue_core_lease_owner'=>$claim->leaseOwner,'queue_core_lease_generation'=>$claim->leaseGeneration,
                ],fn():QueueResult=>$this->handlers->get($claim->workType)->handle($claim,$context));
            }catch(RemoteResultUncertainException){$result=QueueResult::review('remote_result_uncertain');
            }catch(OAuthRefreshRequiredException){$result=QueueResult::review('oauth_refresh_required');
            }catch(ApiBudgetExhaustedException $e){$result=QueueResult::retry('policy_deferred',$e->nextSafeAt);
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
