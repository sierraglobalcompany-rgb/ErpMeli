<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use PDO;
use RuntimeException;
use Throwable;

final class InventoryWarehouseService
{
    public function __construct(private readonly AuthorizedBusinessScope $scope = new AuthorizedBusinessScope()) {}

    /** @return list<array<string,mixed>> */
    public function warehouses(int $companyId = 0, bool $activeOnly = false): array
    {
        [$sql, $params] = $this->companyPredicate('w.company_id', $companyId);
        $where = [$sql];
        if ($activeOnly) {
            $where[] = 'w.status="active"';
        }
        $stmt = Database::connection()->prepare(
            'SELECT w.*,c.name company_name
             FROM inventory_warehouses w JOIN companies c ON c.id=w.company_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY c.name,w.is_default DESC,w.name,w.id'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $input): int
    {
        $this->assertCanWrite();
        $companyId = (int) ($input['company_id'] ?? 0);
        $this->assertCompany($companyId);
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{1,63}$/', $code) !== 1 || $name === '') {
            throw new RuntimeException('Código o nombre de bodega inválido.');
        }
        $default = (int) ($input['is_default'] ?? 0) === 1;
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $this->lockCompany($pdo, $companyId);
            if ($default) {
                $pdo->prepare('UPDATE inventory_warehouses SET is_default=0 WHERE company_id=?')->execute([$companyId]);
            }
            $stmt = $pdo->prepare(
                'INSERT INTO inventory_warehouses (company_id,code,name,status,is_default,created_by)
                 VALUES (?,?,?,"active",?,?)'
            );
            $stmt->execute([$companyId, $code, mb_substr($name, 0, 160), $default ? 1 : 0, Auth::id()]);
            $id = (int) $pdo->lastInsertId();
            AuditService::record('create', 'inventory', 'inventory_warehouse', $id, null, null, [
                'company_id' => $companyId, 'code' => $code, 'is_default' => $default,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $id;
    }

    public function setDefault(int $warehouseId): void
    {
        $this->assertCanWrite();
        $warehouse = $this->authorizedWarehouse($warehouseId, false);
        $companyId = (int) $warehouse['company_id'];
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $this->lockCompany($pdo, $companyId);
            $current = $pdo->prepare(
                'SELECT id,is_default,status FROM inventory_warehouses
                 WHERE id=? AND company_id=? FOR UPDATE'
            );
            $current->execute([$warehouseId, $companyId]);
            $warehouse = $current->fetch(PDO::FETCH_ASSOC);
            if (!is_array($warehouse) || $warehouse['status'] !== 'active') {
                throw new RuntimeException('La bodega predeterminada debe estar activa.');
            }
            $pdo->prepare('UPDATE inventory_warehouses SET is_default=0 WHERE company_id=?')->execute([$companyId]);
            $target = $pdo->prepare(
                'UPDATE inventory_warehouses SET is_default=1
                 WHERE id=? AND company_id=? AND status="active"'
            );
            $target->execute([$warehouseId, $companyId]);
            if ($target->rowCount() !== 1) {
                throw new RuntimeException('No se pudo fijar la bodega predeterminada.');
            }
            AuditService::record('set_default', 'inventory', 'inventory_warehouse', $warehouseId, null, [
                'is_default' => (bool) $warehouse['is_default'],
            ], ['is_default' => true]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function setStatus(int $warehouseId, string $status): void
    {
        $this->assertCanWrite();
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new RuntimeException('Estado de bodega inválido.');
        }
        $warehouse = $this->authorizedWarehouse($warehouseId, false);
        $companyId = (int) $warehouse['company_id'];
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $this->lockCompany($pdo, $companyId);
            $current = $pdo->prepare(
                'SELECT id,is_default,status FROM inventory_warehouses
                 WHERE id=? AND company_id=? FOR UPDATE'
            );
            $current->execute([$warehouseId, $companyId]);
            $warehouse = $current->fetch(PDO::FETCH_ASSOC);
            if (!is_array($warehouse)) {
                throw new RuntimeException('La bodega ya no existe.');
            }
            if ($status === 'inactive' && (int) $warehouse['is_default'] === 1) {
                throw new RuntimeException('Seleccione otra bodega predeterminada antes de inactivar ésta.');
            }
            if ($status === 'inactive') {
                $balance = $pdo->prepare(
                    'SELECT internal_product_id FROM inventory_balances
                     WHERE company_id=? AND warehouse_id=? AND (on_hand<>0 OR reserved<>0)
                     ORDER BY internal_product_id LIMIT 1 FOR UPDATE'
                );
                $balance->execute([$companyId, $warehouseId]);
                if ($balance->fetchColumn() !== false) {
                    throw new RuntimeException('No se puede inactivar una bodega con existencias o reservas.');
                }
            }
            $update = $pdo->prepare(
                'UPDATE inventory_warehouses SET status=? WHERE id=? AND company_id=?'
            );
            $update->execute([$status, $warehouseId, $companyId]);
            if ($update->rowCount() !== 1 && $warehouse['status'] !== $status) {
                throw new RuntimeException('No se pudo actualizar el estado de la bodega.');
            }
            AuditService::record('status', 'inventory', 'inventory_warehouse', $warehouseId, null, [
                'status' => $warehouse['status'],
            ], ['status' => $status]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public function authorizedWarehouse(int $warehouseId, bool $activeOnly): array
    {
        [$scopeSql, $params] = $this->companyPredicate('company_id', 0);
        $stmt = Database::connection()->prepare(
            'SELECT * FROM inventory_warehouses
             WHERE id=:id AND ' . $scopeSql . ($activeOnly ? ' AND status="active"' : '') . ' LIMIT 1'
        );
        $stmt->execute(['id' => $warehouseId] + $params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new HttpException(404, 'No se encontró la bodega solicitada.');
        }
        return $row;
    }

    private function assertCanWrite(): void
    {
        Auth::requireRole('admin', 'operador');
        if (Auth::isTemporary()) {
            throw new RuntimeException('Los usuarios temporales no pueden modificar inventario.');
        }
    }

    private function assertCompany(int $companyId): void
    {
        if ($companyId < 1 || !in_array($companyId, $this->scope->companyIds(), true)) {
            throw new HttpException(404, 'No se encontró la empresa solicitada.');
        }
    }

    private function lockCompany(PDO $pdo, int $companyId): void
    {
        $stmt = $pdo->prepare('SELECT id FROM companies WHERE id=? AND status=1 FOR UPDATE');
        $stmt->execute([$companyId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('No se pudo bloquear la empresa de la bodega.');
        }
    }

    /** @return array{0:string,1:array<string,int>} */
    private function companyPredicate(string $column, int $requested): array
    {
        $ids = $this->scope->companyIds();
        if ($requested > 0) {
            if (!in_array($requested, $ids, true)) {
                throw new HttpException(404, 'No se encontró la empresa solicitada.');
            }
            return [$column . '=:inventory_company', ['inventory_company' => $requested]];
        }
        if ($ids === []) {
            return ['1=0', []];
        }
        $params = [];
        $slots = [];
        foreach ($ids as $i => $id) {
            $key = 'inventory_company_' . $i;
            $slots[] = ':' . $key;
            $params[$key] = $id;
        }
        return [$column . ' IN (' . implode(',', $slots) . ')', $params];
    }
}
