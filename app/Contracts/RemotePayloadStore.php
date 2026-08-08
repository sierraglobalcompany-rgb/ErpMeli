<?php

declare(strict_types=1);

namespace App\Contracts;

use App\ValueObjects\PayloadContext;
use App\ValueObjects\PayloadReference;

interface RemotePayloadStore
{
    public function persist(string $payload, PayloadContext $context): PayloadReference;

    public function verify(PayloadReference $reference): bool;

    public function retrieve(PayloadReference $reference): string;
}
