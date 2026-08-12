<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use PDO;
use RuntimeException;

final class InventoryApplicationService
{
    private const MANUAL_TYPES = ['opening','receipt','adjustment_in','adjustment_out','reserve','release'];

    public function __construct(private readonly AuthorizedBusinessScope $scope = new AuthorizedBusinessScope()) {}

    /** @return array<string,mixed> */
    public function manualMovement(array $input): array
    {
        $this->assertCanWrite();
        $warehouseId = (int) ($input['warehouse_id'] ?? 0);
        $productId = (int) ($input['internal_product_id'] ?? 0);
        $type = trim((string) ($input['movement_type'] ?? ''));
        $requestId = trim((string) ($input['request_id'] ?? ''));
        if (!in_array($type, self::MANUAL_TYPES, true)) {
            throw new RuntimeException('Movimiento manual no permitido.');
        }
        if (preg_match('/^[a-f0-9]{32}$/', $requestId) !== 1) {
            throw new RuntimeException('Identidad de solicitud manual inválida.');
        }
        $warehouse = (new InventoryWarehouseService($this->scope))->authorizedWarehouse($warehouseId, true);
        $companyId = (int) $warehouse['company_id'];
        $this->assertProduct($companyId, $productId);
        $movement = (new InventoryLedgerService(Database::connection()))->applyBatch([[
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'internal_product_id' => $productId,
            'movement_type' => $type,
            'quantity' => (string) ($input['quantity'] ?? ''),
            'unit_cost' => (string) ($input['unit_cost'] ?? '0'),
            'reference_type' => 'manual',
            'reference_id' => $requestId,
            'idempotency_key' => 'manual:' . $companyId . ':' . $requestId,
            'actor_user_id' => Auth::id(),
            'source' => 'manual',
            'reason' => (string) ($input['reason'] ?? ''),
        ]])[0];
        AuditService::record('movement', 'inventory', 'inventory_movement', (int) $movement['id'], null, null, [
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'internal_product_id' => $productId,
            'movement_type' => $type,
            'quantity' => (string) ($input['quantity'] ?? ''),
        ]);
        return $movement;
    }

    /** @return array{outcome:string,movements:int,reviews:int} */
    public function retryReview(int $reviewId): array
    {
        $this->assertCanWrite();
        $companyIds = $this->scope->companyIds();
        $accountIds = $this->scope->accountIds();
        if ($companyIds === [] || $accountIds === []) {
            throw new HttpException(404, 'No se encontró la revisión solicitada.');
        }
        $params = [$reviewId];
        $companySlots = implode(',', array_fill(0, count($companyIds), '?'));
        $accountSlots = implode(',', array_fill(0, count($accountIds), '?'));
        $params = array_merge($params, $companyIds, $accountIds);
        $stmt = Database::connection()->prepare(
            'SELECT id,company_id,meli_account_id,meli_order_id
             FROM inventory_reviews
             WHERE id=? AND company_id IN (' . $companySlots . ')
               AND meli_account_id IN (' . $accountSlots . ') AND state="open" LIMIT 1'
        );
        $stmt->execute($params);
        $review = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($review)) {
            throw new HttpException(404, 'No se encontró la revisión solicitada.');
        }
        $result = (new OrderInventoryService(Database::connection()))->project(
            (int) $review['company_id'], (int) $review['meli_account_id'], (int) $review['meli_order_id']
        );
        AuditService::record('retry_review', 'inventory', 'inventory_review', $reviewId, (int) $review['meli_account_id'], null, $result);
        return $result;
    }

    private function assertProduct(int $companyId, int $productId): void
    {
        if (!in_array($companyId, $this->scope->companyIds(), true)) {
            throw new HttpException(404, 'No se encontró la empresa solicitada.');
        }
        $stmt = Database::connection()->prepare(
            'SELECT id FROM internal_products
             WHERE id=? AND company_id=? AND deleted_at IS NULL AND status="active" LIMIT 1'
        );
        $stmt->execute([$productId, $companyId]);
        if (!$stmt->fetchColumn()) {
            throw new HttpException(404, 'No se encontró el producto solicitado.');
        }
    }

    private function assertCanWrite(): void
    {
        Auth::requireRole('admin', 'operador');
        if (Auth::isTemporary()) {
            throw new RuntimeException('Los usuarios temporales no pueden modificar inventario.');
        }
    }
}
