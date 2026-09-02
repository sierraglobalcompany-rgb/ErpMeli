<?php

declare(strict_types=1);

namespace App\QueueCore;

use PDO;
use RuntimeException;
use Throwable;

/** Persisted fail-closed feature authority. Missing rows are disabled. */
final class QueueCoreFeatureFlagService
{
    /** @var list<string> */
    public const READINESS_FEATURES = [
        'fresh_producer',
        'webhook_producer',
        'pack_shipment_followups',
        'remote_financial',
        'historical_importer',
    ];

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

    /**
     * Cambia exclusivamente la autoridad de features conocida mediante CAS de
     * imagen completa. No crea filas, no acepta features externas y conserva hard_cap.
     *
     * @param array<string,bool> $expected
     * @param array<string,bool> $target
     * @return array<string,bool>
     */
    public function compareAndSwapReadinessFlags(array $expected, array $target, int $generation): array
    {
        $this->assertExactFeatureSet($expected);
        $this->assertExactFeatureSet($target);
        if ($generation < 0) {
            throw new RuntimeException('Queue Core feature generation is invalid.');
        }

        try {
            $this->pdo->beginTransaction();
            $statement = $this->pdo->query(
                'SELECT feature_key,enabled FROM queue_core_feature_flags ORDER BY feature_key FOR UPDATE'
            );
            $current = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = (string) $row['feature_key'];
                if (in_array($key, self::READINESS_FEATURES, true)) {
                    $current[$key] = (int) $row['enabled'] === 1;
                }
            }
            ksort($current, SORT_STRING);
            $expectedSorted = $expected;
            ksort($expectedSorted, SORT_STRING);
            if ($current !== $expectedSorted) {
                $this->pdo->rollBack();
                throw new RuntimeException('Queue Core feature authority changed before CAS.');
            }

            $update = $this->pdo->prepare(
                'UPDATE queue_core_feature_flags SET enabled=?,generation=? WHERE feature_key=? AND enabled=?'
            );
            foreach (self::READINESS_FEATURES as $feature) {
                $before = $expected[$feature] ? 1 : 0;
                $after = $target[$feature] ? 1 : 0;
                if ($before === $after) {
                    continue;
                }
                $update->execute([$after, $generation, $feature, $before]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Queue Core feature CAS changed during update.');
                }
            }
            $this->pdo->commit();
            return $target;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,bool> */
    public function snapshot(): array
    {
        $rows = $this->pdo->query(
            'SELECT feature_key,enabled FROM queue_core_feature_flags ORDER BY feature_key'
        )->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $key = (string) $row['feature_key'];
            if (in_array($key, self::READINESS_FEATURES, true)) {
                $result[$key] = (int) $row['enabled'] === 1;
            }
        }
        ksort($result, SORT_STRING);
        $this->assertExactFeatureSet($result);
        return $result;
    }

    /** @param array<string,bool> $flags */
    private function assertExactFeatureSet(array $flags): void
    {
        $keys = array_keys($flags);
        sort($keys, SORT_STRING);
        $required = self::READINESS_FEATURES;
        sort($required, SORT_STRING);
        if ($keys !== $required) {
            throw new RuntimeException('Queue Core readiness feature set is incomplete.');
        }
        foreach ($flags as $enabled) {
            if (!is_bool($enabled)) {
                throw new RuntimeException('Queue Core readiness feature value is invalid.');
            }
        }
    }
}
