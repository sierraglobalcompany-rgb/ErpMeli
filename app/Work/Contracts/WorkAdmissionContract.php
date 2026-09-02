<?php

declare(strict_types=1);

namespace App\Work\Contracts;

use App\Work\WorkAdmissionReceipt;
use App\Work\WorkEnvelope;

interface WorkAdmissionContract
{
    public function admit(WorkEnvelope $envelope): WorkAdmissionReceipt;
}
