<?php

declare(strict_types=1);

namespace App\Work\Contracts;

use App\Work\DrainResult;

interface DrainerContract
{
    public function drain(string $drainerId, int $maxPhysicalApiCalls, int $runtimeSeconds): DrainResult;
}
