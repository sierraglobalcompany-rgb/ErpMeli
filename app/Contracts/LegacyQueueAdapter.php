<?php

declare(strict_types=1);

namespace App\Contracts;

interface LegacyQueueAdapter
{
    public function queueKey(): string;

    public function lane(): string;

    /**
     * Imports at most $materializeLimit items after inspecting at most $scanLimit rows.
     *
     * @return array{scanned:int,materialized:int,checkpoint:?string,has_more:bool}
     */
    public function importBatch(int $scanLimit = 50, int $materializeLimit = 20): array;
}
