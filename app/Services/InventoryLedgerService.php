<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Libro mayor append-only. Todas las cantidades y costos se calculan en
 * DECIMAL dentro de MariaDB; PHP nunca convierte saldos a float.
 */
final class InventoryLedgerService
{
    private const TYPES = [
        'opening', 'receipt', 'sale_issue', 'sale_reversal',
        'adjustment_in', 'adjustment_out', 'reserve', 'release',
    ];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param list<array<string,mixed>> $specs
     * @return list<array<string,mixed>>
     */
    public function applyBatch(array $specs): array
    {
        if ($specs === []) {
            return [];
        }
        usort($specs, static fn(array $a, array $b): int => [
            (int) $a['company_id'], (int) $a['warehouse_id'], (int) $a['internal_product_id'],
            (string) $a['idempotency_key'],
        ] <=> [
            (int) $b['company_id'], (int) $b['warehouse_id'], (int) $b['internal_product_id'],
            (string) $b['idempotency_key'],
        ]);

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = [];
            foreach ($specs as $spec) {
                $result[] = $this->applyLocked($this->normalize($spec));
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $spec */
    private function applyLocked(array $spec): array
    {
        $companyId = (int) $spec['company_id'];
        $warehouseId = (int) $spec['warehouse_id'];
        $productId = (int) $spec['internal_product_id'];
        $this->assertEntities($companyId, $warehouseId, $productId);

        $this->pdo->prepare(
            'INSERT IGNORE INTO inventory_balances
                (company_id,warehouse_id,internal_product_id,on_hand,reserved,average_unit_cost)
             VALUES (?,?,?,0,0,0)'
        )->execute([$companyId, $warehouseId, $productId]);

        $lock = $this->pdo->prepare(
            'SELECT on_hand,reserved,available,average_unit_cost
             FROM inventory_balances
             WHERE company_id=? AND warehouse_id=? AND internal_product_id=?
             FOR UPDATE'
        );
        $lock->execute([$companyId, $warehouseId, $productId]);
        $before = $lock->fetch(PDO::FETCH_ASSOC);
        if (!is_array($before)) {
            throw new RuntimeException('No se pudo bloquear el saldo de inventario.');
        }

        $existing = $this->pdo->prepare(
            'SELECT * FROM inventory_movements
             WHERE company_id=? AND idempotency_key=? LIMIT 1'
        );
        $existing->execute([$companyId, $spec['idempotency_key']]);
        $movement = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($movement)) {
            $this->assertExistingEquivalent($movement, $spec);
            return $movement;
        }

        $type = (string) $spec['movement_type'];
        $quantity = (string) $spec['quantity'];
        $unitCost = (string) $spec['unit_cost'];
        $onHandDelta = '0.000000';
        $reservedDelta = '0.000000';

        if (in_array($type, ['opening', 'receipt', 'adjustment_in', 'sale_reversal'], true)) {
            if ($type === 'opening') {
                $count = $this->pdo->prepare(
                    'SELECT COUNT(*) FROM inventory_movements
                     WHERE company_id=? AND warehouse_id=? AND internal_product_id=?'
                );
                $count->execute([$companyId, $warehouseId, $productId]);
                if ((int) $count->fetchColumn() !== 0) {
                    throw new RuntimeException('El saldo inicial sólo puede registrarse antes del primer movimiento.');
                }
            }
            $update = $this->pdo->prepare(
                'UPDATE inventory_balances
                 SET average_unit_cost=CASE
                       WHEN on_hand+CAST(:qty AS DECIMAL(20,6))=0 THEN 0
                       ELSE ROUND(
                         ((on_hand*average_unit_cost)+(CAST(:qty2 AS DECIMAL(20,6))*CAST(:cost AS DECIMAL(20,6))))
                         /(on_hand+CAST(:qty3 AS DECIMAL(20,6))),6)
                     END,
                     on_hand=on_hand+CAST(:qty4 AS DECIMAL(20,6)),
                     lock_version=lock_version+1
                 WHERE company_id=:company AND warehouse_id=:warehouse AND internal_product_id=:product'
            );
            $update->execute([
                'qty' => $quantity, 'qty2' => $quantity, 'cost' => $unitCost, 'qty3' => $quantity,
                'qty4' => $quantity, 'company' => $companyId, 'warehouse' => $warehouseId, 'product' => $productId,
            ]);
            $onHandDelta = $quantity;
        } elseif (in_array($type, ['sale_issue', 'adjustment_out'], true)) {
            $effectiveCost = (string) $before['average_unit_cost'];
            if ($type === 'sale_issue' || $unitCost === '0.000000') {
                $unitCost = $this->decimal($effectiveCost, true);
            }
            $update = $this->pdo->prepare(
                'UPDATE inventory_balances
                 SET on_hand=on_hand-CAST(:qty AS DECIMAL(20,6)),
                     average_unit_cost=CASE WHEN on_hand-CAST(:qty2 AS DECIMAL(20,6))=0 THEN 0 ELSE average_unit_cost END,
                     lock_version=lock_version+1
                 WHERE company_id=:company AND warehouse_id=:warehouse AND internal_product_id=:product
                   AND available>=CAST(:required AS DECIMAL(20,6))'
            );
            $update->execute([
                'qty' => $quantity, 'qty2' => $quantity, 'company' => $companyId,
                'warehouse' => $warehouseId, 'product' => $productId, 'required' => $quantity,
            ]);
            if ($update->rowCount() !== 1) {
                throw new InventoryInsufficientStock(
                    $companyId, $warehouseId, $productId, $quantity, (string) $before['available']
                );
            }
            $onHandDelta = '-' . $quantity;
        } elseif ($type === 'reserve') {
            $update = $this->pdo->prepare(
                'UPDATE inventory_balances
                 SET reserved=reserved+CAST(:qty AS DECIMAL(20,6)),lock_version=lock_version+1
                 WHERE company_id=:company AND warehouse_id=:warehouse AND internal_product_id=:product
                   AND available>=CAST(:required AS DECIMAL(20,6))'
            );
            $update->execute([
                'qty' => $quantity, 'company' => $companyId, 'warehouse' => $warehouseId,
                'product' => $productId, 'required' => $quantity,
            ]);
            if ($update->rowCount() !== 1) {
                throw new InventoryInsufficientStock(
                    $companyId, $warehouseId, $productId, $quantity, (string) $before['available']
                );
            }
            $reservedDelta = $quantity;
        } elseif ($type === 'release') {
            $update = $this->pdo->prepare(
                'UPDATE inventory_balances
                 SET reserved=reserved-CAST(:qty AS DECIMAL(20,6)),lock_version=lock_version+1
                 WHERE company_id=:company AND warehouse_id=:warehouse AND internal_product_id=:product
                   AND reserved>=CAST(:required AS DECIMAL(20,6))'
            );
            $update->execute([
                'qty' => $quantity, 'company' => $companyId, 'warehouse' => $warehouseId,
                'product' => $productId, 'required' => $quantity,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('La liberación supera la cantidad reservada.');
            }
            $reservedDelta = '-' . $quantity;
        }

        $lock->execute([$companyId, $warehouseId, $productId]);
        $after = $lock->fetch(PDO::FETCH_ASSOC);
        if (!is_array($after)) {
            throw new RuntimeException('No se pudo comprobar la postimagen de inventario.');
        }
        $insert = $this->pdo->prepare(
            'INSERT INTO inventory_movements
                (company_id,warehouse_id,internal_product_id,meli_account_id,movement_type,
                 on_hand_delta,reserved_delta,unit_cost,total_cost,on_hand_after,reserved_after,
                 available_after,average_unit_cost_after,reference_type,reference_id,idempotency_key,
                 reversal_of_movement_id,actor_user_id,source,reason)
             VALUES
                (:company,:warehouse,:product,:account,:type,CAST(:on_hand_delta AS DECIMAL(20,6)),
                 CAST(:reserved_delta AS DECIMAL(20,6)),CAST(:unit_cost AS DECIMAL(20,6)),
                 ROUND(ABS(CAST(:cost_quantity AS DECIMAL(20,6)))*CAST(:unit_cost2 AS DECIMAL(20,6)),6),
                 :on_hand_after,:reserved_after,:available_after,:average_after,:reference_type,
                 :reference_id,:idempotency_key,:reversal_of,:actor,:source,:reason)'
        );
        $insert->execute([
            'company' => $companyId,
            'warehouse' => $warehouseId,
            'product' => $productId,
            'account' => $spec['meli_account_id'],
            'type' => $type,
            'on_hand_delta' => $onHandDelta,
            'reserved_delta' => $reservedDelta,
            'unit_cost' => $unitCost,
            'cost_quantity' => $onHandDelta,
            'unit_cost2' => $unitCost,
            'on_hand_after' => $after['on_hand'],
            'reserved_after' => $after['reserved'],
            'available_after' => $after['available'],
            'average_after' => $after['average_unit_cost'],
            'reference_type' => $spec['reference_type'],
            'reference_id' => $spec['reference_id'],
            'idempotency_key' => $spec['idempotency_key'],
            'reversal_of' => $spec['reversal_of_movement_id'],
            'actor' => $spec['actor_user_id'],
            'source' => $spec['source'],
            'reason' => $spec['reason'],
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $fetch = $this->pdo->prepare(
            'SELECT * FROM inventory_movements WHERE id=? AND company_id=? LIMIT 1'
        );
        $fetch->execute([$id, $companyId]);
        $movement = $fetch->fetch(PDO::FETCH_ASSOC);
        if (!is_array($movement)) {
            throw new RuntimeException('No se pudo comprobar el movimiento de inventario.');
        }
        return $movement;
    }

    private function assertEntities(int $companyId, int $warehouseId, int $productId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT w.id
             FROM inventory_warehouses w
             JOIN internal_products p ON p.id=? AND p.company_id=w.company_id AND p.deleted_at IS NULL
             WHERE w.id=? AND w.company_id=? AND w.status="active" LIMIT 1'
        );
        $stmt->execute([$productId, $warehouseId, $companyId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('La bodega o el producto no pertenecen a la empresa indicada.');
        }
    }

    /** @param array<string,mixed> $movement @param array<string,mixed> $spec */
    private function assertExistingEquivalent(array $movement, array $spec): void
    {
        $type = (string) $spec['movement_type'];
        $quantity = (string) $spec['quantity'];
        $expectedOnHand = match ($type) {
            'opening', 'receipt', 'adjustment_in', 'sale_reversal' => $quantity,
            'sale_issue', 'adjustment_out' => '-' . $quantity,
            default => '0.000000',
        };
        $expectedReserved = match ($type) {
            'reserve' => $quantity,
            'release' => '-' . $quantity,
            default => '0.000000',
        };
        $account = $spec['meli_account_id'];
        $movementAccount = $movement['meli_account_id'] === null ? null : (int) $movement['meli_account_id'];
        $reversal = $spec['reversal_of_movement_id'];
        $movementReversal = $movement['reversal_of_movement_id'] === null
            ? null : (int) $movement['reversal_of_movement_id'];
        $costMustMatch = in_array($type, ['opening', 'receipt', 'adjustment_in', 'sale_reversal'], true)
            || ($type === 'adjustment_out' && $spec['unit_cost'] !== '0.000000');
        if ((int) $movement['warehouse_id'] !== (int) $spec['warehouse_id']
            || (int) $movement['internal_product_id'] !== (int) $spec['internal_product_id']
            || $movementAccount !== $account
            || !hash_equals((string) $movement['movement_type'], $type)
            || !hash_equals((string) $movement['on_hand_delta'], $expectedOnHand)
            || !hash_equals((string) $movement['reserved_delta'], $expectedReserved)
            || !hash_equals((string) $movement['reference_type'], (string) $spec['reference_type'])
            || !hash_equals((string) $movement['reference_id'], (string) $spec['reference_id'])
            || !hash_equals((string) $movement['source'], (string) $spec['source'])
            || $movementReversal !== $reversal
            || ($costMustMatch && !hash_equals((string) $movement['unit_cost'], (string) $spec['unit_cost']))) {
            throw new RuntimeException('La clave idempotente pertenece a otro movimiento.');
        }
    }

    /** @param array<string,mixed> $spec @return array<string,mixed> */
    private function normalize(array $spec): array
    {
        $type = trim((string) ($spec['movement_type'] ?? ''));
        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException('Tipo de movimiento de inventario inválido.');
        }
        $quantity = $this->decimal((string) ($spec['quantity'] ?? ''), false);
        $costRequired = in_array($type, ['opening', 'receipt', 'adjustment_in', 'sale_reversal'], true);
        $unitCost = $this->decimal((string) ($spec['unit_cost'] ?? '0'), !$costRequired);
        $key = trim((string) ($spec['idempotency_key'] ?? ''));
        if ($key === '' || strlen($key) > 191) {
            throw new RuntimeException('Clave idempotente de inventario inválida.');
        }
        $source = (string) ($spec['source'] ?? 'system');
        if (!in_array($source, ['manual', 'queue_v4_clean', 'system'], true)) {
            throw new RuntimeException('Fuente de inventario inválida.');
        }
        return [
            'company_id' => (int) ($spec['company_id'] ?? 0),
            'warehouse_id' => (int) ($spec['warehouse_id'] ?? 0),
            'internal_product_id' => (int) ($spec['internal_product_id'] ?? 0),
            'meli_account_id' => isset($spec['meli_account_id']) ? (int) $spec['meli_account_id'] : null,
            'movement_type' => $type,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'reference_type' => mb_substr(trim((string) ($spec['reference_type'] ?? 'manual')), 0, 64),
            'reference_id' => mb_substr(trim((string) ($spec['reference_id'] ?? $key)), 0, 191),
            'idempotency_key' => $key,
            'reversal_of_movement_id' => isset($spec['reversal_of_movement_id'])
                ? (int) $spec['reversal_of_movement_id'] : null,
            'actor_user_id' => isset($spec['actor_user_id']) ? (int) $spec['actor_user_id'] : null,
            'source' => $source,
            'reason' => ($reason = trim((string) ($spec['reason'] ?? ''))) !== '' ? mb_substr($reason, 0, 500) : null,
        ];
    }

    private function decimal(string $value, bool $allowZero): string
    {
        $value = trim(str_replace(',', '.', $value));
        if (preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/', $value) !== 1) {
            throw new RuntimeException('Cantidad o costo decimal inválido.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $normalized = ltrim($whole, '0');
        $normalized = ($normalized === '' ? '0' : $normalized) . '.' . str_pad($fraction, 6, '0');
        if (!$allowZero && $normalized === '0.000000') {
            throw new RuntimeException('La cantidad debe ser mayor que cero.');
        }
        return $normalized;
    }
}
