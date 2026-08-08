<?php

declare(strict_types=1);

namespace App\Services;

final class RawPayloadReader
{
    /** @param array<string,mixed> $row @return array<string,mixed>|null */
    public function decode(array $row, string $entityTable): ?array
    {
        $raw = trim((string) ($row['raw_json'] ?? ''));
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        }
        $entityId = (int) ($row['id'] ?? 0);
        $accountId = (int) ($row['meli_account_id'] ?? 0);
        $reference = (new FileRemotePayloadStore())->referenceFor($entityTable, $entityId, $accountId);
        if ($reference === null) {
            return null;
        }
        $decoded = json_decode((new FileRemotePayloadStore())->retrieve($reference), true);
        return is_array($decoded) ? $decoded : null;
    }
}
