<?php
declare(strict_types=1);

namespace App\QueueCore;

use App\Core\Database;
use App\Services\CronDeadlineContext;
use PDO;
use RuntimeException;

final class ManualQueueLauncher
{
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
    public function runExactBatch(array $items): array
    {
        if($items===[])throw new RuntimeException('La seleccion manual no contiene trabajos exactos.');
        $core=QueueCoreFactory::build();
        $worker='manual-'.bin2hex(random_bytes(8));
        $lease=$core['execution_leases']->acquire('manual',$worker,60);
        if($lease===null)throw new RuntimeException('Ya hay un paso manual en curso. Espere su resultado.');
        $results=[];
        $summary=null;
        try {
            $pdo=Database::connectionFresh();
            $core['repository']->recoverAbandonedManualJobs();

            foreach($items as $item){
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

                CronDeadlineContext::start(25,20,8,3);
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
                    ['launcher'=>'manual_single_step','durable_input_version'=>$inputVersion,'explicit_attempt_key'=>$explicitAttemptKey],
                    1,null,'manual'
                ));
                try{
                    $summary=$core['runner']->run(new QueueRunRequest(
                        'manual',$worker,1,CronDeadlineContext::deadline()??microtime(true)+25,
                        45,[],['manual_exact'],$accountId,$lease,'manual',$jobId
                    ));
                }catch(\Throwable $error){
                    $core['repository']->abandonManualJob($jobId,'manual_runner_failed');
                    throw $error;
                } finally {
                    CronDeadlineContext::clear();
                }
                $after=$core['repository']->job($jobId);
                if(is_array($after)&&in_array((string)$after['state'],['pending','claimed','running','retry_wait','waiting_oauth'],true)){
                    $core['repository']->abandonManualJob($jobId,'manual_step_no_background_continuation');
                }
                $row=$core['repository']->job($jobId)??[];
                $attempt=Database::connectionFresh()->prepare('SELECT physical_http_calls,resources_persisted FROM queue_core_attempts WHERE job_id=? ORDER BY id DESC LIMIT 1');
                $attempt->execute([$jobId]);$evidence=$attempt->fetch(PDO::FETCH_ASSOC)?:[];
                $results[]=[
                    'status'=>(string)($row['state']??'review'),
                    'message'=>self::message((string)($row['state']??'review')),
                    'queue_key'=>$queueKey,'source_id'=>$sourceId,
                    'remote_dispatches'=>(int)($evidence['physical_http_calls']??0),
                    'processed'=>(int)($evidence['resources_persisted']??0),
                    'queue_core_job_id'=>$jobId,'launcher_summary'=>$summary,
                ];
            }
        } finally {
            CronDeadlineContext::clear();
            $core['execution_leases']->release($lease);
        }

        $remoteDispatches=0;
        $processed=0;
        $status='completed';
        foreach($results as $result){
            $remoteDispatches+=(int)($result['remote_dispatches']??0);
            $processed+=(int)($result['processed']??0);
            if(in_array((string)($result['status']??''),['review','dead'],true))$status='review';
        }
        return [
            'status'=>$status,
            'message'=>$status==='completed'
                ? 'La seleccion exacta termino. No quedo continuacion en segundo plano.'
                : 'La seleccion exacta termino con elementos para revision; no quedo continuacion en segundo plano.',
            'selected_count'=>count($items),
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
            'dead','review'=>'El paso quedó cerrado para revisión; no se repetirá en segundo plano.',
            default=>'El paso manual terminó sin continuación automática.',
        };
    }

    public static function idempotencyKey(int $companyId,int $accountId,string $queueKey,string $sourceId,string $inputVersion,string $explicitAttemptKey): string
    {
        return hash('sha256',implode('|',[$companyId,$accountId,$queueKey,$sourceId,$inputVersion,$explicitAttemptKey]));
    }
}
