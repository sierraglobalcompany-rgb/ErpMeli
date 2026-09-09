<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class MeliProductUpdateReviewService
{
    private const REVIEW_STATUSES = ['new', 'changed'];

    public function createReview(int $accountId, int $maxItems = 50): int
    {
        if ($accountId < 1) {
            throw new RuntimeException('Seleccione una cuenta Mercado Libre.');
        }
        $account = (new BusinessScopeContext())->account($accountId);
        $companyId = (int) $account['company_id'];
        $maxItems = max(1, min(200, $maxItems));
        $pdo = Database::connection();
        $insert = $pdo->prepare(
            'INSERT INTO meli_product_update_reviews
             (company_id,meli_account_id,status,max_items,created_by,started_at)
             VALUES (:company,:account,"scanning",:max_items,:user,NOW())'
        );
        $insert->execute([
            'company' => $companyId,
            'account' => $accountId,
            'max_items' => $maxItems,
            'user' => Auth::id(),
        ]);
        $reviewId = (int) $pdo->lastInsertId();

        $count = 0;
        try {
            $sync = new MeliItemSyncService($accountId);
            $sellerId = $sync->sellerId();
            $offset = 0;
            $settings = new AppSettingsService();
            $configuredMode = (string) ($settings->get('items.search_mode', 'auto') ?: 'auto');
            $scanMode = $configuredMode === 'scan' && $settings->bool('items.scan_enabled', true);
            if ($configuredMode === 'auto' && $settings->bool('items.scan_enabled', true)) {
                try {
                    $threshold = max(100, $settings->int('items.scan_threshold', 1000));
                    $probe = (new MeliApiClient($accountId))->get('/users/' . $sellerId . '/items/search', ['offset' => 0, 'limit' => 1], ['job_type' => 'product_review']);
                    $scanMode = (int) ($probe['paging']['total'] ?? 0) >= $threshold;
                } catch (Throwable) {
                    $scanMode = false;
                }
            }
            $scrollId = '';
            $discoveredIds = [];
            while ($count < $maxItems) {
                $limit = min(50, $maxItems - $count);
                if ($scanMode) {
                    $query = ['search_type' => 'scan'];
                    if ($scrollId !== '') {
                        $query['scroll_id'] = $scrollId;
                    }
                    $page = (new MeliApiClient($accountId))->get('/users/' . $sellerId . '/items/search', $query, ['job_type' => 'product_review', 'bulk' => true]);
                    $scrollId = (string) ($page['scroll_id'] ?? '');
                } else {
                    $page = (new MeliApiClient($accountId))->get('/users/' . $sellerId . '/items/search', ['offset' => $offset, 'limit' => $limit], ['job_type' => 'product_review', 'bulk' => true]);
                }
                $ids = is_array($page['results'] ?? null) ? $page['results'] : [];
                if ($ids === []) {
                    break;
                }
                foreach ($ids as $externalId) {
                    if ($count >= $maxItems) {
                        break;
                    }
                    $discoveredIds[] = (string) $externalId;
                    $count++;
                }
                $offset += count($ids);
                if ($scanMode) {
                    if ($scrollId === '') {
                        break;
                    }
                    continue;
                }
                if (count($ids) < $limit) {
                    break;
                }
            }
            $count = 0;
            foreach (array_values(array_unique($discoveredIds)) as $externalId) {
                $this->scanOne($reviewId, $accountId, $externalId, $sync);
                $count++;
            }
            $this->refreshReviewTotals($reviewId, 'ready', $count);
        } catch (Throwable $e) {
            Database::connection()->prepare(
                'UPDATE meli_product_update_reviews
                 SET status=:status,total_remote=:total,error_message=:error,completed_at=NOW()
                 WHERE id=:id'
            )->execute([
                'status' => $count > 0 ? 'partial' : 'error',
                'total' => $count,
                'error' => mb_substr($e->getMessage(), 0, 500),
                'id' => $reviewId,
            ]);
            if ($count === 0) {
                throw $e;
            }
        }

        return $reviewId;
    }

    public function createReviewForItem(int $accountId, string $externalItemId, bool $strictExact = false): int
    {
        $externalItemId = strtoupper(trim($externalItemId));
        if ($accountId <= 0 || preg_match('/^[A-Z]{2,4}[0-9]+$/', $externalItemId) !== 1) {
            throw new RuntimeException('Publicación o cuenta Mercado Libre inválida.');
        }
        $companyId = $this->accountCompanyId($accountId);
        $existing = Database::connection()->prepare(
            'SELECT r.id
             FROM meli_product_update_reviews r
             JOIN meli_product_update_review_items i ON i.review_id=r.id
             WHERE r.company_id=? AND r.meli_account_id=? AND i.external_item_id=?
               AND r.status IN ("draft","scanning","ready","applying","partial")
               AND i.item_status IN ("new","changed","approved","error")'
             . ($strictExact ? ' AND i.item_status<>"error"' : '')
             . ' ORDER BY r.id DESC LIMIT 1'
        );
        $existing->execute([$companyId, $accountId, $externalItemId]);
        $existingId = (int) $existing->fetchColumn();
        if ($existingId > 0) {
            return $existingId;
        }

        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO meli_product_update_reviews
             (company_id,meli_account_id,status,max_items,created_by,started_at)
             VALUES (?,?,"scanning",1,?,NOW())'
        )->execute([$companyId, $accountId, Auth::id()]);
        $reviewId = (int) $pdo->lastInsertId();
        try {
            $this->scanOne($reviewId, $accountId, $externalItemId, new MeliItemSyncService($accountId), $strictExact);
            $this->refreshReviewTotals($reviewId, 'ready', 1);
            if ((new AppSettingsService())->bool('items.hybrid_notification_updates_enabled', true)) {
                $this->applySafeNotificationReview($reviewId, $companyId);
            }
        } catch (Throwable $error) {
            $pdo->prepare(
                'UPDATE meli_product_update_reviews
                 SET status="error",total_remote=1,error_message=?,completed_at=NOW()
                 WHERE id=?'
            )->execute(['No fue posible revisar la publicación notificada.', $reviewId]);
            throw $error;
        }
        return $reviewId;
    }

    /**
     * Aplica snapshots seguros del descubrimiento masivo y conserva los cambios
     * de identidad comercial en una revisión por excepción.
     */
    public function ingestDiscoveredSnapshot(int $accountId, string $externalItemId, array $remoteRaw): string
    {
        $externalItemId = strtoupper(trim($externalItemId));
        if ($accountId <= 0 || preg_match('/^[A-Z]{2,4}[0-9]+$/', $externalItemId) !== 1) {
            throw new RuntimeException('Publicación o cuenta Mercado Libre inválida.');
        }
        $companyId = $this->accountCompanyId($accountId);
        $local = $this->localSnapshot($accountId, $externalItemId);
        $remote = $this->remoteSnapshot($remoteRaw, null);
        $changes = $local === null ? [] : $this->diff($local['snapshot'], $remote);
        $sensitive = [
            'seller_sku', 'variations_hash', 'attributes_hash',
            'user_product_id', 'catalog_product_id',
        ];
        $requiresReview = false;
        foreach ($changes as $change) {
            if (in_array((string) ($change['field'] ?? ''), $sensitive, true)) {
                $requiresReview = true;
                break;
            }
        }
        if (!$requiresReview) {
            $meliItemId = (new MeliItemSyncService($accountId))->persistApprovedItem($remoteRaw, null);
            $this->markCatalogsRequiresUpdate($meliItemId);
            return 'applied';
        }

        $active = Database::connectionFresh()->prepare(
            'SELECT r.id
             FROM meli_product_update_reviews r
             JOIN meli_product_update_review_items ri ON ri.review_id=r.id
             WHERE r.company_id=? AND r.meli_account_id=? AND ri.meli_account_id=r.meli_account_id
               AND ri.external_item_id=?
               AND r.status IN ("draft","scanning","ready","applying","partial")
               AND ri.item_status IN ("new","changed","approved","error")
             ORDER BY r.id DESC LIMIT 1'
        );
        $active->execute([$companyId, $accountId, $externalItemId]);
        $reviewId = (int) ($active->fetchColumn() ?: 0);
        if ($reviewId <= 0) {
            $pdo = Database::connectionFresh();
            $pdo->prepare(
                'INSERT INTO meli_product_update_reviews
                 (company_id,meli_account_id,status,max_items,created_by,started_at)
                 VALUES (?,?,"scanning",1,?,UTC_TIMESTAMP())'
            )->execute([$companyId, $accountId, Auth::id()]);
            $reviewId = (int) $pdo->lastInsertId();
        }
        $reviewItemId = $this->upsertReviewItem(
            $reviewId,
            $accountId,
            $externalItemId,
            $local['id'] ?? null,
            'changed',
            $this->summary('changed', $remote, count($changes)),
            $local['snapshot'] ?? null,
            $remote + ['raw' => $remoteRaw],
            count($changes),
            null
        );
        $this->replaceChanges($reviewItemId, $changes);
        $this->refreshReviewTotals($reviewId, 'ready', 1);
        return 'review';
    }

    public function listReviews(int $companyId = 0): array
    {
        $scope = (new BusinessScopeContext())->accountPredicate('r.meli_account_id', null, $companyId);
        $stmt = Database::connection()->prepare(
            'SELECT r.*, a.account_name, u.name created_by_name
             FROM meli_product_update_reviews r
             JOIN meli_accounts a ON a.id=r.meli_account_id
             LEFT JOIN users u ON u.id=r.created_by
             WHERE r.company_id=a.company_id AND ' . $scope['sql'] . '
             ORDER BY r.created_at DESC
             LIMIT 80'
        );
        $stmt->execute($scope['params']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function review(int $reviewId, int $companyId = 0): array
    {
        $scope = (new BusinessScopeContext())->accountPredicate('r.meli_account_id', null, $companyId);
        $stmt = Database::connection()->prepare(
            'SELECT r.*, a.account_name, u.name created_by_name
             FROM meli_product_update_reviews r
             JOIN meli_accounts a ON a.id=r.meli_account_id
             LEFT JOIN users u ON u.id=r.created_by
             WHERE r.id=? AND r.company_id=a.company_id AND ' . $scope['sql']
        );
        $stmt->execute(array_merge([$reviewId], $scope['params']));
        $review = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$review) {
            throw new RuntimeException('Revisión no encontrada.');
        }
        return $review;
    }

    public function items(int $reviewId, string $status = ''): array
    {
        $review = $this->review($reviewId);
        $where = ['ri.review_id=:review'];
        $params = [
            'review' => $reviewId,
            'account' => (int) $review['meli_account_id'],
        ];
        $where[] = 'ri.meli_account_id=:account';
        if ($status !== '') {
            $where[] = 'ri.item_status=:status';
            $params['status'] = $status;
        }
        $stmt = Database::connection()->prepare(
            'SELECT ri.*, mi.title local_title, mi.thumbnail local_thumbnail
             FROM meli_product_update_review_items ri
             LEFT JOIN meli_items mi ON mi.id=ri.local_meli_item_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY FIELD(ri.item_status,"new","changed","error","approved","rejected","applied","unchanged"), ri.id ASC
             LIMIT 500'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function changes(int $reviewItemId): array
    {
        $scope = (new BusinessScopeContext())->accountPredicate('ri.meli_account_id');
        $stmt = Database::connection()->prepare(
            'SELECT ch.*
             FROM meli_product_update_review_changes ch
             JOIN meli_product_update_review_items ri ON ri.id=ch.review_item_id
             JOIN meli_product_update_reviews r ON r.id=ri.review_id
             JOIN meli_accounts a
               ON a.id=ri.meli_account_id
              AND a.company_id=r.company_id
              AND r.meli_account_id=ri.meli_account_id
             WHERE ch.review_item_id=? AND ' . $scope['sql'] . '
             ORDER BY ch.id ASC'
        );
        $stmt->execute(array_merge([$reviewItemId], $scope['params']));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function approveItem(int $itemId): void
    {
        $this->transitionItem($itemId, 'approved', 'approved_by', 'approved_at');
    }

    public function rejectItem(int $itemId): void
    {
        $this->transitionItem($itemId, 'rejected', 'rejected_by', 'rejected_at');
    }

    public function approveBulk(int $reviewId, string $mode): int
    {
        $review = $this->review($reviewId);
        $allowed = match ($mode) {
            'new' => ['new'],
            'changed' => ['changed'],
            default => self::REVIEW_STATUSES,
        };
        $placeholders = [];
        $params = [
            'review' => $reviewId,
            'account' => (int) $review['meli_account_id'],
            'user' => Auth::id(),
        ];
        foreach ($allowed as $index => $status) {
            $key = 'status_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $status;
        }
        $stmt = Database::connection()->prepare(
            'UPDATE meli_product_update_review_items
             SET item_status="approved", approved_by=:user, approved_at=NOW()
             WHERE review_id=:review AND meli_account_id=:account
               AND item_status IN (' . implode(',', $placeholders) . ')'
        );
        $stmt->execute($params);
        $this->refreshReviewTotals($reviewId);
        return $stmt->rowCount();
    }

    public function applyApproved(int $reviewId): int
    {
        $review = $this->review($reviewId);
        $pdo = Database::connection();
        $pdo->prepare('UPDATE meli_product_update_reviews SET status="applying" WHERE id=:id')->execute(['id' => $reviewId]);
        $stmt = $pdo->prepare(
            'SELECT * FROM meli_product_update_review_items
             WHERE review_id=:review AND meli_account_id=:account AND item_status="approved"
             ORDER BY id ASC
             LIMIT 250'
        );
        $stmt->execute(['review' => $reviewId, 'account' => (int) $review['meli_account_id']]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $applied = 0;
        $sync = new MeliItemSyncService((int) $review['meli_account_id']);
        foreach ($items as $item) {
            try {
                $remote = json_decode((string) $item['remote_snapshot_json'], true);
                if (!is_array($remote) || !is_array($remote['raw'] ?? null)) {
                    throw new RuntimeException('Snapshot remoto inválido.');
                }
                $description = null;
                if (is_array($remote['description_raw'] ?? null)) {
                    $plain = trim((string) ($remote['description_raw']['plain_text'] ?? ''));
                    $text = trim((string) ($remote['description_raw']['text'] ?? ''));
                    $description = [
                        'source_status' => ($plain !== '' || $text !== '') ? 'confirmed' : 'unavailable',
                        'plain_text' => $plain !== '' ? $plain : null,
                        'description_text' => $text !== '' ? $text : null,
                        'description_hash' => hash('sha256', $plain !== '' ? $plain : $text),
                        'raw' => $remote['description_raw'],
                        'safe_error_message' => null,
                    ];
                } elseif (array_key_exists('description_status', $remote)) {
                    $description = [
                        'source_status' => in_array((string) $remote['description_status'], ['pending', 'confirmed', 'unavailable', 'error'], true) ? (string) $remote['description_status'] : 'unavailable',
                        'plain_text' => null,
                        'description_text' => null,
                        'description_hash' => null,
                        'raw' => null,
                        'safe_error_message' => null,
                    ];
                }
                $meliItemId = $sync->persistApprovedItem($remote['raw'], $description);
                $this->markCatalogsRequiresUpdate($meliItemId);
                $pdo->prepare(
                    'UPDATE meli_product_update_review_items
                     SET item_status="applied", local_meli_item_id=:local_id, applied_by=:user, applied_at=NOW(), safe_error_message=NULL
                     WHERE id=:id'
                )->execute(['local_id' => $meliItemId, 'user' => Auth::id(), 'id' => (int) $item['id']]);
                $applied++;
            } catch (Throwable $e) {
                $pdo->prepare(
                    'UPDATE meli_product_update_review_items
                     SET item_status="error", safe_error_message=:error
                     WHERE id=:id'
                )->execute(['error' => mb_substr($e->getMessage(), 0, 500), 'id' => (int) $item['id']]);
            }
        }
        $this->refreshReviewTotals($reviewId, null, null, true);
        return $applied;
    }

    private function scanOne(int $reviewId, int $accountId, string $externalId, MeliItemSyncService $sync, bool $strictExact = false): void
    {
        try {
            $remoteRaw = $sync->fetchRemoteItem($externalId);
            $remote = $this->remoteSnapshot($remoteRaw, null);
            $local = $this->localSnapshot($accountId, $externalId);
            $changes = $local === null ? [] : $this->diff($local['snapshot'], $remote);
            $status = $local === null ? 'new' : ($changes === [] ? 'unchanged' : 'changed');
            $summary = $this->summary($status, $remote, count($changes));
            $remotePayload = $remote + ['raw' => $remoteRaw];
            $reviewItemId = $this->upsertReviewItem($reviewId, $accountId, $externalId, $local['id'] ?? null, $status, $summary, $local['snapshot'] ?? null, $remotePayload, count($changes), null);
            $this->replaceChanges($reviewItemId, $changes);
        } catch (Throwable $e) {
            $this->upsertReviewItem($reviewId, $accountId, $externalId, null, 'error', 'Error consultando publicación.', null, null, 0, $e->getMessage());
            if ($strictExact) {
                throw $e;
            }
        }
    }

    private function upsertReviewItem(int $reviewId, int $accountId, string $externalId, ?int $localId, string $status, ?string $summary, ?array $local, ?array $remote, int $changeCount, ?string $error): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO meli_product_update_review_items
             (review_id,meli_account_id,external_item_id,local_meli_item_id,item_status,change_count,summary,local_snapshot_json,remote_snapshot_json,safe_error_message)
             VALUES (:review,:account,:external,:local_id,:status,:change_count,:summary,:local_json,:remote_json,:error)
             ON DUPLICATE KEY UPDATE
                local_meli_item_id=VALUES(local_meli_item_id), item_status=VALUES(item_status),
                change_count=VALUES(change_count), summary=VALUES(summary),
                local_snapshot_json=VALUES(local_snapshot_json), remote_snapshot_json=VALUES(remote_snapshot_json),
                safe_error_message=VALUES(safe_error_message), id=LAST_INSERT_ID(id)'
        );
        $stmt->execute([
            'review' => $reviewId,
            'account' => $accountId,
            'external' => $externalId,
            'local_id' => $localId,
            'status' => $status,
            'change_count' => $changeCount,
            'summary' => $summary,
            'local_json' => $local ? json_encode($local, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'remote_json' => $remote ? json_encode($remote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'error' => $error ? mb_substr($error, 0, 500) : null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function replaceChanges(int $reviewItemId, array $changes): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM meli_product_update_review_changes WHERE review_item_id=:id')->execute(['id' => $reviewItemId]);
        if ($changes === []) {
            return;
        }
        $insert = $pdo->prepare(
            'INSERT INTO meli_product_update_review_changes
             (review_item_id,field_key,field_label,old_value,new_value,change_type)
             VALUES (:item,:field,:label,:old_value,:new_value,:type)'
        );
        foreach ($changes as $change) {
            $insert->execute([
                'item' => $reviewItemId,
                'field' => $change['field'],
                'label' => $change['label'],
                'old_value' => $change['old'],
                'new_value' => $change['new'],
                'type' => $change['type'],
            ]);
        }
    }

    private function localSnapshot(int $accountId, string $externalId): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM meli_items WHERE meli_account_id=:account AND external_item_id=:external LIMIT 1');
        $stmt->execute(['account' => $accountId, 'external' => $externalId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            return null;
        }
        $itemId = (int) $item['id'];
        $variations = $this->fetchAll('SELECT external_variation_id,seller_sku,price,available_quantity,sold_quantity,attribute_summary FROM meli_item_variations WHERE meli_item_id=:id ORDER BY external_variation_id ASC', ['id' => $itemId]);
        $pictures = $this->fetchAll('SELECT COALESCE(secure_url,url) url FROM meli_item_pictures WHERE meli_item_id=:id ORDER BY position ASC, id ASC', ['id' => $itemId]);
        $attributes = $this->fetchAll('SELECT attribute_id,name,value_id,value_name FROM meli_item_attributes WHERE meli_item_id=:id ORDER BY attribute_id ASC, name ASC', ['id' => $itemId]);
        $description = MeliItemDescriptionService::cachedForItem($itemId);
        return [
            'id' => $itemId,
            'snapshot' => [
                'title' => (string) $item['title'],
                'seller_sku' => $item['seller_sku'],
                'user_product_id' => $item['user_product_id'] ?? null,
                'category_id' => $item['category_id'],
                'catalog_product_id' => $item['catalog_product_id'] ?? null,
                'price' => $this->moneyValue($item['price']),
                'base_price' => $this->moneyValue($item['base_price']),
                'original_price' => $this->moneyValue($item['original_price']),
                'condition' => $item['condition'],
                'available_quantity' => (int) $item['available_quantity'],
                'sold_quantity' => (int) $item['sold_quantity'],
                'status' => $item['status'],
                'permalink' => $item['permalink'],
                'thumbnail' => $item['thumbnail'],
                'listing_type_id' => $item['listing_type_id'],
                'pictures_hash' => $this->hashRows($pictures),
                'variations_hash' => $this->hashRows($variations),
                'attributes_hash' => $this->hashRows($attributes),
                'description_hash' => is_array($description) && (string) ($description['source_status'] ?? '') === 'confirmed'
                    ? hash('sha256', (string) ($description['plain_text'] ?? $description['description_text'] ?? ''))
                    : null,
            ],
        ];
    }

    private function remoteSnapshot(array $item, ?array $description = null): array
    {
        return [
            'title' => (string) ($item['title'] ?? 'Sin título'),
            'seller_sku' => $this->sellerSku($item),
            'user_product_id' => $item['user_product_id'] ?? null,
            'category_id' => $item['category_id'] ?? null,
            'catalog_product_id' => $item['catalog_product_id'] ?? null,
            'price' => $this->moneyValue($item['price'] ?? null),
            'base_price' => $this->moneyValue($item['base_price'] ?? null),
            'original_price' => $this->moneyValue($item['original_price'] ?? null),
            'condition' => $item['condition'] ?? null,
            'available_quantity' => (int) ($item['available_quantity'] ?? 0),
            'sold_quantity' => (int) ($item['sold_quantity'] ?? 0),
            'status' => $item['status'] ?? null,
            'permalink' => $item['permalink'] ?? null,
            'thumbnail' => $item['thumbnail'] ?? null,
            'listing_type_id' => $item['listing_type_id'] ?? null,
            'pictures_hash' => $this->hashRows($this->normalizePictures($item['pictures'] ?? [])),
            'variations_hash' => $this->hashRows($this->normalizeVariations($item['variations'] ?? [])),
            'attributes_hash' => $this->hashRows($this->normalizeAttributes($item['attributes'] ?? [])),
            'description_hash' => is_array($description) && ($description['plain_text'] ?? $description['description_text'] ?? '') !== ''
                ? hash('sha256', (string) ($description['plain_text'] ?? $description['description_text'] ?? ''))
                : null,
        ];
    }

    private function diff(array $local, array $remote): array
    {
        $labels = [
            'title' => 'Título',
            'seller_sku' => 'SKU',
            'user_product_id' => 'Producto de usuario',
            'category_id' => 'Categoría',
            'catalog_product_id' => 'Identidad de catálogo',
            'price' => 'Precio',
            'base_price' => 'Precio base',
            'original_price' => 'Precio original',
            'condition' => 'Condición',
            'available_quantity' => 'Disponible',
            'sold_quantity' => 'Vendidos',
            'status' => 'Estado',
            'permalink' => 'Link ML',
            'thumbnail' => 'Miniatura',
            'listing_type_id' => 'Tipo publicación',
            'pictures_hash' => 'Fotos',
            'variations_hash' => 'Variaciones',
            'attributes_hash' => 'Atributos',
            'description_hash' => 'Descripción',
        ];
        $changes = [];
        foreach ($labels as $field => $label) {
            $old = $local[$field] ?? null;
            $new = $remote[$field] ?? null;
            if ((string) $old === (string) $new) {
                continue;
            }
            $changes[] = [
                'field' => $field,
                'label' => $label,
                'old' => $this->displayValue($field, $old),
                'new' => $this->displayValue($field, $new),
                'type' => $old === null ? 'created' : ($new === null ? 'removed' : 'updated'),
            ];
        }
        return $changes;
    }

    private function transitionItem(int $itemId, string $status, string $userField, string $dateField): void
    {
        $scope = (new BusinessScopeContext())->accountPredicate('ri.meli_account_id');
        $stmt = Database::connection()->prepare(
            'UPDATE meli_product_update_review_items ri
             JOIN meli_product_update_reviews r ON r.id=ri.review_id
             JOIN meli_accounts a ON a.id=ri.meli_account_id AND a.company_id=r.company_id
             SET item_status=?, ' . $userField . '=?, ' . $dateField . '=NOW()
             WHERE ri.id=? AND ' . $scope['sql'] . '
               AND item_status IN ("new","changed","approved","rejected")'
        );
        $stmt->execute(array_merge([$status, Auth::id(), $itemId], $scope['params']));
        $reviewId = $this->reviewIdForItem($itemId);
        if ($reviewId > 0) {
            $this->refreshReviewTotals($reviewId);
        }
    }

    private function reviewIdForItem(int $itemId): int
    {
        $scope = (new BusinessScopeContext())->accountPredicate('ri.meli_account_id');
        $stmt = Database::connection()->prepare(
            'SELECT ri.review_id
             FROM meli_product_update_review_items ri
             JOIN meli_product_update_reviews r ON r.id=ri.review_id
             JOIN meli_accounts a ON a.id=ri.meli_account_id AND a.company_id=r.company_id
             WHERE ri.id=? AND ' . $scope['sql']
        );
        $stmt->execute(array_merge([$itemId], $scope['params']));
        return (int) $stmt->fetchColumn();
    }

    private function applySafeNotificationReview(int $reviewId, int $companyId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT ri.*
             FROM meli_product_update_review_items ri
             JOIN meli_product_update_reviews r ON r.id=ri.review_id
             WHERE r.id=? AND r.company_id=? AND ri.meli_account_id=r.meli_account_id
             LIMIT 1'
        );
        $stmt->execute([$reviewId, $companyId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($item) || (string) $item['item_status'] === 'error') {
            return;
        }
        if ((string) $item['item_status'] === 'unchanged') {
            $pdo->prepare(
                'UPDATE meli_product_update_reviews
                 SET status="applied",completed_at=COALESCE(completed_at,NOW()),applied_at=NOW()
                 WHERE id=? AND company_id=?'
            )->execute([$reviewId, $companyId]);
            return;
        }
        $changes = $pdo->prepare(
            'SELECT field_key FROM meli_product_update_review_changes
             WHERE review_item_id=?
               AND field_key IN (
                    "seller_sku","variations_hash","attributes_hash",
                    "user_product_id","catalog_product_id"
               )
             LIMIT 1'
        );
        $changes->execute([(int) $item['id']]);
        if ($changes->fetchColumn() !== false) {
            return;
        }
        $remote = json_decode((string) $item['remote_snapshot_json'], true);
        if (!is_array($remote) || !is_array($remote['raw'] ?? null)) {
            return;
        }
        $meliItemId = (new MeliItemSyncService((int) $item['meli_account_id']))
            ->persistApprovedItem($remote['raw'], null);
        $this->markCatalogsRequiresUpdate($meliItemId);
        $pdo->prepare(
            'UPDATE meli_product_update_review_items
             SET item_status="applied",local_meli_item_id=?,applied_at=NOW(),safe_error_message=NULL
             WHERE id=? AND meli_account_id=?'
        )->execute([$meliItemId, (int) $item['id'], (int) $item['meli_account_id']]);
        $this->refreshReviewTotals($reviewId, null, null, true);
    }

    private function accountCompanyId(int $accountId): int
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT company_id FROM meli_accounts WHERE id=? LIMIT 1'
        );
        $stmt->execute([$accountId]);
        $companyId = (int) $stmt->fetchColumn();
        if ($companyId <= 0) {
            throw new RuntimeException('La cuenta Mercado Libre no pertenece a una empresa válida.');
        }
        return $companyId;
    }

    private function refreshReviewTotals(int $reviewId, ?string $status = null, ?int $totalRemote = null, bool $completeApply = false): void
    {
        $counts = Database::connection()->prepare(
            'SELECT
                SUM(item_status="new") new_count,
                SUM(item_status="changed") changed_count,
                SUM(item_status="unchanged") unchanged_count,
                SUM(item_status="error") error_count,
                SUM(item_status="approved") approved_count,
                SUM(item_status="rejected") rejected_count,
                SUM(item_status="applied") applied_count,
                COUNT(*) total_count
             FROM meli_product_update_review_items
             WHERE review_id=:review'
        );
        $counts->execute(['review' => $reviewId]);
        $row = $counts->fetch(PDO::FETCH_ASSOC) ?: [];
        $nextStatus = $status;
        if ($completeApply) {
            $nextStatus = ((int) ($row['approved_count'] ?? 0) === 0 && (int) ($row['error_count'] ?? 0) === 0) ? 'applied' : 'partial';
        }
        $sql = 'UPDATE meli_product_update_reviews SET
                new_count=:new_count, changed_count=:changed_count, unchanged_count=:unchanged_count,
                error_count=:error_count, approved_count=:approved_count, rejected_count=:rejected_count,
                applied_count=:applied_count';
        $params = [
            'new_count' => (int) ($row['new_count'] ?? 0),
            'changed_count' => (int) ($row['changed_count'] ?? 0),
            'unchanged_count' => (int) ($row['unchanged_count'] ?? 0),
            'error_count' => (int) ($row['error_count'] ?? 0),
            'approved_count' => (int) ($row['approved_count'] ?? 0),
            'rejected_count' => (int) ($row['rejected_count'] ?? 0),
            'applied_count' => (int) ($row['applied_count'] ?? 0),
            'id' => $reviewId,
        ];
        if ($totalRemote !== null) {
            $sql .= ', total_remote=:total_remote';
            $params['total_remote'] = $totalRemote;
        }
        if ($nextStatus !== null) {
            $sql .= ', status=:status, completed_at=COALESCE(completed_at,NOW())';
            $params['status'] = $nextStatus;
        }
        if ($completeApply) {
            $sql .= ', applied_by=:applied_by, applied_at=NOW()';
            $params['applied_by'] = Auth::id();
        }
        $sql .= ' WHERE id=:id';
        Database::connection()->prepare($sql)->execute($params);
    }

    private function markCatalogsRequiresUpdate(int $meliItemId): void
    {
        Database::connection()->prepare(
            'UPDATE catalogs c
             JOIN catalog_items ci ON ci.catalog_id=c.id
             SET c.last_refresh_status="requires_update",
                 c.last_refresh_message="Hay publicaciones Mercado Libre actualizadas; refresque el catálogo para publicar los snapshots nuevos."
             WHERE ci.meli_item_id=:item'
        )->execute(['item' => $meliItemId]);
    }

    private function fetchAll(string $sql, array $params): array
    {
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function normalizePictures(array $pictures): array
    {
        return array_map(static fn(array $picture): array => [
            'url' => $picture['secure_url'] ?? $picture['url'] ?? null,
        ], array_values($pictures));
    }

    private function normalizeVariations(array $variations): array
    {
        $rows = [];
        foreach ($variations as $variation) {
            $rows[] = [
                'external_variation_id' => (int) ($variation['id'] ?? 0),
                'seller_sku' => $this->sellerSku($variation),
                'price' => $this->moneyValue($variation['price'] ?? null),
                'available_quantity' => (int) ($variation['available_quantity'] ?? 0),
                'sold_quantity' => (int) ($variation['sold_quantity'] ?? 0),
                'attribute_summary' => implode(', ', array_filter(
                    array_map(static fn($a): string => trim((string) ($a['name'] ?? '') . ': ' . (string) ($a['value_name'] ?? ''), ' :'), $variation['attribute_combinations'] ?? []),
                    static fn(string $summary): bool => $summary !== ''
                )),
            ];
        }
        return $rows;
    }

    private function normalizeAttributes(array $attributes): array
    {
        return array_map(static fn(array $attribute): array => [
            'attribute_id' => $attribute['id'] ?? 'unknown',
            'name' => $attribute['name'] ?? null,
            'value_id' => $attribute['value_id'] ?? null,
            'value_name' => $attribute['value_name'] ?? null,
        ], $attributes);
    }

    private function hashRows(array $rows): string
    {
        return hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function moneyValue(mixed $value): ?string
    {
        return $value === null ? null : number_format((float) $value, 2, '.', '');
    }

    private function displayValue(string $field, mixed $value): string
    {
        if (in_array($field, ['pictures_hash', 'variations_hash', 'attributes_hash', 'description_hash'], true)) {
            return $value ? 'Cambió' : 'Sin datos';
        }
        if ($value === null || $value === '') {
            return '—';
        }
        return mb_substr((string) $value, 0, 240);
    }

    private function sellerSku(array $row): ?string
    {
        foreach ($row['attributes'] ?? [] as $attribute) {
            if (in_array($attribute['id'] ?? '', ['SELLER_SKU', 'SKU'], true)) {
                return $attribute['value_name'] ?? null;
            }
        }
        return $row['seller_custom_field'] ?? null;
    }

    private function summary(string $status, array $remote, int $changes): string
    {
        if ($status === 'new') {
            return 'Publicación nueva detectada.';
        }
        if ($status === 'unchanged') {
            return 'Sin cambios relevantes.';
        }
        $state = (string) ($remote['status'] ?? '');
        if (in_array($state, ['paused', 'closed', 'inactive', 'under_review'], true)) {
            return 'Cambio detectado; estado actual: ' . $state . '.';
        }
        return $changes . ' cambio(s) detectado(s).';
    }
}
