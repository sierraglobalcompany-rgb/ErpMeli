<?php
declare(strict_types=1);

namespace App\QueueCore;

use App\Core\Database;
use App\Services\ApiExecutionMetadataContext;
use App\Services\EmergencyControlService;
final class QueueCoreDispatchFence
{
    public static function beforeTransport(string $method,string $endpoint): void
    {
        $m=ApiExecutionMetadataContext::current();
        if(($m['source']??'')!=='queue_core')return;
        if(($m['queue_core_launcher']??'')==='cron_v4' && (new EmergencyControlService())->automationStopped()){
            throw new QueueCorePreRemoteBlockedException('Automation Stop denied Cron V4 transport.');
        }
        $claim=self::claim($m);
        $attempt=max(0,(int)($m['queue_core_attempt_id']??0));
        if($attempt<1 || !self::renewFences($m,$claim)
            || !(new QueueCoreRepository(Database::connectionFresh()))->reserveTransport($claim,$attempt)){
            throw new QueueCorePreRemoteBlockedException('Queue Core fencing denied transport before cURL.');
        }
    }

    public static function transportStarted(string $method,string $endpoint): void
    {
        $m=ApiExecutionMetadataContext::current();
        if(($m['source']??'')!=='queue_core')return;
        $claim=self::claim($m);$attempt=max(0,(int)($m['queue_core_attempt_id']??0));
        if($attempt<1 || !self::renewFences($m,$claim)
            || !(new QueueCoreRepository(Database::connectionFresh()))->physicalTransportStarted($claim,$attempt,$method,$endpoint)){
            throw new QueueCorePreRemoteBlockedException('Queue Core fencing denied the physical HTTP boundary.');
        }
    }

    public static function heartbeat(): bool
    {
        $m=ApiExecutionMetadataContext::current();
        if(($m['source']??'')!=='queue_core')return true;
        return self::renewFences($m,self::claim($m));
    }

    public static function responseKnown(int $status): void
    {
        $m=ApiExecutionMetadataContext::current();
        if(($m['source']??'')!=='queue_core')return;
        $attempt=max(0,(int)($m['queue_core_attempt_id']??0));
        if($attempt<1 || !(new QueueCoreRepository(Database::connectionFresh()))->responseKnown(self::claim($m),$attempt,$status)){
            throw new RuntimeException('Queue Core rejected a response from a stale worker.');
        }
    }

    /** @param array<string,scalar|null> $m */
    private static function claim(array $m): QueueClaim
    {
        return new QueueClaim((int)($m['queue_core_job_id']??0),(int)($m['company_id']??0),(int)($m['account_id']??0),(string)($m['queue_core_work_type']??''),'remote',null,(string)($m['queue_core_lane']??'normal'),0,'running',(int)($m['queue_core_attempt_count']??1),(int)($m['queue_core_max_attempts']??1),(string)($m['queue_core_lease_owner']??''),(int)($m['queue_core_lease_generation']??0),'NOT_DISPATCHED',[],'queue_core',null);
    }

    /** @param array<string,scalar|null> $m */
    private static function renewFences(array $m,QueueClaim $claim): bool
    {
        $pdo=Database::connectionFresh();
        if(!(new QueueCoreRepository($pdo))->validateTransport($claim,max(5,(int)($m['queue_core_lease_seconds']??60))))return false;
        $owner=(string)($m['queue_core_execution_owner']??'');$generation=(int)($m['queue_core_execution_generation']??0);
        if($owner==='')return true;
        $lease=new QueueExecutionLease((string)($m['queue_core_launcher']??'test'),$owner,$generation,max(5,(int)($m['queue_core_execution_lease_seconds']??60)));
        return (new QueueExecutionLeaseService($pdo))->heartbeat($lease);
    }
}
