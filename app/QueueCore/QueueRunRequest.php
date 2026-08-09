<?php
declare(strict_types=1);
namespace App\QueueCore;
final readonly class QueueRunRequest
{
    /** @param list<int> $allowedJobIds Legacy argument; never reorders FIFO. @param list<string> $allowedWorkTypes */
    public function __construct(public string $launcher,public string $workerId,public int $maxJobs,
        public float $deadline,public int $leaseSeconds=60,public array $allowedJobIds=[],
        public array $allowedWorkTypes=[],public ?int $accountId=null,
        public ?QueueExecutionLease $executionLease=null,public ?string $queueDomain=null,
        public ?int $targetJobId=null,public ?int $runId=null) {}

    public function domain(): ?string
    {
        if($this->queueDomain!==null)return $this->queueDomain;
        return match($this->launcher){'cron_v4','canary_v4'=>'operational','manual'=>'manual',default=>null};
    }
}
