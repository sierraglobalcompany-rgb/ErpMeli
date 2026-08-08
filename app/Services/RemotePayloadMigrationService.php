<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use App\ValueObjects\PayloadContext;
use PDO;
use RuntimeException;
use Throwable;

final class RemotePayloadMigrationService
{
    /** @var array<string,array{account:string,date:string}> */
    private const SOURCES = [
        'meli_orders' => ['account' => 'meli_account_id', 'date' => 'synced_at'],
        'meli_shipments' => ['account' => 'meli_account_id', 'date' => 'synced_at'],
        'meli_payments' => ['account' => 'meli_account_id', 'date' => 'synced_at'],
        'meli_packs' => ['account' => 'meli_account_id', 'date' => 'synced_at'],
    ];

    /**
     * @return array{
     *   reviewed:int,processed:int,skipped:int,errors:int,bytes_released:int,
     *   last_id:int,table_complete:bool
     * }
     */
    public function migrateBatch(
        int $limit = 100,
        ?string $onlyTable = null,
        int $afterId = 0
    ): array
    {
        $limit = max(1, min(500, $limit));
        $afterId = max(0, $afterId);
        // Reserva ~450 ms del presupuesto de dos segundos para aprobar el
        // checkpoint, serializar JSON y responder aun con disco ocupado.
        $deadline = microtime(true) + 1.45;
        $result = [
            'reviewed' => 0,
            'processed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'bytes_released' => 0,
            'last_id' => $afterId,
            'table_complete' => false,
        ];
        if ($onlyTable === 'meli_order_items') {
            $selected = $this->migrateOrderItems(
                $limit,
                $result,
                $deadline,
                $afterId
            );
            $result['table_complete'] = $selected === 0;
            return $result;
        }
        $tables = $onlyTable !== null ? [$onlyTable] : array_keys(self::SOURCES);
        foreach ($tables as $table) {
            if (!isset(self::SOURCES[$table]) || $result['reviewed'] >= $limit) {
                continue;
            }
            $remaining = $limit - $result['reviewed'];
            $dateColumn = self::SOURCES[$table]['date'];
            $stmt = Database::connection()->query(
                'SELECT id,meli_account_id,raw_json,raw_path
                 FROM `' . $table . '`
                 WHERE raw_json IS NOT NULL AND raw_json<>""
                   AND `' . $dateColumn . '`<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 DAY)
                   AND id>' . $afterId . '
                 ORDER BY id ASC
                 LIMIT ' . $remaining
            );
            $selectedRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->migrateRows($table, $selectedRows, $result, true, $deadline);
            if ($onlyTable !== null) {
                $result['table_complete'] = $selectedRows === [];
                return $result;
            }
        }
        if ($result['reviewed'] < $limit && microtime(true) < $deadline) {
            $this->migrateOrderItems(
                $limit - $result['reviewed'],
                $result,
                $deadline,
                0
            );
        }
        return $result;
    }

    /**
     * @param array{
     *   reviewed:int,processed:int,skipped:int,errors:int,bytes_released:int,
     *   last_id:int,table_complete:bool
     * } $result
     */
    private function migrateOrderItems(
        int $limit,
        array &$result,
        float $deadline,
        int $afterId
    ): int
    {
        if ($limit <= 0) {
            return 0;
        }
        $stmt = Database::connection()->query(
            'SELECT i.id,i.meli_account_id,i.raw_json
             FROM meli_order_items i
             WHERE i.raw_json IS NOT NULL AND i.raw_json<>""
               AND i.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 DAY)
               AND i.id>' . max(0, $afterId) . '
             ORDER BY i.id ASC
             LIMIT ' . max(1, min(500, $limit))
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->migrateRows('meli_order_items', $rows, $result, false, $deadline);
        return count($rows);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array{
     *   reviewed:int,processed:int,skipped:int,errors:int,bytes_released:int,
     *   last_id:int,table_complete:bool
     * } $result
     */
    private function migrateRows(
        string $table,
        array $rows,
        array &$result,
        bool $hasRawPath,
        float $deadline
    ): void
    {
        if ($rows === []) {
            return;
        }
        try {
            $update = Database::connection()->prepare(
                'UPDATE `' . $table . '`
                 SET raw_json=NULL' . ($hasRawPath ? ',raw_path=NULL' : '') . '
                 WHERE id=:id AND meli_account_id=:account
                   AND raw_json IS NOT NULL
                   AND SHA2(raw_json,256)=:payload_hash'
            );
            $store = new FileRemotePayloadStore();
            foreach (array_chunk($rows, 10) as $chunkIndex => $chunk) {
                // Diez filas es la unidad atómica conservadora. En equipos
                // rápidos se encadenan varias unidades hasta 500 filas; en
                // hosting lento se aprueba el checkpoint antes de dos segundos.
                if ($chunkIndex > 0 && microtime(true) >= $deadline) {
                    break;
                }
                $entries = [];
                foreach ($chunk as $row) {
                    $entries[] = [
                        'payload' => (string) $row['raw_json'],
                        'context' => new PayloadContext(
                            $table,
                            (int) $row['id'],
                            (int) $row['meli_account_id']
                        ),
                    ];
                }
                $references = $store->persistMany($entries);
                foreach ($chunk as $index => $row) {
                    $result['reviewed']++;
                    $result['last_id'] = max($result['last_id'], (int) $row['id']);
                    $payload = (string) $row['raw_json'];
                    $update->execute([
                        'id' => (int) $row['id'],
                        'account' => (int) $row['meli_account_id'],
                        'payload_hash' => $references[$index]->sha256,
                    ]);
                    if ($update->rowCount() !== 1) {
                        $result['skipped']++;
                        continue;
                    }
                    if ($hasRawPath) {
                        $this->removeLegacyFile((string) ($row['raw_path'] ?? ''));
                    }
                    $result['processed']++;
                    $result['bytes_released'] += strlen($payload);
                }
            }
        } catch (Throwable) {
            /*
             * No se avanza el cursor cuando el lote no pudo aprobarse. Los
             * archivos ya escritos son content-addressed e idempotentes; el
             * siguiente intento podrá reutilizarlos sin perder la fila.
             */
            $result['errors']++;
        }
    }

    private function removeLegacyFile(string $rawPath): void
    {
        $normalized = str_replace('\\', '/', $rawPath);
        if (
            !str_starts_with($normalized, 'storage/raw/')
            || str_contains($normalized, '..')
            || preg_match('#^storage/raw/[A-Za-z0-9_./-]+\.json\.gz$#', $normalized) !== 1
        ) {
            return;
        }
        $absolute = AppPaths::sharedRoot() . '/' . $normalized;
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }
}
