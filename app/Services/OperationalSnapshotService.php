<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class OperationalSnapshotService
{
    /** @param array<string,mixed> $summary @param array<string,array<string,mixed>> $availability */
    public function recordCron(string $runToken, array $summary, array $availability): void
    {
        if (!(new SchemaInspectorService())->hasTable('system_operational_snapshots')) {
            return;
        }
        try {
            $pdo = Database::connectionFresh();
            $previous = $this->latestRow($pdo);
        } catch (Throwable) {
            // El snapshot es telemetría auxiliar: una conexión no disponible no
            // puede convertir un ciclo útil de Cron en fallido.
            return;
        }
        $previousPayload = is_array($previous)
            ? json_decode((string) ($previous['payload_json'] ?? ''), true)
            : null;
        $previousQueues = is_array($previousPayload['queues'] ?? null)
            ? $previousPayload['queues']
            : [];
        $queues = [];
        $hasUnavailable = false;
        $hasPartial = false;
        foreach ($availability as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $queueKey = (string) $key;
            $prior = is_array($previousQueues[$queueKey] ?? null) ? $previousQueues[$queueKey] : [];
            $state = in_array((string) ($row['measurement_state'] ?? ''), ['complete', 'partial', 'unavailable'], true)
                ? (string) $row['measurement_state']
                : 'unavailable';
            $pending = $this->nonNegativeValue($row, ['total_pending', 'pending']);
            $eligible = $this->nonNegativeValue($row, ['eligible_now', 'work_count']);
            $waiting = $this->nonNegativeValue($row, ['waiting_schedule']);
            $running = $this->nonNegativeValue($row, ['running', 'running_count']);
            $attention = $this->nonNegativeValue($row, ['attention', 'attention_count']);
            $carried = false;
            foreach ([
                'pending' => &$pending,
                'eligible_now' => &$eligible,
                'waiting_schedule' => &$waiting,
                'running_count' => &$running,
                'attention_count' => &$attention,
            ] as $field => &$value) {
                if ($value === null && array_key_exists($field, $prior) && is_numeric($prior[$field])) {
                    $value = max(0, (int) $prior[$field]);
                    $carried = true;
                }
            }
            unset($value);
            $queues[$queueKey] = [
                'pending' => $pending,
                'eligible_now' => $eligible,
                'waiting_schedule' => $waiting,
                'running_count' => $running,
                'attention_count' => $attention,
                'measurement_state' => $state,
                'value_state' => $carried ? 'last_known' : ($state === 'complete' ? 'current' : 'unknown'),
                'measured_at' => $row['measured_at'] ?? $row['observed_at'] ?? null,
                'remote' => array_key_exists('remote', $row) ? !empty($row['remote']) : !empty($prior['remote']),
            ];
            $hasUnavailable = $hasUnavailable || $state === 'unavailable';
            $hasPartial = $hasPartial || $state === 'partial' || $carried;
        }
        foreach ($previousQueues as $key => $prior) {
            if (isset($queues[$key]) || !is_array($prior)) {
                continue;
            }
            $queues[(string) $key] = array_merge($prior, [
                'measurement_state' => 'unavailable',
                'value_state' => 'last_known',
            ]);
            $hasUnavailable = true;
        }
        $remoteBacklog = 0;
        $remoteBacklogKnown = true;
        foreach ($queues as $queue) {
            if (empty($queue['remote'])) {
                continue;
            }
            if (!is_numeric($queue['pending'] ?? null)) {
                $remoteBacklogKnown = false;
                continue;
            }
            $remoteBacklog += max(0, (int) $queue['pending']);
        }
        $hasKnownQueue = array_filter(
            $queues,
            static fn (array $queue): bool => is_numeric($queue['pending'] ?? null)
        ) !== [];
        $protocol = !$hasKnownQueue
            ? 'unavailable'
            : (($hasUnavailable || $hasPartial || !$remoteBacklogKnown) ? 'partial' : 'complete');
        if ($protocol === 'complete' && array_sum(array_map(
            static fn (array $queue): int => max(0, (int) ($queue['pending'] ?? 0)),
            $queues
        )) === 0) {
            $protocol = 'authoritative_empty';
        }
        $payload = [
            'protocol' => $protocol,
            'run_token' => $runToken,
            'measured_at' => gmdate('Y-m-d H:i:s'),
            'remote_backlog' => $remoteBacklogKnown ? $remoteBacklog : null,
            'queues' => $queues,
            'counters' => [
                'selected' => max(0, (int) ($summary['selected'] ?? 0)),
                'started' => max(0, (int) ($summary['started'] ?? 0)),
                'attempted_http' => max(0, (int) ($summary['attempted_remote_calls'] ?? 0)),
                'http_dispatched' => max(0, (int) ($summary['remote_calls'] ?? 0)),
                'blocked_http' => max(0, (int) ($summary['blocked_remote_calls'] ?? 0)),
                'completed' => max(0, (int) ($summary['completed'] ?? 0)),
                'deferred' => max(0, (int) ($summary['deferred'] ?? 0)),
                'failed' => max(0, (int) ($summary['errors'] ?? 0)),
                'not_started' => max(0, (int) ($summary['not_started'] ?? 0)),
            ],
            'end_reason' => (string) ($summary['end_reason'] ?? ''),
            'campaign' => is_array($summary['manual_campaign'] ?? null) ? $summary['manual_campaign'] : null,
        ];
        try {
            if (is_array($previous)) {
                $old = $previousPayload;
                $sameState = is_array($old)
                    && ($old['protocol'] ?? null) === $payload['protocol']
                    && ($old['remote_backlog'] ?? null) === $payload['remote_backlog']
                    && $this->comparableQueues(is_array($old['queues'] ?? null) ? $old['queues'] : [])
                        === $this->comparableQueues($payload['queues'])
                    && ($old['counters'] ?? null) === $payload['counters']
                    && ($old['campaign']['status'] ?? null) === ($payload['campaign']['status'] ?? null);
                $measured = strtotime((string) $previous['measured_at'] . ' UTC');
                if ($sameState && $measured !== false && $measured >= time() - 300) {
                    return;
                }
            }
            $pdo->prepare(
                'INSERT INTO system_operational_snapshots
                 (snapshot_kind,scope_signature,generation,run_token,protocol,payload_json,measured_at)
                  VALUES ("cron_cycle","global",:generation,:run_token,:protocol,:payload,UTC_TIMESTAMP(3))'
            )->execute([
                'generation' => substr(hash('sha256', $runToken . '|' . json_encode($queues)), 0, 40),
                'run_token' => $runToken,
                'protocol' => $protocol,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
        } catch (Throwable) {
            // La telemetría no puede convertir un ciclo útil en fallido.
        }
    }

    /** @return array<string,mixed>|null */
    public function latestCron(): ?array
    {
        if (!(new SchemaInspectorService())->hasTable('system_operational_snapshots')) {
            return null;
        }
        try {
            $row = Database::connectionFresh()->query(
                'SELECT protocol,payload_json,measured_at
                 FROM system_operational_snapshots
                 WHERE snapshot_kind="cron_cycle" AND scope_signature="global"
                 ORDER BY measured_at DESC,id DESC LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return null;
            }
            $payload = json_decode((string) $row['payload_json'], true, 32, JSON_THROW_ON_ERROR);
            return is_array($payload) ? $payload + ['protocol' => (string) $row['protocol'], 'measured_at' => $row['measured_at']] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed>|false */
    private function latestRow(PDO $pdo): array|false
    {
        return $pdo->query(
            'SELECT protocol,payload_json,measured_at
             FROM system_operational_snapshots
             WHERE snapshot_kind="cron_cycle" AND scope_signature="global"
             ORDER BY measured_at DESC,id DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $row @param list<string> $keys */
    private function nonNegativeValue(array $row, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && is_numeric($row[$key])) {
                return max(0, (int) $row[$key]);
            }
        }
        return null;
    }

    /** @param array<string,mixed> $queues @return array<string,mixed> */
    private function comparableQueues(array $queues): array
    {
        foreach ($queues as &$queue) {
            if (is_array($queue)) {
                unset($queue['measured_at']);
            }
        }
        unset($queue);
        ksort($queues);
        return $queues;
    }
}
