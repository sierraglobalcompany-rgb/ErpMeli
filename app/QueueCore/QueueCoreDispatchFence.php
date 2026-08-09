<?php
declare(strict_types=1);

namespace App\QueueCore;

use App\Core\Database;
use App\Services\ApiExecutionMetadataContext;
use App\Services\EmergencyControlService;
use App\Services\MeliEmergencyStopService;
use RuntimeException;
final class QueueCoreDispatchFence
{
    public static function beforeTransport(string $method,string $endpoint): void
    {
        $m=ApiExecutionMetadataContext::current();
        if(($m['source']??'')!=='queue_core')return;
        if(($m['queue_core_launcher']??'')==='cron_v4' && (new EmergencyControlService())->automationStopped()){
            throw new QueueCorePreRemoteBlockedException('Automation Stop denied Cron V4 transport.');
        }
        self::assertCapability($m,$method,$endpoint);
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
        if(($m['queue_core_launcher']??'')==='cron_v4' && (new EmergencyControlService())->automationStopped()){
            throw new QueueCorePreRemoteBlockedException('Automation Stop denied the physical HTTP boundary.');
        }
        self::assertCapability($m,$method,$endpoint);
        $claim=self::claim($m);$attempt=max(0,(int)($m['queue_core_attempt_id']??0));
        if($attempt<1 || !self::renewFences($m,$claim)){
            throw new QueueCorePreRemoteBlockedException('Queue Core fencing denied the physical HTTP boundary.');
        }
        if(($m['queue_core_launcher']??'')==='cron_v4' && (new EmergencyControlService())->automationStopped()){
            throw new QueueCorePreRemoteBlockedException('Automation Stop changed before the physical HTTP boundary.');
        }
        // The physical API stop is checked again at the last Queue Core
        // instruction before persisting the boundary. CurlMeliHttpTransport
        // invokes curl_exec immediately after this method returns.
        try{
            (new MeliEmergencyStopService())->assertTransportAllowed($method,$endpoint);
        }catch(\App\Services\ApiManualPauseException $blocked){
            throw new QueueCorePreRemoteBlockedException('API Stop changed before the physical HTTP boundary.',0,$blocked);
        }
        if(!(new QueueCoreRepository(Database::connectionFresh()))->physicalTransportStarted($claim,$attempt,$method,$endpoint)){
            throw new QueueCorePreRemoteBlockedException('Queue Core fencing denied the physical HTTP boundary.');
        }
    }

    /** True only when the exact current attempt has a persisted physical HTTP marker. */
    public static function physicalTransportRecorded(): bool
    {
        $m=ApiExecutionMetadataContext::current();
        if(($m['source']??'')!=='queue_core')return false;
        $attempt=max(0,(int)($m['queue_core_attempt_id']??0));
        return $attempt>0 && (new QueueCoreRepository(Database::connectionFresh()))
            ->physicalTransportRecorded(self::claim($m),$attempt);
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

    /** @param array<string,scalar|null> $m */
    private static function assertCapability(array $m,string $method,string $endpoint): void
    {
        $launcher=(string)($m['queue_core_launcher']??'');
        $boundLauncher=(string)($m['queue_core_capability_launcher']??'');
        $domain=(string)($m['queue_core_domain']??'');
        $expectedMethod=strtoupper((string)($m['queue_core_expected_method']??''));
        $pattern=(string)($m['queue_core_expected_endpoint_pattern']??'');
        $expectedOperation=(string)($m['queue_core_expected_operation']??'');
        $actualOperation=(string)($m['transport_operation_key']??'');
        $maxCalls=max(0,(int)($m['queue_core_max_remote_calls']??0));
        $usesApi=(int)($m['queue_core_uses_api']??0)===1;
        $validDomain=($launcher==='cron_v4'&&$domain==='operational')
            || ($launcher==='manual'&&$domain==='manual')
            || ($launcher==='test'&&in_array($domain,['operational','manual'],true));
        if(!$usesApi || $launcher!==$boundLauncher || !$validDomain || $maxCalls!==1
            || $expectedMethod==='' || strtoupper($method)!==$expectedMethod
            || $pattern==='' || @preg_match($pattern,$endpoint)!==1
            || $expectedOperation==='' || $actualOperation!==$expectedOperation
            || $actualOperation==='unknown_read'){
            throw new QueueCorePreRemoteBlockedException('Queue Core capability denied the physical transport.');
        }
    }
}
