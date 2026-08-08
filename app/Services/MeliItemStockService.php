<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class MeliItemStockService
{
    public function __construct(private readonly int $accountId)
    {
    }

    /**
     * Sincroniza el stock multi-origen cacheado para una publicación local.
     * No modifica Mercado Libre: solo consulta /user-products/{id}/stock y guarda snapshot local.
     */
    public function syncItem(int $meliItemId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, meli_account_id, external_item_id, user_product_id FROM meli_items WHERE id=:id AND meli_account_id=:account LIMIT 1');
        $stmt->execute(['id' => $meliItemId, 'account' => $this->accountId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            throw new \RuntimeException('Publicación local no encontrada para consultar stock.');
        }

        $targets = [];
        if (!empty($item['user_product_id'])) {
            $targets[] = [
                'meli_item_id' => (int) $item['id'],
                'meli_item_variation_id' => null,
                'external_item_id' => (string) $item['external_item_id'],
                'external_variation_id' => null,
                'user_product_id' => (string) $item['user_product_id'],
            ];
        }

        $variationStmt = $pdo->prepare('SELECT id, external_variation_id, user_product_id FROM meli_item_variations WHERE meli_item_id=:item AND user_product_id IS NOT NULL AND user_product_id<>""');
        $variationStmt->execute(['item' => $meliItemId]);
        foreach ($variationStmt->fetchAll(PDO::FETCH_ASSOC) as $variation) {
            $targets[] = [
                'meli_item_id' => (int) $item['id'],
                'meli_item_variation_id' => (int) $variation['id'],
                'external_item_id' => (string) $item['external_item_id'],
                'external_variation_id' => (int) $variation['external_variation_id'],
                'user_product_id' => (string) $variation['user_product_id'],
            ];
        }

        if ($targets === []) {
            return ['synced' => 0, 'skipped' => 1, 'message' => 'La publicación no tiene user_product_id local.'];
        }

        $api = new MeliApiClient($this->accountId);
        $capabilities = new MeliApiCapabilityService();
        $synced = 0;
        $errors = 0;
        foreach ($targets as $target) {
            $endpoint = '/user-products/{USER_PRODUCT_ID}/stock';
            $path = '/user-products/' . rawurlencode($target['user_product_id']) . '/stock';
            if (!MeliEndpointRegistry::isConfirmed('GET', $path)) {
                $this->markUnavailable(
                    $target,
                    'endpoint_not_confirmed',
                    'Stock por origen deshabilitado hasta confirmar el contrato en el mapa API local.'
                );
                continue;
            }
            if ($capabilities->isCoolingDown($this->accountId, $endpoint, 'stock_by_origin')) {
                $errors++;
                continue;
            }
            try {
                $payload = $api->get($path, [], ['job_type' => 'stock_origin']);
                $this->storeLocations($target, $payload);
                $capabilities->mark($this->accountId, $endpoint, 'stock_by_origin', 'supported');
                $synced++;
            } catch (MeliApiException $e) {
                $errors++;
                if ($e->httpStatus === 403) {
                    $capabilities->mark($this->accountId, $endpoint, 'stock_by_origin', 'permission_required', $e->getMessage(), 1440);
                    $this->markUnavailable($target, 'permission_required', 'Mercado Libre no permitió consultar stock por origen para esta cuenta.');
                    Logger::write('warning', 'Stock por origen no disponible por permisos.', [
                        'account_id' => $this->accountId,
                        'meli_item_id' => $meliItemId,
                        'external_item_id' => $target['external_item_id'],
                        'http_status' => $e->httpStatus,
                        'error' => $e->getMessage(),
                    ]);
                    break;
                }
                $capabilities->mark($this->accountId, $endpoint, 'stock_by_origin', 'error', $e->getMessage(), in_array($e->httpStatus, [400, 404], true) ? 1440 : 30);
                $this->markUnavailable($target, 'unsupported', $e->getMessage());
            } catch (Throwable $e) {
                $errors++;
                Logger::write('warning', 'No se pudo consultar stock multi-origen de publicación ML.', [
                    'account_id' => $this->accountId,
                    'meli_item_id' => $meliItemId,
                    'external_item_id' => $target['external_item_id'],
                    'user_product_id' => $target['user_product_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['synced' => $synced, 'errors' => $errors, 'skipped' => 0];
    }

    private function markUnavailable(array $target, string $status, string $message): void
    {
        try {
            (new SchemaInspectorService())->hasTable('meli_item_stock_locations');
            $stmt = Database::connection()->prepare(
                'INSERT INTO meli_item_stock_locations
                 (meli_account_id,meli_item_id,meli_item_variation_id,external_item_id,external_variation_id,user_product_id,location_type,quantity,stock_source,raw_json,last_synced_at)
                 VALUES (:account,:item,:variation,:external_item,:external_variation,:user_product,:location_type,0,"unavailable",:raw,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE location_type=VALUES(location_type), quantity=0, stock_source="unavailable", raw_json=VALUES(raw_json), last_synced_at=UTC_TIMESTAMP()'
            );
            $stmt->execute([
                'account' => $this->accountId,
                'item' => $target['meli_item_id'],
                'variation' => $target['meli_item_variation_id'],
                'external_item' => $target['external_item_id'],
                'external_variation' => $target['external_variation_id'],
                'user_product' => $target['user_product_id'],
                'location_type' => $status,
                'raw' => json_encode(['status' => $status, 'message' => mb_substr($message, 0, 500)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable) {
            // No bloquear el flujo por diagnóstico de stock.
        }
    }

    private function storeLocations(array $target, array $payload): void
    {
        $pdo = Database::connection();
        $locations = is_array($payload['locations'] ?? null) ? $payload['locations'] : [];
        $pdo->prepare('DELETE FROM meli_item_stock_locations WHERE meli_account_id=:account AND user_product_id=:user_product')
            ->execute(['account' => $this->accountId, 'user_product' => $target['user_product_id']]);

        $stmt = $pdo->prepare(
            'INSERT INTO meli_item_stock_locations
             (meli_account_id,meli_item_id,meli_item_variation_id,external_item_id,external_variation_id,user_product_id,location_type,network_node_id,store_id,quantity,stock_source,raw_json,last_synced_at)
             VALUES
             (:account,:item,:variation,:external_item,:external_variation,:user_product,:location_type,:network_node,:store,:quantity,"multi_origin",:raw,NOW())'
        );

        foreach ($locations as $location) {
            $stmt->execute([
                'account' => $this->accountId,
                'item' => $target['meli_item_id'],
                'variation' => $target['meli_item_variation_id'],
                'external_item' => $target['external_item_id'],
                'external_variation' => $target['external_variation_id'],
                'user_product' => $target['user_product_id'],
                'location_type' => $location['type'] ?? null,
                'network_node' => $location['network_node_id'] ?? null,
                'store' => $location['store_id'] ?? null,
                'quantity' => (int) ($location['quantity'] ?? 0),
                'raw' => json_encode($location, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    }
}
