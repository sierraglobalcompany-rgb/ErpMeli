<?php

declare(strict_types=1);

namespace App\QueueCore;

use PDO;

/** Persisted fail-closed feature authority. Missing rows are disabled. */
final class QueueCoreFeatureFlagService
{
    public function __construct(private readonly PDO $pdo) {}

    public function enabled(string $feature): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT enabled FROM queue_core_feature_flags WHERE feature_key=? LIMIT 1'
        );
        $statement->execute([$feature]);
        return (int) $statement->fetchColumn() === 1;
    }

    public function hardCap(string $feature, int $fallback, int $maximum): int
    {
        $statement = $this->pdo->prepare(
            'SELECT enabled,hard_cap FROM queue_core_feature_flags WHERE feature_key=? LIMIT 1'
        );
        $statement->execute([$feature]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int) $row['enabled'] !== 1) {
            return 0;
        }
        return max(1, min($maximum, (int) ($row['hard_cap'] ?? $fallback)));
    }
}
