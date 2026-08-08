<?php

declare(strict_types=1);

namespace App\Contracts;

use App\ValueObjects\JobLease;
use App\ValueObjects\JobResult;

interface JobHandler
{
    public function handle(JobLease $lease): JobResult;
}
