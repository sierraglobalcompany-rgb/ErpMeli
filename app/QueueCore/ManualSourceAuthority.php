<?php

declare(strict_types=1);

namespace App\QueueCore;

final readonly class ManualSourceAuthority
{
    /**
     * @param array{method:string,endpoint_pattern:string,operation_key:string,max_remote_calls:int}|null $remoteContract
     */
    public function __construct(
        public string $durableInputVersion,
        public bool $usesApi,
        public string $operationKey,
        public ?array $remoteContract,
        public bool $explicitlyUnsupported = false,
        public ?string $unsupportedReason = null,
    ) {
    }
}
