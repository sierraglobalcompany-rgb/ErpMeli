<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\CronV3ExecutionContext;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;

interface CronV3WorkHandler
{
    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult;
}
