<?php
declare(strict_types=1);

namespace App\QueueCore;

use App\Core\Database;
use App\Services\CronDeadlineContext;
use App\Services\CapacityPolicyService;
use PDO;
use RuntimeException;

final class ManualQueueLauncher
{
    private ?\Closure $coreFactory;

    /** Optional local-fixture seam; production always uses the certified factory. */
    public function __construct(?callable $coreFactory = null)
    {
        $this->coreFactory = $coreFactory === null ? null : \Closure::fromCallable($coreFactory);
    }

    /** @return array<string,mixed> */
    public function runExact(
        int $companyId,
        int $accountId,
        string $queueKey,
        string $sourceId,
        bool $usesApi,
        string $operationKey,
        string $inputVersion,
        string $sourceAuthorityVersion,
        string $explicitAttemptKey,
        ?array $contract=null
    ): array {
        $batch=$this->runExactBatch([[
            'company_id'=>$companyId,
            'account_id'=>$accountId,
            'queue_key'=>$queueKey,
            'source_id'=>$sourceId,
            'uses_api'=>$usesApi,
            'operation_key'=>$operationKey,
            'input_version'=>$inputVersion,
            'source_authority_version'=>$sourceAuthorityVersion,
            'explicit_attempt_key'=>$explicitAttemptKey,
            'remote_contract'=>$contract,
        ]]);
        return (array)($batch['results'][0]??[]);
    }

    /**
     * Execute a browser-confirmed, exact manual selection under one global
     * execution lease. The batch never falls back to unrelated FIFO work.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<string,mixed>
     */
    public function runExactBatch(array $items, ?int $physicalCallBudget = null, ?float $requestDeadline = null, ?callable $admit = null): array
    {
        $ownsDeadline = !CronDeadlineContext::active();
        if ($ownsDeadline) CronDeadlineContext::start(45, 43, 8, 3);
        $deadline = min($requestDeadline ?? INF, CronDeadlineContext::deadline() ?? microtime(true) + 45);
        try {
            return $this->runBoundBatch($items, $physicalCallBudget, $deadline, $admit);
        } finally {
            if ($ownsDeadline) CronDeadlineContext::clear();
        }
    }

    private function runBoundBatch(array $items, ?int $physicalCallBudget, float $requestDeadline, ?callable $admit): array
    {
        if($items===[])throw new RuntimeException('La seleccion manual no contiene trabajos exactos.');
        $policy=(new CapacityPolicyService())->snapshot('manual');
        $physicalCallBudget=max(1,min($physicalCallBudget??(int)$policy['current'],(int)$policy['current'],(int)$policy['ceiling'],CapacityPolicyService::TECHNICAL_MAX));
        $core=$this->coreFactory===null?QueueCoreFactory::build():($this->coreFactory)();
        $worker='manual-'.bin2hex(random_bytes(8));
        $lease=$core['execution_leases']->acquire('manual',$worker,60);
        if($lease===null)throw new RuntimeException('Ya hay un paso manual en curso. Espere su resultado.');
        $results=[];
        $summary=null;
        $usedCalls=0;
        $stopReason='selection_completed';
        try {
            $pdo=Database::connectionFresh();
            // Admission follows ALL read-only checks, but precedes recovery or
            // enqueue. A later failure can never make the same preview reusable.
            foreach($items as $item){
                if((int)($item['company_id']??0)<1||(int)($item['account_id']??0)<1
                    ||(string)($item['queue_key']??'')===''||(string)($item['source_id']??'')==='')throw new RuntimeException('La seleccion manual no esta completamente aislada por empresa y cuenta.');
                if(!empty($item['uses_api'])&&!is_array($item['remote_contract']??null))throw new RuntimeException('El trabajo manual no tiene un contrato remoto certificado.');
                if(preg_match('/^[a-f0-9]{64}$/',(string)($item['explicit_attempt_key']??''))!==1)throw new RuntimeException('La intención manual explícita no es válida.');
            }
            $pendingOrphans=$admit===null?[]:$core['repository']->pendingManualOrphans($items,$lease);
            $busy=$pdo->query("SELECT id FROM queue_core_jobs WHERE queue_domain='manual'
                AND state IN ('pending','claimed','running','retry_wait','waiting_oauth')
                AND NOT (work_type='manual_exact' AND state IN ('claimed','running') AND lease_expires_at IS NOT NULL AND lease_expires_at<=UTC_TIMESTAMP(3))
                ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
            foreach($busy as $busyId)if(!in_array((int)$busyId,$pendingOrphans,true))throw new ManualFifoBusyException();
            $freshPolicy=(new CapacityPolicyService())->snapshot('manual');
            $physicalCallBudget=min($physicalCallBudget,(int)$freshPolicy['current'],(int)$freshPolicy['ceiling']);
            if(microtime(true)>=$requestDeadline-0.25 || !CronDeadlineContext::canAcceptWork(1)){
                throw new RuntimeException('Se agotó el tiempo para preparar el paso manual. Vuelva a calcular.');
            }
            if($admit!==null)$admit();
            $core['repository']->recoverAbandonedManualJobs($lease,$items,$pendingOrphans);

            foreach($items as $item){
                $freshPolicy=(new CapacityPolicyService())->snapshot('manual');
                $physicalCallBudget=min($physicalCallBudget,(int)$freshPolicy['current'],(int)$freshPolicy['ceiling']);
                if($usedCalls>=$physicalCallBudget){$stopReason='physical_call_budget';break;}
                if(microtime(true)>=$requestDeadline-0.25 || !CronDeadlineContext::canAcceptWork(1)){$stopReason='request_deadline';break;}
                $companyId=(int)($item['company_id']??0);
                $accountId=(int)($item['account_id']??0);
                $queueKey=(string)($item['queue_key']??'');
                $sourceId=(string)($item['source_id']??'');
                $usesApi=(bool)($item['uses_api']??false);
                $operationKey=(string)($item['operation_key']??'');
                $inputVersion=(string)($item['input_version']??'');
                $sourceAuthorityVersion=(string)($item['source_authority_version']??'');
                $explicitAttemptKey=(string)($item['explicit_attempt_key']??'');
                $contract=is_array($item['remote_contract']??null)?$item['remote_contract']:null;

                if($companyId<1||$accountId<1||$queueKey===''||$sourceId==='')throw new RuntimeException('La seleccion manual no esta completamente aislada por empresa y cuenta.');
                if($usesApi&&$contract===null)throw new RuntimeException('El trabajo manual no tiene un contrato remoto certificado.');
                if(preg_match('/^[a-f0-9]{64}$/',$explicitAttemptKey)!==1)throw new RuntimeException('La intención manual explícita no es válida.');

                $busy=$pdo->query("SELECT id FROM queue_core_jobs WHERE queue_domain='manual' AND state IN ('pending','claimed','running','retry_wait','waiting_oauth') ORDER BY id LIMIT 1")->fetchColumn();
                if((int)$busy>0)throw new ManualFifoBusyException();

                $stepDeadline=min($requestDeadline,microtime(true)+25);
                $stepRemoteDeadline=min($stepDeadline,microtime(true)+20);
                $jobId=$core['repository']->enqueue(new QueueJob(
                    $companyId,$accountId,'manual_exact',$queueKey,$sourceId,
                    $usesApi?'normal':'local',80,
                    self::idempotencyKey($companyId,$accountId,$queueKey,$sourceId,$inputVersion,$explicitAttemptKey),
                    $inputVersion,'manual_web','manual:'.$queueKey.':'.$sourceId,
                    [
                        'queue_key'=>$queueKey,
                        'source_id'=>$sourceId,
                        'operation_key'=>$operationKey,
                        'source_authority_version'=>$sourceAuthorityVersion,
                        'explicit_attempt_key'=>$explicitAttemptKey,
                        'remote_contract'=>$contract,
                    ],
                    ['launcher'=>'manual_single_step','durable_input_version'=>$inputVersion,'explicit_attempt_key'=>$explicitAttemptKey,
                        'manual_preview_id'=>(int)($item['manual_preview_id']??0),'manual_user_id'=>(int)($item['manual_user_id']??0),
                        'execution_owner'=>$lease->ownerToken,'execution_generation'=>$lease->generation],
                    1,null,'manual'
                ));
                try{
                    $previousAttempt=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM queue_core_attempts WHERE job_id=? AND company_id=? AND meli_account_id=?');
                    $previousAttempt->execute([$jobId,$companyId,$accountId]);
                    $previousAttemptId=(int)$previousAttempt->fetchColumn();
                    $summary=CronDeadlineContext::within($stepRemoteDeadline,fn()=>$core['runner']->run(new QueueRunRequest(
                        'manual',$worker,1,$stepDeadline,
                        45,[],['manual_exact'],$accountId,$lease,'manual',$jobId
                    )));
                    $after=$core['repository']->job($jobId);
                    if(is_array($after)&&in_array((string)$after['state'],['pending','claimed','running','retry_wait','waiting_oauth'],true)){
                        $core['repository']->abandonManualJob($jobId,'manual_step_no_background_continuation');
                    }
                    $row=$core['repository']->job($jobId)??[];
                    $protectedHttp=in_array((int)($row['last_http_status']??0),[401,403,429],true);
                    $outcome=$protectedHttp?'review':((int)($summary['claimed']??0)===0?'not_started':((string)($row['last_error_class']??'')==='manual_checkpoint_deferred'?'deferred':(string)($row['state']??'review')));
                    $attempt=$pdo->prepare('SELECT COALESCE(SUM(physical_http_calls),0) physical_http_calls,COALESCE(SUM(resources_persisted),0) resources_persisted FROM queue_core_attempts WHERE job_id=? AND company_id=? AND meli_account_id=? AND id>?');
                    $attempt->execute([$jobId,$companyId,$accountId,$previousAttemptId]);$evidence=$attempt->fetch(PDO::FETCH_ASSOC)?:[];
                    $usedCalls+=(int)($evidence['physical_http_calls']??0);
                    $results[]=[
                        'status'=>$outcome,
                        'message'=>self::message($outcome),
                        'reason'=>$protectedHttp?'remote_'.(int)$row['last_http_status']:(string)($row['last_error_class']??''),
                        'queue_key'=>$queueKey,'source_id'=>$sourceId,
                        'remote_dispatches'=>(int)($evidence['physical_http_calls']??0),
                        'processed'=>(int)($evidence['resources_persisted']??0),
                        'queue_core_job_id'=>$jobId,'launcher_summary'=>$summary,
                    ];
                    // An ordinary successful checkpoint may continue the selection;
                    // protection, uncertainty and lease loss always stop it.
                    if($protectedHttp || !in_array($outcome,['completed','deferred'],true)){
                        $stopReason=$protectedHttp?'remote_'.(int)$row['last_http_status']:(string)($after['last_error_class']??$summary['reason']??'protection');
                        break;
                    }
                }catch(\Throwable $error){
                    $core['repository']->abandonManualJob($jobId,'manual_runner_failed');
                    throw $error;
                }
            }
        } finally {
            $core['execution_leases']->release($lease);
        }

        $remoteDispatches=0;
        $processed=0;
        $status='completed';
        $completed=0;
        $deferred=0;
        $review=0;
        foreach($results as $result){
            $remoteDispatches+=(int)($result['remote_dispatches']??0);
            $processed+=(int)($result['processed']??0);
            if(in_array((string)($result['status']??''),['review','dead'],true))$status='review';
            if($result['status']==='completed')$completed++;
            elseif($result['status']==='deferred')$deferred++;
            elseif($result['status']!=='not_started')$review++;
        }
        $attended=$completed+$deferred+$review;
        $notProcessed=max(0,count($items)-$attended);
        if($status==='completed' && $deferred>0)$status='deferred';
        if($notProcessed>0 && $status==='completed')$status='waiting';
        return [
            'status'=>$status,
            'message'=>sprintf('Paso manual: %d completados, %d aplazados con su progreso guardado, %d para revisión y %d sin iniciar. Para otro paso, vuelva a calcular. No hay continuación manual en segundo plano.',$completed,$deferred,$review,$notProcessed),
            'selected_count'=>count($items),
            'processed_count'=>$attended,
            'completed_count'=>$completed,
            'deferred_count'=>$deferred,
            'waiting_count'=>$deferred,
            'review_error_count'=>$review,
            'not_processed_count'=>$notProcessed,
            'requested_api_calls'=>$physicalCallBudget,
            'api_calls_used'=>$remoteDispatches,
            'stop_reason'=>$stopReason,
            'background_continuation'=>0,
            'remote_dispatches'=>$remoteDispatches,
            'processed'=>$processed,
            'results'=>$results,
            'lease_acquisitions'=>1,
        ];
    }

    private static function message(string $state): string
    {
        return match($state){
            'completed'=>'El paso exacto terminó. No quedó continuación en segundo plano.',
            'deferred'=>'El recurso conserva progreso pendiente. Vuelva a calcular para otro paso manual.',
            'not_started'=>'El recurso no se inició. Vuelva a calcular para otro paso manual.',
            'dead','review'=>'El paso quedó cerrado para revisión; no se repetirá en segundo plano.',
            default=>'El paso manual terminó sin continuación automática.',
        };
    }

    public static function idempotencyKey(int $companyId,int $accountId,string $queueKey,string $sourceId,string $inputVersion,string $explicitAttemptKey): string
    {
        return hash('sha256',implode('|',[$companyId,$accountId,$queueKey,$sourceId,$inputVersion,$explicitAttemptKey]));
    }
}
