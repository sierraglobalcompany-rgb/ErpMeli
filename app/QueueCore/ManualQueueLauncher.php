<?php
declare(strict_types=1);
namespace App\QueueCore;
use App\Core\Database;
use App\Services\CronDeadlineContext;
final class ManualQueueLauncher
{
    /** @return array<string,mixed> */
    public function runExact(int $companyId,int $accountId,string $queueKey,string $sourceId,bool $usesApi): array
    {$core=QueueCoreFactory::build();$jobId=$core['repository']->enqueue(new QueueJob($companyId,$accountId,'manual_exact',$queueKey,$sourceId,$usesApi?'normal':'local',80,hash('sha256',implode('|',[$companyId,$accountId,$queueKey,$sourceId])),'v1','manual_web','manual:'.$queueKey.':'.$sourceId,['queue_key'=>$queueKey,'source_id'=>$sourceId],['launcher'=>'manual_single_step'],3));CronDeadlineContext::start(25,20,8,3);try{$request=new QueueRunRequest('manual','manual-'.bin2hex(random_bytes(8)),1,CronDeadlineContext::deadline()??microtime(true)+25,45,[$jobId],['manual_exact'],$accountId);$summary=$core['runner']->run($request);}finally{CronDeadlineContext::clear();}$row=$core['repository']->job($jobId)??[];$attempt=Database::connectionFresh()->prepare('SELECT physical_http_calls,resources_persisted FROM queue_core_attempts WHERE job_id=? ORDER BY id DESC LIMIT 1');$attempt->execute([$jobId]);$evidence=$attempt->fetch(\PDO::FETCH_ASSOC)?:[];return ['status'=>(string)($row['state']??'review'),'message'=>self::message((string)($row['state']??'review')),'queue_key'=>$queueKey,'source_id'=>$sourceId,'remote_dispatches'=>(int)($evidence['physical_http_calls']??0),'processed'=>(int)($evidence['resources_persisted']??0),'queue_core_job_id'=>$jobId,'launcher_summary'=>$summary];}
    private static function message(string $state): string{return match($state){'completed'=>'El trabajo exacto terminó por Queue Core.','retry_wait'=>'El trabajo continuará en la próxima oportunidad segura.','dead'=>'El trabajo agotó sus intentos y necesita revisión.','review'=>'El resultado requiere revisión antes de repetirlo.',default=>'Queue Core conservó el trabajo para continuar de forma segura.'};}
}
