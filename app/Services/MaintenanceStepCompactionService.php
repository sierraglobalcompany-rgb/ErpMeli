<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

final class MaintenanceStepCompactionService
{
    /** @return array<string,mixed> */
    public function compact(int $sessionId, int $keepTail = 50, int $limit = 1000): array
    {
        $keepTail = max(10, min(500, $keepTail));
        $limit = max(1, min(5000, $limit));
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT *
                 FROM database_maintenance_sessions
                 WHERE id=:id
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['id' => $sessionId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($session)) {
                throw new RuntimeException('No se encontro la sesion de saneamiento.');
            }
            if (!in_array((string) $session['status'], ['completed', 'finished', 'failed'], true)) {
                $pdo->commit();
                return ['session_id' => $sessionId, 'deleted' => 0, 'skipped' => true, 'reason' => 'active_session'];
            }

            $maxSequence = (int) $pdo->query(
                'SELECT COALESCE(MAX(sequence_no),0)
                 FROM database_maintenance_steps
                 WHERE maintenance_session_id=' . $sessionId
            )->fetchColumn();
            $cutoff = max(0, $maxSequence - $keepTail);
            if ($cutoff <= 0) {
                $pdo->commit();
                return ['session_id' => $sessionId, 'deleted' => 0, 'skipped' => true, 'reason' => 'tail_only'];
            }

            $rows = $pdo->query(
                'SELECT id,sequence_no,idempotency_key,phase,dataset_key,status,rows_reviewed,rows_archived,
                        rows_summarized,rows_deleted,payloads_externalized,bytes_released,
                        duration_ms,safe_message,started_at,completed_at
                 FROM database_maintenance_steps
                 WHERE maintenance_session_id=' . $sessionId . '
                   AND status="completed"
                   AND sequence_no<=' . $cutoff . '
                 ORDER BY sequence_no ASC
                 LIMIT ' . $limit
            )->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                $pdo->commit();
                return ['session_id' => $sessionId, 'deleted' => 0, 'skipped' => true, 'reason' => 'nothing_to_compact'];
            }

            $summary = $this->mergeSummary(
                json_decode((string) ($session['steps_summary_json'] ?? ''), true),
                $rows
            );
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
            $pdo->prepare(
                'UPDATE database_maintenance_sessions
                 SET steps_summary_json=:summary,
                     steps_compacted_count=:count,
                     steps_compacted_at=UTC_TIMESTAMP(3)
                 WHERE id=:id'
            )->execute([
                'summary' => json_encode(
                    $summary,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                'count' => (int) ($summary['compacted_steps'] ?? 0),
                'id' => $sessionId,
            ]);
            $pdo->exec(
                'DELETE FROM database_maintenance_steps
                 WHERE id IN (' . implode(',', $ids) . ')'
            );
            $pdo->commit();
            return [
                'session_id' => $sessionId,
                'deleted' => count($ids),
                'remaining_tail' => $keepTail,
                'complete' => count($ids) < $limit,
            ];
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * @param mixed $existing
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function mergeSummary(mixed $existing, array $rows): array
    {
        $summary = is_array($existing) ? $existing : [];
        $summary['version'] = 1;
        $summary['compacted_steps'] = (int) ($summary['compacted_steps'] ?? 0);
        $summary['rows_reviewed'] = (int) ($summary['rows_reviewed'] ?? 0);
        $summary['rows_archived'] = (int) ($summary['rows_archived'] ?? 0);
        $summary['rows_summarized'] = (int) ($summary['rows_summarized'] ?? 0);
        $summary['rows_deleted'] = (int) ($summary['rows_deleted'] ?? 0);
        $summary['payloads_externalized'] = (int) ($summary['payloads_externalized'] ?? 0);
        $summary['bytes_released'] = (int) ($summary['bytes_released'] ?? 0);
        $summary['duration_ms'] = (int) ($summary['duration_ms'] ?? 0);
        $summary['first_sequence'] = $summary['first_sequence'] ?? (int) $rows[0]['sequence_no'];
        $summary['last_sequence'] = (int) $rows[array_key_last($rows)]['sequence_no'];
        $summary['by_phase'] = is_array($summary['by_phase'] ?? null) ? $summary['by_phase'] : [];
        $summary['by_dataset'] = is_array($summary['by_dataset'] ?? null) ? $summary['by_dataset'] : [];
        $summary['idempotency_keys_compacted'] = (int) ($summary['idempotency_keys_compacted'] ?? 0);
        $summary['first_idempotency_key'] = $summary['first_idempotency_key'] ?? null;
        $summary['last_idempotency_key'] = $summary['last_idempotency_key'] ?? null;
        foreach ($rows as $row) {
            $summary['compacted_steps']++;
            $summary['idempotency_keys_compacted']++;
            $idempotencyKey = (string) ($row['idempotency_key'] ?? '');
            if ($idempotencyKey !== '') {
                $summary['first_idempotency_key'] ??= $idempotencyKey;
                $summary['last_idempotency_key'] = $idempotencyKey;
            }
            foreach ([
                'rows_reviewed',
                'rows_archived',
                'rows_summarized',
                'rows_deleted',
                'payloads_externalized',
                'bytes_released',
                'duration_ms',
            ] as $metric) {
                $summary[$metric] += max(0, (int) ($row[$metric] ?? 0));
            }
            $phase = (string) ($row['phase'] ?? '');
            if ($phase !== '') {
                $summary['by_phase'][$phase] = ($summary['by_phase'][$phase] ?? 0) + 1;
            }
            $dataset = (string) ($row['dataset_key'] ?? '');
            if ($dataset !== '') {
                $summary['by_dataset'][$dataset] = ($summary['by_dataset'][$dataset] ?? 0) + 1;
            }
        }
        ksort($summary['by_phase']);
        ksort($summary['by_dataset']);
        return $summary;
    }
}
