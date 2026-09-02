<?php

declare(strict_types=1);

namespace App\Work;

final readonly class WorkAdmissionReceipt
{
    public function __construct(
        public bool $accepted,
        public ?int $workId,
        public bool $deduplicated,
        public string $reason,
        public string $canonicalWorkType,
        public string $adapter,
    ) {
    }

    /** @return array{accepted:bool,job_id:?int,deduplicated:bool,reason:string} */
    public function toLegacyCronAdmissionReceipt(): array
    {
        return [
            'accepted' => $this->accepted,
            'job_id' => $this->workId,
            'deduplicated' => $this->deduplicated,
            'reason' => $this->reason,
        ];
    }
}
