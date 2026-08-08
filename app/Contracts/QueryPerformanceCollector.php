<?php

declare(strict_types=1);

namespace App\Contracts;

use App\ValueObjects\QueryTrace;

interface QueryPerformanceCollector
{
    public function begin(string $route): QueryTrace;

    public function finish(QueryTrace $trace): void;
}
