<?php
declare(strict_types=1);
namespace App\QueueCore;
final readonly class QueueRunRequest
{
    /** @param list<int> $allowedJobIds @param list<string> $allowedWorkTypes */
    public function __construct(public string $launcher,public string $workerId,public int $maxJobs,
        public float $deadline,public int $leaseSeconds=60,public array $allowedJobIds=[],
        public array $allowedWorkTypes=[],public ?int $accountId=null,
        public ?QueueExecutionLease $executionLease=null) {}
}
