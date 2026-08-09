<?php
declare(strict_types=1);

namespace App\QueueCore;

use App\Core\Database;
use App\Services\ApiExecutionMetadataContext;
use App\Services\EmergencyControlService;
use RuntimeException;

final class QueueCoreDispatchFence
{
    public static function beforeTransport(string $method,string $endpoint): void
    {
        $m=ApiExecutionMetadataContext::current();
        if(($m['source']??'')!=='queue_core')return;
        if((new EmergencyControlService())->automationStopped()){
            throw new RuntimeException('Automation Stop denied Queue Core transport.');
        }
        $claim=self::claim($m);
        $attempt=max(0,(int)($m['queue_core_attempt_id']??0));
        if($attempt<1 || !(new QueueCoreRepository(Database::connectionFresh()))->dispatchStarted($claim,$attempt,$method,$endpoint)){
            throw new RuntimeException('Queue Core fencing denied the physical transport.');
        }
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
}
