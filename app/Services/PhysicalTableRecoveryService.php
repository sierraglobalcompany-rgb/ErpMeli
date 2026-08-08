<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class PhysicalTableRecoveryService
{
    /** @var list<string> */
    private const ALLOWED_TABLES = [
        'meli_notification_events',
        'api_request_logs',
        'cron_health_checks',
        'meli_orders',
        'meli_order_items',
        'meli_items',
        'meli_payments',
        'meli_shipments',
        'meli_packs',
        'order_financial_recalc_job_items',
    ];

    /** @return list<array<string,mixed>> */
    public function plan(): array
    {
        $database = (string) Database::connection()->query(
            'SELECT DATABASE()'
        )->fetchColumn();
        $cached = $this->readCachedPlan($database);
        if ($cached !== null) {
            return $cached;
        }

        $cachePath = $this->cachePath();
        $directory = dirname($cachePath);
        if (!is_dir($directory)) {
            @mkdir($directory, 0770, true);
        }
        $lock = @fopen($cachePath . '.lock', 'c+b');
        if (is_resource($lock) && @flock($lock, LOCK_EX)) {
            try {
                $cached = $this->readCachedPlan($database);
                if ($cached !== null) {
                    return $cached;
                }
                $rows = $this->buildPlan();
                $this->writeCachedPlan($database, $rows);
                return $rows;
            } finally {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }

        return $this->buildPlan();
    }

    /** @return list<array<string,mixed>> */
    private function buildPlan(): array
    {
        $metricsByTable = $this->allMetrics();
        $externalizedByTable = $this->allExternalizedPayloads();
        $rows = [];
        foreach (self::ALLOWED_TABLES as $table) {
            $metrics = $metricsByTable[$table] ?? null;
            if (!is_array($metrics)) {
                continue;
            }
            $externalized = (int) ($externalizedByTable[$table] ?? 0);
            $payloadRewritePending = $this->payloadRewriteRequiresRebuild(
                $table,
                $externalized
            );
            $bulkRewritePending = $this->bulkRewriteRequiresRebuild($table);
            $rows[] = [
                ...$metrics,
                'payloads_externalized' => $externalized,
                'payload_rewrite_pending' => $payloadRewritePending,
                'bulk_rewrite_pending' => $bulkRewritePending,
                'eligible' => $this->fragmentationRequiresRebuild($metrics)
                    || $payloadRewritePending
                    || $bulkRewritePending,
                'estimated_temporary_bytes' => (int) ceil(
                    ($metrics['data_bytes'] + $metrics['index_bytes']) * 2.5
                ),
            ];
        }
        usort(
            $rows,
            static fn (array $left, array $right): int =>
                $right['free_bytes'] <=> $left['free_bytes']
        );
        return $rows;
    }

    public function invalidateCache(): void
    {
        $path = $this->cachePath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function allMetrics(): array
    {
        $schema = (string) Database::connection()->query('SELECT DATABASE()')->fetchColumn();
        $placeholders = [];
        $parameters = ['schema' => $schema];
        foreach (self::ALLOWED_TABLES as $index => $table) {
            $key = 'table_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $table;
        }
        $stmt = Database::connection()->prepare(
            'SELECT TABLE_NAME,DATA_LENGTH,INDEX_LENGTH,DATA_FREE,TABLE_ROWS
             FROM information_schema.TABLES
             WHERE BINARY TABLE_SCHEMA=BINARY :schema
               AND TABLE_NAME IN (' . implode(',', $placeholders) . ')'
        );
        $stmt->execute($parameters);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = (string) $row['TABLE_NAME'];
            $result[$table] = [
                'table' => $table,
                'estimated_rows' => max(0, (int) $row['TABLE_ROWS']),
                'data_bytes' => max(0, (int) $row['DATA_LENGTH']),
                'index_bytes' => max(0, (int) $row['INDEX_LENGTH']),
                'free_bytes' => max(0, (int) $row['DATA_FREE']),
            ];
        }
        return $result;
    }

    /** @return array<string,mixed> */
    public function rebuild(string $table, bool $confirmed): array
    {
        if (!$confirmed) {
            throw new RuntimeException('La recuperación física requiere confirmación explícita.');
        }
        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            throw new RuntimeException('La tabla no pertenece al plan de recuperación aprobado.');
        }
        if (!(new MeliEmergencyStopService())->active()) {
            throw new RuntimeException('Detenga Mercado Libre antes de reconstruir una tabla.');
        }
        if (!(new EmergencyControlService())->automationStopped()) {
            throw new RuntimeException('Detenga la automatización antes de reconstruir una tabla.');
        }
        $before = $this->metrics($table);
        if ($before === null) {
            throw new RuntimeException('La tabla ya no existe.');
        }
        if (
            !$this->fragmentationRequiresRebuild($before)
            && !$this->payloadRewriteRequiresRebuild(
                $table,
                $this->externalizedPayloads($table)
            )
            && !$this->bulkRewriteRequiresRebuild($table)
        ) {
            $this->record($table, 'not_required', $before, $before, 'La fragmentación no supera el umbral.');
            return ['status' => 'not_required', 'before' => $before, 'after' => $before];
        }
        $temporaryBytes = (int) ceil(($before['data_bytes'] + $before['index_bytes']) * 2.5);
        $diskFree = @disk_free_space(AppPaths::storage());
        if ($diskFree === false) {
            throw new RuntimeException('No se pudo comprobar espacio temporal suficiente.');
        }
        if ((int) $diskFree < max(268435456, $temporaryBytes)) {
            throw new RuntimeException('No existe espacio temporal suficiente para reconstruir esta tabla.');
        }

        $historyId = $this->record($table, 'planned', $before, null, 'Reconstrucción local confirmada.');
        try {
            $result = Database::connection()->query(
                'OPTIMIZE TABLE `' . $table . '`'
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($result as $message) {
                $type = strtolower((string) ($message['Msg_type'] ?? $message['msg_type'] ?? ''));
                $text = strtolower((string) ($message['Msg_text'] ?? $message['msg_text'] ?? ''));
                if ($type === 'error' || str_contains($text, 'error')) {
                    throw new RuntimeException('MariaDB no pudo reconstruir la tabla.');
                }
            }
            $after = $this->metrics($table) ?? $before;
            $this->finish($historyId, 'completed', $after, 'Reconstrucción verificada.');
            $this->invalidateCache();
            return ['status' => 'completed', 'before' => $before, 'after' => $after];
        } catch (Throwable $error) {
            $this->finish($historyId, 'failed', $before, 'La reconstrucción no pudo completarse.');
            throw $error;
        }
    }

    /** @return array<string,mixed>|null */
    private function metrics(string $table): ?array
    {
        $schema = (string) Database::connection()->query('SELECT DATABASE()')->fetchColumn();
        $stmt = Database::connection()->prepare(
            'SELECT TABLE_NAME,DATA_LENGTH,INDEX_LENGTH,DATA_FREE,TABLE_ROWS
             FROM information_schema.TABLES
             WHERE BINARY TABLE_SCHEMA=BINARY :schema AND BINARY TABLE_NAME=BINARY :table
             LIMIT 1'
        );
        $stmt->execute(['schema' => $schema, 'table' => $table]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? [
            'table' => (string) $row['TABLE_NAME'],
            'estimated_rows' => max(0, (int) $row['TABLE_ROWS']),
            'data_bytes' => max(0, (int) $row['DATA_LENGTH']),
            'index_bytes' => max(0, (int) $row['INDEX_LENGTH']),
            'free_bytes' => max(0, (int) $row['DATA_FREE']),
        ] : null;
    }

    /** @param array<string,mixed> $before @param array<string,mixed>|null $after */
    private function record(
        string $table,
        string $status,
        array $before,
        ?array $after,
        string $message
    ): int {
        $stmt = Database::connection()->prepare(
            'INSERT INTO system_table_maintenance_history
             (table_name,action_key,status,data_bytes_before,index_bytes_before,free_bytes_before,
              data_bytes_after,index_bytes_after,free_bytes_after,safe_message,completed_at)
             VALUES (:table,"rebuild",:status,:data_before,:index_before,:free_before,
                     :data_after,:index_after,:free_after,:message,
                     CASE WHEN :terminal=1 THEN UTC_TIMESTAMP(3) ELSE NULL END)'
        );
        $stmt->execute([
            'table' => $table,
            'status' => $status,
            'data_before' => $before['data_bytes'],
            'index_before' => $before['index_bytes'],
            'free_before' => $before['free_bytes'],
            'data_after' => $after['data_bytes'] ?? 0,
            'index_after' => $after['index_bytes'] ?? 0,
            'free_after' => $after['free_bytes'] ?? 0,
            'message' => $message,
            'terminal' => $status === 'planned' ? 0 : 1,
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    /** @param array<string,mixed> $after */
    private function finish(int $id, string $status, array $after, string $message): void
    {
        Database::connection()->prepare(
            'UPDATE system_table_maintenance_history
             SET status=:status,data_bytes_after=:data,index_bytes_after=:index_bytes,
                 free_bytes_after=:free,safe_message=:message,completed_at=UTC_TIMESTAMP(3)
             WHERE id=:id AND status="planned"'
        )->execute([
            'status' => $status,
            'data' => $after['data_bytes'],
            'index_bytes' => $after['index_bytes'],
            'free' => $after['free_bytes'],
            'message' => $message,
            'id' => $id,
        ]);
    }

    private function minimumFreeBytes(): int
    {
        return max(
            10485760,
            (new AppSettingsService())->int('maintenance.minimum_rebuild_free_bytes', 10485760)
        );
    }

    /** @param array<string,mixed> $metrics */
    private function fragmentationRequiresRebuild(array $metrics): bool
    {
        $free = max(0, (int) ($metrics['free_bytes'] ?? 0));
        $allocated = max(
            1,
            (int) ($metrics['data_bytes'] ?? 0) + (int) ($metrics['index_bytes'] ?? 0)
        );
        return $free >= $this->minimumFreeBytes()
            || ($free >= 4194304 && ($free / $allocated) >= 0.25);
    }

    private function externalizedPayloads(string $table): int
    {
        if (!in_array($table, [
            'meli_orders',
            'meli_order_items',
            'meli_payments',
            'meli_shipments',
            'meli_packs',
        ], true)) {
            return 0;
        }
        try {
            $stmt = Database::connection()->prepare(
                'SELECT COUNT(*) FROM remote_payload_references WHERE entity_table=:table'
            );
            $stmt->execute(['table' => $table]);
            return max(0, (int) $stmt->fetchColumn());
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array<string,int> */
    private function allExternalizedPayloads(): array
    {
        try {
            $stmt = Database::connection()->query(
                'SELECT entity_table,COUNT(*) AS references_count
                 FROM remote_payload_references
                 WHERE entity_table IN (
                    "meli_orders","meli_order_items","meli_payments",
                    "meli_shipments","meli_packs"
                 )
                 GROUP BY entity_table'
            );
            $result = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[(string) $row['entity_table']] = max(
                    0,
                    (int) $row['references_count']
                );
            }
            return $result;
        } catch (Throwable) {
            return [];
        }
    }

    private function bulkRewriteRequiresRebuild(string $table): bool
    {
        if ($table !== 'meli_notification_events') {
            return false;
        }
        try {
            $normalizedAt = Database::connection()->query(
                'SELECT MAX(changed_at)
                 FROM (
                    SELECT s.completed_at AS changed_at
                    FROM database_maintenance_sessions s
                    INNER JOIN database_maintenance_steps st
                      ON st.maintenance_session_id=s.id
                    WHERE s.status="completed"
                      AND st.phase IN ("legacy_notifications","legacy_messages")
                      AND st.rows_summarized>0
                    UNION ALL
                    SELECT s.steps_compacted_at AS changed_at
                    FROM database_maintenance_sessions s
                    WHERE s.status="completed"
                      AND s.steps_compacted_count>0
                      AND (
                        s.steps_summary_json LIKE "%legacy_notifications%"
                        OR s.steps_summary_json LIKE "%legacy_messages%"
                      )
                 ) normalized'
            )->fetchColumn();
            if (!is_string($normalizedAt) || $normalizedAt === '') {
                return false;
            }
            $rebuiltAt = Database::connection()->query(
                'SELECT MAX(completed_at)
                 FROM system_table_maintenance_history
                 WHERE table_name="meli_notification_events"
                   AND action_key="rebuild"
                   AND status="completed"'
            )->fetchColumn();
            return !is_string($rebuiltAt)
                || $rebuiltAt === ''
                || strtotime($normalizedAt . ' UTC') > strtotime($rebuiltAt . ' UTC');
        } catch (Throwable) {
            return false;
        }
    }

    private function payloadRewriteRequiresRebuild(string $table, int $externalized): bool
    {
        if ($externalized < 1000) {
            return false;
        }
        try {
            $stmt = Database::connection()->prepare(
                'SELECT MAX(linked_at)
                 FROM remote_payload_references
                 WHERE entity_table=:table'
            );
            $stmt->execute(['table' => $table]);
            $externalizedAt = $stmt->fetchColumn();
            if (!is_string($externalizedAt) || $externalizedAt === '') {
                return false;
            }
            $stmt = Database::connection()->prepare(
                'SELECT MAX(completed_at)
                 FROM system_table_maintenance_history
                 WHERE table_name=:table
                   AND action_key="rebuild"
                   AND status="completed"'
            );
            $stmt->execute(['table' => $table]);
            $rebuiltAt = $stmt->fetchColumn();
            return !is_string($rebuiltAt)
                || $rebuiltAt === ''
                || strtotime($externalizedAt . ' UTC') > strtotime($rebuiltAt . ' UTC');
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<array<string,mixed>>|null */
    private function readCachedPlan(string $database): ?array
    {
        $path = $this->cachePath();
        if (!is_file($path) || (int) @filemtime($path) < time() - 60) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (
            !is_array($decoded)
            || (string) ($decoded['database'] ?? '') !== $database
            || !is_array($decoded['rows'] ?? null)
        ) {
            return null;
        }
        return array_values(array_filter(
            $decoded['rows'],
            static fn (mixed $row): bool => is_array($row)
        ));
    }

    /** @param list<array<string,mixed>> $rows */
    private function writeCachedPlan(string $database, array $rows): void
    {
        $path = $this->cachePath();
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(5));
        $payload = json_encode([
            'database' => $database,
            'generated_at' => gmdate(DATE_ATOM),
            'rows' => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (@file_put_contents($temporary, $payload, LOCK_EX) === false) {
            return;
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
        }
    }

    private function cachePath(): string
    {
        return AppPaths::storage('cache/physical-recovery-plan.json');
    }
}
