<?php

declare(strict_types=1);

namespace App\Work\Contracts;

use App\Work\WorkAdmissionReceipt;
use App\Work\WorkEnvelope;

interface CanonicalWorkStore extends WorkAdmissionContract
{
    /** @return array<string,mixed> */
    public function toPhysicalShape(WorkEnvelope $envelope): array;

    /** @return array{ready:int,running:int,waiting:int,review:int,dead:int,completed:int,total:int} */
    public function counts(): array;
}
