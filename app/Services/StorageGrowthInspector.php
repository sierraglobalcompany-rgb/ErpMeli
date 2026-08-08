<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class StorageGrowthInspector
{
    /**
     * @return array{
     *   captured_at:string,table_count:int,estimated_rows:int,data_bytes:int,
     *   index_bytes:int,free_bytes:int,tables:list<array<string,mixed>>
     * }
     */
    public function snapshot(bool $persist = false, string $source = 'manual_cli'): array
    {
        $pdo = Database::connection();
        $schema = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $stmt = $pdo->prepare(
            'SELECT TABLE_NAME,TABLE_ROWS,DATA_LENGTH,INDEX_LENGTH,DATA_FREE,AVG_ROW_LENGTH
             FROM information_schema.TABLES
             WHERE BINARY TABLE_SCHEMA=BINARY :schema AND TABLE_TYPE="BASE TABLE"
             ORDER BY DATA_LENGTH+INDEX_LENGTH DESC,TABLE_NAME'
        );
        $stmt->execute(['schema' => $schema]);
        $tables = [];
        $totals = [
            'estimated_rows' => 0,
            'data_bytes' => 0,
            'index_bytes' => 0,
            'free_bytes' => 0,
        ];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = (string) $row['TABLE_NAME'];
            $item = [
                'table' => $table,
                'data_class' => $this->classify($table),
                'estimated_rows' => max(0, (int) $row['TABLE_ROWS']),
                'data_bytes' => max(0, (int) $row['DATA_LENGTH']),
                'index_bytes' => max(0, (int) $row['INDEX_LENGTH']),
                'free_bytes' => max(0, (int) $row['DATA_FREE']),
                'average_row_bytes' => max(0, (int) $row['AVG_ROW_LENGTH']),
            ];
            foreach (array_keys($totals) as $metric) {
                $totals[$metric] += $item[$metric];
            }
            $tables[] = $item;
        }
        $snapshot = [
            'captured_at' => gmdate(DATE_ATOM),
            'table_count' => count($tables),
            ...$totals,
            'tables' => $tables,
        ];
        if ($persist) {
            $this->persist($snapshot, $schema, $source);
        }
        return $snapshot;
    }

    /** @return list<array<string,mixed>> */
    public function producers(): array
    {
        try {
            return Database::connection()->query(
                'SELECT producer_key,dataset_key,table_pattern,service_class,execution_source,
                        retention_class,remote_transport_possible
                 FROM system_storage_producer_catalog
                 WHERE enabled=1
                 ORDER BY dataset_key,producer_key'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $snapshot */
    private function persist(array $snapshot, string $schema, string $source): void
    {
        $source = in_array($source, ['manual_cli', 'scheduled_cli', 'test'], true)
            ? $source
            : 'manual_cli';
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO system_database_growth_snapshots
                 (database_name_hash,table_count,estimated_rows,data_bytes,index_bytes,free_bytes,source,capture_version)
                 VALUES (:database_hash,:table_count,:estimated_rows,:data_bytes,:index_bytes,:free_bytes,:source,:version)'
            );
            $stmt->execute([
                'database_hash' => hash('sha256', $schema),
                'table_count' => $snapshot['table_count'],
                'estimated_rows' => $snapshot['estimated_rows'],
                'data_bytes' => $snapshot['data_bytes'],
                'index_bytes' => $snapshot['index_bytes'],
                'free_bytes' => $snapshot['free_bytes'],
                'source' => $source,
                'version' => AppVersionService::fileVersion(),
            ]);
            $snapshotId = (int) $pdo->lastInsertId();
            $insert = $pdo->prepare(
                'INSERT INTO system_database_growth_tables
                 (snapshot_id,table_name,data_class,estimated_rows,data_bytes,index_bytes,free_bytes,average_row_bytes)
                 VALUES (:snapshot_id,:table_name,:data_class,:estimated_rows,:data_bytes,:index_bytes,:free_bytes,:average_row_bytes)'
            );
            foreach ($snapshot['tables'] as $table) {
                $insert->execute([
                    'snapshot_id' => $snapshotId,
                    'table_name' => $table['table'],
                    'data_class' => $table['data_class'],
                    'estimated_rows' => $table['estimated_rows'],
                    'data_bytes' => $table['data_bytes'],
                    'index_bytes' => $table['index_bytes'],
                    'free_bytes' => $table['free_bytes'],
                    'average_row_bytes' => $table['average_row_bytes'],
                ]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function classify(string $table): string
    {
        if (preg_match('/^(meli_orders|meli_order_items|meli_payments|meli_shipments|meli_packs|meli_items|products|companies|meli_accounts)$/', $table)) {
            return 'commercial';
        }
        if (str_contains($table, 'fiscal') || str_contains($table, 'billing') || str_contains($table, 'close')) {
            return 'fiscal';
        }
        if (str_contains($table, 'notification') || str_contains($table, 'webhook')) {
            return 'event';
        }
        if (str_contains($table, 'log') || str_contains($table, 'health') || str_contains($table, 'metric')) {
            return 'log';
        }
        if (str_contains($table, 'preview') || str_contains($table, 'probe') || str_contains($table, 'temporary')) {
            return 'temporary';
        }
        if (str_contains($table, 'job') || str_contains($table, 'queue') || str_contains($table, 'campaign') || str_contains($table, 'sync_')) {
            return 'operational';
        }
        if (str_starts_with($table, 'system_') || $table === 'schema_migrations' || $table === 'app_versions') {
            return 'technical';
        }
        return 'unknown';
    }
}
