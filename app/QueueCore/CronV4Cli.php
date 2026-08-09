<?php
declare(strict_types=1);
namespace App\QueueCore;
use App\Core\Database;
use App\Core\Env;
use App\Services\EmergencyControlService;
use App\Services\CronDeadlineContext;
use Throwable;
use PDO;
final class CronV4Cli
{
    public function __construct(private readonly ?PDO $pdo=null) {}
    /** @param list<string> $argv @return array<string,mixed> */
    public function run(array $argv): array
    {
        $runtime=$this->option($argv,'runtime',45,5,55);$max=$this->option($argv,'max-jobs',50,1,200);$started=microtime(true);$deadline=$started+$runtime;
        // Mandatory pre-bootstrap barrier: no PDO, producer, claim or recovery
        // may happen while automation is stopped.
        if((new EmergencyControlService())->automationStopped())return ['ok'=>true,'status'=>'SKIPPED_AUTOMATION_STOPPED','side_effects'=>0,'claimed'=>0];
        if(!Env::bool('CRON_V4_ENABLED',false))return ['ok'=>true,'status'=>'DISABLED','side_effects'=>0,'claimed'=>0];
        if(Env::bool('ML_WRITE_ENABLED',false))return ['ok'=>false,'status'=>'BLOCKED_ML_WRITE_ENABLED','side_effects'=>0,'claimed'=>0];
        $safeClose=min(10,max(1,$runtime-1));
        CronDeadlineContext::start($runtime,$runtime-$safeClose,8,3);
        $executionLease=null;
        try{if($this->pdo===null)Database::useProfile('cli');$core=QueueCoreFactory::build($this->pdo);$worker='cron-v4-'.bin2hex(random_bytes(8));$executionLease=$core['execution_leases']->acquire('cron_v4',$worker,60);if($executionLease===null){$active=$core['execution_leases']->activeLauncher();return ['ok'=>true,'status'=>$active==='manual'?'SKIPPED_MANUAL_ACTIVE':'SKIPPED_LAUNCHER_ACTIVE','side_effects'=>0,'producer_calls'=>0,'stale_recovery'=>0,'claimed'=>0,'handlers'=>0,'http'=>0];}$recovered=$core['repository']->recoverStale(min(100,$max));$produced=$core['producer']->scheduleDueAccounts(min(20,$max));$effectiveDeadline=CronDeadlineContext::deadline()??$deadline;$run=$core['runner']->run(new QueueRunRequest('cron_v4',$worker,$max,$effectiveDeadline,60,[],[],null,$executionLease));return ['ok'=>true,'status'=>'COMPLETE','producer'=>$produced,'recovered'=>$recovered,'run'=>$run,'metrics'=>(new QueueMetricsService($this->pdo??Database::connectionFresh()))->snapshot()];}
        catch(Throwable $e){return ['ok'=>false,'status'=>'LOCAL_FAILURE','error_class'=>strtolower((new \ReflectionClass($e))->getShortName()),'claimed'=>0];}
        finally{if($executionLease!==null&&isset($core))$core['execution_leases']->release($executionLease);CronDeadlineContext::clear();}
    }
    /** @param list<string> $argv */ private function option(array $argv,string $key,int $default,int $min,int $max):int{foreach($argv as $arg){if(str_starts_with($arg,'--'.$key.'='))return max($min,min($max,(int)substr($arg,strlen($key)+3)));}return $default;}
}
