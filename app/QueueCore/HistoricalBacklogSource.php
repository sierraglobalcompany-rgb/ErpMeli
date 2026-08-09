<?php

declare(strict_types=1);

namespace App\QueueCore;

use PDO;

/** A closed, tenant-scoped source contract for one legacy backlog. */
interface HistoricalBacklogSource
{
    public function key(): string;

    public function requiredCapability(): string;

    public function highWater(PDO $pdo, int $companyId, int $accountId): int;

    /** @return list<array<string,mixed>> */
    public function scan(PDO $pdo, int $companyId, int $accountId, int $afterId, int $highWaterId, int $limit): array;

    /** @param array<string,mixed> $row @return array{job:?QueueJob,reason:?string,source_id:int,source_state:string,source_version:string,source_generation:int,input_version:string,snapshot:array<string,mixed>,evidence_sha256:string} */
    public function classify(array $row, LegacyWorkClassifier $classifier): array;

    /** @param array<string,mixed> $receipt */
    public function close(PDO $pdo, array $receipt): bool;

    /** @param array<string,mixed> $receipt */
    public function restore(PDO $pdo, array $receipt): bool;
}
