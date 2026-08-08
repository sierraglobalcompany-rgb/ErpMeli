<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\RemotePayloadStore;
use App\Core\AppPaths;
use App\Core\Database;
use App\ValueObjects\PayloadContext;
use App\ValueObjects\PayloadReference;
use PDO;
use RuntimeException;
use Throwable;

final class FileRemotePayloadStore implements RemotePayloadStore
{
    /** @var list<string> */
    private const TABLES = [
        'meli_orders',
        'meli_shipments',
        'meli_payments',
        'meli_packs',
        'meli_order_items',
    ];

    public function persist(string $payload, PayloadContext $context): PayloadReference
    {
        $references = $this->persistMany([[
            'payload' => $payload,
            'context' => $context,
        ]]);
        return $references[0];
    }

    /**
     * Persiste varios archivos verificados y aprueba sus referencias en una
     * sola transacción. El lote evita cuatro viajes a MariaDB por entidad sin
     * relajar la verificación individual ni la idempotencia.
     *
     * La validación permanece deliberadamente en tiempo de ejecución porque
     * este método agrupa escrituras de varios servicios internos.
     *
     * @param list<mixed> $entries
     * @return list<PayloadReference>
     */
    public function persistMany(array $entries): array
    {
        if ($entries === [] || count($entries) > 500) {
            throw new RuntimeException('El lote de payloads no es válido.');
        }
        $prepared = [];
        foreach ($entries as $entry) {
            if (
                !is_array($entry)
                || !isset($entry['payload'], $entry['context'])
                || !is_string($entry['payload'])
                || !$entry['context'] instanceof PayloadContext
            ) {
                throw new RuntimeException('El lote contiene una referencia no válida.');
            }
            $prepared[] = $this->prepareFile($entry['payload'], $entry['context']);
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $previousObjectIds = $this->previousObjectIds($pdo, $prepared);
            $this->upsertObjects($pdo, $prepared);
            $objectsByHash = $this->objectsByHash($pdo, $prepared);
            $this->upsertReferences($pdo, $prepared, $objectsByHash);
            $affectedObjectIds = array_values(array_unique(array_merge(
                array_values($previousObjectIds),
                array_map(
                    static fn (array $row): int => (int) $objectsByHash[$row['hash']]['id'],
                    $prepared
                )
            )));
            $this->refreshReferenceCounts($pdo, $affectedObjectIds);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        return array_map(
            static function (array $row) use ($objectsByHash): PayloadReference {
                $object = $objectsByHash[$row['hash']];
                return new PayloadReference(
                    (int) $object['id'],
                    $row['hash'],
                    $row['relative'],
                    $row['original_bytes'],
                    $row['stored_bytes']
                );
            },
            $prepared
        );
    }

    /**
     * @return array{
     *   payload:string,context:PayloadContext,hash:string,relative:string,
     *   original_bytes:int,stored_bytes:int
     * }
     */
    private function prepareFile(string $payload, PayloadContext $context): array
    {
        $this->assertContext($context);
        if (json_decode($payload, true) === null && trim($payload) !== 'null') {
            throw new RuntimeException('El payload remoto no contiene JSON válido.');
        }
        $hash = hash('sha256', $payload);
        $relative = substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash . '.json.gz';
        $absolute = AppPaths::remotePayloads() . '/' . $relative;
        $directory = dirname($absolute);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible preparar el almacén privado de payloads.');
        }
        @chmod(AppPaths::remotePayloads(), 0700);
        @chmod(dirname($directory), 0700);
        @chmod($directory, 0700);
        if (!is_file($absolute)) {
            $compressed = gzencode($payload, 6);
            if (!is_string($compressed)) {
                throw new RuntimeException('No fue posible comprimir el payload remoto.');
            }
            $temporary = $absolute . '.part-' . bin2hex(random_bytes(6));
            if (@file_put_contents($temporary, $compressed, LOCK_EX) === false) {
                throw new RuntimeException('No fue posible escribir el payload remoto.');
            }
            @chmod($temporary, 0600);
            if (!@rename($temporary, $absolute)) {
                @unlink($temporary);
                if (!is_file($absolute)) {
                    throw new RuntimeException('No fue posible aprobar el payload remoto.');
                }
            }
            @chmod($absolute, 0600);
        }
        $decoded = @gzdecode((string) @file_get_contents($absolute));
        if (!is_string($decoded) || !hash_equals($hash, hash('sha256', $decoded))) {
            throw new RuntimeException('El payload remoto no superó la verificación.');
        }

        return [
            'payload' => $payload,
            'context' => $context,
            'hash' => $hash,
            'relative' => $relative,
            'original_bytes' => strlen($payload),
            'stored_bytes' => (int) filesize($absolute),
        ];
    }

    /**
     * @param list<array{context:PayloadContext}> $prepared
     * @return array<string,int>
     */
    private function previousObjectIds(PDO $pdo, array $prepared): array
    {
        $groups = [];
        foreach ($prepared as $row) {
            $context = $row['context'];
            $groups[$context->entityTable][] = $context->entityId;
        }
        $result = [];
        foreach ($groups as $table => $ids) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare(
                'SELECT entity_id,payload_object_id
                 FROM remote_payload_references
                 WHERE entity_table=? AND entity_id IN (' . $placeholders . ')
                 FOR UPDATE'
            );
            $stmt->execute(array_merge([$table], $ids));
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $result[$table . ':' . (int) $row['entity_id']] = (int) $row['payload_object_id'];
            }
        }
        return $result;
    }

    /** @param list<array<string,mixed>> $prepared */
    private function upsertObjects(PDO $pdo, array $prepared): void
    {
        $unique = [];
        foreach ($prepared as $row) {
            $unique[$row['hash']] = $row;
        }
        $values = [];
        $params = [];
        foreach (array_values($unique) as $index => $row) {
            $values[] = '(?,?,?,?,UTC_TIMESTAMP(3))';
            array_push(
                $params,
                $row['hash'],
                $row['relative'],
                $row['original_bytes'],
                $row['stored_bytes']
            );
        }
        $pdo->prepare(
            'INSERT INTO remote_payload_objects
             (payload_sha256,storage_name,original_bytes,stored_bytes,verified_at)
             VALUES ' . implode(',', $values) . '
             ON DUPLICATE KEY UPDATE verified_at=VALUES(verified_at),
                original_bytes=VALUES(original_bytes),stored_bytes=VALUES(stored_bytes)'
        )->execute($params);
    }

    /**
     * @param list<array{hash:string}> $prepared
     * @return array<string,array{id:int}>
     */
    private function objectsByHash(PDO $pdo, array $prepared): array
    {
        $hashes = array_values(array_unique(array_column($prepared, 'hash')));
        $stmt = $pdo->prepare(
            'SELECT id,payload_sha256
             FROM remote_payload_objects
             WHERE payload_sha256 IN (' . implode(',', array_fill(0, count($hashes), '?')) . ')'
        );
        $stmt->execute($hashes);
        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[(string) $row['payload_sha256']] = ['id' => (int) $row['id']];
        }
        if (count($result) !== count($hashes)) {
            throw new RuntimeException('No fue posible aprobar todos los objetos remotos.');
        }
        return $result;
    }

    /**
     * @param list<array{context:PayloadContext,hash:string}> $prepared
     * @param array<string,array{id:int}> $objectsByHash
     */
    private function upsertReferences(PDO $pdo, array $prepared, array $objectsByHash): void
    {
        $values = [];
        $params = [];
        foreach ($prepared as $row) {
            $context = $row['context'];
            $values[] = '(?,?,?,?,UTC_TIMESTAMP(3))';
            array_push(
                $params,
                (int) $objectsByHash[$row['hash']]['id'],
                $context->entityTable,
                $context->entityId,
                $context->accountId
            );
        }
        $pdo->prepare(
            'INSERT INTO remote_payload_references
             (payload_object_id,entity_table,entity_id,meli_account_id,linked_at)
             VALUES ' . implode(',', $values) . '
             ON DUPLICATE KEY UPDATE payload_object_id=VALUES(payload_object_id),
                meli_account_id=VALUES(meli_account_id),linked_at=VALUES(linked_at)'
        )->execute($params);
    }

    /** @param list<int> $objectIds */
    private function refreshReferenceCounts(PDO $pdo, array $objectIds): void
    {
        $objectIds = array_values(array_unique(array_filter(
            array_map('intval', $objectIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($objectIds === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($objectIds), '?'));
        $pdo->prepare(
            'UPDATE remote_payload_objects o
             LEFT JOIN (
                SELECT payload_object_id,COUNT(*) AS total
                FROM remote_payload_references
                WHERE payload_object_id IN (' . $placeholders . ')
                GROUP BY payload_object_id
             ) r ON r.payload_object_id=o.id
             SET o.reference_count=COALESCE(r.total,0)
             WHERE o.id IN (' . $placeholders . ')'
        )->execute(array_merge($objectIds, $objectIds));
    }

    public function verify(PayloadReference $reference): bool
    {
        $absolute = $this->absolute($reference->storageName);
        if (!is_file($absolute)) {
            return false;
        }
        $decoded = @gzdecode((string) @file_get_contents($absolute));
        return is_string($decoded)
            && hash_equals($reference->sha256, hash('sha256', $decoded));
    }

    public function retrieve(PayloadReference $reference): string
    {
        if (!$this->verify($reference)) {
            throw new RuntimeException('El payload remoto no está disponible o cambió.');
        }
        $decoded = @gzdecode((string) @file_get_contents($this->absolute($reference->storageName)));
        if (!is_string($decoded)) {
            throw new RuntimeException('El payload remoto no se pudo leer.');
        }
        return $decoded;
    }

    public function referenceFor(string $entityTable, int $entityId, int $accountId): ?PayloadReference
    {
        if (!in_array($entityTable, self::TABLES, true) || $entityId <= 0 || $accountId <= 0) {
            return null;
        }
        $stmt = Database::connection()->prepare(
            'SELECT o.id,o.payload_sha256,o.storage_name,o.original_bytes,o.stored_bytes
             FROM remote_payload_references r
             INNER JOIN remote_payload_objects o ON o.id=r.payload_object_id
             WHERE r.entity_table=:entity_table AND r.entity_id=:entity_id
               AND r.meli_account_id=:account_id
             LIMIT 1'
        );
        $stmt->execute([
            'entity_table' => $entityTable,
            'entity_id' => $entityId,
            'account_id' => $accountId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)
            ? new PayloadReference(
                (int) $row['id'],
                (string) $row['payload_sha256'],
                (string) $row['storage_name'],
                (int) $row['original_bytes'],
                (int) $row['stored_bytes'],
            )
            : null;
    }

    /** @return array{deleted:int,errors:int} */
    public function purgeOrphans(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $rows = Database::connection()->query(
            'SELECT o.id,o.storage_name
             FROM remote_payload_objects o
             WHERE o.reference_count=0
               AND NOT EXISTS (
                   SELECT 1 FROM remote_payload_references r WHERE r.payload_object_id=o.id
               )
             ORDER BY o.id
             LIMIT ' . $limit
        )->fetchAll(PDO::FETCH_ASSOC);
        $result = ['deleted' => 0, 'errors' => 0];
        foreach ($rows as $row) {
            try {
                $path = $this->absolute((string) $row['storage_name']);
                if (is_file($path) && !@unlink($path)) {
                    throw new RuntimeException('No fue posible retirar un objeto sin referencias.');
                }
                $stmt = Database::connection()->prepare(
                    'DELETE FROM remote_payload_objects
                     WHERE id=:id AND reference_count=0
                       AND NOT EXISTS (
                           SELECT 1 FROM remote_payload_references r
                           WHERE r.payload_object_id=remote_payload_objects.id
                       )'
                );
                $stmt->execute(['id' => (int) $row['id']]);
                $result['deleted'] += $stmt->rowCount();
            } catch (Throwable) {
                $result['errors']++;
            }
        }
        return $result;
    }

    /**
     * Retira únicamente referencias cuyo recurso normalizado ya no existe.
     * La comprobación se hace contra la tabla y cuenta originales; después se
     * recalcula el contador de cada objeto afectado para proteger payloads
     * compartidos por referencias que siguen vivas.
     *
     * @return array{deleted:int,objects_recounted:int}
     */
    public function purgeDanglingReferences(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $rows = $pdo->query(
                '(SELECT r.id,r.payload_object_id
                  FROM remote_payload_references r FORCE INDEX (uq_remote_payload_entity)
                  LEFT JOIN meli_orders e
                    ON e.id=r.entity_id AND e.meli_account_id=r.meli_account_id
                  WHERE r.entity_table="meli_orders" AND e.id IS NULL
                  LIMIT ' . $limit . ')
                 UNION ALL
                (SELECT r.id,r.payload_object_id
                 FROM remote_payload_references r FORCE INDEX (uq_remote_payload_entity)
                 LEFT JOIN meli_shipments e
                   ON e.id=r.entity_id AND e.meli_account_id=r.meli_account_id
                 WHERE r.entity_table="meli_shipments" AND e.id IS NULL
                 LIMIT ' . $limit . ')
                 UNION ALL
                (SELECT r.id,r.payload_object_id
                 FROM remote_payload_references r FORCE INDEX (uq_remote_payload_entity)
                 LEFT JOIN meli_payments e
                   ON e.id=r.entity_id AND e.meli_account_id=r.meli_account_id
                 WHERE r.entity_table="meli_payments" AND e.id IS NULL
                 LIMIT ' . $limit . ')
                 UNION ALL
                (SELECT r.id,r.payload_object_id
                 FROM remote_payload_references r FORCE INDEX (uq_remote_payload_entity)
                 LEFT JOIN meli_packs e
                   ON e.id=r.entity_id AND e.meli_account_id=r.meli_account_id
                 WHERE r.entity_table="meli_packs" AND e.id IS NULL
                 LIMIT ' . $limit . ')
                 UNION ALL
                (SELECT r.id,r.payload_object_id
                 FROM remote_payload_references r FORCE INDEX (uq_remote_payload_entity)
                 LEFT JOIN meli_order_items e
                   ON e.id=r.entity_id AND e.meli_account_id=r.meli_account_id
                 WHERE r.entity_table="meli_order_items" AND e.id IS NULL
                 LIMIT ' . $limit . ')
                 LIMIT ' . $limit
            )->fetchAll(PDO::FETCH_ASSOC);
            if ($rows === []) {
                $pdo->commit();
                return ['deleted' => 0, 'objects_recounted' => 0];
            }
            $referenceIds = array_values(array_unique(array_map(
                static fn (array $row): int => (int) $row['id'],
                $rows
            )));
            $objectIds = array_values(array_unique(array_map(
                static fn (array $row): int => (int) $row['payload_object_id'],
                $rows
            )));
            $locked = $pdo->query(
                'SELECT id FROM remote_payload_references
                 WHERE id IN (' . implode(',', $referenceIds) . ')
                 FOR UPDATE'
            )->fetchAll(PDO::FETCH_COLUMN);
            if (count($locked) !== count($referenceIds)) {
                throw new RuntimeException(
                    'Las referencias cambiaron antes de poder bloquear el lote.'
                );
            }
            $deleted = (int) $pdo->exec(
                'DELETE FROM remote_payload_references
                 WHERE id IN (' . implode(',', $referenceIds) . ')'
            );
            if ($deleted !== count($referenceIds)) {
                throw new RuntimeException(
                    'Las referencias cambiaron durante la limpieza y el lote no fue aprobado.'
                );
            }
            $recounted = (int) $pdo->exec(
                'UPDATE remote_payload_objects o
                 SET reference_count=(
                     SELECT COUNT(*) FROM remote_payload_references r
                     WHERE r.payload_object_id=o.id
                 )
                 WHERE o.id IN (' . implode(',', $objectIds) . ')'
            );
            $pdo->commit();
            return ['deleted' => $deleted, 'objects_recounted' => $recounted];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function assertContext(PayloadContext $context): void
    {
        if (
            !in_array($context->entityTable, self::TABLES, true)
            || $context->entityId <= 0
            || $context->accountId <= 0
        ) {
            throw new RuntimeException('La referencia de payload no es válida.');
        }
    }

    private function absolute(string $storageName): string
    {
        if (
            $storageName === ''
            || str_contains($storageName, '..')
            || preg_match('#^[a-f0-9]{2}/[a-f0-9]{2}/[a-f0-9]{64}\.json\.gz$#', $storageName) !== 1
        ) {
            throw new RuntimeException('La referencia privada no es válida.');
        }
        return AppPaths::remotePayloads() . '/' . $storageName;
    }
}
