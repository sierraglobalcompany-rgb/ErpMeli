<?php
declare(strict_types=1);
namespace App\QueueCore;
/**
 * Pure classification seam for a later, explicit legacy import phase.
 * It performs no reads, writes or cleanup and can never contaminate fresh.
 */
final class LegacyWorkClassifier
{
    /** @param array<string,mixed> $payload */
    public function historical(int $companyId,int $accountId,string $workType,string $resourceType,string $resourceId,string $inputVersion,array $payload=[]):QueueJob
    {return new QueueJob($companyId,$accountId,$workType,$resourceType,$resourceId,'historical_backfill',-100,hash('sha256',implode('|',[$companyId,$accountId,$workType,$resourceId])),$inputVersion,'legacy_import',null,$payload,['classification'=>'historical_backfill'],5);}
}
