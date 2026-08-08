<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

/**
 * Materializa como máximo una familia legacy por ciclo local. El adaptador
 * conserva su propio checkpoint y solo opera cuando V3 posee la cola.
 */
final class CronV3LegacyImportCoordinator
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{queue_key:?string,read:int,materialized:int,created:int,status:string} */
    public function importOne(int $readLimit = 50, int $materializeLimit = 20): array
    {
        $result = $this->importMany($readLimit, $materializeLimit, 1);
        $first = $result['queues'][0] ?? null;
        if (is_array($first)) {
            return [
                'queue_key' => $first['queue_key'],
                'read' => (int) $first['read'],
                'materialized' => (int) $first['materialized'],
                'created' => (int) $first['created'],
                'status' => (string) $first['status'],
            ];
        }

        return $this->empty($result['status']);
    }

    /** @return array{status:string,queues:list<array{queue_key:string,read:int,materialized:int,created:int,status:string}>,read:int,materialized:int,created:int} */
    public function importMany(int $readLimit = 50, int $materializeLimit = 20, int $familyLimit = 4): array
    {
        $definitions = CronV3LegacyQueueAdapter::definitions();
        $keys = array_keys($definitions);
        if ($keys === []) {
            return $this->emptyMany('no_adapters');
        }

        try {
            $enabled = $this->enabledKeys($definitions);
            if ($enabled === []) {
                return $this->emptyMany('no_owned_legacy_queue');
            }
            $cursor = $this->cursor();
            $ordered = array_values(array_filter($keys, static fn (string $key): bool => isset($enabled[$key])));
            $start = $ordered === [] ? 0 : (($cursor + 1) % count($ordered));
            $summary = $this->emptyMany('imported');
            for ($offset = 0; $offset < count($ordered); $offset++) {
                $index = ($start + $offset) % count($ordered);
                $key = $ordered[$index];
                $result = (new CronV3LegacyQueueAdapter($this->pdo, $key))
                    ->importBatch($readLimit, $materializeLimit);
                $this->saveCursor($index);
                $queue = [
                    'queue_key' => $key,
                    'read' => (int) $result['read'],
                    'materialized' => (int) $result['materialized'],
                    'created' => (int) $result['created'],
                    'status' => 'imported',
                ];
                $summary['queues'][] = $queue;
                $summary['read'] += $queue['read'];
                $summary['materialized'] += $queue['materialized'];
                $summary['created'] += $queue['created'];
                if (count($summary['queues']) >= max(1, min(12, $familyLimit))) {
                    return $summary;
                }
            }
            return $summary;
        } catch (Throwable) {
            return $this->emptyMany('legacy_import_unavailable');
        }
    }

    /** @param array<string,array<string,mixed>> $definitions @return array<string,true> */
    private function enabledKeys(array $definitions): array
    {
        $keys = [];
        foreach ($definitions as $queueKey => $definition) {
            $keys[(string) $queueKey] = true;
            foreach ((array) ($definition['ownership_keys'] ?? []) as $key) {
                $keys[(string) $key] = true;
            }
        }
        $keys = array_keys($keys);
        if ($keys === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($keys), '?'));
        $statement = $this->pdo->prepare(
            'SELECT queue_key,lane FROM cron_v3_queue_ownership
             WHERE owner_engine="v3" AND enabled=1 AND queue_key IN (' . $marks . ')'
        );
        $statement->execute($keys);
        $owned = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $owned[(string) $row['queue_key']] = (string) $row['lane'];
        }
        $enabled = [];
        foreach ($definitions as $queueKey => $definition) {
            $lane = (string) ($definition['lane'] ?? '');
            foreach ((array) ($definition['ownership_keys'] ?? [$queueKey]) as $key) {
                if (($owned[(string) $key] ?? null) === $lane) {
                    $enabled[(string) $queueKey] = true;
                    break;
                }
            }
        }

        return $enabled;
    }

    private function cursor(): int
    {
        $statement = $this->pdo->prepare(
            'SELECT payload_json FROM cron_v3_snapshots WHERE snapshot_key="legacy-adapter:round-robin" LIMIT 1'
        );
        $statement->execute();
        $payload = json_decode((string) ($statement->fetchColumn() ?: '{}'), true);
        return max(-1, (int) ($payload['cursor'] ?? -1));
    }

    private function saveCursor(int $cursor): void
    {
        $payload = json_encode(['cursor' => $cursor], JSON_UNESCAPED_SLASHES) ?: '{}';
        $this->pdo->prepare(
            'INSERT INTO cron_v3_snapshots
                (snapshot_key,snapshot_type,lane,payload_json,generation,observed_at)
             VALUES ("legacy-adapter:round-robin","run","local",?,1,UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json),
                generation=generation+1,observed_at=UTC_TIMESTAMP(3)'
        )->execute([$payload]);
    }

    /** @return array{queue_key:?string,read:int,materialized:int,created:int,status:string} */
    private function empty(string $status): array
    {
        return ['queue_key' => null, 'read' => 0, 'materialized' => 0, 'created' => 0, 'status' => $status];
    }

    /** @return array{status:string,queues:list<array{queue_key:string,read:int,materialized:int,created:int,status:string}>,read:int,materialized:int,created:int} */
    private function emptyMany(string $status): array
    {
        return ['status' => $status, 'queues' => [], 'read' => 0, 'materialized' => 0, 'created' => 0];
    }
}
