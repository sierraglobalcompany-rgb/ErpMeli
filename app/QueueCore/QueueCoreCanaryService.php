<?php

declare(strict_types=1);

namespace App\QueueCore;

use App\Services\EmergencyControlService;
use PDO;
use Throwable;

/** One-shot acotado. Consume la cabeza FIFO; nunca produce trabajo. */
final class QueueCoreCanaryService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?FreshOrdersGateway $freshOrdersGateway = null,
    ) {}

    /** @return array<string,mixed> */
    public function run(int $accountId, int $maxJobs, int $maxPhysicalHttp, int $deadlineSeconds): array
    {
        $maxJobs=max(1,min(20,$maxJobs));
        $maxPhysicalHttp=max(0,min(10,$maxPhysicalHttp));
        $deadlineSeconds=max(5,min(50,$deadlineSeconds));
        $safety=(new EmergencyControlService())->status();
        if($accountId<1 || ($safety['automation']??'')!=='stopped' || ($safety['api']??'')!=='enabled'){
            return $this->blocked('safety_precondition');
        }
        $preflight=(new QueueCorePreflightService($this->pdo))->check(true);
        if(empty($preflight['ok']))return $this->blocked('preflight_failed',['issues'=>$preflight['issues']??[]]);
        $engine=(new QueueEngineControlService($this->pdo))->snapshot();
        if($engine['active_engine']!=='disabled')return $this->blocked('engine_must_be_disabled');

        $core=QueueCoreFactory::build($this->pdo,$this->freshOrdersGateway);
        $worker='canary-v4-'.bin2hex(random_bytes(8));
        $lease=$core['execution_leases']->acquire('canary_v4',$worker,$deadlineSeconds+10);
        if($lease===null)return $this->blocked('launcher_busy');
        $ledger=new QueueCoreRunLedger($this->pdo);
        $runId=$ledger->begin($engine['generation'],'canary_v4',$worker);
        $deadline=microtime(true)+$deadlineSeconds;
        $totals=['claimed'=>0,'completed'=>0,'retry_wait'=>0,'waiting_oauth'=>0,'review'=>0,'dead'=>0,'lease_lost'=>0];
        $reason='max_jobs';
        try{
            while($totals['claimed']<$maxJobs && microtime(true)<$deadline-1){
                if((new EmergencyControlService())->status()['automation']!=='stopped'){$reason='automation_resumed';break;}
                $http=$this->httpCalls($runId);
                if($http>=$maxPhysicalHttp && $maxPhysicalHttp>=0){$reason='max_http';break;}
                $types=$core['capabilities']->certifiedTypes($core['registry']);
                $head=$core['repository']->peekOldestEligible($types,'operational');
                if($head===null){$reason='drained';break;}
                if((int)$head['meli_account_id']!==$accountId){$reason='fifo_head_other_account';break;}
                $definition=$core['capabilities']->definition((string)$head['work_type']);
                $method=strtoupper((string)($definition['method']??''));
                if($method==='POST' || (int)($definition['max_remote_calls']??0)>1){$reason='fifo_head_not_canary_safe';break;}
                $run=$core['runner']->run(new QueueRunRequest(
                    'canary_v4',$worker,1,$deadline,max(5,$deadlineSeconds+5),[],[(string)$head['work_type']],
                    null,$lease,'operational',(int)$head['id'],$runId
                ));
                foreach(array_keys($totals) as $key)$totals[$key]+=max(0,(int)($run[$key]??0));
                $reason=(string)($run['reason']??'unknown');
                if((int)($run['claimed']??0)===0)break;
            }
            $http=$this->httpCalls($runId);
            $known=$this->knownResponses($runId);
            $persisted=$this->persisted($runId);
            // A local/no-op cycle cannot certify the remote read path.  A
            // passing canary proves one or more physical requests with known
            // responses and at least one locally persisted resource.
            $passed=$totals['claimed']>0 && $http>0 && $known===$http && $persisted>0
                && $totals['review']===0 && $totals['dead']===0
                && $totals['lease_lost']===0 && $http<=$maxPhysicalHttp;
            $summary=['run'=>$totals,'physical_http_calls'=>$http,'known_responses'=>$known,
                'resources_persisted'=>$persisted,'reason'=>$reason];
            $ledger->finish($runId,$passed?'completed':'stopped',$reason,$summary);
            (new QueueCoreReadinessReceiptService($this->pdo))->record(
                $engine['generation'],'canary',$passed,
                ['account_id'=>$accountId,'claimed'=>$totals['claimed'],'http'=>$http,
                    'known'=>$known,'persisted'=>$persisted,'review'=>$totals['review'],'dead'=>$totals['dead']],
                3600,null,$accountId
            );
            return ['ok'=>$passed,'status'=>$passed?'PASS':'NOT_PASSED','account_id'=>$accountId,
                'jobs_claimed'=>$totals['claimed'],'physical_http_calls'=>$http,'known_responses'=>$known,
                'resources_persisted'=>$persisted,'final_states'=>$totals,'reason'=>$reason,'fifo_preserved'=>true];
        }catch(Throwable){
            try{$ledger->finish($runId,'failed','local_failure',['claimed'=>$totals['claimed']]);}catch(Throwable){}
            return $this->blocked('local_failure',['jobs_claimed'=>$totals['claimed'],'physical_http_calls'=>$this->httpCalls($runId)]);
        }finally{$core['execution_leases']->release($lease);}
    }

    private function httpCalls(int $runId): int{return $this->sum($runId,'physical_http_calls');}
    private function knownResponses(int $runId): int{return $this->sum($runId,'response_known_at IS NOT NULL');}
    private function persisted(int $runId): int{return $this->sum($runId,'resources_persisted');}
    private function sum(int $runId,string $expression): int
    {$s=$this->pdo->prepare("SELECT COALESCE(SUM($expression),0) FROM queue_core_attempts WHERE run_id=?");$s->execute([$runId]);return max(0,(int)$s->fetchColumn());}
    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function blocked(string $reason,array $extra=[]):array{return ['ok'=>false,'status'=>'BLOCKED','reason'=>$reason,
        'jobs_claimed'=>0,'physical_http_calls'=>0,'known_responses'=>0,'resources_persisted'=>0,
        'final_states'=>[],'fifo_preserved'=>true]+$extra;}
}
