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
        ?array $contract=null
    ): array {
        $core=QueueCoreFactory::build();
        $worker='manual-'.bin2hex(random_bytes(8));
        $lease=$core['execution_leases']->acquire('manual',$worker,60);
        if($lease===null)throw new RuntimeException('Ya hay un paso manual en curso. Espere su resultado.');
        try {
            if($usesApi&&$contract===null)throw new RuntimeException('El trabajo manual no tiene un contrato remoto certificado.');
            $pdo=Database::connectionFresh();
            $core['repository']->recoverAbandonedManualJobs();
            $busy=$pdo->query("SELECT id FROM queue_core_jobs WHERE queue_domain='manual' AND state IN ('pending','claimed','running','retry_wait','waiting_oauth') ORDER BY id LIMIT 1")->fetchColumn();
            if((int)$busy>0)throw new ManualFifoBusyException();

            CronDeadlineContext::start(25,20,8,3);
            $jobId=$core['repository']->enqueue(new QueueJob(
                $companyId,$accountId,'manual_exact',$queueKey,$sourceId,
                $usesApi?'normal':'local',80,
                hash('sha256',implode('|',[$companyId,$accountId,$queueKey,$sourceId,$inputVersion])),
                $inputVersion,'manual_web','manual:'.$queueKey.':'.$sourceId,
                [
                    'queue_key'=>$queueKey,
                    'source_id'=>$sourceId,
                    'operation_key'=>$operationKey,
                    'source_authority_version'=>$sourceAuthorityVersion,
                    'remote_contract'=>$contract,
                ],
                ['launcher'=>'manual_single_step','durable_input_version'=>$inputVersion],
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
            }
            $after=$core['repository']->job($jobId);
            if(is_array($after)&&in_array((string)$after['state'],['pending','claimed','running','retry_wait','waiting_oauth'],true)){
                $core['repository']->abandonManualJob($jobId,'manual_step_no_background_continuation');
            }
        } finally {
            CronDeadlineContext::clear();
            $core['execution_leases']->release($lease);
        }

        $row=$core['repository']->job($jobId)??[];
        $attempt=Database::connectionFresh()->prepare('SELECT physical_http_calls,resources_persisted FROM queue_core_attempts WHERE job_id=? ORDER BY id DESC LIMIT 1');
        $attempt->execute([$jobId]);$evidence=$attempt->fetch(PDO::FETCH_ASSOC)?:[];
        return [
            'status'=>(string)($row['state']??'review'),
            'message'=>self::message((string)($row['state']??'review')),
            'queue_key'=>$queueKey,'source_id'=>$sourceId,
            'remote_dispatches'=>(int)($evidence['physical_http_calls']??0),
            'processed'=>(int)($evidence['resources_persisted']??0),
            'queue_core_job_id'=>$jobId,'launcher_summary'=>$summary,
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
}
